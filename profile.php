<?php

require_once 'config.php';

requireLogin();

/* =========================================================
   USER
========================================================= */

$user = currentUser();

$user_id = $user['id'];

$isAdmin =
    ($user['role'] ?? '') === 'admin';

$success = '';
$error   = '';

/* =========================================================
   SETTINGS
========================================================= */

$settings = getSettings();

/* =========================================================
   CREATE PROFILE IF NOT EXISTS
========================================================= */

$checkProfil = $pdo->prepare("
    SELECT *
    FROM profils
    WHERE utilisateur_id=?
");

$checkProfil->execute([$user_id]);

$profilExiste = $checkProfil->fetch();

if(!$profilExiste){

    $insertProfil = $pdo->prepare("
        INSERT INTO profils
        (
            utilisateur_id,
            created_at
        )
        VALUES
        (
            ?,
            NOW()
        )
    ");

    $insertProfil->execute([$user_id]);
}

/* =========================================================
   UPDATE PROFILE
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['update_profile'])
) {

    verify_csrf();

    $email =
        strtolower(trim($_POST['email'] ?? ''));

    $nom =
        trim(strip_tags($_POST['nom'] ?? ''));

    $telephone =
        trim($_POST['telephone']);

    $adresse =
        trim($_POST['adresse']);

    $genre =
        trim($_POST['genre']);

    if ($nom === '') {
        $error = 'Le nom complet est obligatoire';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Adresse email invalide';
    }

    if (!$error) {
        $checkEmail = $pdo->prepare("SELECT id FROM utilisateurs WHERE email=? AND id<>? LIMIT 1");
        $checkEmail->execute([$email, $user_id]);

        if ($checkEmail->fetch()) {
            $error = 'Cette adresse email est déjà utilisée';
        }
    }

    /* =====================================================
       PHOTO ACTUELLE
    ===================================================== */

    $stmtPhoto = $pdo->prepare("SELECT photo FROM profils WHERE utilisateur_id=?");
    $stmtPhoto->execute([$user_id]);

    $oldPhoto = $stmtPhoto->fetch();
    $photo = $oldPhoto['photo'] ?? null;

    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === 0) {
        $uploadDir = 'uploads/profiles/';

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $extension = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];

        if (!in_array($extension, $allowed, true)) {
            $error = 'Image invalide';
        } else {
            $fileName = 'profile_'.time().'_'.rand(1000, 9999).'.'.$extension;
            $destination = $uploadDir.$fileName;

            if (move_uploaded_file($_FILES['photo']['tmp_name'], $destination)) {
                $photo = $destination;
            } else {
                $error = 'Impossible d’enregistrer la photo';
            }
        }
    }

    /* =====================================================
       UPDATE USER
    ===================================================== */

    if(!$error){

        $stmtUser = $pdo->prepare("UPDATE utilisateurs SET nom=?, email=? WHERE id=?");
        $stmtUser->execute([$nom, $email, $user_id]);
        /* =====================================================
           UPDATE PROFILE
        ===================================================== */

        $stmtProfil = $pdo->prepare("
            UPDATE profils
            SET
                telephone=?,
                adresse=?,
                genre=?,
                photo=?
            WHERE utilisateur_id=?
        ");

        $stmtProfil->execute([

            $telephone,

            $adresse,

            $genre,

            $photo,

            $user_id
        ]);

        refreshUserSession();

        $_SESSION['success'] =
            "✅ Profil mis à jour avec succès";

        header("Location: profile.php");

        exit;
    }
}

/* =========================================================
   UPDATE PASSWORD
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['update_password'])
) {

    verify_csrf();

    $password =
        trim($_POST['new_password']);

    $confirm =
        trim($_POST['confirm_password']);

    if(empty($password)){

        $error =
            "Mot de passe obligatoire";

    }elseif(strlen($password) < 6){

        $error =
            "Le mot de passe doit contenir au moins 6 caractères";

    }elseif($password !== $confirm){

        $error =
            "Les mots de passe ne correspondent pas";
    }

    if(!$error){

        $hash =
            password_hash(
                $password,
                PASSWORD_DEFAULT
            );

        $stmt = $pdo->prepare("
            UPDATE utilisateurs
            SET mot_de_passe=?
            WHERE id=?
        ");

        $stmt->execute([

            $hash,

            $user_id
        ]);

        $_SESSION['success'] =
            "✅ Mot de passe modifié avec succès";

        header("Location: profile.php");

        exit;
    }
}

/* =========================================================
   USER UPDATED
========================================================= */

