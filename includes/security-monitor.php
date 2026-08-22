<?php

/*
|==========================================================================
| SECURITY MONITOR PRO
|==========================================================================
| ✔ Surveillance sessions
| ✔ Détection multi connexions
| ✔ Alertes email admin
| ✔ Mise à jour activité
| ✔ Détection IP suspectes
| ✔ Logs sécurité
| ✔ Sessions expirées
| ✔ Protection session hijacking
| ✔ Anti blacklist
| ✔ Optimisé DB
| ✔ Compatible Multi Magasin
| ✔ Compatible avec votre ancien code
|==========================================================================
*/

/* =========================
   SAFE SESSION
========================= */
if(session_status() === PHP_SESSION_NONE){

    session_start();
}

/* =========================
   REQUIRE FILES
========================= */

require_once dirname(__DIR__).'/config-settings.php';
/* =========================
   CHECK PDO
========================= */

if(!isset($pdo)){

    return;
}

/* =========================
   USER CONNECTED ?
========================= */

if(!isset($_SESSION['user'])){

    return;
}

/* =========================
   VARIABLES
========================= */

$user = $_SESSION['user'];

$userId =
    $user['id'] ?? null;

$userEmail =
    $user['email'] ?? 'UNKNOWN';

$userNom =
    $user['nom'] ?? 'Utilisateur';

$magasinId =
    $user['magasin_id'] ?? null;

$role =
    $user['role'] ?? 'user';

$ip =
    getClientIp();

$userAgent =
    $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';

$sessionId =
    session_id();

/* =========================
   DEVICE HASH
========================= */

$deviceHash = hash(
    'sha256',
    $userAgent.$ip
);

/* =========================
   SAVE DEVICE
========================= */

if(!isset($_SESSION['device'])){

    $_SESSION['device'] = $deviceHash;
}

/* =========================
    LAST ACTIVITY
========================= */

if(!isset($_SESSION['last_activity'])){

    $_SESSION['last_activity'] = time();
}

/* =========================
   UPDATE ACTIVITY
========================= */

$_SESSION['last_activity'] = time();

/* =========================
   SESSION HIJACKING
========================= */

