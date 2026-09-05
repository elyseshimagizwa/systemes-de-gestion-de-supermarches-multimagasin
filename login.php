<?php

require_once 'config.php';

/* =========================================================
   REDIRECTION SI CONNECTÉ
========================================================= */

if (isLoggedIn()) {

    header(
        (currentUser()['role'] ?? '') === 'client'
        ? 'Location: index.php'
        : 'Location: dashboard.php'
    );
    exit;
}

/* =========================================================
   VARIABLES
========================================================= */

$error   = null;
$success = null;

/* =========================================================
   SETTINGS
========================================================= */

$settings = getSettings();

/* =========================================================
   ALERTES URL
========================================================= */

if (isset($_GET['timeout'])) {

    $error =
        "⏰ Session expirée. Veuillez vous reconnecter.";
}

if (isset($_GET['multi'])) {

    $error =
        "⚠ Votre compte a été connecté sur un autre appareil.";
}

if (isset($_GET['security'])) {

    $error =
        "🔒 Problème de sécurité détecté. Veuillez vous reconnecter.";
}

if (isset($_GET['logout'])) {

    $success =
        "✅ Déconnexion réussie.";
}

/* =========================================================
   LOGIN PROCESS
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    /* =====================================================
       CLEAN INPUTS
    ===================================================== */

    $email = trim(
        strtolower(
            $_POST['email'] ?? ''
        )
    );

    $password =
        $_POST['password'] ?? '';

    $remember =
        !empty($_POST['remember']);

    $ip =
        $_SERVER['REMOTE_ADDR']
        ?? 'UNKNOWN';

    $userAgent =
        $_SERVER['HTTP_USER_AGENT']
        ?? 'UNKNOWN';

    /* =====================================================
       VALIDATION EMAIL
    ===================================================== */

    if (
        empty($email)
        ||
        empty($password)
    ) {

        $error =
            "❌ Veuillez remplir tous les champs.";

    } elseif (

        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )

    ) {

        $error =
            "❌ Adresse email invalide.";

    } elseif (

        strlen($password) > 255

    ) {

        $error =
            "❌ Mot de passe invalide.";

    } else {

        /* =================================================
           CHECK BLOCK BRUTE FORCE
        ================================================= */

        if (isBlockedAdvanced($email)) {

            logSecurity(

                "LOGIN_BLOCKED",

                "Tentative bloquée : " . $email

            );

            $error =
                "⛔ Trop de tentatives échouées. Réessayez après 1 minute.";

        } else {

            /* =============================================
               USER QUERY
            ============================================== */

            $stmt = $pdo->prepare("
                SELECT *
                FROM utilisateurs
                WHERE email=?
                LIMIT 1
            ");

            $stmt->execute([
                $email
            ]);

            $user = $stmt->fetch();

            /* =============================================
               USER EXIST ?
            ============================================== */

            if (!$user) {

                addLoginAttemptAdvanced($email);

                logSecurity(

                    "LOGIN_FAIL",

                    "Email inexistant : " . $email
                );

                $error =
                    "❌ Email ou mot de passe incorrect.";

            } else {

                /* =========================================
                   USER STATUS
                ========================================== */

                if (
                    isset($user['statut'])
                    &&
                    $user['statut'] !== 'actif'
                ) {

                    logSecurity(

                        "ACCOUNT_DISABLED",

                        "Compte désactivé : " . $email
                    );

                    $error =
                        "⛔ Votre compte est désactivé.";

                } elseif (

                    !empty($user['force_logout'])

                ) {

                    logSecurity(

                        "FORCE_LOGOUT",

                        "Compte déconnecté par admin : " . $email
                    );

                    $error =
                        "⚠ Votre session a été suspendue.";

                } else {

                    /* =====================================
                       VERIFY PASSWORD
                    ====================================== */

                    if (

                        password_verify(
                            $password,
                            $user['mot_de_passe']
                        )

                    ) {

                        /* =================================
                           RESET LOGIN ATTEMPTS
                        ================================== */

                        resetLoginAttempts($email);

                        /* =================================
                           SESSION REGENERATE
                        ================================== */

                        session_regenerate_id(true);

                        /* =================================
                           MAGASIN DATA
                        ================================== */

                        $magasin_id =
                            $user['magasin_id']
                            ?? null;

                        $multi_magasin =
                            $user['multi_magasin']
                            ?? 0;

                        /* =================================
                           USER SESSION
                        ================================== */

                        $_SESSION['user'] = [

                            'id' => $user['id'],

                            'nom' => $user['nom'],

                            'email' => $user['email'],

                            'role' => $user['role'],

                            'magasin_id' => $magasin_id,

                            'multi_magasin' => $multi_magasin
                        ];

                        /* =================================
                           SECURITY SESSION
                        ================================== */

                        $_SESSION['last_activity'] =
                            time();

                        $_SESSION['ip'] =
                            $ip;

                        $_SESSION['device'] = hash(

                            'sha256',

                            $userAgent . $ip
                        );

                        /* =================================
                           CREATE TOKEN
                        ================================== */

                        createUserSession($user);

                        /* =================================
                           UPDATE LAST LOGIN
                        ================================== */

                        $pdo->prepare("
                            UPDATE utilisateurs
                            SET derniere_connexion = NOW()
                            WHERE id=?
                        ")->execute([

                            $user['id']
                        ]);

                        /* =================================
                           REMEMBER TOKEN
                        ================================== */

                        if ($remember) {

                            $rememberToken =
                                bin2hex(
                                    random_bytes(64)
                                );

                            $pdo->prepare("
                                UPDATE utilisateurs
                                SET remember_token=?
                                WHERE id=?
                            ")->execute([

                                $rememberToken,

                                $user['id']
                            ]);

                            setcookie(

                                'remember_token',

                                $rememberToken,

                                [

                                    'expires' =>
                                        time() + (86400 * 30),

                                    'path' => '/',

                                    'secure' =>
                                        !empty($_SERVER['HTTPS']),

                                    'httponly' => true,

                                    'samesite' => 'Strict'
                                ]
                            );
                        }

                        /* =================================
                           SAVE CONNECTION
                        ================================== */

                        $sessionId =
                            session_id();

                        try {

                            $pdo->prepare("
                                INSERT INTO connexions_utilisateurs
                                (
                                    utilisateur_id,
                                    session_id,
                                    ip,
                                    user_agent,
                                    derniere_activite,
                                    statut
                                )
                                VALUES
                                (
                                    ?,
                                    ?,
                                    ?,
                                    ?,
                                    NOW(),
                                    'active'
                                )
                            ")->execute([

                                $user['id'],

                                $sessionId,

                                $ip,

                                $userAgent
                            ]);

                        } catch (Exception $e) {

                            // silent
                        }

                        /* =================================
                           MULTI SESSION DETECTION
                        ================================== */

                        try {

                            $stmtMulti = $pdo->prepare("
                                SELECT COUNT(*)
                                FROM connexions_utilisateurs
                                WHERE utilisateur_id=?
                                AND statut='active'
                                AND derniere_activite >=
                                NOW() - INTERVAL 10 MINUTE
                            ");

                            $stmtMulti->execute([

                                $user['id']
                            ]);

                            $activeSessions =
                                $stmtMulti->fetchColumn();

                            if ($activeSessions > 2) {

                                logSecurity(

                                    "MULTI_CONNEXION",

                                    "Connexion multiple : "
                                    . $user['email']
                                );

                                sendSecurityAlert(

                                    "🚨 Multi Connexion",

                                    "
                                    Utilisateur :
                                    ".$user['email']."

                                    IP :
                                    ".$ip."

                                    Date :
                                    ".date('Y-m-d H:i:s')."
                                    "
                                );
                            }

                        } catch (Exception $e) {

                            // silent
                        }

                        /* =================================
                           SECURITY LOGS
                        ================================== */

                        logSecurity(

                            "LOGIN_SUCCESS",

                            "Connexion réussie : "
                            . $user['email']
                        );

                        logAction(

                            "LOGIN",

                            "Connexion utilisateur : "
                            . $user['email'],

                            "SUCCESS"
                        );

                        /* =================================
                           SUSPICIOUS IP
                        ================================== */

                        detectSuspiciousIP();

                        /* =================================
                           SUCCESS MESSAGE
                        ================================== */

                        flash(

                            'success',

                            '✅ Bienvenue '
                            . $user['nom']
                        );

                        /* =================================
                           REDIRECTION
                        ================================== */

                        header(
                            $user['role'] === 'client'
                            ? "Location: index.php"
                            : "Location: dashboard.php"
                        );
                        exit;

                    } else {

                        /* =============================
                           PASSWORD INCORRECT
                        ============================== */

                        addLoginAttemptAdvanced(
                            $email
                        );

                        logSecurity(

                            "LOGIN_FAIL",

                            "Mot de passe incorrect : "
                            . $email
                        );

                        sendSecurityAlert(

                            "⚠ Tentative échouée",

                            "
                            Email :
                            ".$email."

                            IP :
                            ".$ip."

                            Heure :
                            ".date('Y-m-d H:i:s')."
                            "
                        );

                        $error =
                            "❌ Email ou mot de passe incorrect.";

                        /* =============================
                           AUTO BLOCK
                        ============================== */

                        if (

                            isBlockedAdvanced($email)

                        ) {

                            $error =
                                "⛔ Compte bloqué temporairement pendant 1 minute.";

                            logSecurity(

                                "AUTO_BLOCK",

                                "Blocage automatique : "
                                . $email
                            );
                        }
                    }
                }
            }
        }
    }
}

