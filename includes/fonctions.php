
<?php

/* =========================================================
| SAFE SESSION INIT
========================================================= */

if (session_status() === PHP_SESSION_NONE) {

    session_start();
}

/* =========================================================
| SAFE LOGIN ATTEMPTS INIT
========================================================= */

if (
    !isset($_SESSION['login_attempts'])
    ||
    !is_array($_SESSION['login_attempts'])
) {

    $_SESSION['login_attempts'] = [];
}

/* =========================================================
| ESCAPE HTML
========================================================= */

if (!function_exists('e')) {

    function e($value)
    {
        return htmlspecialchars(

            (string)$value,

            ENT_QUOTES,

            'UTF-8'
        );
    }
}

/* =========================================================
| AUTH SYSTEM
========================================================= */

if (!function_exists('isLoggedIn')) {

    function isLoggedIn()
    {
        return !empty($_SESSION['user']);
    }
}

if (!function_exists('currentUser')) {

    function currentUser()
    {
        return $_SESSION['user'] ?? null;
    }
}

if (!function_exists('requireLogin')) {

    function requireLogin()
    {
        if (!isLoggedIn()) {

            header('Location: login.php');

            exit;
        }
    }
}

/* =========================================================
| ROLE SYSTEM
========================================================= */

if (!function_exists('requireRole')) {

    function requireRole($roles)
    {
        requireLogin();

        $user = currentUser();

        if (
            !in_array(
                $user['role'] ?? '',
                (array)$roles
            )
        ) {

            header("Location: dashboard.php");

            exit;
        }
    }
}

if (!function_exists('requireAdmin')) {

    function requireAdmin()
    {
        requireRole(['admin']);
    }
}

if (!function_exists('requireCaissier')) {

    function requireCaissier()
    {
        requireRole([

            'admin',

            'caissier'
        ]);
    }
}

/* =========================================================
| MULTI MAGASIN
========================================================= */

if (!function_exists('currentMagasinId')) {

    function currentMagasinId()
    {
        return $_SESSION['user']['magasin_id'] ?? null;
    }
}

/* =========================================================
| CSRF PROTECTION
========================================================= */