if($_SESSION['device'] !== $deviceHash){

    /*
    |-----------------------------------
    | LOG
    |-----------------------------------
    */

    logSecurity(

        "SESSION_HIJACK",

        "Tentative hijacking détectée : ".$userEmail
    );

    /*
    |-----------------------------------
    | EMAIL ALERT
    |-----------------------------------
    */

    sendSecurityAlertEmail(

        "Tentative Session Hijacking",

        "
        Utilisateur : ".$userNom."

        Email : ".$userEmail."

        Magasin ID : ".$magasinId."

        IP : ".$ip."

        Date : ".date('Y-m-d H:i:s')."

        User-Agent :
        ".$userAgent."
        "
    );

    /*
    |-----------------------------------
    | DESTROY SESSION
    |-----------------------------------
    */

    session_unset();

    session_destroy();

    die("
        <div style='
            padding:30px;
            font-family:Arial;
            color:red;
        '>

            ⛔ Session invalide détectée

        </div>
    ");
}

/* =========================
   CHECK BLACKLIST
========================= */

try{

    $stmt = $pdo->prepare("
        SELECT id
        FROM blacklist_ip
        WHERE ip=?
        LIMIT 1
    ");

    $stmt->execute([$ip]);

    $blacklisted =
        $stmt->fetch();

    if($blacklisted){

        logSecurity(

            "BLACKLIST_ACCESS",

            "Tentative accès IP blacklistée : ".$ip
        );

        die("
            <div style='
                padding:30px;
                font-family:Arial;
                color:red;
            '>

                ⛔ Votre IP a été blacklistée

            </div>
        ");
    }

}catch(Exception $e){

    error_log($e->getMessage());
}

/* =========================
   CHECK EXISTING SESSION
========================= */

$stmt = $pdo->prepare("
    SELECT id
    FROM connexions_utilisateurs
    WHERE session_id=?
    LIMIT 1
");

$stmt->execute([

    $sessionId
]);

$existingSession =
    $stmt->fetch();

/* =========================
   UPDATE SESSION
========================= */

if($existingSession){

    $stmt = $pdo->prepare("
        UPDATE connexions_utilisateurs
        SET
            derniere_activite=NOW(),
            statut='active'
        WHERE id=?
    ");

    $stmt->execute([

        $existingSession['id']
    ]);

}else{

    /*
    |-----------------------------------
    | INSERT SESSION
    |-----------------------------------
    */

    $stmt = $pdo->prepare("
        INSERT INTO connexions_utilisateurs
        (
            utilisateur_id,
            magasin_id,
            ip,
            user_agent,
            session_id,
            statut,
            created_at,
            derniere_activite
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            ?,
            'active',
            NOW(),
            NOW()
        )
    ");

    $stmt->execute([

        $userId,
        $magasinId,
        $ip,
        $userAgent,
        $sessionId
    ]);

    /*
    |-----------------------------------
    | SECURITY LOG
    |-----------------------------------
    */

    logSecurity(

        "NEW_SESSION",

        "Nouvelle session : ".$userEmail
    );
}

/* =========================
   CLEAN OLD SESSIONS
========================= */

try{

    $pdo->query("
        UPDATE connexions_utilisateurs
        SET statut='expiree'
        WHERE derniere_activite <
        NOW() - INTERVAL 30 MINUTE
    ");

}catch(Exception $e){

    error_log($e->getMessage());
}

/* =========================
   MULTI CONNECTION
========================= */

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM connexions_utilisateurs
    WHERE utilisateur_id=?
    AND statut='active'
    AND derniere_activite >=
    NOW() - INTERVAL 10 MINUTE
");

$stmt->execute([

    $userId
]);

$activeSessions =
    (int)$stmt->fetchColumn();

/* =========================
   MULTI SESSION ALERT
========================= */

if($activeSessions > 2){

    $message =
        "Multi connexion détectée
        pour utilisateur ".$userEmail;

    /*
    |-----------------------------------
    | SAVE ALERT
    |-----------------------------------
    */

    try{

        $stmt = $pdo->prepare("
            INSERT INTO alertes_securite
            (
                utilisateur_id,
                type,
                message,
                ip,
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

            $userId,
            'MULTI_CONNEXION',
            $message,
            $ip
        ]);

    }catch(Exception $e){

        error_log($e->getMessage());
    }

    /*
    |-----------------------------------
    | LOG
    |-----------------------------------
    */

    logSecurity(

        "MULTI_CONNEXION",

        $message
    );

    /*
    |-----------------------------------
    | EMAIL
    |-----------------------------------
    */

    sendSecurityAlertEmail(

        "ALERTE MULTI CONNEXION",

        "
        Utilisateur :
        ".$userEmail."

        Sessions actives :
        ".$activeSessions."

        IP :
        ".$ip."

        Date :
        ".date('Y-m-d H:i:s')."
        "
    );
}

/* =========================
   TOO MANY IPS
========================= */

$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT ip)
    FROM connexions_utilisateurs
    WHERE utilisateur_id=?
    AND derniere_activite >=
    NOW() - INTERVAL 24 HOUR
");

$stmt->execute([

    $userId
]);

$ipsCount =
    (int)$stmt->fetchColumn();

/* =========================
   MULTI IP ALERT
========================= */

if($ipsCount > 5){

    logSecurity(

        "MULTI_IP",

        "Plusieurs IP détectées : ".$userEmail
    );

    sendSecurityAlertEmail(

        "ALERTE MULTI IP",

        "
        Utilisateur :
        ".$userEmail."

        Nombre IP :
        ".$ipsCount."

        Dernière IP :
        ".$ip."
        "
    );
}

/* =========================
   SUSPICIOUS IPS
========================= */

$suspiciousIps = [

    '127.0.0.2',
    '10.0.0.1'
];

/* =========================
   DETECT SUSPICIOUS IP
========================= */

if(in_array($ip, $suspiciousIps)){

    logSecurity(

        "SUSPICIOUS_IP",

        "IP suspecte détectée : ".$ip
    );

    sendSecurityAlertEmail(

        "IP SUSPECTE DETECTEE",

        "
        Utilisateur :
        ".$userEmail."

        IP :
        ".$ip."

        Date :
        ".date('Y-m-d H:i:s')."
        "
    );
}

/* =========================
   AUTO BLACKLIST
========================= */

try{

    $stmt = $pdo->prepare("
        SELECT tentatives
        FROM securite_login
        WHERE email=?
        LIMIT 1
    ");

    $stmt->execute([

        $userEmail
    ]);

    $loginData =
        $stmt->fetch();

    if(

        $loginData
        &&
        $loginData['tentatives'] >= 10
    ){

        /*
        |-----------------------------------
        | INSERT BLACKLIST
        |-----------------------------------
        */

        $stmt = $pdo->prepare("
            INSERT IGNORE INTO blacklist_ip
            (
                ip,
                raison,
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

            $ip,
            'Trop de tentatives'
        ]);

        /*
        |-----------------------------------
        | LOG
        |-----------------------------------
        */

        logSecurity(

            "BLACKLIST_IP",

            "IP blacklistée : ".$ip
        );
    }

}catch(Exception $e){

    error_log($e->getMessage());
}

/* =========================
   LIVE ACTIVITY
========================= */

if(!isset($_SESSION['last_live_activity'])){

    $_SESSION['last_live_activity'] = 0;
}

/* =========================
   SAVE LIVE ACTIVITY
========================= */

if(

    time() - $_SESSION['last_live_activity']
    > 60
){

    try{

        $stmt = $pdo->prepare("
            INSERT INTO activites_live
            (
                utilisateur_id,
                magasin_id,
                action,
                ip,
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

            $userId,
            $magasinId,
            'Navigation système',
            $ip
        ]);

        $_SESSION['last_live_activity'] = time();

    }catch(Exception $e){

        error_log($e->getMessage());
    }
}

/* =========================
   SECURITY HEARTBEAT
========================= */

if(!isset($_SESSION['last_security_check'])){

    $_SESSION['last_security_check'] = 0;
}

if(

    time() - $_SESSION['last_security_check']
    > 300
){

    logSecurity(

        "SECURITY_HEARTBEAT",

        "Vérification sécurité automatique"
    );

    $_SESSION['last_security_check'] = time();
}

/* =========================
   DONE
========================= */
?>