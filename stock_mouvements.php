<?php
require_once 'config.php';

requireLogin();

/* =========================================================
   ACCES
========================================================= */

$user = currentUser();

if (
    !in_array(
        $user['role'],
        ['admin', 'caissier']
    )
) {

    die("Accès refusé");
}

/* =========================================================
   MAGASIN UTILISATEUR
========================================================= */

$magasin_id =
    (int)($user['magasin_id'] ?? 0);

if ($magasin_id <= 0) {

    die("
        <div style='padding:30px;font-family:Arial'>
            ⛔ Aucun magasin assigné
        </div>
    ");
}

/* =========================================================
   HISTORIQUE
========================================================= */

function ajouterHistoriqueStock(
    $pdo,
    $user,
    $action,
    $details,
    $niveau = 'INFO'
){

    $stmt = $pdo->prepare("
        INSERT INTO historiques
        (
            utilisateur_id,
            magasin_id,
            action,
            details,
            ip,
            created_at,
            niveau
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            ?,
            NOW(),
            ?
        )
    ");

    $stmt->execute([

        $user['id'],

        $user['magasin_id'],

        $action,

        $details,

        $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN',

        $niveau
    ]);
}

/* =========================================================
   AJOUT MOUVEMENT
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['ajouter'])
) {

    verify_csrf();

    $produit_id =
        (int)$_POST['produit_id'];

    $type =
        trim($_POST['type']);

    $quantite =
        abs((int)$_POST['quantite']);

    $motif =
        trim($_POST['motif']);

    if ($quantite <= 0) {

        flash(
            'error',
            'Quantité invalide'
        );

        header(
            "Location: stock_mouvements.php"
        );

        exit;
    }

    $pdo->beginTransaction();

    try {

        /* =====================================================
           LOCK PRODUIT
        ===================================================== */

        $stmtProduit = $pdo->prepare("
            SELECT *
            FROM produits
            WHERE id=?
            AND magasin_id=?
            FOR UPDATE
        ");

        $stmtProduit->execute([

            $produit_id,

            $magasin_id
        ]);

        $produit =
            $stmtProduit->fetch();

        if (!$produit) {

            throw new Exception(
                "Produit introuvable"
            );
        }

        $ancien_stock =
            (int)$produit['quantite'];

        $nouveau_stock =
            $ancien_stock;

        /* =====================================================
           TYPES
        ===================================================== */

        switch ($type) {

            case 'perte':

                if ($ancien_stock < $quantite) {

                    throw new Exception(
                        "Stock insuffisant"
                    );
                }

                $nouveau_stock =
                    $ancien_stock - $quantite;

            break;

            case 'inventaire_correctif':

                $nouveau_stock =
                    $quantite;

                $quantite =
                    abs(
                        $nouveau_stock
                        -
                        $ancien_stock
                    );

            break;

            case 'ajout_stock':

                $nouveau_stock =
                    $ancien_stock + $quantite;

            break;

            default:

                throw new Exception(
                    "Type invalide"
                );
        }

        /* =====================================================
           UPDATE STOCK
        ===================================================== */

        $update = $pdo->prepare("
            UPDATE produits
            SET quantite=?
            WHERE id=?
            AND magasin_id=?
        ");

        $update->execute([

            $nouveau_stock,

            $produit_id,

            $magasin_id
        ]);

        /* =====================================================
           INSERT MOUVEMENT
        ===================================================== */

        $insert = $pdo->prepare("
            INSERT INTO stock_mouvements
            (
                magasin_id,
                produit_id,
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
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                NOW()
            )
        ");

        $insert->execute([

            $magasin_id,

            $produit_id,

            $type,

            $quantite,

            $ancien_stock,

            $nouveau_stock,

            $motif,

            $user['id']
        ]);

        /* =====================================================
           HISTORIQUE
        ===================================================== */

        ajouterHistoriqueStock(

            $pdo,

            $user,

            strtoupper($type),

            "Produit : "
            . $produit['nom']
            . " | Ancien stock : "
            . $ancien_stock
            . " | Nouveau stock : "
            . $nouveau_stock,

            'SUCCESS'
        );

        $pdo->commit();

        flash(
            'success',
            '✅ Mouvement enregistré'
        );

    } catch (Exception $e) {

        $pdo->rollBack();

        ajouterHistoriqueStock(

            $pdo,

            $user,

            'ERREUR_MOUVEMENT',

            $e->getMessage(),

            'DANGER'
        );

        flash(
            'error',
            $e->getMessage()
        );
    }

    header(
        "Location: stock_mouvements.php"
    );

    exit;
}

/* =========================================================
   FILTRES
========================================================= */

$typeFilter =
    trim($_GET['type'] ?? '');

$search =
    trim($_GET['search'] ?? '');

/* =========================================================
   SQL
========================================================= */

$sql = "
SELECT

    sm.*,

    p.nom AS produit,

    u.nom AS utilisateur

FROM stock_mouvements sm

INNER JOIN produits p
ON p.id = sm.produit_id

INNER JOIN utilisateurs u
ON u.id = sm.utilisateur_id

WHERE sm.magasin_id=?
";

$params = [$magasin_id];

/* =========================================================
   FILTRE TYPE
========================================================= */

if ($typeFilter !== '') {

    $sql .= "
    AND sm.type=?
    ";

    $params[] =
        $typeFilter;
}

/* =========================================================
   RECHERCHE
========================================================= */

if ($search !== '') {

    $sql .= "
    AND p.nom LIKE ?
    ";

    $params[] =
        "%$search%";
}

/* =========================================================
   ORDER
========================================================= */

$sql .= "
ORDER BY sm.id DESC
LIMIT 500
";

$stmt = $pdo->prepare($sql);

$stmt->execute($params);

$mouvements =
    $stmt->fetchAll();

/* =========================================================
   PRODUITS DU MAGASIN
========================================================= */

$stmtProduits = $pdo->prepare("
    SELECT
        id,
        nom,
        quantite
    FROM produits
    WHERE magasin_id=?
    ORDER BY nom ASC
");

$stmtProduits->execute([
    $magasin_id
]);

$produits =
    $stmtProduits->fetchAll();

/* =========================================================
   STATS
========================================================= */

$totalMouvements = $pdo->prepare("
    SELECT COUNT(*)
    FROM stock_mouvements
    WHERE magasin_id=?
");

$totalMouvements->execute([
    $magasin_id
]);

$totalMouvements =
    $totalMouvements->fetchColumn();

$pertes = $pdo->prepare("
    SELECT COUNT(*)
    FROM stock_mouvements
    WHERE magasin_id=?
    AND type='perte'
");

$pertes->execute([
    $magasin_id
]);

$pertes =
    $pertes->fetchColumn();

$inventaires = $pdo->prepare("
    SELECT COUNT(*)
    FROM stock_mouvements
    WHERE magasin_id=?
    AND type='inventaire_correctif'
");

$inventaires->execute([
    $magasin_id
]);

$inventaires =
    $inventaires->fetchColumn();

/* =========================================================
   MAGASIN
========================================================= */

$stmtMagasin = $pdo->prepare("
    SELECT *
    FROM magasins
    WHERE id=?
");

$stmtMagasin->execute([
    $magasin_id
]);

$magasin =
    $stmtMagasin->fetch();

/* =========================================================
   INCLUDES
========================================================= */

include 'includes/header.php';
include 'includes/sidebar.php';

?>

<div class="p-4 md:p-6">

<!-- ALERTES -->

<?php if($m = flash('success')): ?>

<div class="bg-green-100 text-green-700 p-4 rounded-2xl mb-5">

    <?= e($m) ?>

</div>

<?php endif; ?>

<?php if($m = flash('error')): ?>

<div class="bg-red-100 text-red-700 p-4 rounded-2xl mb-5">

    <?= e($m) ?>

</div>

<?php endif; ?>

<!-- HEADER -->

<div class="flex flex-col md:flex-row justify-between gap-4 mb-6">

    <div>

        <h1 class="text-3xl font-bold text-gray-800">

            📦 Mouvements du Stock

        </h1>

        <p class="text-gray-500">

            Gestion intelligente des stocks

        </p>

    </div>

    <div class="bg-blue-100 text-blue-700 px-5 py-3 rounded-2xl font-bold">

        🏬 <?= e($magasin['nom']) ?>

    </div>

</div>

<!-- STATS -->

<div class="grid md:grid-cols-3 gap-4 mb-6">

    <div class="bg-white p-5 rounded-2xl shadow border">

        <p class="text-gray-500 text-sm">
            Total mouvements
        </p>

        <h2 class="text-3xl font-bold">
            <?= $totalMouvements ?>
        </h2>

    </div>

    <div class="bg-white p-5 rounded-2xl shadow border">

        <p class="text-gray-500 text-sm">
            Pertes
        </p>

        <h2 class="text-3xl font-bold text-red-600">
            <?= $pertes ?>
        </h2>

    </div>

    <div class="bg-white p-5 rounded-2xl shadow border">

        <p class="text-gray-500 text-sm">
            Inventaires
        </p>

        <h2 class="text-3xl font-bold text-yellow-600">
            <?= $inventaires ?>
        </h2>

    </div>

</div>

<!-- BUTTON -->

<div class="mb-6">

    <button
        onclick="toggleForm()"
        class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-3 rounded-2xl"
    >
        ➕ Ajouter mouvement
    </button>

</div>

<!-- FORM -->

<div
    id="formBox"
    class="hidden bg-white rounded-2xl shadow border p-5 mb-6"
>

<h2 class="text-xl font-bold mb-5">

    ➕ Nouveau mouvement

</h2>

<form
    method="POST"
    class="grid md:grid-cols-2 lg:grid-cols-5 gap-4"
>

    <input
        type="hidden"
        name="csrf_token"
        value="<?= csrf_token() ?>"
    >

    <input
        type="hidden"
        name="ajouter"
        value="1"
    >

    <!-- PRODUIT -->

    <select
        name="produit_id"
        class="border p-3 rounded-xl"
        required
    >

        <option value="">
            Produit
        </option>

        <?php foreach($produits as $p): ?>

        <option value="<?= $p['id'] ?>">

            <?= e($p['nom']) ?>
            (Stock: <?= $p['quantite'] ?>)

        </option>

        <?php endforeach; ?>

    </select>

    <!-- TYPE -->

    <select
        name="type"
        class="border p-3 rounded-xl"
        required
    >

        <option value="perte">
            🔴 Perte
        </option>

        <option value="inventaire_correctif">
            🟡 Inventaire
        </option>

        <option value="ajout_stock">
            🟢 Ajout stock
        </option>

    </select>

    <!-- QUANTITE -->

    <input
        type="number"
        name="quantite"
        min="1"
        required
        placeholder="Quantité"
        class="border p-3 rounded-xl"
    >

    <!-- MOTIF -->

    <input
        type="text"
        name="motif"
        placeholder="Motif"
        class="border p-3 rounded-xl"
    >

    <!-- BTN -->

    <button
        class="bg-green-600 hover:bg-green-700 text-white rounded-xl p-3"
    >

        Enregistrer

    </button>

</form>

</div>

<!-- FILTRES -->

<div class="bg-white rounded-2xl shadow border p-5 mb-6">

<form method="GET" class="grid md:grid-cols-3 gap-4">

    <input
        name="search"
        value="<?= e($search) ?>"
        placeholder="🔎 Recherche produit"
        class="border p-3 rounded-xl"
    >

    <select
        name="type"
        class="border p-3 rounded-xl"
    >

        <option value="">
            Tous types
        </option>

        <option
            value="perte"
            <?= $typeFilter=='perte' ? 'selected' : '' ?>
        >
            🔴 Perte
        </option>

        <option
            value="inventaire_correctif"
            <?= $typeFilter=='inventaire_correctif' ? 'selected' : '' ?>
        >
            🟡 Inventaire
        </option>

        <option
            value="ajout_stock"
            <?= $typeFilter=='ajout_stock' ? 'selected' : '' ?>
        >
            🟢 Ajout stock
        </option>

    </select>

    <button
        class="bg-black text-white rounded-xl p-3"
    >

        Filtrer

    </button>

</form>

</div>

<!-- TABLE -->

<div class="bg-white rounded-2xl shadow border overflow-x-auto">

<table class="min-w-full text-sm">

<thead class="bg-gray-100">

<tr>

    <th class="p-4 text-left">
        Produit
    </th>

    <th class="p-4 text-center">
        Type
    </th>

    <th class="p-4 text-center">
        Quantité
    </th>

    <th class="p-4 text-center">
        Ancien
    </th>

    <th class="p-4 text-center">
        Nouveau
    </th>

    <th class="p-4 text-center">
        Utilisateur
    </th>

    <th class="p-4 text-left">
        Motif
    </th>

    <th class="p-4 text-center">
        Date
    </th>

</tr>

</thead>

<tbody>

<?php foreach($mouvements as $m): ?>

<tr class="border-t hover:bg-gray-50">

    <td class="p-4 font-semibold">

        <?= e($m['produit']) ?>

    </td>

    <td class="p-4 text-center">

        <?php if($m['type']=='perte'): ?>

        <span class="bg-red-100 text-red-700 px-3 py-1 rounded-full text-xs">

            🔴 Perte

        </span>

        <?php elseif($m['type']=='inventaire_correctif'): ?>

        <span class="bg-yellow-100 text-yellow-700 px-3 py-1 rounded-full text-xs">

            🟡 Inventaire

        </span>

        <?php else: ?>

        <span class="bg-green-100 text-green-700 px-3 py-1 rounded-full text-xs">

            🟢 Ajout

        </span>

        <?php endif; ?>

    </td>

    <td class="p-4 text-center font-bold">

        <?= $m['quantite'] ?>

    </td>

    <td class="p-4 text-center">

        <?= $m['ancien_stock'] ?>

    </td>

    <td class="p-4 text-center">

        <?= $m['nouveau_stock'] ?>

    </td>

    <td class="p-4 text-center">

        <?= e($m['utilisateur']) ?>

    </td>

    <td class="p-4">

        <?= e($m['motif']) ?>

    </td>

    <td class="p-4 text-center text-xs text-gray-500">

        <?= date(
            'd/m/Y H:i',
            strtotime($m['date_mouvement'])
        ) ?>

    </td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

</div>

<script>

function toggleForm(){

    let form =
        document.getElementById(
            'formBox'
        );

    form.classList.toggle(
        'hidden'
    );
}

</script>

<?php include 'includes/footer.php'; ?>