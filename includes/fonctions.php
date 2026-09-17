
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
        $user = $_SESSION['user'] ?? null;

        if (!$user) {
            return null;
        }

        $activeMagasinId = (int)($_SESSION['magasin_actif'] ?? 0);

        if (
            $activeMagasinId > 0
            && ($user['role'] ?? '') === 'admin'
        ) {
            $user['magasin_id'] = $activeMagasinId;
        }

        return $user;
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

    function denyClientBackofficeAccess()
    {
        http_response_code(403);
        echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta http-equiv="refresh" content="3;url=index.php"><title>Accès refusé</title></head><body style="font-family:Arial,sans-serif;padding:40px;text-align:center;background:#f6f7f2;color:#17221b"><h1>⛔ Accès refusé</h1><p>Votre compte client ne peut pas accéder à cet espace.</p><p>Redirection vers la boutique dans 3 secondes...</p><a href="index.php">Retour immédiat à la boutique</a><script>setTimeout(function(){ window.location.replace("index.php"); }, 3000);</script></body></html>';
        exit;
    }

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

            if (($user['role'] ?? '') === 'client') {
                denyClientBackofficeAccess();
            }

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

if (!function_exists('isAdmin')) {

    function isAdmin()
    {
        return (currentUser()['role'] ?? '') === 'admin';
    }
}

if (!function_exists('isCaissier')) {

    function isCaissier()
    {
        return (currentUser()['role'] ?? '') === 'caissier';
    }
}

if (!function_exists('canAccessMagasin')) {

    function canAccessMagasin($magasinId)
    {
        $user = currentUser();

        if (!$user || (int)$magasinId <= 0) {
            return false;
        }

        if (($user['role'] ?? '') === 'admin') {
            return true;
        }

        return (int)($user['magasin_id'] ?? 0) === (int)$magasinId;
    }
}

if (!function_exists('currentOpenCaisseId')) {

    function currentCaisseId($magasinId = null)
    {
        global $pdo;

        $magasinId = $magasinId ?? currentMagasinId();
        $selectedId = (int)($_SESSION['caisse_active'] ?? 0);

        if ((int)$magasinId <= 0) {
            return null;
        }

        if ($selectedId > 0) {
            $stmt = $pdo->prepare("SELECT id FROM caisses WHERE id=? AND magasin_id=? AND statut='active' LIMIT 1");
            $stmt->execute([$selectedId, (int)$magasinId]);

            if ($stmt->fetchColumn()) {
                return $selectedId;
            }
        }

        $stmt = $pdo->prepare("SELECT id FROM caisses WHERE magasin_id=? AND statut='active' ORDER BY id ASC LIMIT 1");
        $stmt->execute([(int)$magasinId]);
        $id = $stmt->fetchColumn();

        if ($id === false) {
            return null;
        }

        $_SESSION['caisse_active'] = (int)$id;
        return (int)$id;
    }

    function setCaisseActive($caisseId, $magasinId = null)
    {
        global $pdo;

        $magasinId = $magasinId ?? currentMagasinId();
        $stmt = $pdo->prepare("SELECT id FROM caisses WHERE id=? AND magasin_id=? AND statut='active' LIMIT 1");
        $stmt->execute([(int)$caisseId, (int)$magasinId]);

        if (!$stmt->fetchColumn()) {
            return false;
        }

        $_SESSION['caisse_active'] = (int)$caisseId;
        return true;
    }

    function currentOpenCaisseId($utilisateurId = null, $magasinId = null)
    {
        global $pdo;

        $magasinId = $magasinId ?? currentMagasinId();
        $caisseId = currentCaisseId($magasinId);

        if ((int)$caisseId <= 0 || (int)$magasinId <= 0) {
            return null;
        }

        $stmt = $pdo->prepare("SELECT id FROM sessions_caisse WHERE caisse_id=? AND magasin_id=? AND statut='ouverte' LIMIT 1");
        $stmt->execute([(int)$caisseId, (int)$magasinId]);

        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }
}

if (!function_exists('requireMagasinAccess')) {

    function requireMagasinAccess($magasinId)
    {
        requireLogin();

        if (!canAccessMagasin($magasinId)) {
            http_response_code(403);
            exit('Accès magasin refusé');
        }
    }
}

if (!function_exists('getUserMagasins')) {

    function getUserMagasins()
    {
        global $pdo;

        $user = currentUser();

        if (!$user) {
            return [];
        }

        if (($user['role'] ?? '') === 'admin') {
            $stmt = $pdo->query("SELECT * FROM magasins WHERE statut='actif' ORDER BY nom ASC");
            return $stmt->fetchAll();
        }

        $stmt = $pdo->prepare("SELECT * FROM magasins WHERE id=? AND statut='actif' LIMIT 1");
        $stmt->execute([(int)($user['magasin_id'] ?? 0)]);

        return $stmt->fetchAll();
    }
}

/* =========================================================
| MULTI MAGASIN
========================================================= */

if (!function_exists('currentMagasinId')) {

    function currentMagasinId()
    {
        $activeId = (int)($_SESSION['magasin_actif'] ?? 0);

        if ($activeId > 0 && canAccessMagasin($activeId)) {
            return $activeId;
        }

        return (int)($_SESSION['user']['magasin_id'] ?? 0);
    }
}

if (!function_exists('setMagasinActif')) {

    function setMagasinActif($magasinId)
    {
        if (!canAccessMagasin($magasinId)) {
            return false;
        }

        $_SESSION['magasin_actif'] = (int)$magasinId;

        return true;
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

if (!function_exists('consumeProductLots')) {

    function consumeProductLots(PDO $pdo, int $produitId, int $magasinId, float $quantity, int $ligneVenteId = 0): void
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Quantité de lot invalide.');
        }

        $stmt = $pdo->prepare("SELECT id, quantite_restante, prix_achat FROM lots_produits WHERE produit_id=? AND magasin_id=? AND quantite_restante > 0 AND (date_expiration IS NULL OR date_expiration >= CURDATE()) AND statut='actif' ORDER BY date_expiration IS NULL, date_expiration, date_reception, id FOR UPDATE");
        $stmt->execute([$produitId, $magasinId]);
        $lots = $stmt->fetchAll();
        $remaining = $quantity;

        foreach ($lots as $lot) {
            if ($remaining <= 0.000001) {
                break;
            }

            $available = (float)$lot['quantite_restante'];
            $taken = min($available, $remaining);
            $newQuantity = $available - $taken;
            $status = $newQuantity <= 0.000001 ? 'epuise' : 'actif';

            $update = $pdo->prepare('UPDATE lots_produits SET quantite_restante=?, statut=? WHERE id=? AND quantite_restante>=?');
            $update->execute([$newQuantity, $status, (int)$lot['id'], $taken]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Le lot produit n’a pas pu être réservé.');
            }

            if ($ligneVenteId > 0) {
                $allocation = $pdo->prepare('INSERT INTO ligne_vente_lots (ligne_vente_id, lot_id, quantite, prix_achat) VALUES (?, ?, ?, ?)');
                $allocation->execute([$ligneVenteId, (int)$lot['id'], $taken, (float)$lot['prix_achat']]);
            }
            $remaining -= $taken;
        }

        if ($remaining > 0.000001) {
            throw new RuntimeException('Stock par lots insuffisant ou expiré.');
        }
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

