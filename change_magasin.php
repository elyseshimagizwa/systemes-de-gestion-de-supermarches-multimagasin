
<?php

require_once 'config.php';

requireLogin();

$user = currentUser();

/* =========================================================
   ADMIN SEULEMENT
========================================================= */

if(!isAdmin()){

    exit("
    <div style='padding:30px;font-family:sans-serif'>
        ⛔ Accès refusé
    </div>
    ");
}

/* =========================================================
   MAGASIN ACTUEL
========================================================= */

$magasin_actif_id =
    currentMagasinId();

/* =========================================================
   VERIFIER SI CAISSE OUVERTE
========================================================= */

$sessionOuverte = false;

$checkSession = $pdo->prepare("
    SELECT id
    FROM sessions_caisse
    WHERE utilisateur_id=?
    AND magasin_id=?
    AND statut='ouverte'
    LIMIT 1
");

$checkSession->execute([

    $user['id'],
    $magasin_actif_id

]);

if($checkSession->fetch()){

    $sessionOuverte = true;
}

/* =========================================================
   CHANGER MAGASIN
========================================================= */

if(

    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['magasin_id'])

){

    verify_csrf();

    /* =========================================
       BLOQUER SI CAISSE OUVERTE
    ========================================= */

    if($sessionOuverte){

        flash(
            'error',
            '⛔ Impossible de changer de magasin pendant qu’une caisse est ouverte.'
        );

        header("Location:change_magasin.php");
        exit;
    }

    $nouveau_magasin =
        (int)$_POST['magasin_id'];

    /* =========================================
       VERIFIER EXISTENCE
    ========================================= */

    if (!canAccessMagasin($nouveau_magasin)) {
        flash(
            'error',
            '⛔ Magasin non autorisé'
        );

        header("Location:change_magasin.php");
        exit;
    }

    $stmtMagasin = $pdo->prepare("SELECT id FROM magasins WHERE id=? AND statut='actif' LIMIT 1");
    $stmtMagasin->execute([$nouveau_magasin]);

    if (!$stmtMagasin->fetch()) {
        flash(
            'error',
            '⛔ Magasin introuvable ou inactif'
        );

        header("Location:change_magasin.php");
        exit;
    }

    /* =========================================
       METTRE A JOUR UTILISATEUR
    ========================================= */
    setMagasinActif($nouveau_magasin);

    /* =========================================
       SUCCESS
    ========================================= */

    flash(
        'success',
        '✅ Magasin actif changé avec succès'
    );

    header("Location:dashboard.php");
    exit;
}

/* =========================================================
   LISTE MAGASINS
========================================================= */

$magasins = getUserMagasins();

/* =========================================================
   MAGASIN ACTUEL
========================================================= */

$magasinActuel = null;

if($magasin_actif_id > 0){

    $stmtActuel = $pdo->prepare("
        SELECT *
        FROM magasins
        WHERE id=?
        LIMIT 1
    ");

    $stmtActuel->execute([
        $magasin_actif_id
    ]);

    $magasinActuel =
        $stmtActuel->fetch();
}

/* =========================================================
   STATS MAGASIN
========================================================= */

$totalProduits = 0;
$totalVentes = 0;
$totalCaisses = 0;

if($magasin_actif_id > 0){

    /* PRODUITS */

    $stmtP = $pdo->prepare("
        SELECT COUNT(*)
        FROM produits
        WHERE magasin_id=?
    ");

    $stmtP->execute([
        $magasin_actif_id
    ]);

    $totalProduits =
        (int)$stmtP->fetchColumn();

    /* VENTES */

    $stmtV = $pdo->prepare("
        SELECT COUNT(*)
        FROM ventes
        WHERE magasin_id=?
    ");

    $stmtV->execute([
        $magasin_actif_id
    ]);

    $totalVentes =
        (int)$stmtV->fetchColumn();

    /* CAISSES */

    $stmtC = $pdo->prepare("
        SELECT COUNT(*)
        FROM sessions_caisse
        WHERE magasin_id=?
    ");

    $stmtC->execute([
        $magasin_actif_id
    ]);

    $totalCaisses =
        (int)$stmtC->fetchColumn();
}

include 'includes/header.php';
include 'includes/sidebar.php';

?>

<link rel="stylesheet" href="assets/tailwind.css">

<div class="p-6 bg-slate-100 min-h-screen">

<div class="max-w-6xl mx-auto">

<!-- HEADER -->

<div class="flex items-center justify-between mb-8">

    <div>

        <h1 class="text-4xl font-black text-slate-800">

            🏪 Changer de magasin

        </h1>

        <p class="text-slate-500 mt-2">

            Architecture multi-boutiques professionnelle

        </p>

    </div>

    <?php if($magasinActuel): ?>

    <div class="bg-blue-600 text-white px-6 py-3 rounded-2xl shadow-lg">

        <div class="text-xs uppercase opacity-80">

            Magasin actif

        </div>

        <div class="font-black text-lg">

            <?= e($magasinActuel['nom']) ?>

        </div>

    </div>

    <?php endif; ?>

</div>

<!-- ALERTES -->

<?php if($m = flash('success')): ?>

<div class="bg-green-100 border border-green-300 text-green-700 p-5 rounded-3xl mb-6">

    <?= e($m) ?>

</div>

<?php endif; ?>

<?php if($m = flash('error')): ?>

<div class="bg-red-100 border border-red-300 text-red-700 p-5 rounded-3xl mb-6">

    <?= e($m) ?>

</div>

<?php endif; ?>

<!-- SESSION OUVERTE -->

<?php if($sessionOuverte): ?>

<div class="bg-orange-100 border border-orange-300 text-orange-700 p-5 rounded-3xl mb-6">

    ⚠️ Vous avez une caisse ouverte dans ce magasin.
    <br><br>

    🔒 Fermez la caisse avant de changer de magasin.

</div>

<?php endif; ?>

<!-- STATS -->

<div class="grid md:grid-cols-3 gap-5 mb-8">

    <div class="bg-white rounded-3xl shadow p-6">

        <div class="text-slate-500 text-sm">

            Produits

        </div>

        <div class="text-4xl font-black text-blue-600 mt-2">

            <?= number_format($totalProduits) ?>

        </div>

    </div>

    <div class="bg-white rounded-3xl shadow p-6">

        <div class="text-slate-500 text-sm">

            Ventes

        </div>

        <div class="text-4xl font-black text-green-600 mt-2">

            <?= number_format($totalVentes) ?>

        </div>

    </div>

    <div class="bg-white rounded-3xl shadow p-6">

        <div class="text-slate-500 text-sm">

            Sessions caisse

        </div>

        <div class="text-4xl font-black text-red-600 mt-2">

            <?= number_format($totalCaisses) ?>

        </div>

    </div>

</div>

<!-- LISTE MAGASINS -->

<div class="bg-white rounded-3xl shadow overflow-hidden">

<div class="p-6 border-b">

    <h2 class="text-2xl font-black">

        🏬 Sélection du magasin actif

    </h2>

</div>

<div class="p-6">

<form method="POST">

<input
    type="hidden"
    name="csrf_token"
    value="<?= csrf_token() ?>"
>

<div class="grid md:grid-cols-2 gap-5">

<?php foreach($magasins as $m): ?>

<label
    class="border-2 rounded-3xl p-5 cursor-pointer transition hover:border-blue-500

    <?= ($magasin_actif_id == $m['id'])
        ? 'border-blue-600 bg-blue-50'
        : 'border-slate-200 bg-white'
    ?>
    "
>

<input
    type="radio"
    name="magasin_id"
    value="<?= $m['id'] ?>"
    class="hidden"
    <?= ($magasin_actif_id == $m['id']) ? 'checked' : '' ?>
>

<div class="flex items-center justify-between">

    <div>

        <div class="text-2xl font-black text-slate-800">

            <?= e($m['nom']) ?>

        </div>

        <div class="text-slate-500 mt-2">

            <?= e($m['adresse'] ?? 'Adresse non définie') ?>

        </div>

    </div>

    <?php if($magasin_actif_id == $m['id']): ?>

    <div class="bg-blue-600 text-white px-4 py-2 rounded-2xl font-bold">

        ACTIF

    </div>

    <?php endif; ?>

</div>

</label>

<?php endforeach; ?>

</div>

<button
    type="submit"

    <?= $sessionOuverte ? 'disabled' : '' ?>

    class="mt-8 w-full py-5 rounded-3xl font-black text-xl transition

    <?= $sessionOuverte
        ? 'bg-slate-300 text-slate-500 cursor-not-allowed'
        : 'bg-blue-600 hover:bg-blue-700 text-white'
    ?>
    "
>

    🔄 Changer le magasin actif

</button>

</form>

</div>

</div>

</div>

</div>

<?php include 'includes/footer.php'; ?>

