<?php
require_once 'config.php';

requireLogin();
requireRole('admin');

/* =========================================================
   SECURITE
========================================================= */

if (!defined('APP_SECURE')) {
    define('APP_SECURE', true);
}

/* =========================================================
   VALIDATION
========================================================= */

if (!function_exists('validateEmail')) {

    function validateEmail($email)
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL);
    }
}

/* =========================================================
   HISTORIQUE
========================================================= */

if (!function_exists('historique')) {

    function historique(
        $pdo,
        $userId,
        $action,
        $details,
        $niveau = 'INFO'
    ){

        $ip =
            $_SERVER['REMOTE_ADDR']
            ?? 'UNKNOWN';

        $stmt =
            $pdo->prepare("
                INSERT INTO historiques
                (
                    utilisateur_id,
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
                    NOW(),
                    ?
                )
            ");

        $stmt->execute([

            $userId,
            $action,
            $details,
            $ip,
            $niveau
        ]);
    }
}

/* =========================================================
   AJOUT FOURNISSEUR
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['ajouter'])
) {

    verify_csrf();

    try {

        $nom =
            trim($_POST['nom']);

        $contact =
            trim($_POST['contact']);

        $telephone =
            trim($_POST['telephone']);

        $email =
            trim($_POST['email']);

        $adresse =
            trim($_POST['adresse']);

        /* =====================================
           VALIDATIONS
        ===================================== */

        if (empty($nom)) {

            throw new Exception(
                "Le nom du fournisseur est obligatoire"
            );
        }

        if (
            !empty($email)
            &&
            !validateEmail($email)
        ) {

            throw new Exception(
                "Adresse email invalide"
            );
        }

        /* =====================================
           CHECK DUPLICATE
        ===================================== */

        $check =
            $pdo->prepare("
                SELECT id
                FROM fournisseurs
                WHERE nom=?
                LIMIT 1
            ");

        $check->execute([$nom]);

        if ($check->fetch()) {

            throw new Exception(
                "Ce fournisseur existe déjà"
            );
        }

        /* =====================================
           INSERT
        ===================================== */

        $stmt =
            $pdo->prepare("
                INSERT INTO fournisseurs
                (
                    nom,
                    contact,
                    telephone,
                    email,
                    adresse,
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

        $stmt->execute([

            $nom,
            $contact,
            $telephone,
            $email,
            $adresse
        ]);

        /* =====================================
           HISTORIQUE
        ===================================== */

        historique(

            $pdo,

            currentUser()['id'],

            'AJOUT_FOURNISSEUR',

            'Nouveau fournisseur : '
            . $nom,

            'SUCCESS'
        );

        flash(
            'success',
            '✅ Fournisseur ajouté avec succès'
        );

    } catch (Exception $e) {

        flash(
            'error',
            $e->getMessage()
        );
    }

    header('Location: fournisseurs.php');
    exit;
}

/* =========================================================
   MODIFICATION
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['update'])
) {

    verify_csrf();

    try {

        $id =
            (int)$_POST['id'];

        $nom =
            trim($_POST['nom']);

        $contact =
            trim($_POST['contact']);

        $telephone =
            trim($_POST['telephone']);

        $email =
            trim($_POST['email']);

        $adresse =
            trim($_POST['adresse']);

        if (empty($nom)) {

            throw new Exception(
                "Le nom est obligatoire"
            );
        }

        if (
            !empty($email)
            &&
            !validateEmail($email)
        ) {

            throw new Exception(
                "Email invalide"
            );
        }

        /* =====================================
           CHECK EXIST
        ===================================== */

        $check =
            $pdo->prepare("
                SELECT id
                FROM fournisseurs
                WHERE nom=?
                AND id != ?
                LIMIT 1
            ");

        $check->execute([
            $nom,
            $id
        ]);

        if ($check->fetch()) {

            throw new Exception(
                "Ce fournisseur existe déjà"
            );
        }

        /* =====================================
           UPDATE
        ===================================== */

        $stmt =
            $pdo->prepare("
                UPDATE fournisseurs
                SET
                    nom=?,
                    contact=?,
                    telephone=?,
                    email=?,
                    adresse=?
                WHERE id=?
            ");

        $stmt->execute([

            $nom,
            $contact,
            $telephone,
            $email,
            $adresse,
            $id
        ]);

        /* =====================================
           HISTORIQUE
        ===================================== */

        historique(

            $pdo,

            currentUser()['id'],

            'MODIFICATION_FOURNISSEUR',

            'Fournisseur modifié : '
            . $nom,

            'INFO'
        );

        flash(
            'success',
            '✅ Fournisseur modifié'
        );

    } catch (Exception $e) {

        flash(
            'error',
            $e->getMessage()
        );
    }

    header('Location: fournisseurs.php');
    exit;
}

/* =========================================================
   SUPPRESSION
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['delete_id'])
) {

    verify_csrf();

    try {

        $id =
            (int)$_POST['delete_id'];

        /* =====================================
           RECUP
        ===================================== */

        $stmtF =
            $pdo->prepare("
                SELECT *
                FROM fournisseurs
                WHERE id=?
            ");

        $stmtF->execute([$id]);

        $f =
            $stmtF->fetch();

        if (!$f) {

            throw new Exception(
                "Fournisseur introuvable"
            );
        }

        /* =====================================
           CHECK PRODUITS
        ===================================== */

        $checkProduit =
            $pdo->prepare("
                SELECT COUNT(*)
                FROM produits
                WHERE fournisseur_id=?
            ");

        $checkProduit->execute([$id]);

        $totalProduits =
            $checkProduit->fetchColumn();

        if ($totalProduits > 0) {

            throw new Exception(
                "Impossible de supprimer ce fournisseur car il est utilisé par des produits"
            );
        }

        /* =====================================
           DELETE
        ===================================== */

        $delete =
            $pdo->prepare("
                DELETE FROM fournisseurs
                WHERE id=?
            ");

        $delete->execute([$id]);

        /* =====================================
           HISTORIQUE
        ===================================== */

        historique(

            $pdo,

            currentUser()['id'],

            'SUPPRESSION_FOURNISSEUR',

            'Fournisseur supprimé : '
            . $f['nom'],

            'DANGER'
        );

        flash(
            'success',
            '✅ Fournisseur supprimé'
        );

    } catch (Exception $e) {

        flash(
            'error',
            $e->getMessage()
        );
    }

    header('Location: fournisseurs.php');
    exit;
}

/* =========================================================
   MODE EDIT
========================================================= */

$editFournisseur = null;

if (isset($_GET['edit'])) {

    $stmt =
        $pdo->prepare("
            SELECT *
            FROM fournisseurs
            WHERE id=?
        ");

    $stmt->execute([
        (int)$_GET['edit']
    ]);

    $editFournisseur =
        $stmt->fetch();
}

/* =========================================================
   SEARCH
========================================================= */

$search =
    trim($_GET['search'] ?? '');

$sql = "
    SELECT *
    FROM fournisseurs
    WHERE 1
";

$params = [];

if (!empty($search)) {

    $sql .= "
        AND
        (
            nom LIKE ?
            OR telephone LIKE ?
            OR email LIKE ?
        )
    ";

    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= "
    ORDER BY id DESC
";

$stmt =
    $pdo->prepare($sql);

$stmt->execute($params);

$list =
    $stmt->fetchAll();

/* =========================================================
   STATS
========================================================= */

$totalFournisseurs =
    $pdo->query("
        SELECT COUNT(*)
        FROM fournisseurs
    ")->fetchColumn();

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="p-4 md:p-6 max-w-7xl mx-auto">

<!-- HEADER -->
<div class="flex flex-col md:flex-row justify-between md:items-center gap-4 mb-8">

    <div>

        <h1 class="text-3xl font-black">
            🚚 Gestion Fournisseurs
        </h1>

        <p class="text-gray-500 mt-2">
            Gestion sécurisée des fournisseurs
        </p>

    </div>

    <div class="bg-blue-100 text-blue-700 px-5 py-3 rounded-2xl font-bold">

        📦 Total :
        <?= $totalFournisseurs ?>

    </div>

</div>

<!-- ALERT -->

<?php if($msg = flash('success')): ?>

<div class="bg-green-100 border border-green-300 text-green-700 p-4 rounded-2xl mb-6">

    <?= e($msg) ?>

</div>

<?php endif; ?>

<?php if($msg = flash('error')): ?>

<div class="bg-red-100 border border-red-300 text-red-700 p-4 rounded-2xl mb-6">

    <?= e($msg) ?>

</div>

<?php endif; ?>

<!-- FORM -->

<div class="bg-white rounded-3xl shadow-2xl overflow-hidden mb-8">

<div class="bg-gradient-to-r from-blue-600 to-indigo-700 p-6 text-white">

    <h2 class="text-2xl font-black">

        <?= $editFournisseur
            ? '✏ Modifier fournisseur'
            : '➕ Ajouter fournisseur' ?>

    </h2>

</div>

<form method="POST" class="p-6">

<input
    type="hidden"
    name="csrf_token"
    value="<?= csrf_token() ?>"
>

<?php if($editFournisseur): ?>

<input
    type="hidden"
    name="id"
    value="<?= $editFournisseur['id'] ?>"
>

<?php endif; ?>

<div class="grid md:grid-cols-2 lg:grid-cols-3 gap-5">

    <!-- NOM -->
    <div>

        <label class="font-bold block mb-2">
            🏢 Nom
        </label>

        <input
            type="text"
            name="nom"
            required
            value="<?= e($editFournisseur['nom'] ?? '') ?>"
            class="w-full border p-4 rounded-2xl"
        >

    </div>

    <!-- CONTACT -->
    <div>

        <label class="font-bold block mb-2">
            👤 Contact
        </label>

        <input
            type="text"
            name="contact"
            value="<?= e($editFournisseur['contact'] ?? '') ?>"
            class="w-full border p-4 rounded-2xl"
        >

    </div>

    <!-- TELEPHONE -->
    <div>

        <label class="font-bold block mb-2">
            📞 Téléphone
        </label>

        <input
            type="text"
            name="telephone"
            value="<?= e($editFournisseur['telephone'] ?? '') ?>"
            class="w-full border p-4 rounded-2xl"
        >

    </div>

    <!-- EMAIL -->
    <div>

        <label class="font-bold block mb-2">
            📧 Email
        </label>

        <input
            type="email"
            name="email"
            value="<?= e($editFournisseur['email'] ?? '') ?>"
            class="w-full border p-4 rounded-2xl"
        >

    </div>

    <!-- ADRESSE -->
    <div class="md:col-span-2">

        <label class="font-bold block mb-2">
            📍 Adresse
        </label>

        <input
            type="text"
            name="adresse"
            value="<?= e($editFournisseur['adresse'] ?? '') ?>"
            class="w-full border p-4 rounded-2xl"
        >

    </div>

</div>

<div class="mt-6">

    <?php if($editFournisseur): ?>

    <button
        type="submit"
        name="update"
        class="bg-yellow-500 hover:bg-yellow-600 text-white px-8 py-4 rounded-2xl font-bold"
    >

        💾 Modifier fournisseur

    </button>

    <a
        href="fournisseurs.php"
        class="bg-gray-300 hover:bg-gray-400 text-black px-8 py-4 rounded-2xl font-bold ml-2"
    >

        Annuler

    </a>

    <?php else: ?>

    <button
        type="submit"
        name="ajouter"
        class="bg-blue-600 hover:bg-blue-700 text-white px-8 py-4 rounded-2xl font-bold"
    >

        ➕ Ajouter fournisseur

    </button>

    <?php endif; ?>

</div>

</form>

</div>

<!-- SEARCH -->

<div class="bg-white rounded-2xl shadow p-4 mb-6">

<form method="GET" class="flex gap-3">

    <input
        type="text"
        name="search"
        value="<?= e($search) ?>"
        placeholder="Recherche fournisseur..."
        class="border p-3 rounded-xl w-full"
    >

    <button class="bg-black text-white px-5 rounded-xl">

        🔍 Rechercher

    </button>

</form>

</div>

<!-- TABLE -->

<div class="bg-white rounded-3xl shadow-2xl overflow-hidden">

<div class="bg-gradient-to-r from-slate-800 to-slate-900 p-6 text-white">

    <h2 class="text-2xl font-black">
        📜 Liste fournisseurs
    </h2>

</div>

<div class="overflow-x-auto">

<table class="w-full">

<thead class="bg-slate-100">

<tr>

    <th class="p-4 text-left">
        Nom
    </th>

    <th class="p-4 text-left">
        Contact
    </th>

    <th class="p-4 text-left">
        Téléphone
    </th>

    <th class="p-4 text-left">
        Email
    </th>

    <th class="p-4 text-left">
        Adresse
    </th>

    <th class="p-4 text-left">
        Actions
    </th>

</tr>

</thead>

<tbody>

<?php foreach($list as $f): ?>

<tr class="border-t hover:bg-slate-50">

    <td class="p-4 font-bold">

        <?= e($f['nom']) ?>

    </td>

    <td class="p-4">

        <?= e($f['contact']) ?>

    </td>

    <td class="p-4">

        <?= e($f['telephone']) ?>

    </td>

    <td class="p-4">

        <?= e($f['email']) ?>

    </td>

    <td class="p-4">

        <?= e($f['adresse']) ?>

    </td>

    <td class="p-4 flex gap-2">

        <!-- EDIT -->
        <a
            href="fournisseurs.php?edit=<?= $f['id'] ?>"
            class="bg-yellow-500 hover:bg-yellow-600 text-white px-4 py-2 rounded-xl text-sm"
        >

            ✏ Modifier

        </a>

        <!-- DELETE -->
        <form
            method="POST"
            onsubmit="return confirm('Supprimer ce fournisseur ?')"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= csrf_token() ?>"
            >

            <input
                type="hidden"
                name="delete_id"
                value="<?= $f['id'] ?>"
            >

            <button class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-xl text-sm">

                🗑 Supprimer

            </button>

        </form>

    </td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

</div>

</div>

<?php include 'includes/footer.php'; ?>