<?php
require_once 'config.php';

requireLogin();

/* =========================================================
   ACCESS
========================================================= */

$user = currentUser();

if (
    !in_array(
        $user['role'],
        ['admin','caissier']
    )
) {

    die("Accès refusé");
}

/* =========================================================
   MAGASIN USER
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
   SEARCH & FILTER
========================================================= */

$search =
    trim($_GET['search'] ?? '');

$type =
    trim($_GET['type'] ?? '');

$date1 =
    trim($_GET['date1'] ?? '');

$date2 =
    trim($_GET['date2'] ?? '');

/* =========================================================
   SQL
========================================================= */

$sql = "
SELECT

    sm.*,

    p.nom AS produit,

    p.codebarre,

    u.nom AS utilisateur,

    m.nom AS magasin

FROM stock_mouvements sm

LEFT JOIN produits p
ON p.id = sm.produit_id
AND p.magasin_id = sm.magasin_id

LEFT JOIN utilisateurs u
ON u.id = sm.utilisateur_id

LEFT JOIN magasins m
ON m.id = sm.magasin_id

WHERE sm.magasin_id=?
";

$params = [
    $magasin_id
];

/* =========================================================
   SEARCH
========================================================= */

if ($search !== '') {

    $sql .= "
    AND
    (
        p.nom LIKE ?
        OR
        p.codebarre LIKE ?
    )
    ";

    $params[] =
        "%$search%";

    $params[] =
        "%$search%";
}

/* =========================================================
   TYPE
========================================================= */

if ($type !== '') {

    $sql .= "
    AND sm.type=?
    ";

    $params[] =
        $type;
}

/* =========================================================
   DATE FILTER
========================================================= */

if ($date1 !== '') {

    $sql .= "
    AND DATE(sm.date_mouvement) >= ?
    ";

    $params[] =
        $date1;
}

if ($date2 !== '') {

    $sql .= "
    AND DATE(sm.date_mouvement) <= ?
    ";

    $params[] =
        $date2;
}

/* =========================================================
   ORDER
========================================================= */

$sql .= "
ORDER BY sm.id DESC
LIMIT 1000
";

$stmt = $pdo->prepare($sql);

$stmt->execute($params);

$mouvements =
    $stmt->fetchAll();

/* =========================================================
   RESUME DU STOCK PAR PRODUIT
========================================================= */

