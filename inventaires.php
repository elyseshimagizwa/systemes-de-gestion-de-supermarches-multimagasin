<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

requireLogin();

$user = currentUser();
$role = (string)($user['role'] ?? '');

if (!in_array($role, ['admin', 'caissier'], true)) {
    http_response_code(403);
    exit('Accès refusé');
}

$isAdmin = $role === 'admin';
$selectedMagasinId = $isAdmin
    ? (int)($_GET['magasin_id'] ?? $_POST['magasin_id'] ?? currentMagasinId())
    : (int)($user['magasin_id'] ?? 0);

if ($selectedMagasinId <= 0 || !canAccessMagasin($selectedMagasinId)) {
    http_response_code(403);
    exit('Magasin non autorisé');
}

function inventoryRedirect(int $magasinId): void
{
    header('Location: inventaires.php?magasin_id=' . $magasinId);
    exit;
}

function inventoryReference(): string
{
    return 'INV-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

function inventoryHistory(PDO $pdo, array $user, int $magasinId, string $action, string $details, string $niveau = 'info'): void
{
    $stmt = $pdo->prepare('INSERT INTO historiques (utilisateur_id, magasin_id, action, details, ip, niveau, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute([
        $user['id'] ?? null,
        $magasinId,
        $action,
        $details,
        $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN',
        $niveau,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'create') {
            $notes = trim((string)($_POST['notes'] ?? ''));
            $check = $pdo->prepare("SELECT id FROM inventaires WHERE magasin_id=? AND statut IN ('comptage','attente_validation') LIMIT 1");
            $check->execute([$selectedMagasinId]);

            if ($check->fetchColumn()) {
                throw new RuntimeException('Une session d’inventaire est déjà ouverte ou en attente de validation pour ce magasin.');
            }

            $stmt = $pdo->prepare("INSERT INTO inventaires (reference, magasin_id, utilisateur_id, statut, notes) VALUES (?, ?, ?, 'comptage', ?)");
            $stmt->execute([inventoryReference(), $selectedMagasinId, (int)$user['id'], $notes ?: null]);
            $inventoryId = (int)$pdo->lastInsertId();
            inventoryHistory($pdo, $user, $selectedMagasinId, 'INVENTAIRE_OUVERT', 'Session #' . $inventoryId . ' ouverte', 'success');
            flash('success', 'Session d’inventaire créée.');
        } elseif ($action === 'count') {
            $inventoryId = (int)($_POST['inventaire_id'] ?? 0);
            $productId = (int)($_POST['produit_id'] ?? 0);
            $barcode = trim((string)($_POST['codebarre'] ?? ''));
            $counted = filter_var($_POST['quantite_comptee'] ?? null, FILTER_VALIDATE_INT);

            if ($counted === false || $counted === null || $counted < 0) {
                throw new RuntimeException('La quantité comptée doit être un entier positif ou zéro.');
            }

            $inventory = $pdo->prepare("SELECT * FROM inventaires WHERE id=? AND magasin_id=? AND statut='comptage' FOR UPDATE");
            $pdo->beginTransaction();
            $inventory->execute([$inventoryId, $selectedMagasinId]);
            $inventoryRow = $inventory->fetch();

            if (!$inventoryRow) {
                throw new RuntimeException('Session d’inventaire introuvable ou déjà soumise.');
            }

            if ($productId > 0) {
                $productStmt = $pdo->prepare('SELECT id, nom, quantite FROM produits WHERE id=? AND magasin_id=? FOR UPDATE');
                $productStmt->execute([$productId, $selectedMagasinId]);
            } elseif ($barcode !== '') {
                $productStmt = $pdo->prepare('SELECT id, nom, quantite FROM produits WHERE codebarre=? AND magasin_id=? LIMIT 1 FOR UPDATE');
                $productStmt->execute([$barcode, $selectedMagasinId]);
            } else {
                throw new RuntimeException('Saisissez un produit ou un code-barres.');
            }

            $product = $productStmt->fetch();
            if (!$product) {
                throw new RuntimeException('Produit introuvable dans ce magasin.');
            }

            $line = $pdo->prepare('SELECT id, stock_theorique FROM inventaire_lignes WHERE inventaire_id=? AND produit_id=? FOR UPDATE');
            $line->execute([$inventoryId, (int)$product['id']]);
            $existing = $line->fetch();
            $theoretical = $existing ? (int)$existing['stock_theorique'] : (int)$product['quantite'];
            $difference = (int)$counted - $theoretical;

            if ($existing) {
                $save = $pdo->prepare('UPDATE inventaire_lignes SET quantite_comptee=?, ecart=?, utilisateur_id=?, compte_le=NOW() WHERE id=?');
                $save->execute([(int)$counted, $difference, (int)$user['id'], (int)$existing['id']]);
            } else {
                $save = $pdo->prepare('INSERT INTO inventaire_lignes (inventaire_id, produit_id, stock_theorique, quantite_comptee, ecart, utilisateur_id) VALUES (?, ?, ?, ?, ?, ?)');
                $save->execute([$inventoryId, (int)$product['id'], $theoretical, (int)$counted, $difference, (int)$user['id']]);
            }

            $pdo->commit();
            flash('success', 'Comptage enregistré pour ' . $product['nom'] . '.');
        } elseif ($action === 'submit') {
            $inventoryId = (int)($_POST['inventaire_id'] ?? 0);
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT * FROM inventaires WHERE id=? AND magasin_id=? AND statut='comptage' FOR UPDATE");
            $stmt->execute([$inventoryId, $selectedMagasinId]);
            $inventory = $stmt->fetch();
            if (!$inventory) {
                throw new RuntimeException('Session introuvable ou déjà soumise.');
            }

            $count = $pdo->prepare('SELECT COUNT(*) FROM inventaire_lignes WHERE inventaire_id=?');
            $count->execute([$inventoryId]);
            if ((int)$count->fetchColumn() === 0) {
                throw new RuntimeException('Comptez au moins un produit avant de soumettre l’inventaire.');
            }

            $pdo->prepare("UPDATE inventaires SET statut='attente_validation', date_soumission=NOW() WHERE id=?")->execute([$inventoryId]);
            inventoryHistory($pdo, $user, $selectedMagasinId, 'INVENTAIRE_SOUMIS', 'Session #' . $inventoryId . ' soumise pour validation', 'info');
            $pdo->commit();
            flash('success', 'Inventaire soumis à la validation administrative.');
        } elseif ($action === 'validate' && $isAdmin) {
            $inventoryId = (int)($_POST['inventaire_id'] ?? 0);
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT * FROM inventaires WHERE id=? AND magasin_id=? AND statut='attente_validation' FOR UPDATE");
            $stmt->execute([$inventoryId, $selectedMagasinId]);
            $inventory = $stmt->fetch();
            if (!$inventory) {
                throw new RuntimeException('Inventaire introuvable ou déjà traité.');
            }

            $lines = $pdo->prepare('SELECT il.*, p.nom FROM inventaire_lignes il JOIN produits p ON p.id=il.produit_id AND p.magasin_id=? WHERE il.inventaire_id=? FOR UPDATE');
            $lines->execute([$selectedMagasinId, $inventoryId]);
            $update = $pdo->prepare('UPDATE produits SET quantite=? WHERE id=? AND magasin_id=?');
            $movement = $pdo->prepare("INSERT INTO stock_mouvements (produit_id, magasin_id, type, quantite, ancien_stock, nouveau_stock, motif, utilisateur_id, date_mouvement) VALUES (?, ?, 'inventaire_correctif', ?, ?, ?, ?, ?, NOW())");

            foreach ($lines->fetchAll() as $line) {
                $productStmt = $pdo->prepare('SELECT quantite FROM produits WHERE id=? AND magasin_id=? FOR UPDATE');
                $productStmt->execute([(int)$line['produit_id'], $selectedMagasinId]);
                $current = $productStmt->fetchColumn();
                if ($current === false) {
                    throw new RuntimeException('Un produit de l’inventaire n’existe plus dans ce magasin.');
                }
                $currentStock = (int)$current;
                $countedStock = (int)$line['quantite_comptee'];
                $difference = $countedStock - $currentStock;
                if ($difference !== 0) {
                    $update->execute([$countedStock, (int)$line['produit_id'], $selectedMagasinId]);
                    $movement->execute([(int)$line['produit_id'], $selectedMagasinId, abs($difference), $currentStock, $countedStock, 'Inventaire ' . $inventory['reference'], (int)$user['id']]);
                }
            }

            $pdo->prepare("UPDATE inventaires SET statut='validee', validateur_id=?, date_validation=NOW() WHERE id=?")->execute([(int)$user['id'], $inventoryId]);
            inventoryHistory($pdo, $user, $selectedMagasinId, 'INVENTAIRE_VALIDE', 'Session ' . $inventory['reference'] . ' validée', 'success');
            $pdo->commit();
            flash('success', 'Inventaire validé et corrections de stock enregistrées.');
        } elseif ($action === 'cancel' && $isAdmin) {
            $inventoryId = (int)($_POST['inventaire_id'] ?? 0);
            $stmt = $pdo->prepare("UPDATE inventaires SET statut='annulee' WHERE id=? AND magasin_id=? AND statut IN ('comptage','attente_validation')");
            $stmt->execute([$inventoryId, $selectedMagasinId]);
            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('Cet inventaire ne peut pas être annulé.');
            }
            inventoryHistory($pdo, $user, $selectedMagasinId, 'INVENTAIRE_ANNULE', 'Session #' . $inventoryId . ' annulée', 'warning');
            flash('success', 'Inventaire annulé.');
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', $exception->getMessage());
    }

    inventoryRedirect($selectedMagasinId);
}