?>

<!DOCTYPE html>
<html lang="fr">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>

<?= e($settings['nom_boutique'] ?? 'POS PREMIUM') ?>

- Connexion

</title>

<link rel="stylesheet" href="assets/tailwind.css">

<?php
require_once __DIR__ . '/includes/icons.php';
renderIconAssets('assets/vendor/fontawesome.min.css');
?>

<style>

body{

    background:
    linear-gradient(
        135deg,
        #020617,
        #0f172a,
        #1e293b
    );
}

.glass{

    background:
    rgba(255,255,255,.08);

    backdrop-filter:
    blur(18px);

    border:
    1px solid rgba(255,255,255,.1);
}

.fade-in{

    animation:
    fade .6s ease;
}

@keyframes fade{

    from{

        opacity:0;
        transform:translateY(20px);
    }

    to{

        opacity:1;
        transform:translateY(0);
    }
}

.input{

    width:100%;

    padding:15px;

    border-radius:18px;

    border:1px solid #cbd5e1;

    background:white;

    outline:none;

    transition:.25s;
}

.input:focus{

    border-color:#3b82f6;

    box-shadow:
    0 0 0 4px rgba(59,130,246,.2);
}

.btn{

    width:100%;

    padding:15px;

    border-radius:18px;

    font-weight:bold;

    color:white;

    background:
    linear-gradient(
        90deg,
        #2563eb,
        #3b82f6
    );

    transition:.25s;
}

