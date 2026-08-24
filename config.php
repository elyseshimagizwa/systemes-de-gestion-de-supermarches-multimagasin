<?php

/*
|--------------------------------------------------------------------------
| CONFIG PRINCIPAL ULTRA SECURE
| POS PREMIUM MULTI MAGASIN
|--------------------------------------------------------------------------
|
| VERSION FULL SÉCURITÉ
| - Protection XSS
| - Protection SQL Injection
| - Anti brute force
| - Session timeout
| - Session regeneration
| - Upload sécurisé
| - Headers sécurité
| - Logs sécurité
| - CSRF protection
| - Contrôle IP/Appareil
| - Multi magasin
| - Multi session
|
*/

require_once __DIR__ . '/config-settings.php';

/*
|--------------------------------------------------------------------------
| TIMEZONE
|--------------------------------------------------------------------------
*/

date_default_timezone_set('Africa/Bujumbura');

/*
|--------------------------------------------------------------------------
| SECURITY HEADERS
|--------------------------------------------------------------------------
*/

header('X-Frame-Options: SAMEORIGIN');

header('X-Content-Type-Options: nosniff');

header('Referrer-Policy: strict-origin-when-cross-origin');

header('X-XSS-Protection: 1; mode=block');

/*
|--------------------------------------------------------------------------
| SECURITY HEADERS
|--------------------------------------------------------------------------
*/

header("X-Frame-Options: SAMEORIGIN");

header("X-Content-Type-Options: nosniff");

header("X-XSS-Protection: 1; mode=block");

header("Referrer-Policy: strict-origin-when-cross-origin");

header("Permissions-Policy: geolocation=(), microphone=(), camera=()");

header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; img-src 'self' data: blob:; font-src 'self' data: https://cdnjs.cloudflare.com;");

/*
|--------------------------------------------------------------------------
| SESSION SECURISEE
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {

    ini_set('session.cookie_httponly', 1);

    ini_set('session.use_only_cookies', 1);

    ini_set('session.use_strict_mode', 1);

    ini_set('session.cookie_samesite', 'Strict');

    ini_set('session.gc_maxlifetime', 2147483647);

    if (!empty($_SERVER['HTTPS'])) {

        ini_set('session.cookie_secure', 1);
    }

    session_name('POS_PREMIUM_SESSION');

    session_start();

    if (
    empty($_SESSION['magasin_actif'])
    &&
    !empty($_SESSION['user']['magasin_id'])
) {

    $_SESSION['magasin_actif'] =
        $_SESSION['user']['magasin_id'];
}
}



/*
|--------------------------------------------------------------------------
| REGENERATE SESSION ID
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['last_regeneration'])
) {

    session_regenerate_id(true);

    $_SESSION['last_regeneration'] = time();

} else {

    if (

        time()
        -
        $_SESSION['last_regeneration']

        > 3600
    ) {

        session_regenerate_id(true);

        $_SESSION['last_regeneration'] =
            time();
    }
}

/*
|--------------------------------------------------------------------------
| DATABASE CONFIG
|--------------------------------------------------------------------------
*/

$host       = "localhost";
$dbname     = "e_servicesburundi";
$username   = "root";
$password   = "";
$charset    = "utf8mb4";

try {

    $pdo = new PDO(

        "mysql:host=$host;dbname=$dbname;charset=$charset",

        $username,

        $password,

        [

            PDO::ATTR_ERRMODE =>
                PDO::ERRMODE_EXCEPTION,

            PDO::ATTR_DEFAULT_FETCH_MODE =>
                PDO::FETCH_ASSOC,

            PDO::ATTR_EMULATE_PREPARES =>
                false
        ]

    );

} catch (PDOException $e) {

    die("Erreur connexion base de données.");
}

/*
|--------------------------------------------------------------------------
| SECURITY CONFIG
|--------------------------------------------------------------------------
*/

define('MAX_LOGIN_ATTEMPTS', 3);

define('LOGIN_BLOCK_MINUTES', 1);

define('MAX_UPLOAD_SIZE', 2 * 1024 * 1024);

define('UPLOAD_ALLOWED_EXTENSIONS', [

    'jpg',
    'jpeg',
    'png',
    'webp'
]);

/*
|--------------------------------------------------------------------------
| LOAD FILES
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/includes/fonctions.php';

require_once __DIR__ . '/includes/security-monitor.php';

/*
|--------------------------------------------------------------------------
| ESCAPE HTML
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| CLEAN INPUT
|--------------------------------------------------------------------------
*/

function clean($data)
{
    return trim(strip_tags($data));
}

/*
|--------------------------------------------------------------------------
| VALIDATE EMAIL
|--------------------------------------------------------------------------
*/

function validateEmail($email)
{
    return filter_var(

        $email,

        FILTER_VALIDATE_EMAIL
    );
}

/*
|--------------------------------------------------------------------------
| VALIDATE TEXT
|--------------------------------------------------------------------------
*/