$stmtStockResume = $pdo->prepare("
SELECT
    p.id,
    p.nom,
    p.codebarre,
    p.quantite AS stock_restant,
    COALESCE((
        SELECT sm.ancien_stock
        FROM stock_mouvements sm
        WHERE sm.produit_id=p.id
        AND sm.magasin_id=p.magasin_id
        ORDER BY sm.id ASC
        LIMIT 1
    ), p.quantite) AS stock_debut,
    COALESCE((
        SELECT SUM(sm.quantite)
        FROM stock_mouvements sm
        WHERE sm.produit_id=p.id
        AND sm.magasin_id=p.magasin_id
        AND sm.type IN ('entree', 'entree_commande', 'transfert_entree', 'retour_client', 'ajout_stock')
    ), 0) AS total_entrees,
    COALESCE((
        SELECT SUM(sm.quantite)
        FROM stock_mouvements sm
        WHERE sm.produit_id=p.id
        AND sm.magasin_id=p.magasin_id
        AND sm.type IN ('sortie', 'sortie_vente', 'transfert_sortie', 'perte')
    ), 0) AS total_sorties
FROM produits p
WHERE p.magasin_id=?
ORDER BY p.nom ASC
");
$stmtStockResume->execute([$magasin_id]);
$stockResume = $stmtStockResume->fetchAll();

/* =========================================================
   KPI
========================================================= */

$stmtTotal = $pdo->prepare("
SELECT COUNT(*)
FROM stock_mouvements
WHERE magasin_id=?
");

$stmtTotal->execute([
    $magasin_id
]);

$totalMouvements =
    $stmtTotal->fetchColumn();

/* =========================================================
   ENTREES
========================================================= */

$stmtEntrees = $pdo->prepare("
SELECT SUM(quantite)
FROM stock_mouvements
WHERE magasin_id=?
AND
(
    type='entree'
    OR
    type='ajout_stock'
    OR
    type='transfert_entree'
)
");

$stmtEntrees->execute([
    $magasin_id
]);

$totalEntrees =
    $stmtEntrees->fetchColumn();

/* =========================================================
   SORTIES
========================================================= */

$stmtSorties = $pdo->prepare("
SELECT SUM(quantite)
FROM stock_mouvements
WHERE magasin_id=?
AND
(
    type='sortie'
    OR
    type='perte'
    OR
    type='transfert_sortie'
    OR
    type='sortie_vente'
)
");

$stmtSorties->execute([
    $magasin_id
]);

$totalSorties =
    $stmtSorties->fetchColumn();

/* =========================================================
   INVENTAIRES
========================================================= */

$stmtInv = $pdo->prepare("
SELECT COUNT(*)
FROM stock_mouvements
WHERE magasin_id=?
AND type='inventaire_correctif'
");

$stmtInv->execute([
    $magasin_id
]);

$totalInventaires =
    $stmtInv->fetchColumn();

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
   INCLUDE
========================================================= */

include 'includes/header.php';
include 'includes/sidebar.php';

?>

<div class="p-4 md:p-6">

<!-- =========================================================
     HEADER
========================================================= -->

<div class="flex flex-col md:flex-row justify-between gap-4 mb-6">

    <div>

        <h1 class="text-3xl font-black">

            📜 Historique Produits

        </h1>

        <p class="text-gray-500 mt-2">

            Historique complet des mouvements

        </p>

    </div>

    <div class="bg-blue-100 text-blue-700 px-5 py-3 rounded-2xl font-bold">

        🏬
        <?= e($magasin['nom'] ?? 'Magasin') ?>

    </div>

</div>

<!-- =========================================================
     KPI
========================================================= -->

<div class="grid md:grid-cols-4 gap-4 mb-6">

    <div class="bg-white rounded-2xl shadow border p-5">

        <p class="text-gray-500 text-sm">

            Total mouvements

        </p>

        <h2 class="text-3xl font-black">

            <?= $totalMouvements ?>

        </h2>

    </div>

    <div class="bg-white rounded-2xl shadow border p-5">

        <p class="text-gray-500 text-sm">

            Total Entrées

        </p>

        <h2 class="text-3xl font-black text-green-600">

            + <?= $totalEntrees ?: 0 ?>

        </h2>

    </div>

    <div class="bg-white rounded-2xl shadow border p-5">

        <p class="text-gray-500 text-sm">

            Total Sorties

        </p>

        <h2 class="text-3xl font-black text-red-600">

            - <?= $totalSorties ?: 0 ?>

        </h2>

    </div>

    <div class="bg-white rounded-2xl shadow border p-5">

        <p class="text-gray-500 text-sm">

            Inventaires

        </p>

        <h2 class="text-3xl font-black text-yellow-600">

            <?= $totalInventaires ?>

        </h2>

    </div>

</div>

<!-- =========================================================
     SEARCH
========================================================= -->

<div class="bg-white rounded-2xl shadow border p-5 mb-6">

<form
    method="GET"
    class="grid md:grid-cols-5 gap-4"
>

    <!-- SEARCH -->

    <input
        type="text"
        name="search"
        value="<?= e($search) ?>"
        placeholder="Produit ou code barre"
        class="border p-3 rounded-xl"
    >

    <!-- TYPE -->

    <select
        name="type"
        class="border p-3 rounded-xl"
    >

        <option value="">
            Tous types
        </option>

        <option
            value="entree"
            <?= $type=='entree' ? 'selected' : '' ?>
        >
            ➕ Entrée
        </option>

        <option
            value="sortie"
            <?= $type=='sortie' ? 'selected' : '' ?>
        >
            ➖ Sortie
        </option>

        <option
            value="perte"
            <?= $type=='perte' ? 'selected' : '' ?>
        >
            🔴 Perte
        </option>

        <option
            value="inventaire_correctif"
            <?= $type=='inventaire_correctif' ? 'selected' : '' ?>
        >
            🟡 Inventaire
        </option>

        <option
            value="transfert_entree"
            <?= $type=='transfert_entree' ? 'selected' : '' ?>
        >
            🔄 Entrée transfert
        </option>

        <option
            value="transfert_sortie"
            <?= $type=='transfert_sortie' ? 'selected' : '' ?>
        >
            🔄 Sortie transfert
        </option>

    </select>

    <!-- DATE 1 -->

    <input
        type="date"
        name="date1"
        value="<?= e($date1) ?>"
        class="border p-3 rounded-xl"
    >

    <!-- DATE 2 -->

    <input
        type="date"
        name="date2"
        value="<?= e($date2) ?>"
        class="border p-3 rounded-xl"
    >

    <!-- BTN -->

    <button
        class="bg-black text-white rounded-xl p-3"
    >

        🔍 Rechercher

    </button>

</form>

</div>

<div class="bg-white rounded-2xl shadow border overflow-x-auto mb-6">
    <div class="p-5 border-b">
        <h2 class="text-xl font-black">Stock par produit dans ce magasin</h2>
        <p class="text-sm text-gray-500 mt-1">Stock au début de l’historique, mouvements cumulés et stock restant actuel.</p>
    </div>
    <table class="min-w-full text-sm">
        <thead class="bg-gray-100">
            <tr>
                <th class="p-4 text-left">Produit</th>
                <th class="p-4 text-center">Stock au début</th>
                <th class="p-4 text-center">Entrées</th>
                <th class="p-4 text-center">Sorties</th>
                <th class="p-4 text-center">Stock restant</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($stockResume as $stock): ?>
            <tr class="border-t hover:bg-gray-50">
                <td class="p-4 font-semibold"><?= e($stock['nom']) ?><span class="block text-xs text-gray-500"><?= e($stock['codebarre'] ?? '') ?></span></td>
                <td class="p-4 text-center"><?= (int)$stock['stock_debut'] ?></td>
                <td class="p-4 text-center text-green-700 font-bold">+<?= (int)$stock['total_entrees'] ?></td>
                <td class="p-4 text-center text-red-700 font-bold">-<?= (int)$stock['total_sorties'] ?></td>
                <td class="p-4 text-center text-blue-700 font-black text-lg"><?= (int)$stock['stock_restant'] ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$stockResume): ?><tr><td colspan="5" class="p-8 text-center text-gray-500">Aucun produit dans ce magasin.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<!-- =========================================================
     TABLE
========================================================= -->

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

<?php

$badge = "
bg-gray-100
text-gray-700
";

$label = e($m['type']);

if ($m['type'] == 'entree') {

    $badge =
    "bg-green-100 text-green-700";

    $label =
    "➕ Entrée";
}

elseif ($m['type'] == 'entree_commande') {

    $badge = "bg-green-100 text-green-700";
    $label = "📥 Entrée commande";
}

elseif ($m['type'] == 'ajout_stock') {

    $badge = "bg-green-100 text-green-700";
    $label = "➕ Ajout stock";
}

elseif ($m['type'] == 'sortie') {

    $badge =
    "bg-red-100 text-red-700";

    $label =
    "➖ Sortie";
}

elseif ($m['type'] == 'sortie_vente') {

    $badge = "bg-red-100 text-red-700";
    $label = "🧾 Sortie vente";
}

elseif ($m['type'] == 'retour_client') {

    $badge = "bg-blue-100 text-blue-700";
    $label = "↩ Retour client";
}

elseif ($m['type'] == 'perte') {

    $badge =
    "bg-red-100 text-red-700";

    $label =
    "🔴 Perte";
}

elseif ($m['type'] == 'inventaire_correctif') {

    $badge =
    "bg-yellow-100 text-yellow-700";

    $label =
    "🟡 Inventaire";
}

elseif ($m['type'] == 'transfert_entree') {

    $badge =
    "bg-blue-100 text-blue-700";

    $label =
    "🔄 Entrée";
}

elseif ($m['type'] == 'transfert_sortie') {

    $badge =
    "bg-purple-100 text-purple-700";

    $label =
    "🔄 Sortie";
}

?>

<tr class="border-t hover:bg-gray-50">

    <!-- PRODUIT -->

    <td class="p-4">

        <div class="font-bold">

            <?= e($m['produit']) ?>

        </div>

        <div class="text-xs text-gray-500 mt-1">

            <?= e($m['codebarre']) ?>

        </div>

    </td>

    <!-- TYPE -->

    <td class="p-4 text-center">

        <span class="px-3 py-1 rounded-full text-xs font-bold <?= $badge ?>">

            <?= $label ?>

        </span>

    </td>

    <!-- QUANTITE -->

    <td class="p-4 text-center font-black">

        <?= $m['quantite'] ?>

    </td>

    <!-- ANCIEN -->

    <td class="p-4 text-center">

        <?= $m['ancien_stock'] ?>

    </td>

    <!-- NOUVEAU -->

    <td class="p-4 text-center font-black text-blue-600">

        <?= $m['nouveau_stock'] ?>

    </td>

    <!-- USER -->

    <td class="p-4 text-center">

        <?= e($m['utilisateur'] ?? 'N/A') ?>

    </td>

    <!-- MOTIF -->

    <td class="p-4">

        <?= e($m['motif'] ?? '-') ?>

    </td>

    <!-- DATE -->

    <td class="p-4 text-center text-xs text-gray-500">

        <?= date(
            'd/m/Y H:i',
            strtotime($m['date_mouvement'])
        ) ?>

    </td>

</tr>

<?php endforeach; ?>

<?php if(empty($mouvements)): ?>

<tr>

<td
    colspan="8"
    class="p-10 text-center text-gray-500"
>

    Aucun historique trouvé

</td>

</tr>

<?php endif; ?>

</tbody>

</table>

</div>

</div>

<?php include 'includes/footer.php'; ?>