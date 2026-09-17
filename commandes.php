
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
   ENREGISTRER UNE RECEPTION
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enregistrer_reception'])) {
    verify_csrf();
    $id = (int)$_POST['commande_id'];
    $pdo->beginTransaction();

    try {
        $commandeStmt = $pdo->prepare('SELECT id FROM commandes WHERE id=? AND magasin_id=? FOR UPDATE');
        $commandeStmt->execute([$id, (int)currentMagasinId()]);
        if (!$commandeStmt->fetch()) {
            throw new Exception('Commande introuvable ou magasin non autorisé');
        }
        $linesStmt = $pdo->prepare('SELECT lc.*, COALESCE((SELECT SUM(lr.quantite_recue + lr.quantite_endommagee + lr.quantite_manquante) FROM lignes_receptions_fournisseurs lr JOIN receptions_fournisseurs r ON r.id=lr.reception_id WHERE lr.ligne_commande_id=lc.id AND r.commande_id=?), 0) AS deja_traite FROM ligne_commandes lc WHERE lc.commande_id=? FOR UPDATE');
        $linesStmt->execute([$id, $id]);
        $lines = $linesStmt->fetchAll();
        if (!$lines) {
            throw new Exception('Cette commande ne contient aucune ligne');
        }

        $numeroBon = 'BR-' . date('YmdHis') . '-' . random_int(100, 999);
        $receptionStmt = $pdo->prepare('INSERT INTO receptions_fournisseurs (commande_id, magasin_id, utilisateur_id, numero_bon, commentaire) VALUES (?, ?, ?, ?, ?)');
        $receptionStmt->execute([$id, (int)currentMagasinId(), (int)$user['id'], $numeroBon, trim($_POST['commentaire'] ?? '') ?: null]);
        $receptionId = (int)$pdo->lastInsertId();
        $lineReception = $pdo->prepare('INSERT INTO lignes_receptions_fournisseurs (reception_id, ligne_commande_id, quantite_recue, quantite_endommagee, quantite_manquante, prix_prevu, prix_recu) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $totalTraite = 0;
        $totalCommande = 0;
        $totalReception = 0;

        foreach ($lines as $line) {
            $lineId = (int)$line['id'];
            $restant = max(0, (float)$line['quantite'] - (float)$line['deja_traite']);
            $recu = max(0, (float)($_POST['recu'][$lineId] ?? 0));
            $endommage = max(0, (float)($_POST['endommage'][$lineId] ?? 0));
            $manquant = max(0, (float)($_POST['manquant'][$lineId] ?? 0));
            $prixRecu = max(0, (float)($_POST['prix_recu'][$lineId] ?? $line['prix_achat']));
            if ($recu + $endommage + $manquant > $restant) {
                throw new Exception('Les quantités dépassent le restant de la commande');
            }
            $lineReception->execute([$receptionId, $lineId, $recu, $endommage, $manquant, $line['prix_achat'], $prixRecu]);
            $totalReception += $recu + $endommage + $manquant;
            $totalTraite += (float)$line['deja_traite'] + $recu + $endommage + $manquant;
            $totalCommande += (float)$line['quantite'];

            if ($recu > 0) {
                $stockStmt = $pdo->prepare('SELECT quantite FROM produits WHERE id=? AND magasin_id=? FOR UPDATE');
                $stockStmt->execute([(int)$line['produit_id'], (int)currentMagasinId()]);
                $ancien = (int)$stockStmt->fetchColumn();
                $nouveau = $ancien + $recu;
                $pdo->prepare('UPDATE produits SET quantite=? WHERE id=? AND magasin_id=?')->execute([$nouveau, (int)$line['produit_id'], (int)currentMagasinId()]);
                $pdo->prepare('INSERT INTO lots_produits (produit_id, magasin_id, numero_lot, quantite_initiale, quantite_restante, prix_achat, date_expiration) SELECT id, ?, ?, ?, ?, ?, date_peremption FROM produits WHERE id=? AND magasin_id=?')->execute([(int)currentMagasinId(), $numeroBon . '-' . $line['produit_id'], $recu, $recu, $prixRecu, (int)$line['produit_id'], (int)currentMagasinId()]);
                $pdo->prepare('INSERT INTO stock_mouvements (produit_id, magasin_id, type, quantite, ancien_stock, nouveau_stock, motif, utilisateur_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([(int)$line['produit_id'], (int)currentMagasinId(), 'entree_commande', $recu, $ancien, $nouveau, 'Réception ' . $numeroBon, (int)$user['id']]);
            }
        }

        if ($totalReception <= 0) {
            throw new Exception('Saisissez au moins une quantité reçue, manquante ou endommagée');
        }

        $statut = ($totalTraite >= $totalCommande) ? 'Reçue totalement' : 'Reçue partiellement';
        $fournisseurStatut = ($statut === 'Reçue totalement') ? 'Livrée' : 'Expédiée';
        $pdo->prepare('UPDATE commandes SET statut=?, fournisseur_statut=?, date_livraison=IF(?="Reçue totalement", NOW(), date_livraison) WHERE id=?')->execute([$statut, $fournisseurStatut, $statut, $id]);
        $pdo->prepare('INSERT INTO historiques (utilisateur_id, magasin_id, action, details, ip, niveau, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())')->execute([(int)$user['id'], (int)currentMagasinId(), 'RECEPTION COMMANDE', 'Bon ' . $numeroBon . ' pour la commande #' . $id, $_SERVER['REMOTE_ADDR'], 'info']);
        $pdo->commit();
        header('Location: bon_reception.php?id=' . $receptionId);
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', 'Erreur réception : ' . $e->getMessage());
        header('Location: commandes.php?recevoir=' . $id);
        exit;
    }
}

/* Ancien flux GET conservé désactivé pour empêcher une modification par lien. */
if (isset($_GET['recevoir']) && false) {

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

            $lot = $pdo->prepare('INSERT INTO lots_produits (produit_id, magasin_id, numero_lot, quantite_initiale, quantite_restante, prix_achat, date_expiration) SELECT id, ?, ?, ?, ?, ?, date_peremption FROM produits WHERE id=? AND magasin_id=?');
            $lot->execute([
                currentMagasinId(),
                'CMD-' . $id . '-' . $it['produit_id'],
                (float)$it['quantite'],
                (float)$it['quantite'],
                (float)$it['prix_achat'],
                (int)$it['produit_id'],
                currentMagasinId(),
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

$receptionCommande = null;
$receptionLines = [];
if (isset($_GET['recevoir'])) {
    $receptionCommandeStmt = $pdo->prepare('SELECT c.id, c.statut, f.nom AS fournisseur FROM commandes c JOIN fournisseurs f ON f.id=c.fournisseur_id WHERE c.id=? AND c.magasin_id=?');
    $receptionCommandeStmt->execute([(int)$_GET['recevoir'], (int)currentMagasinId()]);
    $receptionCommande = $receptionCommandeStmt->fetch();
    if ($receptionCommande) {
        $receptionLinesStmt = $pdo->prepare('SELECT lc.*, p.nom AS produit_nom, COALESCE((SELECT SUM(lr.quantite_recue + lr.quantite_endommagee + lr.quantite_manquante) FROM lignes_receptions_fournisseurs lr JOIN receptions_fournisseurs r ON r.id=lr.reception_id WHERE lr.ligne_commande_id=lc.id), 0) AS deja_traite FROM ligne_commandes lc JOIN produits p ON p.id=lc.produit_id WHERE lc.commande_id=? ORDER BY lc.id');
        $receptionLinesStmt->execute([(int)$receptionCommande['id']]);
        $receptionLines = $receptionLinesStmt->fetchAll();
    }
}

/* =========================
   INCLUDES
========================= */
include 'includes/header.php';

include 'includes/sidebar.php';
?>

<div class="p-4 md:p-6">

<?php if ($receptionCommande && $receptionLines): ?>
<div class="bg-white dark:bg-slate-800 rounded-2xl shadow p-5 mb-6">
    <h2 class="text-xl font-bold mb-1">Réception de la commande #<?= (int)$receptionCommande['id'] ?></h2>
    <p class="text-gray-500 mb-4">Fournisseur : <?= e($receptionCommande['fournisseur']) ?>. Les quantités endommagées ou manquantes ne sont pas ajoutées au stock.</p>
    <form method="post" class="space-y-4">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="enregistrer_reception" value="1">
        <input type="hidden" name="commande_id" value="<?= (int)$receptionCommande['id'] ?>">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead><tr class="border-b"><th class="p-2 text-left">Produit</th><th class="p-2">Commandé</th><th class="p-2">Restant</th><th class="p-2">Reçu</th><th class="p-2">Endommagé</th><th class="p-2">Manquant</th><th class="p-2">Prix prévu</th><th class="p-2">Prix reçu</th></tr></thead>
                <tbody>
                <?php foreach ($receptionLines as $line): $remaining = max(0, (float)$line['quantite'] - (float)$line['deja_traite']); ?>
                    <tr class="border-b">
                        <td class="p-2"><?= e($line['produit_nom']) ?></td>
                        <td class="p-2 text-center"><?= e($line['quantite']) ?></td>
                        <td class="p-2 text-center font-semibold"><?= e($remaining) ?></td>
                        <td class="p-2"><input class="border rounded p-2 w-24" type="number" min="0" max="<?= e($remaining) ?>" step="0.001" name="recu[<?= (int)$line['id'] ?>]" value="0"></td>
                        <td class="p-2"><input class="border rounded p-2 w-24" type="number" min="0" max="<?= e($remaining) ?>" step="0.001" name="endommage[<?= (int)$line['id'] ?>]" value="0"></td>
                        <td class="p-2"><input class="border rounded p-2 w-24" type="number" min="0" max="<?= e($remaining) ?>" step="0.001" name="manquant[<?= (int)$line['id'] ?>]" value="0"></td>
                        <td class="p-2 text-center"><?= e(number_format((float)$line['prix_achat'], 2, ',', ' ')) ?></td>
                        <td class="p-2"><input class="border rounded p-2 w-28" type="number" min="0" step="0.01" name="prix_recu[<?= (int)$line['id'] ?>]" value="<?= e($line['prix_achat']) ?>"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <textarea name="commentaire" class="border rounded-xl p-3 w-full" rows="2" placeholder="Commentaire de réception (facultatif)"></textarea>
        <div class="flex gap-3"><button class="bg-green-600 hover:bg-green-700 text-white rounded-xl px-4 py-3" type="submit">Enregistrer la réception</button><a class="border rounded-xl px-4 py-3" href="commandes.php">Annuler</a></div>
    </form>
</div>
<?php elseif (isset($_GET['recevoir'])): ?>
<div class="bg-red-100 text-red-700 p-3 rounded-xl mb-4">Commande introuvable ou inaccessible.</div>
<?php endif; ?>

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

        <?php elseif($c['statut']=='Reçue partiellement'): ?>
            <span class="bg-orange-100 text-orange-700 px-3 py-1 rounded-full text-xs">↗ Reçue partiellement</span>
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
            class="bg-green-600 hover:bg-green-700
                   text-white px-4 py-2 rounded-xl text-sm"
        >

            ✅ Réceptionner / réception partielle

        </a>

        <?php else: ?>

        <span class="text-green-600 font-semibold">

            Terminée · <a class="underline" href="bon_reception.php?commande_id=<?= (int)$c['id'] ?>">Voir les bons</a>

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
