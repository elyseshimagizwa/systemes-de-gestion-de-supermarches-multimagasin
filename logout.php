<?php
require_once 'config.php';

/* =========================
   LOGOUT SECURISE PREMIUM
========================= */

if (isLoggedIn()) {

    $user = currentUser();

    $userId = $user['id'] ?? null;

    $email = $user['email'] ?? 'UNKNOWN';

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';

    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';

    /* =========================
       LOG ACTION
    ========================== */
    logAction(
        "LOGOUT",
        "Déconnexion utilisateur : " . $email,
        "INFO"
    );

    /* =========================
       SECURITY LOG
    ========================== */
    logSecurity(
        "LOGOUT",
        "Utilisateur déconnecté : " . $email
    );

    /* =========================
       SAVE SECURITY LOG
    ========================== */
    try {

        $stmt = $pdo->prepare("
            INSERT INTO securite_logs
            (
                type,
                message,
                ip,
                user_agent,
                created_at
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                NOW()
            )
        ");

        $stmt->execute([

            'LOGOUT',

            'Déconnexion utilisateur : ' . $email,

            $ip,

            $userAgent
        ]);

    } catch(Exception $e) {
        // éviter crash logout
    }

    /* =========================
       DELETE ACTIVE SESSION
    ========================== */
    if ($userId) {

        try {

            $pdo->prepare("
                DELETE FROM connexions_utilisateurs
                WHERE utilisateur_id=?
            ")->execute([$userId]);

        } catch(Exception $e) {
            // ignorer erreur
        }
    }

    /* =========================
       REMOVE SESSION TOKEN
    ========================== */
    if (isset($_SESSION['session_token'])) {

        try {

            $pdo->prepare("
                DELETE FROM sessions_utilisateurs
                WHERE token=?
            ")->execute([
                $_SESSION['session_token']
            ]);

        } catch(Exception $e) {
            // ignorer erreur
        }
    }
}

/* =========================
   DESTROY SESSION
========================= */

/* vider session */
$_SESSION = [];

/* supprimer cookie session */
if (ini_get("session.use_cookies")) {

    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

/* destroy */
session_unset();

session_destroy();

/* =========================
   DELETE REMEMBER COOKIE
========================= */
setcookie(
    "remember_token",
    "",
    time() - 3600,
    "/",
    "",
    false,
    true
);

/* =========================
   REDIRECT LOGIN
========================= */
header("Location: login.php");

exit;
?>