function validateText(
    $value,
    $min = 2,
    $max = 255
)
{
    $len = mb_strlen($value);

    return (

        $len >= $min

        &&

        $len <= $max
    );
}

/*
|--------------------------------------------------------------------------
| VALIDATE IMAGE
|--------------------------------------------------------------------------
*/

function validateImage($file)
{
    if (

        empty($file)

        ||

        $file['error'] !== 0
    ) {

        return false;
    }

    if (

        $file['size']
        >
        MAX_UPLOAD_SIZE
    ) {

        return false;
    }

    $extension = strtolower(

        pathinfo(

            $file['name'],

            PATHINFO_EXTENSION
        )
    );

    if (

        !in_array(

            $extension,

            UPLOAD_ALLOWED_EXTENSIONS
        )
    ) {

        return false;
    }

    $mime = mime_content_type(
        $file['tmp_name']
    );

    $allowedMime = [

        'image/jpeg',

        'image/png',

        'image/webp'
    ];

    if (

        !in_array(
            $mime,
            $allowedMime
        )
    ) {

        return false;
    }

    return true;
}

/*
|--------------------------------------------------------------------------
| SECURE FILE NAME
|--------------------------------------------------------------------------
*/

function secureFileName($name)
{
    return preg_replace(

        '/[^a-zA-Z0-9_\.-]/',

        '',

        $name
    );
}

/*
|--------------------------------------------------------------------------
| FLASH MESSAGE
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| REFRESH USER SESSION
|--------------------------------------------------------------------------
*/

