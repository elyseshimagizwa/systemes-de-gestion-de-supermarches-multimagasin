<?php
require_once 'config.php';

/* =========================================================
   ACCESS
========================================================= */

requireLogin();
requireAdmin();

$user = currentUser();

if(
    !in_array(
        $user['role'],
        ['admin','caissier']
    )
){
    die("Accès refusé");
}

/* =========================================================
   HELPERS
========================================================= */

function ipUser(){

    return $_SERVER['REMOTE_ADDR']
        ?? 'UNKNOWN';
}

function historique(
    $pdo,
    $utilisateur_id,
    $action,
    $details,
    $niveau='INFO',
    $magasin_id=null
){

    $stmt = $pdo->prepare("
        INSERT INTO historiques
        (
            utilisateur_id,
            action,
            details,
            ip,
            created_at,
            niveau,
            magasin_id
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            NOW(),
            ?,
            ?
        )
    ");

    $stmt->execute([

        $utilisateur_id,
        $action,
        $details,
        ipUser(),
        $niveau,
        $magasin_id
    ]);
}

/* =========================================================
   CREATION TRANSFERT
========================================================= */

if(
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['transferer'])
){

    verify_csrf();

    $source_id =
        (int)$_POST['magasin_source_id'];

    $destination_id =
        (int)$_POST['magasin_destination_id'];

    $motif =
        trim($_POST['motif']);

    $note =
        trim($_POST['note']);

    $produits =
        $_POST['produit_id'] ?? [];

    $quantites =
        $_POST['quantite'] ?? [];

    if(
        $source_id <= 0
        ||
        $destination_id <= 0
    ){

        flash(
            'error',
            'Magasins invalides'
        );

        header("Location: transferts-stock.php");
        exit;
    }

    if($source_id == $destination_id){

        flash(
            'error',
            'Les magasins doivent être différents'
        );

        header("Location: transferts-stock.php");
        exit;
    }

    if (!canAccessMagasin($source_id) || !canAccessMagasin($destination_id)) {
        flash('error', 'Magasin non autorisé');
        header("Location: transferts-stock.php");
        exit;
    }

    if(empty($produits)){

        flash(
            'error',
            'Ajoutez au moins un produit'
        );

        header("Location: transferts-stock.php");
        exit;
    }

    $pdo->beginTransaction();

    try{

        /* =================================================
           REFERENCE
        ================================================= */

        $reference =
            'TRF-'
            .date('YmdHis')
            .'-'
            .rand(1000,9999);

        /* =================================================
           INSERT TRANSFERT
        ================================================= */

        $stmtTransfer =
            $pdo->prepare("
                INSERT INTO transferts_stock
                (
                    reference,
                    magasin_source_id,
                    magasin_destination_id,
                    utilisateur_id,
                    date_transfert,
                    statut,
                    note,
                    motif,
                    created_at
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    NOW(),
                    'en_attente',
                    ?,
                    ?,
                    NOW()
                )
            ");

        $stmtTransfer->execute([

            $reference,
            $source_id,
            $destination_id,
            $user['id'],
            $note,
            $motif
        ]);

        $transfert_id =
            $pdo->lastInsertId();

        /* =================================================
           PRODUITS
        ================================================= */

        foreach($produits as $index => $produit_id){

            $produit_id =
                (int)$produit_id;

            $quantite =
                (int)$quantites[$index];

            if(
                $produit_id <= 0
                ||
                $quantite <= 0
            ){
                continue;
            }

            /* =============================================
               PRODUIT SOURCE
            ============================================= */

            $stmtProduit =
                $pdo->prepare("
                    SELECT *
                    FROM produits
                    WHERE id=?
                    AND magasin_id=?
                    FOR UPDATE
                ");

            $stmtProduit->execute([
                $produit_id,
                $source_id
            ]);

            $produitSource =
                $stmtProduit->fetch();

            if(!$produitSource){

                throw new Exception(
                    "Produit introuvable"
                );
            }

            $stockSourceAvant =
                (int)$produitSource['quantite'];

            if(
                $stockSourceAvant < $quantite
            ){

                throw new Exception(
                    "Stock insuffisant : "
                    .$produitSource['nom']
                );
            }

            $stockSourceApres =
                $stockSourceAvant - $quantite;

            /* =============================================
               UPDATE SOURCE
            ============================================= */

            $pdo->prepare("
                UPDATE produits
                SET quantite=?
                WHERE id=?
            ")->execute([

                $stockSourceApres,
                $produit_id
            ]);

            /* =============================================
               DESTINATION
            ============================================= */

            $stmtDestination =
                $pdo->prepare("
                    SELECT *
                    FROM produits
                    WHERE nom=?
                    AND magasin_id=?
                    LIMIT 1
                    FOR UPDATE
                ");

            $stmtDestination->execute([

                $produitSource['nom'],
                $destination_id
            ]);

            $produitDestination =
                $stmtDestination->fetch();

            /* =============================================
               CREATION AUTO
            ============================================= */

            if(!$produitDestination){

                $insertProduit =
                    $pdo->prepare("
                        INSERT INTO produits
                        (
                            magasin_id,
                            nom,
                            codebarre,
                            prix_achat,
                            prix_vente,
                            quantite,
                            seuil_alerte,
                            date_peremption,
                            fournisseur_id,
                            categorie_id,
                            created_at
                        )
                        VALUES
                        (
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            0,
                            ?,
                            ?,
                            ?,
                            ?,
                            NOW()
                        )
                    ");

                $insertProduit->execute([

                    $destination_id,
                    $produitSource['nom'],
                    $produitSource['codebarre'],
                    $produitSource['prix_achat'],
                    $produitSource['prix_vente'],
                    $produitSource['seuil_alerte'],
                    $produitSource['date_peremption'],
                    $produitSource['fournisseur_id'],
                    $produitSource['categorie_id']
                ]);

                $produit_destination_id =
                    $pdo->lastInsertId();

                $stockDestinationAvant = 0;

            }else{

                $produit_destination_id =
                    $produitDestination['id'];

                $stockDestinationAvant =
                    (int)$produitDestination['quantite'];
            }

            $stockDestinationApres =
                $stockDestinationAvant + $quantite;

            /* =============================================
               UPDATE DESTINATION
            ============================================= */

            $pdo->prepare("
                UPDATE produits
                SET quantite=?
                WHERE id=?
            ")->execute([

                $stockDestinationApres,
                $produit_destination_id
            ]);

            /* =============================================
               ITEMS
            ============================================= */

            $pdo->prepare("
                INSERT INTO transfert_stock_items
                (
                    transfert_id,
                    produit_id,
                    produit_nom,
                    quantite,
                    produit_source_id,
                    produit_destination_id,
                    stock_source_avant,
                    stock_source_apres,
                    stock_destination_avant,
                    stock_destination_apres
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
                    ?,
                    ?,
                    ?
                )
            ")->execute([

                $transfert_id,
                $produit_id,
                $produitSource['nom'],
                $quantite,
                $produit_id,
                $produit_destination_id,
                $stockSourceAvant,
                $stockSourceApres,
                $stockDestinationAvant,
                $stockDestinationApres
            ]);

            /* =============================================
               MOUVEMENT SOURCE
            ============================================= */

            $pdo->prepare("
                INSERT INTO stock_mouvements
                (
                    produit_id,
                    magasin_id,
                    type,
                    quantite,
                    ancien_stock,
                    nouveau_stock,
                    motif,
                    utilisateur_id,
                    date_mouvement
                )
                VALUES
                (
                    ?,
                    ?,
                    'transfert_sortie',
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    NOW()
                )
            ")->execute([

                $produit_id,
                $source_id,
                $quantite,
                $stockSourceAvant,
                $stockSourceApres,
                $motif,
                $user['id']
            ]);

            /* =============================================
               MOUVEMENT DESTINATION
            ============================================= */

            $pdo->prepare("
                INSERT INTO stock_mouvements
                (
                    produit_id,
                    magasin_id,
                    type,
                    quantite,
                    ancien_stock,
                    nouveau_stock,
                    motif,
                    utilisateur_id,
                    date_mouvement
                )
                VALUES
                (
                    ?,
                    ?,
                    'transfert_entree',
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    NOW()
                )
            ")->execute([

                $produit_destination_id,
                $destination_id,
                $quantite,
                $stockDestinationAvant,
                $stockDestinationApres,
                $motif,
                $user['id']
            ]);
        }

        /* =================================================
           HISTORIQUE
        ================================================= */

        historique(

            $pdo,
            $user['id'],
            'TRANSFERT_STOCK',
            'Transfert '.$reference,
            'SUCCESS',
            $source_id
        );

        $pdo->commit();

        flash(
            'success',
            '✅ Transfert effectué avec succès'
        );

    }catch(Exception $e){

        $pdo->rollBack();

        historique(

            $pdo,
            $user['id'],
            'ECHEC_TRANSFERT',
            $e->getMessage(),
            'DANGER',
            $source_id
        );

        flash(
            'error',
            $e->getMessage()
        );
    }

    header("Location: transferts-stock.php");
    exit;
}