if (!function_exists('csrf_token')) {

    function csrf_token()
    {
        if (empty($_SESSION['csrf_token'])) {

            $_SESSION['csrf_token'] =
                bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('verify_csrf')) {

    function verify_csrf()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {

            $token =
                $_POST['csrf_token'] ?? '';

            if (
                !$token
                ||
                !hash_equals(
                    $_SESSION['csrf_token'] ?? '',
                    $token
                )
            ) {

                exit('❌ CSRF invalide');
            }
        }
    }
}

/* =========================================================
| FLASH SYSTEM
========================================================= */

if (!function_exists('flash')) {

    function flash($key, $msg = null)
    {
        if ($msg !== null) {

            $_SESSION['flash'][$key] = $msg;

            return;
        }

        $val =
            $_SESSION['flash'][$key] ?? null;

        unset($_SESSION['flash'][$key]);

        return $val;
    }
}

/* =========================================================
| GET CLIENT IP
========================================================= */

if (!function_exists('getClientIp')) {

    function getClientIp()
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {

            return $_SERVER['HTTP_CLIENT_IP'];
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {

            return explode(

                ',',

                $_SERVER['HTTP_X_FORWARDED_FOR']
            )[0];
        }

        return $_SERVER['REMOTE_ADDR']
            ?? 'UNKNOWN';
    }
}

/* =========================================================
| LOG ACTION
========================================================= */

if (!function_exists('logAction')) {

    function logAction(
        $action,
        $details = null,
        $niveau = 'info'
    ) {

        global $pdo;

        try {

            if (!isset($pdo)) {

                return;
            }

            $user =
                currentUser();

            $stmt = $pdo->prepare("
                INSERT INTO historiques
                (
                    utilisateur_id,
                    magasin_id,
                    action,
                    details,
                    ip,
                    niveau,
                    created_at
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    NOW()
                )
            ");

            $stmt->execute([

                $user['id'] ?? null,

                $user['magasin_id'] ?? null,

                $action,

                $details,

                getClientIp(),

                $niveau
            ]);

        } catch (Exception $e) {

            error_log($e->getMessage());
        }
    }
}

/* =========================================================
| LOG SECURITY
========================================================= */

if (!function_exists('logSecurity')) {

    function logSecurity(
        $action,
        $details = ''
    ) {

        logAction(

            $action,

            $details,

            'security'
        );
    }
}

/* =========================================================
| SECURITY ALERT EMAIL
========================================================= */

if (!function_exists('sendSecurityAlertEmail')) {

    function sendSecurityAlertEmail(
        $subject,
        $message
    ) {

        try {

            $adminEmail =
                'admin@gmail.com';

            $headers =
                "MIME-Version: 1.0\r\n";

            $headers .=
                "Content-type:text/html;charset=UTF-8\r\n";

            $headers .=
                "From: SECURITY SYSTEM <noreply@system.com>\r\n";

            $html = "

            <div style='
                font-family:Arial;
                padding:20px;
                background:#f8fafc;
            '>

                <div style='
                    background:#0f172a;
                    color:white;
                    padding:20px;
                    border-radius:12px;
                '>

                    <h2>
                        🔐 Alerte Sécurité
                    </h2>

                    <div style='
                        margin-top:20px;
                        line-height:1.8;
                    '>

                        ".nl2br(
                            htmlspecialchars($message)
                        )."

                    </div>

                </div>

            </div>
            ";

            @mail(

                $adminEmail,

                $subject,

                $html,

                $headers
            );

        } catch (Exception $e) {

            error_log($e->getMessage());
        }
    }
}

/* =========================================================
| LOGIN SECURITY SYSTEM
========================================================= */

/* BLOCK CHECK */

if (!function_exists('isBlocked')) {

    function isBlocked($email)
    {
        if (
            !isset(
                $_SESSION['login_attempts'][$email]
            )
        ) {

            return false;
        }

        $data =
            $_SESSION['login_attempts'][$email];

        if ($data['count'] >= 3) {

            if (
                time() - $data['time']
                < 60
            ) {

                return true;
            }

            unset(
                $_SESSION['login_attempts'][$email]
            );
        }

        return false;
    }
}

/* ADD ATTEMPT */

if (!function_exists('addLoginAttempt')) {

    function addLoginAttempt($email)
    {
        if (
            !isset(
                $_SESSION['login_attempts'][$email]
            )
        ) {

            $_SESSION['login_attempts'][$email] = [

                'count' => 0,

                'time' => time()
            ];
        }

        $_SESSION['login_attempts'][$email]['count']++;

        $_SESSION['login_attempts'][$email]['time'] =
            time();

        if (
            $_SESSION['login_attempts'][$email]['count']
            >= 3
        ) {

            logSecurity(

                "LOGIN_BLOCK",

                "Blocage automatique : ".$email
            );
        }
    }
}

/* =========================================================
| GET LOGIN ATTEMPTS
========================================================= */

if (!function_exists('getLoginAttempts')) {

    function getLoginAttempts($email)
    {
        if (
            !isset($_SESSION['login_attempts'])
        ) {

            $_SESSION['login_attempts'] = [];
        }

        if (
            !isset(
                $_SESSION['login_attempts'][$email]
            )
        ) {

            return [

                'count' => 0,

                'time' => 0
            ];
        }

        return
            $_SESSION['login_attempts'][$email];
    }
}

/* =========================================================
| AUTO SESSION TIMEOUT
========================================================= */

if (isLoggedIn()) {

    if (!isset($_SESSION['last_activity'])) {

        $_SESSION['last_activity'] = time();
    }

    if (

        time() - $_SESSION['last_activity']
        > 900

    ) {

        logSecurity(

            "TIMEOUT",

            "Session expirée"
        );

        session_unset();

        session_destroy();

        header("Location: login.php?timeout=1");

        exit;
    }

    $_SESSION['last_activity'] = time();
}

/* =========================================================
| SAFE JSON RESPONSE
========================================================= */

if (!function_exists('jsonResponse')) {

    function jsonResponse(
        $data,
        $code = 200
    ) {

        http_response_code($code);

        header('Content-Type: application/json');

        echo json_encode($data);

        exit;
    }
}

/* =========================================================
| FORMAT MONEY
========================================================= */

if (!function_exists('money')) {

    function money($amount)
    {
        return number_format(

            (float)$amount,

            2,

            '.',

            ' '
        );
    }
}

/* =========================================================
| RANDOM TOKEN
========================================================= */

if (!function_exists('randomToken')) {

    function randomToken($length = 32)
    {
        return bin2hex(

            random_bytes($length)
        );
    }
}

/* =========================================================
| DONE
========================================================= */