.btn:hover{

    transform:translateY(-2px);

    box-shadow:
    0 10px 25px rgba(37,99,235,.35);
}

.logo-glow{

    text-shadow:
    0 0 20px rgba(59,130,246,.4);
}

</style>

</head>

<body class="min-h-screen flex items-center justify-center p-4">

<div class="glass w-full max-w-md rounded-3xl p-8 shadow-2xl fade-in">

    <!-- LOGO -->

    <div class="text-center mb-8">

        <?php if(!empty($settings['logo'])): ?>

            <div class="w-24 h-24 mx-auto rounded-3xl overflow-hidden shadow-2xl border border-white/20 mb-4">

                <img
                    src="<?= e($settings['logo']) ?>"
                    class="w-full h-full object-cover"
                >

            </div>

        <?php else: ?>

            <div class="w-24 h-24 mx-auto rounded-3xl bg-blue-600 flex items-center justify-center text-white text-4xl shadow-2xl mb-4">

                <i class="fa-solid fa-store"></i>

            </div>

        <?php endif; ?>

        <h1 class="text-3xl font-black text-white logo-glow">

            Connexion

        </h1>

        <p class="text-slate-300 mt-2">

            <?= e($settings['nom_boutique'] ?? 'POS PREMIUM') ?>

        </p>

    </div>

    <!-- ERROR -->

    <?php if($error): ?>

        <div class="bg-red-500/20 border border-red-400 text-red-100 p-4 rounded-2xl mb-5">

            <?= e($error) ?>

        </div>

    <?php endif; ?>

    <!-- SUCCESS -->

    <?php if($success): ?>

        <div class="bg-green-500/20 border border-green-400 text-green-100 p-4 rounded-2xl mb-5">

            <?= e($success) ?>

        </div>

    <?php endif; ?>

    <!-- FORM -->

    <form method="POST" class="space-y-5">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= csrf_token() ?>"
        >

        <!-- EMAIL -->

        <div>

            <label class="text-sm text-slate-200 block mb-2">

                📧 Adresse email

            </label>

            <input
                type="email"
                name="email"
                required
                autocomplete="off"
                maxlength="150"
                value="<?= e($_POST['email'] ?? '') ?>"
                class="input"
                placeholder="Entrer votre email"
            >

        </div>

        <!-- PASSWORD -->

        <div>

            <label class="text-sm text-slate-200 block mb-2">

                🔑 Mot de passe

            </label>

            <div class="relative">

                <input
                    type="password"
                    name="password"
                    id="password"
                    required
                    maxlength="255"
                    class="input pr-12"
                    placeholder="Entrer votre mot de passe"
                >

                <button
                    type="button"
                    onclick="togglePassword()"
                    class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-500"
                >

                    <i
                        id="eyeIcon"
                        class="fa-solid fa-eye"
                    ></i>

                </button>

            </div>

        </div>

        <!-- REMEMBER -->

        <div class="flex items-center justify-between text-sm text-slate-200">

            <label class="flex items-center gap-2">

                <input
                    type="checkbox"
                    name="remember"
                    class="rounded"
                >

                Se souvenir de moi

            </label>

        </div>

        <!-- BUTTON -->

        <button
            type="submit"
            class="btn"
        >

            🔐 Se connecter

        </button>

        <a href="index.php" class="text-blue-500 hover:text-blue-700"  style="display: block; text-align: center; margin-top: 10px; border: 1px solid #5039c5; padding: 10px; border-radius: 15px; background-color: rgba(59, 130, 246, 0.7); color: white; text-decoration: none; transition: background-color 0.3s ease, color 0.3s ease;">
            Retour à l'accueil
        </a>
        <p id="returnMessage" style="text-align: center; margin-top: 10px; display: none; color: #5039c5; font-weight: bold;">
            Retour à l'accueil
        </p>

    </form>

    
    <!-- SECURITY -->

    <div class="mt-6 text-center text-slate-400 text-xs leading-relaxed">


    </div>

    <!-- FOOTER -->

    <div class="mt-5 text-center text-slate-500 text-sm">

        © <?= date('Y') ?>

        <?= e($settings['nom_boutique'] ?? 'POS PREMIUM') ?>

    </div>

</div>

<script>

function togglePassword(){

    const password =
        document.getElementById(
            'password'
        );

    const eye =
        document.getElementById(
            'eyeIcon'
        );

    if(password.type === 'password'){

        password.type = 'text';

        eye.classList.remove(
            'fa-eye'
        );

        eye.classList.add(
            'fa-eye-slash'
        );

    }else{

        password.type = 'password';

        eye.classList.remove(
            'fa-eye-slash'
        );

        eye.classList.add(
            'fa-eye'
        );
    }
}

var returnLink = document.querySelector('a[href="index.php"]');
var returnMessage = document.getElementById('returnMessage');
returnLink.addEventListener('click', function(event) {
    event.preventDefault();
    returnLink.style.display = 'none';
    returnMessage.style.display = 'block';
    setTimeout(function() {
        window.location.href = 'index.php';
    }, 2000);
});

returnLink.addEventListener('click', function(event) {
    event.preventDefault();
    returnMessage.style.display = 'flex';
});

</script>

</body>
</html>