/* =========================================================
   VALIDATION RECEPTION
========================================================= */

if(
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['valider_reception'])
){

    verify_csrf();

    $transfert_id =
        (int)$_POST['transfert_id'];

    $commentaire =
        trim($_POST['commentaire_reception']);

    $pdo->beginTransaction();

    try{

        $stmt =
            $pdo->prepare("
                SELECT *
                FROM transferts_stock
                WHERE id=?
                FOR UPDATE
            ");

        $stmt->execute([
            $transfert_id
        ]);

        $transfert =
            $stmt->fetch();

        if(!$transfert){

            throw new Exception(
                "Transfert introuvable"
            );
        }

        if (!canAccessMagasin($transfert['magasin_destination_id'])) {
            throw new Exception('Magasin destination non autorisé');
        }

        if($transfert['statut'] == 'recu'){

            throw new Exception(
                "Transfert déjà réceptionné"
            );
        }

        $pdo->prepare("
            UPDATE transferts_stock
            SET
                statut='recu',
                reception_par=?,
                date_reception=NOW(),
                commentaire_reception=?
            WHERE id=?
        ")->execute([

            $user['id'],
            $commentaire,
            $transfert_id
        ]);

        historique(

            $pdo,
            $user['id'],
            'RECEPTION_TRANSFERT',
            'Réception du transfert '.$transfert['reference'],
            'SUCCESS',
            $transfert['magasin_destination_id']
        );

        $pdo->commit();

        flash(
            'success',
            '✅ Réception validée'
        );

    }catch(Exception $e){

        $pdo->rollBack();

        flash(
            'error',
            $e->getMessage()
        );
    }

    header("Location: transferts-stock.php");
    exit;
}