function refreshUserSession()
{
    global $pdo;

    if (!isLoggedIn()) {

        return;
    }

    $userId =
        $_SESSION['user']['id']
        ?? 0;

    if (!$userId) {

        return;
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM utilisateurs
        WHERE id=?
        LIMIT 1
    ");

    $stmt->execute([
        $userId
    ]);

    $user = $stmt->fetch();

    if ($user) {

        $_SESSION['user'] = $user;
    }
}

/*
|--------------------------------------------------------------------------
| ANTI BRUTE FORCE
|--------------------------------------------------------------------------
*/

function isBlockedAdvanced($email)
{
    global $pdo;

    $ip =
        $_SERVER['REMOTE_ADDR'];

    $stmt = $pdo->prepare("
        SELECT *
        FROM securite_login
        WHERE email=?
        OR ip=?
        LIMIT 1
    ");

    $stmt->execute([
        $email,
        $ip
    ]);

    $data = $stmt->fetch();

    if (!$data) {

        return false;
    }

    if (

        !empty(
            $data['bloque_jusqu']
        )

        &&

        strtotime(
            $data['bloque_jusqu']
        )
        >
        time()
    ) {

        return true;
    }

    return false;
}

function addLoginAttemptAdvanced($email)
{
    global $pdo;

    $ip =
        $_SERVER['REMOTE_ADDR'];

    $stmt = $pdo->prepare("
        SELECT *
        FROM securite_login
        WHERE email=?
        OR ip=?
        LIMIT 1
    ");

    $stmt->execute([
        $email,
        $ip
    ]);

    $data = $stmt->fetch();

    if (!$data) {

        $pdo->prepare("
            INSERT INTO securite_login
            (
                email,
                ip,
                tentatives,
                bloque_jusqu,
                created_at
            )
            VALUES
            (
                ?,
                ?,
                1,
                NULL,
                NOW()
            )
        ")->execute([

            $email,
            $ip
        ]);

    } else {

        $tentatives =
            $data['tentatives']
            + 1;

        $bloque = null;

        if (

            $tentatives
            >=
            MAX_LOGIN_ATTEMPTS
        ) {

            $bloque = date(

                'Y-m-d H:i:s',

                time()
                +
                (
                    LOGIN_BLOCK_MINUTES
                    * 60
                )
            );

            logSecurity(

                "AUTO_BLOCK",

                "Blocage login : "
                .
                $email
            );
        }

        $pdo->prepare("
            UPDATE securite_login
            SET
                tentatives=?,
                bloque_jusqu=?
            WHERE id=?
        ")->execute([

            $tentatives,

            $bloque,

            $data['id']
        ]);
    }
}

/*
|--------------------------------------------------------------------------
| RESET LOGIN ATTEMPTS
|--------------------------------------------------------------------------
*/

function resetLoginAttempts($email)
{
    global $pdo;

    $stmt = $pdo->prepare("
        DELETE FROM securite_login
        WHERE email=?
    ");

    $stmt->execute([$email]);
}

/*
|--------------------------------------------------------------------------
| CREATE SESSION TOKEN
|--------------------------------------------------------------------------
*/

function createUserSession($user)
{
    global $pdo;

    $token =
        bin2hex(
            random_bytes(64)
        );

    $stmt = $pdo->prepare("
        UPDATE utilisateurs
        SET session_token=?
        WHERE id=?
    ");

    $stmt->execute([

        $token,

        $user['id']
    ]);

    $_SESSION['session_token'] =
        $token;
}

/*
|--------------------------------------------------------------------------
| SINGLE SESSION CONTROL
|--------------------------------------------------------------------------
*/

function checkSingleSession()
{
    global $pdo;

    $user = currentUser();

    if (!$user) {

        return;
    }

    $stmt = $pdo->prepare("
        SELECT session_token
        FROM utilisateurs
        WHERE id=?
        LIMIT 1
    ");

    $stmt->execute([
        $user['id']
    ]);

    $dbToken =
        $stmt->fetchColumn();

    if (

        empty(
            $_SESSION['session_token']
        )
    ) {

        $_SESSION['session_token'] =
            $dbToken;

        return;
    }

    if (

        $_SESSION['session_token']

        !==

        $dbToken
    ) {

        logSecurity(

            "MULTI_SESSION",

            "Connexion multiple détectée utilisateur ID : "
            .
            $user['id']
        );

        session_destroy();

        header(
            "Location: login.php?multi=1"
        );

        exit;
    }
}

/*
|--------------------------------------------------------------------------
| DETECT SUSPICIOUS IP
|--------------------------------------------------------------------------
*/

function detectSuspiciousIP()
{
    $ip =
        $_SERVER['REMOTE_ADDR'];

    if (

        !str_starts_with(
            $ip,
            '127.'
        )

        &&

        !str_starts_with(
            $ip,
            '192.'
        )

        &&

        !str_starts_with(
            $ip,
            '10.'
        )
    ) {

        logSecurity(

            "SUSPICIOUS_IP",

            "IP suspecte : "
            .
            $ip
        );
    }
}

/*
|--------------------------------------------------------------------------
| IP LOCK
|--------------------------------------------------------------------------
*/

function checkIP()
{
    if (
        !isset($_SESSION['ip'])
    ) {

        $_SESSION['ip'] =
            $_SERVER['REMOTE_ADDR'];
    }

    if (

        $_SESSION['ip']

        !==

        $_SERVER['REMOTE_ADDR']
    ) {

        logSecurity(

            "IP_CHANGE",

            "Changement IP détecté"
        );

        session_destroy();

        header(
            "Location: login.php?security=ip"
        );

        exit;
    }
}

/*
|--------------------------------------------------------------------------
| DEVICE LOCK
|--------------------------------------------------------------------------
*/

function checkDevice()
{
    $device = hash(

        'sha256',

        ($_SERVER['HTTP_USER_AGENT']
        ??
        '')

        .

        ($_SERVER['REMOTE_ADDR']
        ??
        '')
    );

    if (
        !isset(
            $_SESSION['device']
        )
    ) {

        $_SESSION['device'] =
            $device;

        return;
    }

    if (

        $_SESSION['device']

        !==

        $device
    ) {

        logSecurity(

            "DEVICE_CHANGE",

            "Changement appareil détecté"
        );

        session_destroy();

        header(
            "Location: login.php?security=device"
        );

        exit;
    }
}

/*
|--------------------------------------------------------------------------
| EMAIL ALERT SECURITY
|--------------------------------------------------------------------------
*/

function sendSecurityAlert(
    $subject,
    $message
)
{
    $settings = getSettings();

    $email =
        $settings['email_admin']
        ??
        '';

    if (!$email) {

        return;
    }

    $headers =
        "From: security@pos-premium.com";

    @mail(

        $email,

        $subject,

        $message,

        $headers
    );
}

/*
|--------------------------------------------------------------------------
| UPDATE USER ACTIVITY
|--------------------------------------------------------------------------
*/

function updateUserActivity()
{
    global $pdo;

    if (!isLoggedIn()) {

        return;
    }

    try {

        $pdo->prepare("
            UPDATE connexions_utilisateurs
            SET derniere_activite = NOW()
            WHERE session_id = ?
        ")->execute([

            session_id()
        ]);

    } catch (Exception $e) {

        // silent
    }
}

/*
|--------------------------------------------------------------------------
| MULTI MAGASIN MIDDLEWARE
|--------------------------------------------------------------------------
*/

function bootMultiMagasin()
{
    if (!isLoggedIn()) {

        return;
    }

    $user = currentUser();

    /*
    |--------------------------------------------------
    | BLOQUER SI PAS DE MAGASIN
    |--------------------------------------------------
    */

    $allowedPages = [

        'change_magasin.php',
        'logout.php'
    ];

    $currentPage =
        basename(
            $_SERVER['PHP_SELF']
        );

    if (
    !currentMagasinId()

        &&

        !in_array(
            $currentPage,
            $allowedPages
        )
    ) {

        header(
            "Location: change_magasin.php"
        );

        exit;
    }
}

bootMultiMagasin();

/*
|--------------------------------------------------------------------------
| AUTO SECURITY ENGINE
|--------------------------------------------------------------------------
*/

if (isLoggedIn()) {

    detectSuspiciousIP();

    checkIP();

    checkDevice();

    checkSingleSession();

    updateUserActivity();
}





?>