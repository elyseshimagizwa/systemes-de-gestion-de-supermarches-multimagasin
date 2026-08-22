<?php
require_once 'config.php';

requireLogin();
requireRole('admin');

/* ======================================================
   SECURITE HEADERS
====================================================== */

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

/* ======================================================
   MULTI MAGASIN
====================================================== */

$user = currentUser();

$magasin_id = $user['magasin_id'] ?? 0;

if (!$magasin_id) {

    die("
    <div style='padding:30px;font-family:Arial'>
        ⛔ Aucun magasin assigné à cet utilisateur
    </div>
    ");
}

/* ======================================================
   HISTORIQUE
====================================================== */

function ajouterHistorique(
    $pdo,
    $action,
    $details,
    $niveau = 'info'
){

    $historique = $pdo->prepare("
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

    $historique->execute([

        currentUser()['id'] ?? null,

        currentUser()['magasin_id'] ?? null,

        $action,

        $details,

        $_SERVER['REMOTE_ADDR'] ?? 'IP inconnue',

        $niveau
    ]);
}

/* ======================================================
   VALIDATION
====================================================== */

function nettoyerNomCategorie($nom)
{
    $nom = trim($nom);

    $nom = strip_tags($nom);

    $nom = preg_replace('/\s+/', ' ', $nom);

    return $nom;
}

/* ======================================================
   AJOUT CATEGORIE
====================================================== */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['ajouter'])
) {

    verify_csrf();

    $nom = nettoyerNomCategorie($_POST['nom'] ?? '');

    /* VALIDATIONS */

    if ($nom === '') {

        flash('error', 'Nom obligatoire');

        header('Location: categories.php');

        exit;
    }

    if (mb_strlen($nom) < 2) {

        flash('error', 'Nom trop court');

        header('Location: categories.php');

        exit;
    }

    if (mb_strlen($nom) > 100) {

        flash('error', 'Nom trop long');

        header('Location: categories.php');

        exit;
    }

    /* VERIFIER DOUBLON */

    $check = $pdo->prepare("
        SELECT id
        FROM categories
        WHERE magasin_id=?
        AND nom=?
        LIMIT 1
    ");

    $check->execute([

        $magasin_id,

        $nom
    ]);

    if ($check->fetch()) {

        flash('error', 'Cette catégorie existe déjà');

        header('Location: categories.php');

        exit;
    }

    /* INSERT */

    $stmt = $pdo->prepare("
        INSERT INTO categories
        (
            magasin_id,
            nom,
            created_at
        )
        VALUES
        (
            ?,
            ?,
            NOW()
        )
    ");

    $stmt->execute([

        $magasin_id,

        $nom
    ]);

    /* HISTORIQUE */

    ajouterHistorique(

        $pdo,

        'AJOUT CATEGORIE',

        'Nouvelle catégorie : ' . $nom,

        'success'
    );

    flash('success', '✅ Catégorie ajoutée');

    header('Location: categories.php');

    exit;
}

/* ======================================================
   MODIFICATION
====================================================== */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['update'])
) {

    verify_csrf();

    $id = (int)($_POST['id'] ?? 0);

    $nom = nettoyerNomCategorie($_POST['nom'] ?? '');

    if ($id <= 0) {

        flash('error', 'ID invalide');

        header('Location: categories.php');

        exit;
    }

    if ($nom === '') {

        flash('error', 'Nom obligatoire');

        header('Location: categories.php');

        exit;
    }

    /* VERIFIER EXISTENCE */

    $check = $pdo->prepare("
        SELECT *
        FROM categories
        WHERE id=?
        AND magasin_id=?
    ");

    $check->execute([

        $id,

        $magasin_id
    ]);

    $categorie = $check->fetch();

    if (!$categorie) {

        ajouterHistorique(

            $pdo,

            'TENTATIVE MODIFICATION',

            'Tentative modification catégorie inexistante ID : ' . $id,

            'danger'
        );

        flash('error', 'Catégorie introuvable');

        header('Location: categories.php');

        exit;
    }

    /* UPDATE */

    $stmt = $pdo->prepare("
        UPDATE categories
        SET nom=?
        WHERE id=?
        AND magasin_id=?
    ");

    $stmt->execute([

        $nom,

        $id,

        $magasin_id
    ]);

    /* HISTORIQUE */

    ajouterHistorique(

        $pdo,

        'MODIFICATION CATEGORIE',

        'Catégorie modifiée : '
        . $categorie['nom']
        . ' → '
        . $nom,

        'warning'
    );

    flash('success', '✅ Catégorie modifiée');

    header('Location: categories.php');

    exit;
}

/* ======================================================
   SUPPRESSION SECURISEE (POST)
====================================================== */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['delete_id'])
) {

    verify_csrf();

    $id = (int)$_POST['delete_id'];

    if ($id <= 0) {

        flash('error', 'ID invalide');

        header('Location: categories.php');

        exit;
    }

    /* VERIFIER EXISTENCE */

    $stmt = $pdo->prepare("
        SELECT *
        FROM categories
        WHERE id=?
        AND magasin_id=?
    ");

    $stmt->execute([

        $id,

        $magasin_id
    ]);

    $categorie = $stmt->fetch();

    if (!$categorie) {

        ajouterHistorique(

            $pdo,

            'TENTATIVE SUPPRESSION',

            'Tentative suppression catégorie inexistante ID : ' . $id,

            'danger'
        );

        flash('error', 'Catégorie introuvable');

        header('Location: categories.php');

        exit;
    }

    /* VERIFIER PRODUITS */

    $checkProduits = $pdo->prepare("
        SELECT COUNT(*)
        FROM produits
        WHERE categorie_id=?
        AND magasin_id=?
    ");

    $checkProduits->execute([

        $id,

        $magasin_id
    ]);

    $nbProduits = $checkProduits->fetchColumn();

    if ($nbProduits > 0) {

        flash(
            'error',
            'Impossible de supprimer : catégorie utilisée par des produits'
        );

        header('Location: categories.php');

        exit;
    }

    /* DELETE */

    $delete = $pdo->prepare("
        DELETE FROM categories
        WHERE id=?
        AND magasin_id=?
    ");

    $delete->execute([

        $id,

        $magasin_id
    ]);

    /* HISTORIQUE */

    ajouterHistorique(

        $pdo,

        'SUPPRESSION CATEGORIE',

        'Catégorie supprimée : ' . $categorie['nom'],

        'danger'
    );

    flash('success', '🗑 Catégorie supprimée');

    header('Location: categories.php');

    exit;
}

/* ======================================================
   MODE EDIT
====================================================== */

$editCategory = null;

if (isset($_GET['edit'])) {

    $id = (int)$_GET['edit'];

    if ($id > 0) {

        $stmt = $pdo->prepare("
            SELECT *
            FROM categories
            WHERE id=?
            AND magasin_id=?
        ");

        $stmt->execute([

            $id,

            $magasin_id
        ]);

        $editCategory = $stmt->fetch();
    }
}

/* ======================================================
   RECHERCHE
====================================================== */

$search = trim($_GET['search'] ?? '');

$sql = "
SELECT *
FROM categories
WHERE magasin_id=?
";

$params = [$magasin_id];

if ($search !== '') {

    $sql .= "
    AND nom LIKE ?
    ";

    $params[] = "%$search%";
}

$sql .= "
ORDER BY nom ASC
";

$stmt = $pdo->prepare($sql);

$stmt->execute($params);

$list = $stmt->fetchAll();

/* ======================================================
   STATS
====================================================== */

$totalCategories = $pdo->prepare("
    SELECT COUNT(*)
    FROM categories
    WHERE magasin_id=?
");

$totalCategories->execute([$magasin_id]);

$totalCategories = $totalCategories->fetchColumn();

/* ======================================================
   MAGASIN
====================================================== */

$stmtMagasin = $pdo->prepare("
    SELECT *
    FROM magasins
    WHERE id=?
");

$stmtMagasin->execute([$magasin_id]);

$magasin = $stmtMagasin->fetch();

/* ======================================================
   TEMPLATE
====================================================== */

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="p-4 md:p-6">

<?php if($m = flash('success')): ?>

<div class="bg-green-100 text-green-700 p-4 rounded-2xl mb-4 shadow">

    <?= e($m) ?>

</div>

<?php endif; ?>

<?php if($m = flash('error')): ?>

<div class="bg-red-100 text-red-700 p-4 rounded-2xl mb-4 shadow">

    <?= e($m) ?>

</div>

<?php endif; ?>

<!-- HEADER -->

<div class="flex flex-col md:flex-row justify-between md:items-center gap-4 mb-6">

    <div>

        <h1 class="text-3xl font-bold text-gray-800">
            🗂 Gestion Catégories
        </h1>

        <p class="text-gray-500">
            Gestion sécurisée multi magasin
        </p>

    </div>

    <div class="flex gap-3">

        <button
        onclick="toggleForm()"
        class="bg-black text-white px-5 py-3 rounded-2xl shadow">

            ➕ Nouvelle Catégorie

        </button>

        <div class="bg-blue-100 text-blue-700 px-4 py-3 rounded-2xl font-bold">

            🏬
            <?= e($magasin['nom'] ?? 'Magasin') ?>

        </div>

    </div>

</div>

<!-- STATS -->

<div class="grid md:grid-cols-3 gap-4 mb-6">

    <div class="bg-white rounded-2xl shadow p-5 border">

        <p class="text-gray-500 text-sm">
            Total catégories
        </p>

        <h2 class="text-3xl font-bold">
            <?= $totalCategories ?>
        </h2>

    </div>

</div>

<!-- FORM -->

<div
id="categoryForm"
class="<?= $editCategory ? '' : 'hidden' ?> bg-white rounded-2xl shadow border p-6 mb-6">

<h2 class="text-2xl font-bold mb-5">

<?= $editCategory ? '✏ Modifier Catégorie' : '➕ Ajouter Catégorie' ?>

</h2>

<form method="POST" class="space-y-4">

    <input
    type="hidden"
    name="csrf_token"
    value="<?= csrf_token() ?>">

    <?php if($editCategory): ?>

    <input
    type="hidden"
    name="id"
    value="<?= $editCategory['id'] ?>">

    <?php endif; ?>

    <input
    type="text"
    name="nom"
    required
    maxlength="100"
    placeholder="Nom catégorie"
    value="<?= e($editCategory['nom'] ?? '') ?>"
    class="border p-3 rounded-xl w-full">

    <div class="flex gap-3">

        <?php if($editCategory): ?>

        <button
        type="submit"
        name="update"
        class="bg-blue-600 text-white px-6 py-3 rounded-xl">

            💾 Modifier

        </button>

        <a
        href="categories.php"
        class="bg-gray-200 px-6 py-3 rounded-xl">

            Annuler

        </a>

        <?php else: ?>

        <button
        type="submit"
        name="ajouter"
        class="bg-green-600 text-white px-6 py-3 rounded-xl">

            ✅ Ajouter

        </button>

        <?php endif; ?>

    </div>

</form>

</div>

<!-- SEARCH -->

<div class="bg-white rounded-2xl shadow border p-4 mb-6">

<form method="GET" class="flex gap-3">

    <input
    type="text"
    name="search"
    value="<?= e($search) ?>"
    placeholder="Recherche catégorie..."
    class="border p-3 rounded-xl w-full">

    <button class="bg-black text-white px-5 rounded-xl">

        🔍 Rechercher

    </button>

</form>

</div>

<!-- TABLE -->

<div class="bg-white rounded-2xl shadow overflow-x-auto border">

<table class="min-w-full text-sm">

<thead class="bg-gray-100">

<tr>

    <th class="p-4 text-left">
        ID
    </th>

    <th class="p-4 text-left">
        Nom
    </th>

    <th class="p-4 text-center">
        Actions
    </th>

</tr>

</thead>

<tbody>

<?php foreach($list as $row): ?>

<tr class="border-t hover:bg-gray-50">

    <td class="p-4">
        #<?= $row['id'] ?>
    </td>

    <td class="p-4 font-medium">
        <?= e($row['nom']) ?>
    </td>

    <td class="p-4">

        <div class="flex justify-center gap-2">

            <a
            href="?edit=<?= $row['id'] ?>"
            class="bg-blue-600 text-white px-4 py-2 rounded-xl text-sm">

                ✏ Modifier

            </a>

            <form
            method="POST"
            onsubmit="return confirm('Supprimer cette catégorie ?')">

                <input
                type="hidden"
                name="csrf_token"
                value="<?= csrf_token() ?>">

                <input
                type="hidden"
                name="delete_id"
                value="<?= $row['id'] ?>">

                <button
                type="submit"
                class="bg-red-600 text-white px-4 py-2 rounded-xl text-sm">

                    🗑 Supprimer

                </button>

            </form>

        </div>

    </td>

</tr>

<?php endforeach; ?>

<?php if(empty($list)): ?>

<tr>

    <td
    colspan="3"
    class="p-6 text-center text-gray-500">

        Aucune catégorie trouvée

    </td>

</tr>

<?php endif; ?>

</tbody>

</table>

</div>

</div>

<script>

function toggleForm(){

    const form =
        document.getElementById('categoryForm');

    form.classList.toggle('hidden');

    window.scrollTo({

        top: 0,

        behavior: 'smooth'
    });
}

</script>

<?php include 'includes/footer.php'; ?>