$magasinStmt = $pdo->prepare('SELECT id, nom FROM magasins WHERE id=?');
$magasinStmt->execute([$selectedMagasinId]);
$magasin = $magasinStmt->fetch();

$inventoriesStmt = $pdo->prepare('SELECT i.*, u.nom AS createur, v.nom AS validateur, COUNT(il.id) AS lignes, COALESCE(SUM(ABS(il.ecart)), 0) AS total_ecart FROM inventaires i JOIN utilisateurs u ON u.id=i.utilisateur_id LEFT JOIN utilisateurs v ON v.id=i.validateur_id LEFT JOIN inventaire_lignes il ON il.inventaire_id=i.id WHERE i.magasin_id=? GROUP BY i.id ORDER BY i.id DESC LIMIT 50');
$inventoriesStmt->execute([$selectedMagasinId]);
$inventories = $inventoriesStmt->fetchAll();

$active = null;
foreach ($inventories as $inventory) {
    if (in_array($inventory['statut'], ['comptage', 'attente_validation'], true)) {
        $active = $inventory;
        break;
    }
}

$lines = [];
$products = [];
if ($active) {
    $linesStmt = $pdo->prepare('SELECT il.*, p.nom, p.codebarre FROM inventaire_lignes il JOIN produits p ON p.id=il.produit_id AND p.magasin_id=? WHERE il.inventaire_id=? ORDER BY il.id DESC');
    $linesStmt->execute([$selectedMagasinId, (int)$active['id']]);
    $lines = $linesStmt->fetchAll();

    $productsStmt = $pdo->prepare('SELECT id, nom, codebarre, quantite FROM produits WHERE magasin_id=? ORDER BY nom ASC');
    $productsStmt->execute([$selectedMagasinId]);
    $products = $productsStmt->fetchAll();
}