/* =========================================================
   DATA
========================================================= */

$produits =
    $pdo->query("
        SELECT
            id,
            nom,
            magasin_id,
            quantite
        FROM produits
        ORDER BY nom ASC
    ")->fetchAll();

$magasins =
    $pdo->query("
        SELECT *
        FROM magasins
        WHERE statut='actif'
        ORDER BY nom ASC
    ")->fetchAll();

$transferts =
    $pdo->query("
        SELECT

            ts.*,

            ms.nom AS magasin_source,

            md.nom AS magasin_destination,

            u.nom AS expediteur,

            r.nom AS receptionnaire

        FROM transferts_stock ts

        LEFT JOIN magasins ms
        ON ms.id = ts.magasin_source_id

        LEFT JOIN magasins md
        ON md.id = ts.magasin_destination_id

        LEFT JOIN utilisateurs u
        ON u.id = ts.utilisateur_id

        LEFT JOIN utilisateurs r
        ON r.id = ts.reception_par

        ORDER BY ts.id DESC
    ")->fetchAll();

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="p-4 md:p-6 max-w-7xl mx-auto">

<!-- HEADER -->
<div class="flex items-center justify-between mb-8">

    <div>

        <h1 class="text-3xl font-black">
            🔄 Gestion Transferts Stock
        </h1>

        <p class="text-gray-500 mt-2">
            Multi magasins professionnel
        </p>

    </div>

</div>

<!-- ALERT -->
<?php if($msg = flash('success')): ?>

<div class="bg-green-100 text-green-700 p-4 rounded-2xl mb-6">
    <?= e($msg) ?>
</div>

<?php endif; ?>

<?php if($msg = flash('error')): ?>

<div class="bg-red-100 text-red-700 p-4 rounded-2xl mb-6">
    <?= e($msg) ?>
</div>

<?php endif; ?>

<!-- FORM -->
<div class="bg-white dark:bg-slate-900 rounded-3xl shadow-2xl overflow-hidden mb-8">

<div class="bg-gradient-to-r from-indigo-600 to-blue-700 p-6 text-white">

    <h2 class="text-2xl font-black">
        🚚 Nouveau transfert
    </h2>

</div>

<form method="POST" class="p-6">

<input
    type="hidden"
    name="csrf_token"
    value="<?= csrf_token() ?>"
>

<input
    type="hidden"
    name="transferer"
    value="1"
>

<div class="grid md:grid-cols-2 gap-5 mb-6">

    <div>

        <label class="font-bold block mb-2">
            🏬 Magasin source
        </label>

        <select
            name="magasin_source_id"
            required
            class="w-full border p-4 rounded-2xl dark:bg-slate-800"
        >

            <option value="">
                Sélectionner
            </option>

            <?php foreach($magasins as $m): ?>

            <option value="<?= $m['id'] ?>">
                <?= e($m['nom']) ?>
            </option>

            <?php endforeach; ?>

        </select>

    </div>

    <div>

        <label class="font-bold block mb-2">
            🏪 Magasin destination
        </label>

        <select
            name="magasin_destination_id"
            required
            class="w-full border p-4 rounded-2xl dark:bg-slate-800"
        >

            <option value="">
                Sélectionner
            </option>

            <?php foreach($magasins as $m): ?>

            <option value="<?= $m['id'] ?>">
                <?= e($m['nom']) ?>
            </option>

            <?php endforeach; ?>

        </select>

    </div>

</div>

<!-- PRODUITS -->
<div id="produits-container">

<div class="grid md:grid-cols-3 gap-5 produit-item mb-4">

    <div>

        <label class="font-bold block mb-2">
            📦 Produit
        </label>

        <select
            name="produit_id[]"
            required
            class="w-full border p-4 rounded-2xl dark:bg-slate-800"
        >

            <option value="">
                Sélectionner
            </option>

            <?php foreach($produits as $p): ?>

            <option value="<?= $p['id'] ?>">

                <?= e($p['nom']) ?>
                (<?= $p['quantite'] ?>)

            </option>

            <?php endforeach; ?>

        </select>

    </div>

    <div>

        <label class="font-bold block mb-2">
            🔢 Quantité
        </label>

        <input
            type="number"
            name="quantite[]"
            min="1"
            required
            class="w-full border p-4 rounded-2xl dark:bg-slate-800"
        >

    </div>

    <div class="flex items-end">

        <button
            type="button"
            onclick="this.closest('.produit-item').remove()"
            class="bg-red-600 hover:bg-red-700 text-white px-5 py-4 rounded-2xl font-bold w-full"
        >
            ❌ Supprimer
        </button>

    </div>

</div>

</div>

<button
    type="button"
    onclick="ajouterProduit()"
    class="bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded-2xl font-bold mb-6"
>
    ➕ Ajouter produit
</button>

<div class="grid md:grid-cols-2 gap-5">

    <div>

        <label class="font-bold block mb-2">
            📝 Motif
        </label>

        <textarea
            name="motif"
            rows="4"
            class="w-full border p-4 rounded-2xl dark:bg-slate-800"
        ></textarea>

    </div>

    <div>

        <label class="font-bold block mb-2">
            📄 Note
        </label>

        <textarea
            name="note"
            rows="4"
            class="w-full border p-4 rounded-2xl dark:bg-slate-800"
        ></textarea>

    </div>

</div>

<div class="mt-6">

    <button
        class="bg-indigo-600 hover:bg-indigo-700 text-white px-8 py-4 rounded-2xl font-bold"
    >
        🔄 Effectuer transfert
    </button>

</div>

</form>

</div>

<!-- HISTORIQUE -->
<div class="bg-white dark:bg-slate-900 rounded-3xl shadow-2xl overflow-hidden">

<div class="bg-gradient-to-r from-slate-800 to-slate-900 p-6 text-white">

    <h2 class="text-2xl font-black">
        📜 Historique des transferts
    </h2>

</div>

<div class="overflow-x-auto">

<table class="w-full">

<thead class="bg-slate-100 dark:bg-slate-800">

<tr>

    <th class="p-4 text-left">
        Référence
    </th>

    <th class="p-4 text-left">
        Source
    </th>

    <th class="p-4 text-left">
        Destination
    </th>

    <th class="p-4 text-left">
        Expéditeur
    </th>

    <th class="p-4 text-left">
        Réceptionnaire
    </th>

    <th class="p-4 text-left">
        Statut
    </th>

    <th class="p-4 text-left">
        Date
    </th>

    <th class="p-4 text-left">
        Actions
    </th>

</tr>

</thead>

<tbody>

<?php foreach($transferts as $t): ?>

<tr class="border-t dark:border-slate-800">

    <td class="p-4 font-bold">
        <?= e($t['reference']) ?>
    </td>

    <td class="p-4">
        <?= e($t['magasin_source']) ?>
    </td>

    <td class="p-4">
        <?= e($t['magasin_destination']) ?>
    </td>

    <td class="p-4">
        <?= e($t['expediteur']) ?>
    </td>

    <td class="p-4">
        <?= e($t['receptionnaire'] ?? '-') ?>
    </td>

    <td class="p-4">

        <?php if($t['statut'] == 'recu'): ?>

        <span class="bg-green-100 text-green-700 px-3 py-1 rounded-full text-sm font-bold">
            ✅ Reçu
        </span>

        <?php else: ?>

        <span class="bg-yellow-100 text-yellow-700 px-3 py-1 rounded-full text-sm font-bold">
            ⏳ En attente
        </span>

        <?php endif; ?>

    </td>

    <td class="p-4 text-sm">

        <?= date(
            'd/m/Y H:i',
            strtotime($t['date_transfert'])
        ) ?>

    </td>

    <td class="p-4">

        <div class="flex flex-wrap gap-2">

            <?php if($t['statut'] != 'recu'): ?>

            <form method="POST">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= csrf_token() ?>"
                >

                <input
                    type="hidden"
                    name="valider_reception"
                    value="1"
                >

                <input
                    type="hidden"
                    name="transfert_id"
                    value="<?= $t['id'] ?>"
                >

                <input
                    type="text"
                    name="commentaire_reception"
                    placeholder="Commentaire"
                    class="border px-3 py-2 rounded-xl mb-2 w-full"
                >

                <button
                    class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-xl"
                >
                    ✅ Réception
                </button>

            </form>

            <?php endif; ?>

            <button
                onclick="window.print()"
                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-xl"
            >
                🖨️ Imprimer
            </button>

        </div>

    </td>

</tr>

<!-- DETAILS PRODUITS -->
<tr class="bg-slate-50 dark:bg-slate-800/30">

<td colspan="8" class="p-4">

<?php

$items =
    $pdo->prepare("
        SELECT *
        FROM transfert_stock_items
        WHERE transfert_id=?
    ");

$items->execute([
    $t['id']
]);

$items =
    $items->fetchAll();

?>

<div class="overflow-x-auto">

<table class="w-full text-sm">

<thead>

<tr class="bg-slate-200 dark:bg-slate-700">

    <th class="p-2 text-left">
        Produit
    </th>

    <th class="p-2 text-left">
        Qté
    </th>

    <th class="p-2 text-left">
        Stock Source
    </th>

    <th class="p-2 text-left">
        Stock Destination
    </th>

</tr>

</thead>

<tbody>

<?php foreach($items as $i): ?>

<tr class="border-t">

    <td class="p-2">
        <?= e($i['produit_nom']) ?>
    </td>

    <td class="p-2 font-bold">
        <?= $i['quantite'] ?>
    </td>

    <td class="p-2">

        <?= $i['stock_source_avant'] ?>
        →
        <?= $i['stock_source_apres'] ?>

    </td>

    <td class="p-2">

        <?= $i['stock_destination_avant'] ?>
        →
        <?= $i['stock_destination_apres'] ?>

    </td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

</div>

</div>

<script>

function ajouterProduit(){

    let html = `
    <div class="grid md:grid-cols-3 gap-5 produit-item mb-4">

        <div>

            <select
                name="produit_id[]"
                required
                class="w-full border p-4 rounded-2xl dark:bg-slate-800"
            >

                <option value="">
                    Sélectionner
                </option>

                <?php foreach($produits as $p): ?>

                <option value="<?= $p['id'] ?>">

                    <?= e($p['nom']) ?>
                    (<?= $p['quantite'] ?>)

                </option>

                <?php endforeach; ?>

            </select>

        </div>

        <div>

            <input
                type="number"
                name="quantite[]"
                min="1"
                required
                placeholder="Quantité"
                class="w-full border p-4 rounded-2xl dark:bg-slate-800"
            >

        </div>

        <div>

            <button
                type="button"
                onclick="this.closest('.produit-item').remove()"
                class="bg-red-600 hover:bg-red-700 text-white px-5 py-4 rounded-2xl font-bold w-full"
            >
                ❌ Supprimer
            </button>

        </div>

    </div>
    `;

    document
        .getElementById(
            'produits-container'
        )
        .insertAdjacentHTML(
            'beforeend',
            html
        );
}

</script>

<?php include 'includes/footer.php'; ?>