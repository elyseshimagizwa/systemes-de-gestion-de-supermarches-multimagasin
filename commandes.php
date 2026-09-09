
<?php

require_once 'config.php';
require_once __DIR__ . '/includes/supplier-orders.php';

requireLogin();
requireCaissier();

/* =========================
   ACCES ADMIN + CAISSIER
========================= */
if (!in_array(currentUser()['role'], ['admin', 'caissier'])) {

    flash('error', 'Accès refusé');

    header('Location: dashboard.php');

    exit;
}

/* =========================
   USER
========================= */
$user = currentUser();

$isAdmin = ($user['role'] === 'admin');

/* =========================
   CREER COMMANDE
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['creer'])) {

    verify_csrf();

    $pdo->beginTransaction();

    try {

        $portalToken = bin2hex(random_bytes(32));

        $fournisseurCheck = $pdo->prepare("SELECT id FROM fournisseurs WHERE id=? AND magasin_id=? LIMIT 1");
        $fournisseurCheck->execute([(int)$_POST['fournisseur_id'], currentMagasinId()]);

        if (!$fournisseurCheck->fetch()) {
            throw new Exception('Fournisseur introuvable ou magasin non autorisé');
        }

        /* =========================
           INSERT COMMANDE
        ========================== */
        $stmt = $pdo->prepare("
            INSERT INTO commandes
            (
                fournisseur_id,
                magasin_id,
                utilisateur_id,
                statut,
                portail_token
            )
            VALUES
            (
                ?,
                ?,
                ?,
                'En attente',
                ?
            )
        ");

        $stmt->execute([
            (int)$_POST['fournisseur_id'],
            currentMagasinId(),
            $user['id']
            ,$portalToken
        ]);

        $commandeId = $pdo->lastInsertId();

        /* =========================
           LIGNES COMMANDE
        ========================== */
        foreach ($_POST['produit_id'] as $i => $pid) {

            $qte = (int)$_POST['quantite'][$i];

            if ($pid && $qte > 0) {

                $produitCheck = $pdo->prepare("SELECT id FROM produits WHERE id=? AND magasin_id=? LIMIT 1");
                $produitCheck->execute([(int)$pid, currentMagasinId()]);

                if (!$produitCheck->fetch()) {
                    throw new Exception('Produit introuvable ou magasin non autorisé');
                }

                $pa =
                    (float)$_POST['prix_achat'][$i];

                $l = $pdo->prepare("
                    INSERT INTO ligne_commandes
                    (
                        commande_id,
                        produit_id,
                        quantite,
                        prix_achat
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?
                    )
                ");

                $l->execute([

                    $commandeId,

                    $pid,

                    $qte,

                    $pa
                ]);
            }
        }

        /* =========================
           HISTORIQUE
        ========================== */
        $h = $pdo->prepare("
            INSERT INTO historiques
            (
                utilisateur_id,
                action,
                details,
                ip,
                niveau,
                created_at
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?,
                NOW()
            )
        ");

        $h->execute([

            $user['id'],

            'CREATION COMMANDE',

            'Commande fournisseur ID : '.$commandeId,

            $_SERVER['REMOTE_ADDR'],

            'success'
        ]);

        $pdo->commit();

        $supplierStmt = $pdo->prepare('SELECT f.*, m.nom AS magasin_nom FROM fournisseurs f JOIN magasins m ON m.id=f.magasin_id WHERE f.id=? AND f.magasin_id=? LIMIT 1');
        $supplierStmt->execute([(int)$_POST['fournisseur_id'], (int)currentMagasinId()]);
        $supplier = $supplierStmt->fetch();
        $lineStmt = $pdo->prepare('SELECT lc.quantite, lc.prix_achat, p.nom AS produit_nom FROM ligne_commandes lc JOIN produits p ON p.id=lc.produit_id WHERE lc.commande_id=? ORDER BY lc.id');
        $lineStmt->execute([(int)$commandeId]);
        $orderData = ['id' => $commandeId, 'magasin_nom' => $supplier['magasin_nom'], 'portal_token' => $portalToken];
        $orderLines = $lineStmt->fetchAll();
        $emailResult = sendSupplierOrderEmail($supplier, $orderData, $orderLines, getSettings());

        if ($emailResult['sent']) {
            $pdo->prepare('UPDATE commandes SET email_envoye=NOW(), email_erreur=NULL, email_tentatives=email_tentatives+1, prochaine_relance=NULL WHERE id=?')->execute([(int)$commandeId]);
            flash('success','Commande créée et envoyée au fournisseur par email');
        } else {
            $pdo->prepare('UPDATE commandes SET email_erreur=?, email_tentatives=email_tentatives+1, prochaine_relance=DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id=?')->execute([$emailResult['error'], (int)$commandeId]);
            flash('success','Commande créée, mais l’email fournisseur n’a pas été envoyé : '.$emailResult['error']);
        }

    } catch(Exception $e) {

        $pdo->rollBack();

        flash('error','Erreur création commande');
    }

    header('Location: commandes.php');

    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['renvoyer_email'])) {
    verify_csrf();
    $id = (int)$_POST['renvoyer_email'];
    $stmt = $pdo->prepare('SELECT c.*, f.nom AS fournisseur_nom, f.email, m.nom AS magasin_nom FROM commandes c JOIN fournisseurs f ON f.id=c.fournisseur_id JOIN magasins m ON m.id=c.magasin_id WHERE c.id=? AND c.magasin_id=?');
    $stmt->execute([$id, (int)currentMagasinId()]);
    $order = $stmt->fetch();
    if ($order) {
        $lines = $pdo->prepare('SELECT lc.quantite, lc.prix_achat, p.nom AS produit_nom FROM ligne_commandes lc JOIN produits p ON p.id=lc.produit_id WHERE lc.commande_id=? ORDER BY lc.id');
        $lines->execute([$id]);
        $result = sendSupplierOrderEmail($order, $order, $lines->fetchAll(), getSettings());
        if ($result['sent']) {
            $pdo->prepare('UPDATE commandes SET email_envoye=NOW(), email_erreur=NULL, email_tentatives=email_tentatives+1, prochaine_relance=NULL WHERE id=?')->execute([$id]);
            flash('success', 'Email renvoyé au fournisseur.');
        } else {
            $pdo->prepare('UPDATE commandes SET email_erreur=?, email_tentatives=email_tentatives+1, prochaine_relance=DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id=?')->execute([$result['error'], $id]);
            flash('error', 'Échec du renvoi : '.$result['error']);
        }
    }
    header('Location: commandes.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['expedier'])) {
    verify_csrf();
    $id = (int)$_POST['expedier'];
    $datePrevue = $_POST['date_livraison_prevue'] ?: null;
    $pdo->prepare("UPDATE commandes SET fournisseur_statut='Expédiée', date_expedition=NOW(), date_livraison_prevue=? WHERE id=? AND magasin_id=?")->execute([$datePrevue, $id, (int)currentMagasinId()]);
    $notify = $pdo->prepare('SELECT c.*, f.*, m.nom AS magasin_nom FROM commandes c JOIN fournisseurs f ON f.id=c.fournisseur_id JOIN magasins m ON m.id=c.magasin_id WHERE c.id=?');
    $notify->execute([$id]);
    $shipment = $notify->fetch();
    $shipmentLines = $pdo->prepare('SELECT lc.quantite, lc.prix_achat, p.nom AS produit_nom FROM ligne_commandes lc JOIN produits p ON p.id=lc.produit_id WHERE lc.commande_id=?');
    $shipmentLines->execute([$id]);
    sendSupplierOrderEmail($shipment, array_merge($shipment, ['email_subject' => 'Confirmation d’expédition fournisseur', 'email_message' => 'La commande a été marquée comme expédiée. Date de livraison prévue : ' . ($datePrevue ?: 'à confirmer')]), $shipmentLines->fetchAll(), getSettings());
    flash('success', 'Expédition enregistrée.');
    header('Location: commandes.php');
    exit;
}

/* =========================
   RECEPTIONNER COMMANDE
========================= */
if (isset($_GET['recevoir'])) {

    $id = (int)$_GET['recevoir'];

    $pdo->beginTransaction();

    try {

        $checkCommande = $pdo->prepare("SELECT id FROM commandes WHERE id=? AND magasin_id=? FOR UPDATE");
        $checkCommande->execute([$id, currentMagasinId()]);

        if (!$checkCommande->fetch()) {
            throw new Exception('Commande introuvable ou magasin non autorisé');
        }

        /* =========================
           LIGNES COMMANDE
        ========================== */
        $rows = $pdo->prepare("
            SELECT *
            FROM ligne_commandes
            WHERE commande_id=?
        ");

        $rows->execute([$id]);

        $items = $rows->fetchAll();

        foreach($items as $it){

            /* STOCK ACTUEL */
            $old = $pdo->prepare("
                SELECT quantite
                FROM produits
                WHERE id=?
                AND magasin_id=?
                FOR UPDATE
            ");

            $old->execute([
                $it['produit_id'],
                currentMagasinId()
            ]);

            $ancien =
                (int)$old->fetchColumn();

            $nouveau =
                $ancien + $it['quantite'];

            /* UPDATE STOCK */
            $u = $pdo->prepare("
                UPDATE produits
                SET quantite=?
                WHERE id=?
                AND magasin_id=?
            ");

            $u->execute([

                $nouveau,

                $it['produit_id'],
                currentMagasinId()
            ]);

            /* =========================
               MOUVEMENT STOCK
            ========================== */
            $m = $pdo->prepare("
                INSERT INTO stock_mouvements
                (
                    produit_id,
                    magasin_id,
                    type,
                    quantite,
                    ancien_stock,
                    nouveau_stock,
                    motif,
                    utilisateur_id
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?
                )
            ");

            $m->execute([

                $it['produit_id'],

                currentMagasinId(),

                'entree_commande',

                $it['quantite'],

                $ancien,

                $nouveau,

                'Réception commande',

                $user['id']
            ]);
        }

        /* =========================
           UPDATE COMMANDE
        ========================== */
        $pdo->prepare("
            UPDATE commandes
            SET statut='Reçue totalement', fournisseur_statut='Livrée', date_livraison=NOW()
            WHERE id=?
        ")->execute([$id]);

        /* =========================
           HISTORIQUE
        ========================== */
        $h = $pdo->prepare("
            INSERT INTO historiques
            (
                utilisateur_id,
                action,
                details,
                ip,
                niveau,
                created_at
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?,
                NOW()
            )
        ");

        $h->execute([

            $user['id'],

            'RECEPTION COMMANDE',

            'Commande réceptionnée ID : '.$id,

            $_SERVER['REMOTE_ADDR'],

            'info'
        ]);

        $pdo->commit();

        flash('success','Commande réceptionnée');

    } catch(Exception $e){

        $pdo->rollBack();

        flash('error','Erreur réception');
    }

    header('Location: commandes.php');

    exit;
}

/* =========================
   DATA
========================= */
$fournisseurs = $pdo->query("
    SELECT *
    FROM fournisseurs
    ORDER BY nom
")->fetchAll();

$produits = $pdo->query("
    SELECT *
    FROM produits
    ORDER BY nom
")->fetchAll();

$list = $pdo->query("
    SELECT
        c.*,
        f.nom fournisseur,
        f.email fournisseur_email

    FROM commandes c

    JOIN fournisseurs f
    ON f.id=c.fournisseur_id

    ORDER BY c.id DESC
")->fetchAll();

/* =========================
   INCLUDES
========================= */
include 'includes/header.php';

include 'includes/sidebar.php';
?>

<div class="p-4 md:p-6">

<!-- HEADER -->
<div class="flex justify-between items-center mb-6">

    <div>

        <h1 class="text-3xl font-bold">
            📦 Commandes Fournisseurs
        </h1>

        <p class="text-gray-500">
            Gestion des commandes fournisseurs
        </p>

    </div>

</div>

<!-- ALERT -->
<?php if($m=flash('success')): ?>

<div class="bg-green-100 text-green-700 p-3 rounded-xl mb-4">

    <?= e($m) ?>

</div>

<?php endif; ?>

<?php if($m=flash('error')): ?>

<div class="bg-red-100 text-red-700 p-3 rounded-xl mb-4">

    <?= e($m) ?>

</div>

<?php endif; ?>

<!-- FORM -->
<div class="bg-white dark:bg-slate-800 rounded-2xl shadow p-5 mb-6">

<form method="POST" class="space-y-5">

<input
    type="hidden"
    name="csrf_token"
    value="<?= csrf_token() ?>"
>

<input
    type="hidden"
    name="creer"
    value="1"
>

<!-- FOURNISSEUR -->
<div>

<label class="font-semibold block mb-2">
    Fournisseur
</label>

<select
    name="fournisseur_id"
    required
    class="border p-3 rounded-xl w-full"
>

<option value="">
    Choisir fournisseur
</option>

<?php foreach($fournisseurs as $f): ?>

<option value="<?= $f['id'] ?>">

    <?= e($f['nom']) ?>

</option>

<?php endforeach; ?>

</select>

</div>

<!-- PRODUITS -->
<div class="grid md:grid-cols-3 gap-4">

<?php for($i=0;$i<3;$i++): ?>

<div class="border rounded-2xl p-4 space-y-3">

    <h3 class="font-bold text-sm">
        Produit <?= $i+1 ?>
    </h3>

    <!-- PRODUIT -->
    <select
        name="produit_id[]"
        class="border p-3 rounded-xl w-full"
    >

    <option value="">
        Produit
    </option>

    <?php foreach($produits as $p): ?>

    <option value="<?= $p['id'] ?>">

        <?= e($p['nom']) ?>

    </option>

    <?php endforeach; ?>

    </select>

    <!-- QUANTITE -->
    <input
        type="number"
        name="quantite[]"
        placeholder="Quantité"
        class="border p-3 rounded-xl w-full"
    >

    <!-- PRIX -->
    <input
        type="number"
        step="0.01"
        name="prix_achat[]"
        placeholder="Prix achat"
        class="border p-3 rounded-xl w-full"
    >

</div>

<?php endfor; ?>

</div>

<!-- BTN -->
<button
    class="bg-blue-600 hover:bg-blue-700
           text-white rounded-xl px-4 py-3 w-full"
>

    ➕ Créer commande

</button>

</form>

</div>

<!-- TABLE -->
<div class="bg-white dark:bg-slate-800 rounded-2xl shadow overflow-x-auto">

<table class="min-w-full text-sm">

<thead class="bg-gray-100 dark:bg-slate-700">

<tr>

<th class="p-3 text-left">#</th>

<th class="p-3 text-left">Fournisseur</th>

<th class="p-3 text-left">Statut</th>

<th class="p-3 text-left">État fournisseur</th>

<th class="p-3 text-left">Email fournisseur</th>

<th class="p-3 text-left">Date</th>

<th class="p-3 text-left">Actions</th>

</tr>

</thead>

<tbody>

<?php foreach($list as $c): ?>

<tr class="border-t hover:bg-gray-50 dark:hover:bg-slate-700">

    <td class="p-3">

        <?= $c['id'] ?>

    </td>

    <td class="p-3">

        <?= e($c['fournisseur']) ?>

    </td>

    <td class="p-3">

        <?php if($c['statut']=='Reçue totalement'): ?>

            <span class="bg-green-100 text-green-700 px-3 py-1 rounded-full text-xs">

                ✅ Reçue

            </span>

        <?php else: ?>

            <span class="bg-yellow-100 text-yellow-700 px-3 py-1 rounded-full text-xs">

                ⏳ En attente

            </span>

        <?php endif; ?>

    </td>

    <td class="p-3">
        <?= e($c['fournisseur_statut'] ?? 'En attente') ?>
        <?php if(!empty($c['date_livraison_prevue'])): ?>
            <small class="block text-gray-500">Livraison prévue : <?= e($c['date_livraison_prevue']) ?></small>
        <?php endif; ?>
    </td>

    <td class="p-3">
        <?php if(!empty($c['email_envoye'])): ?>
            <span class="bg-green-100 text-green-700 px-3 py-1 rounded-full text-xs">✅ Envoyée</span>
            <small class="block text-gray-500"><?= e($c['email_envoye']) ?></small>
        <?php elseif(!empty($c['email_erreur'])): ?>
            <span class="bg-red-100 text-red-700 px-3 py-1 rounded-full text-xs">❌ Échec</span>
            <small class="block text-red-500"><?= e($c['email_erreur']) ?></small>
        <?php else: ?>
            <span class="text-gray-500">Non envoyée</span>
        <?php endif; ?>
    </td>

    <td class="p-3">

        <?= e($c['date_commande']) ?>

    </td>

    <td class="p-3">

        <?php if($c['statut'] !== 'Reçue totalement'): ?>

        <a
            href="?recevoir=<?= $c['id'] ?>"
            onclick="return confirm('Réceptionner cette commande ?')"
            class="bg-green-600 hover:bg-green-700
                   text-white px-4 py-2 rounded-xl text-sm"
        >

            ✅ Réceptionner

        </a>

        <?php else: ?>

        <span class="text-green-600 font-semibold">

            Terminée

        </span>

        <?php endif; ?>

        <?php if($c['statut'] !== 'Reçue totalement'): ?>
        <form method="post" class="inline-block mt-2">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <button name="renvoyer_email" value="<?= (int)$c['id'] ?>" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-xl text-sm">Renvoyer l’email</button>
        </form>
        <form method="post" class="inline-block mt-2">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="date" name="date_livraison_prevue" class="border rounded-lg p-2" required>
            <button name="expedier" value="<?= (int)$c['id'] ?>" class="bg-orange-600 hover:bg-orange-700 text-white px-4 py-2 rounded-xl text-sm">Marquer expédiée</button>
        </form>
        <?php endif; ?>

    </td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

</div>

<?php include 'includes/footer.php'; ?>
```