$magasins = [];
if ($isAdmin) {
    $magasins = $pdo->query("SELECT id, nom FROM magasins WHERE statut='actif' ORDER BY nom")->fetchAll();
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<main class="p-4 md:p-6">
    <?php if ($message = flash('success')): ?><div class="mb-5 rounded-xl bg-green-100 p-4 text-green-800"><?= e($message) ?></div><?php endif; ?>
    <?php if ($message = flash('error')): ?><div class="mb-5 rounded-xl bg-red-100 p-4 text-red-800"><?= e($message) ?></div><?php endif; ?>

    <div class="mb-6 flex flex-col justify-between gap-4 md:flex-row md:items-center">
        <div><h1 class="text-3xl font-bold text-slate-800">Inventaire</h1><p class="text-slate-500">Comptage et validation du stock par magasin.</p></div>
        <div class="rounded-xl bg-blue-100 px-4 py-3 font-semibold text-blue-800">Magasin : <?= e($magasin['nom'] ?? '') ?></div>
    </div>

    <?php if ($isAdmin && $magasins): ?>
        <form method="get" class="mb-6 rounded-xl border bg-white p-4 shadow-sm">
            <label class="mr-3 font-semibold">Magasin</label>
            <select name="magasin_id" onchange="this.form.submit()" class="rounded-lg border p-2">
                <?php foreach ($magasins as $store): ?><option value="<?= (int)$store['id'] ?>" <?= (int)$store['id'] === $selectedMagasinId ? 'selected' : '' ?>><?= e($store['nom']) ?></option><?php endforeach; ?>
            </select>
        </form>
    <?php endif; ?>

    <?php if (!$active): ?>
        <form method="post" class="mb-8 rounded-xl border bg-white p-5 shadow-sm">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="magasin_id" value="<?= $selectedMagasinId ?>">
            <label class="mb-2 block font-semibold">Note de la session</label>
            <textarea name="notes" rows="2" class="mb-4 w-full rounded-lg border p-3" placeholder="Ex. inventaire mensuel"></textarea>
            <button class="rounded-lg bg-blue-600 px-5 py-3 font-semibold text-white hover:bg-blue-700">Ouvrir une session d’inventaire</button>
        </form>
    <?php else: ?>
        <section class="mb-8 rounded-xl border bg-white p-5 shadow-sm">
            <div class="mb-5 flex flex-col justify-between gap-3 md:flex-row">
                <div><h2 class="text-xl font-bold">Session <?= e($active['reference']) ?></h2><p class="text-slate-500">Produits comptés : <?= (int)$active['lignes'] ?> · Écart total : <?= (int)$active['total_ecart'] ?></p></div>
                <span class="rounded-full bg-amber-100 px-3 py-2 text-sm font-semibold text-amber-800"><?= e($active['statut']) ?></span>
            </div>

            <?php if ($active['statut'] === 'comptage'): ?>
                <form method="post" class="grid gap-3 md:grid-cols-4">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="count">
                    <input type="hidden" name="inventaire_id" value="<?= (int)$active['id'] ?>">
                    <select name="produit_id" class="rounded-lg border p-3 md:col-span-2"><option value="">Choisir un produit</option><?php foreach ($products as $product): ?><option value="<?= (int)$product['id'] ?>"><?= e($product['nom']) ?> · <?= e($product['codebarre']) ?></option><?php endforeach; ?></select>
                    <input name="codebarre" class="rounded-lg border p-3" placeholder="ou code-barres">
                    <input name="quantite_comptee" type="number" min="0" required class="rounded-lg border p-3" placeholder="Quantité comptée">
                    <button class="rounded-lg bg-emerald-600 px-4 py-3 font-semibold text-white hover:bg-emerald-700 md:col-span-4">Enregistrer le comptage</button>
                </form>
                <form method="post" class="mt-5"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="submit"><input type="hidden" name="inventaire_id" value="<?= (int)$active['id'] ?>"><button class="rounded-lg bg-amber-600 px-5 py-3 font-semibold text-white hover:bg-amber-700">Soumettre pour validation</button></form>
            <?php elseif ($isAdmin): ?>
                <div class="mt-5 overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b text-slate-500"><th class="p-3">Produit</th><th class="p-3">Code-barres</th><th class="p-3">Théorique</th><th class="p-3">Compté</th><th class="p-3">Écart</th></tr></thead><tbody><?php foreach ($lines as $line): ?><tr class="border-b"><td class="p-3"><?= e($line['nom']) ?></td><td class="p-3"><?= e($line['codebarre']) ?></td><td class="p-3"><?= (int)$line['stock_theorique'] ?></td><td class="p-3 font-semibold"><?= (int)$line['quantite_comptee'] ?></td><td class="p-3 <?= (int)$line['ecart'] === 0 ? 'text-green-600' : 'text-red-600' ?>"><?= (int)$line['ecart'] ?></td></tr><?php endforeach; ?></tbody></table></div>
                <div class="flex flex-wrap gap-3"><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="validate"><input type="hidden" name="inventaire_id" value="<?= (int)$active['id'] ?>"><button class="rounded-lg bg-emerald-600 px-5 py-3 font-semibold text-white hover:bg-emerald-700">Valider et corriger le stock</button></form><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="inventaire_id" value="<?= (int)$active['id'] ?>"><button class="rounded-lg bg-red-600 px-5 py-3 font-semibold text-white hover:bg-red-700">Annuler</button></form></div>
            <?php else: ?><p class="text-amber-700">Cet inventaire attend la validation d’un administrateur.</p><?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="rounded-xl border bg-white p-5 shadow-sm"><h2 class="mb-4 text-xl font-bold">Historique des sessions</h2><div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b text-slate-500"><th class="p-3">Référence</th><th class="p-3">Statut</th><th class="p-3">Créateur</th><th class="p-3">Lignes</th><th class="p-3">Date</th></tr></thead><tbody><?php foreach ($inventories as $item): ?><tr class="border-b"><td class="p-3 font-semibold"><?= e($item['reference']) ?></td><td class="p-3"><?= e($item['statut']) ?></td><td class="p-3"><?= e($item['createur']) ?></td><td class="p-3"><?= (int)$item['lignes'] ?></td><td class="p-3"><?= e($item['date_ouverture']) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