$stmt = $pdo->prepare("
    SELECT

        u.*,

        p.photo,
        p.telephone,
        p.adresse,
        p.genre

    FROM utilisateurs u

    LEFT JOIN profils p
    ON p.utilisateur_id = u.id

    WHERE u.id=?

    LIMIT 1
");

$stmt->execute([$user_id]);

$userData = $stmt->fetch();

/* =========================================================
   FLASH
========================================================= */

if(isset($_SESSION['success'])){

    $success =
        $_SESSION['success'];

    unset($_SESSION['success']);
}

/* =========================================================
   HEADER
========================================================= */

include 'includes/header.php';

include 'includes/sidebar.php';

?>

<style>
/* =========================================================
   PROFILE PREMIUM STYLE
========================================================= */

.profile-glass{

    background:
    rgba(255,255,255,.82);

    backdrop-filter:
    blur(18px);

    border:
    1px solid rgba(255,255,255,.22);

    box-shadow:
    0 10px 40px rgba(0,0,0,.08);

    transition:.3s ease;
}

.dark .profile-glass{

    background:
    rgba(15,23,42,.88);

    border:
    1px solid rgba(255,255,255,.05);
}

/* =========================================================
   PROFILE CARD
========================================================= */

.info-card{

    background:
    linear-gradient(
        135deg,
        #2563eb 0%,
        #1d4ed8 50%,
        #1e40af 100%
    );

    color:white;

    border-radius:32px;

    overflow:hidden;

    position:relative;

    box-shadow:
    0 20px 40px rgba(37,99,235,.28);
}

.info-card::before{

    content:'';

    position:absolute;

    width:240px;
    height:240px;

    border-radius:999px;

    background:
    rgba(255,255,255,.08);

    top:-90px;
    right:-70px;
}

.info-card::after{

    content:'';

    position:absolute;

    width:160px;
    height:160px;

    border-radius:999px;

    background:
    rgba(255,255,255,.05);

    bottom:-60px;
    left:-40px;
}

/* =========================================================
   BIG AVATAR
========================================================= */

.profile-avatar-big{

    width:160px;
    height:160px;

    border-radius:999px;

    object-fit:cover;

    border:
    6px solid rgba(255,255,255,.25);

    box-shadow:
    0 15px 40px rgba(0,0,0,.2);

    transition:.3s ease;
}

.profile-avatar-big:hover{

    transform:scale(1.03);
}

/* =========================================================
   INPUTS
========================================================= */

.profile-input{

    width:100%;

    border:
    1px solid #dbeafe;

    background:white;

    border-radius:20px;

    padding:15px 18px;

    transition:.25s ease;

    font-size:15px;
}

.profile-input:focus{

    outline:none;

    border-color:#3b82f6;

    box-shadow:
    0 0 0 5px rgba(59,130,246,.12);
}

.dark .profile-input{

    background:#0f172a;

    border-color:#334155;

    color:white;
}

.dark .profile-input::placeholder{

    color:#94a3b8;
}

/* =========================================================
   BUTTONS
========================================================= */

.profile-btn{

    padding:14px 24px;

    border-radius:18px;

    font-weight:700;

    transition:.25s ease;

    box-shadow:
    0 10px 25px rgba(0,0,0,.12);
}

.profile-btn:hover{

    transform:
    translateY(-2px);

    box-shadow:
    0 14px 30px rgba(0,0,0,.16);
}

/* =========================================================
   STATS
========================================================= */

.profile-stat{

    background:
    rgba(255,255,255,.12);

    border:
    1px solid rgba(255,255,255,.08);

    border-radius:22px;

    padding:16px;

    backdrop-filter:
    blur(8px);

    transition:.25s ease;
}

.profile-stat:hover{

    transform:translateY(-3px);

    background:
    rgba(255,255,255,.16);
}

/* =========================================================
   ANIMATION
========================================================= */

.fade-in{

    animation:
    fadeIn .35s ease;
}

@keyframes fadeIn{

    from{

        opacity:0;
        transform:translateY(12px);
    }

    to{

        opacity:1;
        transform:translateY(0);
    }
}

/* =========================================================
   ALERTS
========================================================= */

.success-alert{

    background:
    linear-gradient(
        135deg,
        #dcfce7,
        #bbf7d0
    );

    border:
    1px solid #86efac;

    color:#166534;
}

.error-alert{

    background:
    linear-gradient(
        135deg,
        #fee2e2,
        #fecaca
    );

    border:
    1px solid #fca5a5;

    color:#991b1b;
}

/* =========================================================
   SECTION TITLE
========================================================= */

.section-title{

    font-size:28px;

    font-weight:900;

    color:#0f172a;
}

.dark .section-title{

    color:white;
}

.section-subtitle{

    color:#64748b;

    margin-top:8px;
}

/* =========================================================
   CUSTOM FILE INPUT
========================================================= */

input[type="file"]{

    cursor:pointer;
}

/* =========================================================
   TEXTAREA
========================================================= */

textarea.profile-input{

    resize:none;
}

/* =========================================================
   MOBILE
========================================================= */

@media(max-width:768px){

    .profile-avatar-big{

        width:130px;
        height:130px;
    }

    .section-title{

        font-size:24px;
    }

    .profile-btn{

        width:100%;
    }
}

</style>

<div class="md:ml-64 p-4 md:p-6">

    <!-- TOP -->

    <div class="flex flex-col xl:flex-row gap-6">

        <!-- LEFT CARD -->

        <div class="xl:w-[360px]">

            <div class="info-card p-8 shadow-2xl">

                <div class="relative z-10 text-center">

                    <?php if(!empty($userData['photo'])): ?>

                        <img
                            src="<?= e($userData['photo']) ?>"
                            class="profile-avatar-big mx-auto"
                        >

                    <?php else: ?>

                        <div class="profile-avatar-big mx-auto bg-white/15 flex items-center justify-center text-6xl font-black">

                            <?= strtoupper(substr($userData['nom'],0,1)) ?>

                        </div>

                    <?php endif; ?>

                    <h1 class="mt-6 text-3xl font-black">

                        <?= e($userData['nom']) ?>

                    </h1>

                    <div class="mt-3">

                        <span class="bg-white/20 px-5 py-2 rounded-full text-sm font-bold">

                            <?= strtoupper(e($userData['role'])) ?>

                        </span>

                    </div>

                    <div class="grid grid-cols-2 gap-4 mt-8">

                        <div class="profile-stat">

                            <div class="text-sm opacity-80">

                                Téléphone

                            </div>

                            <div class="font-bold mt-1">

                                <?= e($userData['telephone'] ?: '---') ?>

                            </div>

                        </div>

                        <div class="profile-stat">

                            <div class="text-sm opacity-80">

                                Genre

                            </div>

                            <div class="font-bold mt-1">

                                <?= e($userData['genre'] ?: '---') ?>

                            </div>

                        </div>

                    </div>

                    <div class="profile-stat mt-4 text-left">

                        <div class="text-sm opacity-80 mb-1">

                            Adresse

                        </div>

                        <div class="font-medium">

                            <?= e($userData['adresse'] ?: 'Non renseignée') ?>

                        </div>

                    </div>

                    <div class="profile-stat mt-4 text-left">

                        <div class="text-sm opacity-80 mb-1">

                            Email

                        </div>

                        <div class="font-medium break-all">

                            <?= e($userData['email']) ?>

                        </div>

                    </div>

                </div>

            </div>

        </div>

        <!-- RIGHT -->

        <div class="flex-1 space-y-6">

            <!-- HEADER -->

            <div class="profile-glass rounded-[28px] p-6 fade-in">

                <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">

                    <div>

                        <h2 class="text-3xl font-black text-slate-800 dark:text-white">

                            👤 Gestion du profil

                        </h2>

                        <p class="text-slate-500 mt-2">

                            Modifier les informations personnelles et la sécurité du compte.
                        </p>

                    </div>

                    <div class="flex flex-wrap gap-3">

                        <button
                            onclick="toggleProfileForm()"
                            class="profile-btn bg-blue-600 hover:bg-blue-700 text-white"
                        >

                            ✏ Modifier Profil

                        </button>

                        <button
                            onclick="togglePasswordForm()"
                            class="profile-btn bg-red-600 hover:bg-red-700 text-white"
                        >

                            🔐 Mot de passe

                        </button>

                    </div>

                </div>
            </div>

            <!-- SUCCESS -->

            <?php if($success): ?>

                <div class="bg-green-100 border border-green-200 text-green-700 p-5 rounded-3xl shadow fade-in">

                    <?= e($success) ?>

                </div>

            <?php endif; ?>

            <!-- ERROR -->

            <?php if($error): ?>

                <div class="bg-red-100 border border-red-200 text-red-700 p-5 rounded-3xl shadow fade-in">

                    <?= e($error) ?>

                </div>

            <?php endif; ?>

            <!-- PROFILE FORM -->

            <div
                id="profileForm"
                class="hidden profile-glass rounded-[28px] p-7 fade-in"
            >

                <h3 class="text-2xl font-black mb-7 text-slate-800 dark:text-white">

                    ✏ Modifier les informations
                </h3>

                <form
                    method="POST"
                    enctype="multipart/form-data"
                    class="grid md:grid-cols-2 gap-5"
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= csrf_token() ?>"
                    >

                    <!-- NOM -->

                    <div>

                        <label class="block mb-3 font-bold">

                            Nom complet

                        </label>

                        <input
                            type="text"
                            name="nom"
                            value="<?= e($userData['nom']) ?>"
                            placeholder="Nom complet"
                            required
                            class="profile-input"
                        >

                    </div>

                    <!-- EMAIL -->

                    <div>

                        <label class="block mb-3 font-bold">

                            Adresse Email

                        </label>

                        <input
                            type="email"
                            name="email"
                            required
                            value="<?= e($userData['email']) ?>"
                            class="profile-input"
                        >

                    </div>

                    <!-- TELEPHONE -->

                    <div>

                        <label class="block mb-3 font-bold">

                            Téléphone

                        </label>

                        <input
                            type="text"
                            name="telephone"
                            value="<?= e($userData['telephone']) ?>"
                            placeholder="Téléphone"
                            class="profile-input"
                        >

                    </div>

                    <!-- GENRE -->

                    <div>

                        <label class="block mb-3 font-bold">

                            Genre

                        </label>

                        <select
                            name="genre"
                            class="profile-input"
                        >

                            <option value="">
                                Sélectionner
                            </option>

                            <option
                                value="Homme"
                                <?= ($userData['genre'] ?? '') === 'Homme' ? 'selected' : '' ?>
                            >

                                Homme

                            </option>

                            <option
                                value="Femme"
                                <?= ($userData['genre'] ?? '') === 'Femme' ? 'selected' : '' ?>
                            >

                                Femme

                            </option>

                        </select>

                    </div>

                    <!-- PHOTO -->

                    <div class="md:col-span-2">

                        <label class="block mb-3 font-bold">

                            Photo de profil

                        </label>

                        <input
                            type="file"
                            name="photo"
                            accept=".jpg,.jpeg,.png,.webp"
                            class="profile-input"
                        >

                    </div>

                    <!-- ADRESSE -->

                    <div class="md:col-span-2">

                        <label class="block mb-3 font-bold">

                            Adresse

                        </label>

                        <textarea
                            name="adresse"
                            rows="5"
                            placeholder="Adresse"
                            class="profile-input"
                        ><?= e($userData['adresse']) ?></textarea>

                    </div>

                    <!-- INFO -->

                    <?php if(!$isAdmin): ?>

                        <div class="md:col-span-2 bg-yellow-100 text-yellow-800 p-5 rounded-3xl border border-yellow-200">

                        </div>

                    <?php endif; ?>

                    <!-- BTN -->

                    <div class="md:col-span-2">

                        <button
                            type="submit"
                            name="update_profile"
                            class="profile-btn bg-blue-600 hover:bg-blue-700 text-white shadow-xl"
                        >

                            💾 Enregistrer les modifications

                        </button>

                    </div>

                </form>

            </div>

            <!-- PASSWORD -->

            <div
                id="passwordForm"
                class="hidden profile-glass rounded-[28px] p-7 fade-in"
            >

                <h3 class="text-2xl font-black mb-7 text-slate-800 dark:text-white">

                    🔐 Sécurité du compte

                </h3>

                <form
                    method="POST"
                    class="grid gap-5"
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= csrf_token() ?>"
                    >

                    <!-- PASSWORD -->

                    <div>

                        <label class="block mb-3 font-bold">

                            Nouveau mot de passe

                        </label>

                        <input
                            type="password"
                            name="new_password"
                            required
                            class="profile-input"
                        >

                    </div>

                    <!-- CONFIRM -->

                    <div>

                        <label class="block mb-3 font-bold">

                            Confirmation du mot de passe

                        </label>

                        <input
                            type="password"
                            name="confirm_password"
                            required
                            class="profile-input"
                        >

                    </div>

                    <!-- BTN -->

                    <div>

                        <button
                            type="submit"
                            name="update_password"
                            class="profile-btn bg-red-600 hover:bg-red-700 text-white shadow-xl"
                        >

                            🔐 Modifier le mot de passe

                        </button>

                    </div>

                </form>

            </div>

        </div>

    </div>

</div>

<script>

function toggleProfileForm(){

    document
    .getElementById('profileForm')
    .classList
    .toggle('hidden');
}

function togglePasswordForm(){

    document
    .getElementById('passwordForm')
    .classList
    .toggle('hidden');
}

</script>

<?php include 'includes/footer.php'; ?>