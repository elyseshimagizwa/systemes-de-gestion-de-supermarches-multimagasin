<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config-settings.php';

requireLogin();

$user       = currentUser();
$settings   = getSettings();

/* =========================================================
   SECURITE HEADER
========================================================= */

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
header("X-XSS-Protection: 1; mode=block");

/* =========================================================
   UPDATE ACTIVITY
========================================================= */

updateUserActivity();

/* =========================================================
   USER PROFILE
========================================================= */

$profil = [];

try {

    $stmtProfil = $pdo->prepare("
        SELECT *
        FROM profils
        WHERE utilisateur_id = ?
        LIMIT 1
    ");

    $stmtProfil->execute([
        $user['id']
    ]);

    $profil = $stmtProfil->fetch();

} catch(Exception $e){

    $profil = [];
}

/* =========================================================
   PHOTO PROFIL
========================================================= */

$userPhoto =
    $profil['photo'] ?? '';

/* =========================================================
   USER INITIAL
========================================================= */

$userInitial =
    strtoupper(
        substr(
            $user['nom'] ?? 'U',
            0,
            1
        )
    );

/* =========================================================
   MAGASINS
========================================================= */

$magasins = [];

try{

    if($user['role'] === 'admin'){

        $stmtMagasins = $pdo->query("
            SELECT
                id,
                nom,
                adresse
            FROM magasins
            ORDER BY nom ASC
        ");

        $magasins = $stmtMagasins->fetchAll();

    }else{

        $stmtMagasins = $pdo->prepare("
            SELECT
                id,
                nom,
                adresse
            FROM magasins
            WHERE id = ?
            LIMIT 1
        ");

        $stmtMagasins->execute([
            $user['magasin_id']
        ]);

        $magasins = $stmtMagasins->fetchAll();
    }

}catch(Exception $e){

    $magasins = [];
}

/* =========================================================
   MAGASIN ACTUEL
========================================================= */

$currentMagasinId =
    $user['magasin_id'] ?? null;

$currentMagasinNom =
    'Aucun magasin';

$currentMagasinAdresse =
    '';

foreach($magasins as $m){

    if($m['id'] == $currentMagasinId){

        $currentMagasinNom =
            $m['nom'];

        $currentMagasinAdresse =
            $m['adresse'] ?? '';
    }
}

/* =========================================================
   NOTIFICATIONS STOCK
========================================================= */

$notifications = [];

try{

    if($currentMagasinId){

        $notif = $pdo->prepare("
            SELECT
                nom,
                quantite,
                seuil_alerte
            FROM produits
            WHERE magasin_id = ?
            AND quantite <= seuil_alerte
            ORDER BY quantite ASC
            LIMIT 5
        ");

        $notif->execute([
            $currentMagasinId
        ]);

        $notifications =
            $notif->fetchAll();
    }

}catch(Exception $e){

    $notifications = [];
}

/* =========================================================
   ALERTES SECURITE
========================================================= */

$securityAlerts = [];

try{

    $stmtSec = $pdo->prepare("
        SELECT
            type,
            message,
            created_at
        FROM securite_logs
        ORDER BY id DESC
        LIMIT 5
    ");

    $stmtSec->execute();

    $securityAlerts =
        $stmtSec->fetchAll();

}catch(Exception $e){

    $securityAlerts = [];
}

/* =========================================================
   TOTAL ALERTES
========================================================= */

$totalAlertes =
    count($notifications);

/* =========================================================
   TOTAL ALERTES SECURITE
========================================================= */

$totalSecurityAlerts =
    count($securityAlerts);

/* =========================================================
   DERNIERE ACTIVITE
========================================================= */

$lastActivity =
    $_SESSION['last_activity'] ?? time();

$minutesInactive =
    floor(
        (time() - $lastActivity)
        / 60
    );

/* =========================================================
   SESSION TOKEN
========================================================= */

$sessionToken =
    substr(
        $_SESSION['session_token'] ?? '',
        0,
        12
    );

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

</title>

<script src="https://cdn.tailwindcss.com"></script>

<link
rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
/>

<script>

tailwind.config = {

    darkMode:'class'
}

</script>

<style>

*{
    scroll-behavior:smooth;
}

body{
    font-family:
    Inter,
    Arial,
    sans-serif;
}

/* =========================================
   BACKGROUND
========================================= */

body{

    background:
    linear-gradient(
        135deg,
        #f8fafc 0%,
        #eef2ff 50%,
        #f1f5f9 100%
    );
}

.dark body{

    background:
    linear-gradient(
        135deg,
        #020617 0%,
        #0f172a 50%,
        #111827 100%
    );
}

/* =========================================
   TOPBAR
========================================= */

.topbar-glass{

    background:
    rgba(255,255,255,.85);

    backdrop-filter:
    blur(16px);

    border-bottom:
    1px solid rgba(255,255,255,.25);

    box-shadow:
    0 10px 30px rgba(0,0,0,.06);
}

.dark .topbar-glass{

    background:
    rgba(15,23,42,.92);

    border-bottom:
    1px solid rgba(255,255,255,.05);
}
/* =========================================
   SMART SEARCH
========================================= */

.search-item{

    display:flex;
    align-items:center;
    gap:14px;

    padding:14px 18px;

    transition:.25s ease;

    cursor:pointer;

    border-bottom:1px solid #f1f5f9;
}

.dark .search-item{

    border-bottom:1px solid #1e293b;
}

.search-item:hover{

    background:#eff6ff;
}

.dark .search-item:hover{

    background:#1e293b;
}

.search-icon{

    width:45px;
    height:45px;

    border-radius:14px;

    display:flex;
    align-items:center;
    justify-content:center;

    font-size:18px;

    flex-shrink:0;
}

.search-title{

    font-weight:700;
    font-size:14px;
}

.search-sub{

    font-size:12px;
    color:#64748b;
}

.search-category{

    font-size:11px;

    padding:4px 10px;

    border-radius:999px;

    background:#dbeafe;

    color:#1d4ed8;

    font-weight:700;
}
/* =========================================
   SEARCH
========================================= */

.search-box{

    background:white;

    border:1px solid #e2e8f0;

    transition:.25s ease;

    box-shadow:
    0 4px 15px rgba(0,0,0,.04);
}

.dark .search-box{

    background:#0f172a;

    border-color:#334155;

    color:white;
}

.search-box:focus{

    outline:none;

    border-color:#3b82f6;

    box-shadow:
    0 0 0 5px rgba(59,130,246,.12);
}

/* =========================================
   ICON BTN
========================================= */

.icon-btn{

    width:46px;

    height:46px;

    border-radius:16px;

    display:flex;

    align-items:center;

    justify-content:center;

    transition:.25s ease;

    background:white;

    box-shadow:
    0 4px 15px rgba(0,0,0,.06);

    position:relative;
}

.dark .icon-btn{

    background:#1e293b;

    color:white;
}

.icon-btn:hover{

    transform:translateY(-2px);

    background:#dbeafe;
}

.dark .icon-btn:hover{

    background:#334155;
}

/* =========================================
   BADGE
========================================= */

.badge{

    position:absolute;

    top:-5px;
    right:-5px;

    background:#ef4444;

    color:white;

    min-width:18px;
    height:18px;

    border-radius:999px;

    font-size:10px;

    font-weight:bold;

    display:flex;

    align-items:center;
    justify-content:center;
}

/* =========================================
   MAGASIN CARD
========================================= */

.magasin-card{

    background:
    linear-gradient(
        135deg,
        #2563eb,
        #1d4ed8
    );

    color:white;

    border-radius:20px;

    padding:10px 18px;

    box-shadow:
    0 12px 30px rgba(37,99,235,.25);
}

/* =========================================
   USER CARD
========================================= */

.user-card{

    background:
    linear-gradient(
        135deg,
        #56627d,
        #1e293b
    );

    color:white;

    border-radius:18px;

    padding:8px 14px;

    box-shadow:
    0 10px 25px rgba(64, 81, 211, 0.18);

    position:relative;
}

.user-card:hover .profile-dropdown{

    display:block;
}

/* =========================================
   PROFILE
========================================= */

.profile-photo{

    width:45px;
    height:45px;

    border-radius:999px;

    object-fit:cover;

    border:2px solid rgba(255,255,255,.3);
}

.profile-avatar{

    width:45px;
    height:45px;

    border-radius:999px;

    display:flex;

    align-items:center;
    justify-content:center;

    background:
    rgba(255,255,255,.15);

    font-weight:bold;

    font-size:18px;
}

/* =========================================
   DROPDOWN
========================================= */

.profile-dropdown,
#notifBox,
#securityBox{

    display:none;

    position:absolute;

    right:0;

    width:340px;

    background:white;

    border-radius:24px;

    overflow:hidden;

    box-shadow:
    0 20px 40px rgba(0,0,0,.18);

    z-index:999;
}

.dark .profile-dropdown,
.dark #notifBox,
.dark #securityBox{

    background:#0f172a;

    border:1px solid #334155;
}

.profile-dropdown{

    top:72px;
    width:280px;
}

#notifBox{

    top:62px;
}

#securityBox{

    top:62px;
}

/* =========================================
   LINKS
========================================= */

.profile-link{

    display:flex;

    align-items:center;

    gap:12px;

    padding:14px 18px;

    text-decoration:none;

    transition:.25s ease;
}

.profile-link:hover{

    background:#f1f5f9;
}

.dark .profile-link:hover{

    background:#1e293b;
}

/* =========================================
   SECURITY STATUS
========================================= */

.security-safe{

    background:
    linear-gradient(
        135deg,
        #16a34a,
        #15803d
    );

    color:white;

    border-radius:16px;

    padding:10px 14px;
}

/* =========================================
   FULLSCREEN BADGE
========================================= */

.full-badge{

    position:fixed;

    bottom:20px;

    right:20px;

    background:#16a34a;

    color:white;

    padding:12px 18px;

    border-radius:16px;

    display:none;

    z-index:9999;

    box-shadow:
    0 10px 25px rgba(0,0,0,.2);
}

::-webkit-scrollbar{

    width:8px;
}

::-webkit-scrollbar-thumb{

    background:#94a3b8;

    border-radius:999px;
}

.dark ::-webkit-scrollbar-thumb{

    background:#475569;
}

</style>

</head>

<body class="text-slate-800 dark:text-white">

<div class="flex min-h-screen flex-col">

<!-- =========================================
     HEADER
========================================= -->

<header class="topbar-glass sticky top-0 z-40 px-4 py-3 md:ml-64">

<div class="flex items-center justify-between gap-4">

    <!-- LEFT -->

    <div class="flex items-center gap-4">

        <!-- MOBILE -->

        <button
            onclick="toggleSidebar()"
            class="md:hidden icon-btn text-lg"
        >

            ☰

        </button>

        <!-- LOGO -->

        <div class="flex items-center gap-3">

            <?php if(!empty($settings['logo'])): ?>

            <img
                src="<?= e($settings['logo']) ?>"
                class="w-12 h-12 rounded-2xl object-cover shadow-xl"
            >

            <?php else: ?>

            <div class="w-12 h-12 rounded-2xl bg-blue-600 text-white flex items-center justify-center shadow-xl">

                <i class="fa-solid fa-store text-xl"></i>

            </div>

            <?php endif; ?>

            <div>

                <div class="font-black text-lg leading-none">

                    <?= e($settings['nom_boutique'] ?? 'POS PREMIUM') ?>

                </div>

                <div class="text-xs text-slate-500 dark:text-slate-400 mt-1">

                    

                </div>

            </div>

        </div>

        <!-- SEARCH -->

        <<!-- SEARCH INTELLIGENTE -->

<div class="hidden lg:block relative">

    <div class="relative">

        <input
            type="text"
            id="globalSearch"
            autocomplete="off"
            placeholder="🔎 Rechercher produit, utilisateur, fournisseur..."
            class="search-box px-5 py-3 rounded-2xl w-[420px] pr-14"
        >

        <button
            id="searchBtn"
            class="absolute right-2 top-1/2 -translate-y-1/2
                   bg-blue-600 hover:bg-blue-700
                   text-white w-10 h-10 rounded-xl
                   transition"
        >

            <i class="fa fa-search"></i>

        </button>

    </div>

    <!-- RESULTATS -->

    <div
        id="searchResults"
        class="absolute top-full left-0 mt-3 w-full
               bg-white dark:bg-slate-900
               rounded-2xl shadow-2xl
               border border-slate-200 dark:border-slate-700
               overflow-hidden hidden z-[9999]"
    >

        <!-- HEADER -->

        <div class="p-4 border-b dark:border-slate-700">

            <div class="font-bold text-sm">

                Résultats de recherche

            </div>

        </div>

        <!-- CONTENT -->

        <div
            id="searchContent"
            class="max-h-[500px] overflow-y-auto"
        >

        </div>

    </div>

</div>

    </div>

    <!-- RIGHT -->

    <div class="flex items-center gap-3">

        <!-- CLOCK -->

        <div
            id="clock"
            class="hidden md:flex items-center gap-2 bg-white dark:bg-slate-900 px-4 py-3 rounded-2xl shadow"
        >

            <i class="fa-regular fa-clock"></i>

            <span></span>

        </div>

        <!-- SECURITY STATUS -->

        <div class="hidden 2xl:flex items-center gap-3 security-safe">

            <i class="fa-solid fa-shield-halved text-xl"></i>

            <div>

                <div class="font-bold text-sm">

                    Session sécurisée

                </div>

                <div class="text-xs opacity-80">

                    <?= $minutesInactive ?> min activité

                </div>

            </div>

        </div>

        <!-- MAGASIN -->

        <div class="hidden xl:flex items-center gap-3 magasin-card">

            <div class="text-2xl">

                🏬

            </div>

            <div>

                <div class="font-bold text-sm">

                    <?= e($currentMagasinNom) ?>

                </div>

                <div class="text-xs opacity-80">

                    <?= e($currentMagasinAdresse) ?>

                </div>

            </div>

        </div>

        <!-- SWITCH MAGASIN -->

        <?php if($user['role'] === 'admin'): ?>

        <select
            onchange="changeMagasin(this.value)"
            class="hidden xl:block bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 px-4 py-3 rounded-2xl shadow"
        >

            <?php foreach($magasins as $mag): ?>

            <option
                value="<?= $mag['id'] ?>"
                <?= $mag['id'] == $currentMagasinId ? 'selected' : '' ?>
            >

                🏬 <?= e($mag['nom']) ?>

            </option>

            <?php endforeach; ?>

        </select>

        <?php endif; ?>

        <!-- FULLSCREEN -->

        <button
            onclick="toggleFullscreen()"
            class="icon-btn"
            id="fullscreenBtn"
            title="Plein écran"
        >

            <i class="fa-solid fa-expand"></i>

        </button>

        <!-- DARK -->

        <button
            onclick="toggleDark()"
            class="icon-btn"
            id="darkBtn"
        >

            🌙

        </button>

        <!-- SECURITY -->

        <div class="relative">

            <button
                onclick="toggleSecurity()"
                class="icon-btn"
            >

                🛡️

                <?php if($totalSecurityAlerts > 0): ?>

                <span class="badge">

                    <?= $totalSecurityAlerts ?>

                </span>

                <?php endif; ?>

            </button>

            <div id="securityBox">

                <div class="p-4 border-b dark:border-slate-700 font-bold text-lg">

                    🛡️ Sécurité système

                </div>

                <div class="p-4 border-b dark:border-slate-700 text-sm">

                    <div class="mb-2">

                        <strong>IP :</strong>
                        <?= e($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN') ?>

                    </div>

                    <div class="mb-2">

                        <strong>Session :</strong>
                        <?= e($sessionToken) ?>...

                    </div>

                    <div>

                        <strong>Activité :</strong>
                        <?= $minutesInactive ?> min
                    </div>

                </div>

                <?php if($securityAlerts): ?>

                    <?php foreach($securityAlerts as $s): ?>

                    <div class="p-4 border-b dark:border-slate-700">

                        <div class="font-semibold text-red-500">

                            <?= e($s['type']) ?>

                        </div>

                        <div class="text-sm mt-1">

                            <?= e($s['message']) ?>

                        </div>

                        <div class="text-xs text-slate-500 mt-2">

                            <?= e($s['created_at']) ?>

                        </div>

                    </div>

                    <?php endforeach; ?>

                <?php else: ?>

                <div class="p-6 text-center text-slate-500">

                    ✅ Aucun incident sécurité

                </div>

                <?php endif; ?>

            </div>

        </div>

        <!-- NOTIFICATIONS -->

        <div class="relative">

            <button
                onclick="toggleNotif()"
                class="icon-btn relative"
            >

                🔔

                <?php if($totalAlertes > 0): ?>

                <span class="badge">

                    <?= $totalAlertes ?>

                </span>

                <?php endif; ?>

            </button>

            <!-- BOX -->

            <div id="notifBox">

                <div class="p-4 border-b dark:border-slate-700 font-bold text-lg">

                    🔔 Notifications magasin

                </div>

                <?php if($notifications): ?>

                    <?php foreach($notifications as $n): ?>

                    <div class="p-4 border-b dark:border-slate-700 flex gap-3">

                        <div class="w-11 h-11 rounded-xl bg-red-100 text-red-600 flex items-center justify-center">

                            <i class="fa-solid fa-box"></i>

                        </div>

                        <div class="flex-1">

                            <div class="font-semibold">

                                <?= e($n['nom']) ?>

                            </div>

                            <div class="text-sm text-slate-500">

                                Stock faible :
                                <?= e($n['quantite']) ?>

                            </div>

                        </div>

                    </div>

                    <?php endforeach; ?>

                <?php else: ?>

                <div class="p-6 text-center text-slate-500">

                    ✅ Aucune alerte

                </div>

                <?php endif; ?>

            </div>

        </div>

        <!-- USER PROFILE -->

        <div class="user-card flex items-center gap-3">

            <?php if(!empty($userPhoto)): ?>

                <img
                    src="<?= e($userPhoto) ?>"
                    class="profile-photo"
                >

            <?php else: ?>

                <div class="profile-avatar">

                    <?= $userInitial ?>

                </div>

            <?php endif; ?>

            <!-- INFO -->

            <div class="hidden md:block">

                <div class="font-bold text-sm">

                    <?= e($user['nom']) ?>

                </div>

                <div class="text-xs opacity-75">

                    <?= strtoupper(e($user['role'])) ?>

                </div>

            </div>

            <!-- DROPDOWN -->

            <div class="profile-dropdown">

                <div class="p-5 border-b dark:border-slate-700">

                    <div class="flex items-center gap-3">

                        <?php if(!empty($userPhoto)): ?>

                            <img
                                src="<?= e($userPhoto) ?>"
                                class="w-14 h-14 rounded-full object-cover"
                            >

                        <?php else: ?>

                            <div class="w-14 h-14 rounded-full bg-blue-600 text-white flex items-center justify-center font-bold text-xl">

                                <?= $userInitial ?>

                            </div>

                        <?php endif; ?>

                        <div>

                            <div class="font-bold">

                                <?= e($user['nom']) ?>

                            </div>

                            <div class="text-sm text-slate-500">

                                <?= e($user['email']) ?>

                            </div>

                        </div>

                    </div>

                </div>

                <a
                    href="profile.php"
                    class="profile-link"
                >

                    <i class="fa fa-user text-blue-500"></i>

                    <span>

                        Mon profil

                    </span>

                </a>

                <a
                    href="settings.php"
                    class="profile-link"
                >

                    <i class="fa fa-gear text-gray-500"></i>

                    <span>

                        Paramètres

                    </span>

                </a>

                <a
                    href="security_logs.php"
                    class="profile-link"
                >

                    <i class="fa fa-shield-halved text-green-500"></i>

                    <span>

                        Logs sécurité

                    </span>

                </a>

                <a
                    href="logout.php"
                    class="profile-link text-red-600"
                >

                    <i class="fa fa-sign-out-alt"></i>

                    <span>

                        Déconnexion

                    </span>

                </a>

            </div>

        </div>

    </div>

</div>

</header>

<!-- CONTENT -->

<main class="flex-1 md:ml-64 p-4">

<div
    id="fullscreenBadge"
    class="full-badge"
>

    ✅ Mode plein écran activé

</div>

<script>

/* =========================================
   CLOCK
========================================= */

setInterval(()=>{

    const now = new Date();

    document.querySelector(
        "#clock span"
    ).innerText =

        now.toLocaleTimeString();

},1000);

/* =========================================
   DARK MODE
========================================= */

if(
    localStorage.getItem("theme")
    ===
    "dark"
){

    document.documentElement
    .classList.add("dark");

    document
    .getElementById("darkBtn")
    .innerHTML = "☀️";

}else{

    document
    .getElementById("darkBtn")
    .innerHTML = "🌙";
}

function toggleDark(){

    document.documentElement
    .classList.toggle("dark");

    if(
        document.documentElement
        .classList.contains("dark")
    ){

        localStorage.setItem(
            "theme",
            "dark"
        );

        document
        .getElementById("darkBtn")
        .innerHTML = "☀️";

    }else{

        localStorage.setItem(
            "theme",
            "light"
        );

        document
        .getElementById("darkBtn")
        .innerHTML = "🌙";
    }
}

/* =========================================
   NOTIFICATIONS
========================================= */

function toggleNotif(){

    let box =
        document.getElementById(
            "notifBox"
        );

    box.style.display =

        box.style.display === "block"
        ? "none"
        : "block";
}

/* =========================================
   SECURITY BOX
========================================= */

function toggleSecurity(){

    let box =
        document.getElementById(
            "securityBox"
        );

    box.style.display =

        box.style.display === "block"
        ? "none"
        : "block";
}

/* =========================================
   CLOSE BOXES
========================================= */

document.addEventListener(
    "click",
    function(e){

        let notif =
            document.getElementById(
                "notifBox"
            );

        let security =
            document.getElementById(
                "securityBox"
            );

        if(

            !e.target.closest("#notifBox")

            &&

            !e.target.closest(
                "[onclick='toggleNotif()']"
            )

        ){

            notif.style.display =
                "none";
        }

        if(

            !e.target.closest("#securityBox")

            &&

            !e.target.closest(
                "[onclick='toggleSecurity()']"
            )

        ){

            security.style.display =
                "none";
        }
    }
);

/* =========================================
   FULLSCREEN
========================================= */

function toggleFullscreen(){

    if(
        !document.fullscreenElement
    ){

        document.documentElement
        .requestFullscreen();

        document
        .getElementById(
            "fullscreenBtn"
        ).innerHTML =

        '<i class="fa-solid fa-compress"></i>';

        showFullscreenBadge();

    }else{

        if(document.exitFullscreen){

            document.exitFullscreen();
        }

        document
        .getElementById(
            "fullscreenBtn"
        ).innerHTML =

        '<i class="fa-solid fa-expand"></i>';
    }
}

/* =========================================
   BADGE
========================================= */

function showFullscreenBadge(){

    let badge =
        document.getElementById(
            "fullscreenBadge"
        );

    badge.style.display = "block";

    setTimeout(()=>{

        badge.style.display = "none";

    },2500);
}

/* =========================================
   CHANGE MAGASIN
========================================= */

function changeMagasin(id){

    if(!id) return;

    window.location.href =
        "change_magasin.php?id="
        +
        id;
}

/* =========================================
   AUTO SESSION WARNING
========================================= */

setInterval(()=>{

    console.log(
        "Session sécurisée active"
    );

},60000);
/* =========================================
   SMART SEARCH
========================================= */

const globalSearch =
    document.getElementById(
        "globalSearch"
    );

const searchResults =
    document.getElementById(
        "searchResults"
    );

const searchContent =
    document.getElementById(
        "searchContent"
    );

/* =========================================
   DATA DEMO
   (remplace plus tard par AJAX/PHP)
========================================= */

const searchDatabase = [

    /* PRODUITS */

    {
        type:"Produit",
        icon:"📦",
        color:"bg-blue-100 text-blue-600",
        title:"Ordinateur HP",
        sub:"Stock: 15",
        link:"produits.php"
    },

    {
        type:"Produit",
        icon:"📱",
        color:"bg-blue-100 text-blue-600",
        title:"Samsung Galaxy",
        sub:"Stock: 8",
        link:"produits.php"
    },

    {
        type:"Produit",
        icon:"🖨️",
        color:"bg-blue-100 text-blue-600",
        title:"Imprimante Epson",
        sub:"Stock: 4",
        link:"produits.php"
    },

    /* UTILISATEURS */

    {
        type:"Utilisateur",
        icon:"👤",
        color:"bg-green-100 text-green-600",
        title:"Admin Principal",
        sub:"admin@gmail.com",
        link:"utilisateurs.php"
    },

    {
        type:"Utilisateur",
        icon:"👨",
        color:"bg-green-100 text-green-600",
        title:"Jean Claude",
        sub:"Caissier",
        link:"utilisateurs.php"
    },

    /* FOURNISSEURS */

    {
        type:"Fournisseur",
        icon:"🚚",
        color:"bg-yellow-100 text-yellow-700",
        title:"Tech Distribution",
        sub:"Fournisseur informatique",
        link:"Fournisseurs.php"
    },

    {
        type:"Fournisseur",
        icon:"🏭",
        color:"bg-yellow-100 text-yellow-700",
        title:"Global Market",
        sub:"Import Export",
        link:"Fournisseurs.php"
    }

];

/* =========================================
   RECHERCHE LIVE
========================================= */

globalSearch.addEventListener(
    "keyup",
    function(){

        const value =
            this.value.toLowerCase().trim();

        if(value === ""){

            searchResults.classList.add(
                "hidden"
            );

            return;
        }

        const results =
            searchDatabase.filter(item =>

                item.title
                .toLowerCase()
                .includes(value)

                ||

                item.sub
                .toLowerCase()
                .includes(value)

                ||

                item.type
                .toLowerCase()
                .includes(value)
            );

        renderResults(results);
    }
);

/* =========================================
   AFFICHAGE RESULTATS
========================================= */

function renderResults(results){

    searchResults.classList.remove(
        "hidden"
    );

    if(results.length === 0){

        searchContent.innerHTML = `

            <div class="p-8 text-center text-slate-500">

                ❌ Aucun résultat trouvé

            </div>

        `;

        return;
    }

    let html = "";

    results.forEach(item => {

        html += `

            <a
                href="${item.link}"
                class="search-item"
            >

                <div class="search-icon ${item.color}">

                    ${item.icon}

                </div>

                <div class="flex-1">

                    <div class="search-title">

                        ${item.title}

                    </div>

                    <div class="search-sub">

                        ${item.sub}

                    </div>

                </div>

                <div class="search-category">

                    ${item.type}

                </div>

            </a>

        `;
    });

    searchContent.innerHTML = html;
}

/* =========================================
   CLICK OUTSIDE
========================================= */

document.addEventListener(
    "click",
    function(e){

        if(

            !e.target.closest(
                "#searchResults"
            )

            &&

            !e.target.closest(
                "#globalSearch"
            )

        ){

            searchResults.classList.add(
                "hidden"
            );
        }
    }
);

/* =========================================
   SEARCH BUTTON
========================================= */

document.getElementById(
    "searchBtn"
).addEventListener(
    "click",
    function(){

        globalSearch.focus();
    }
);

</script>