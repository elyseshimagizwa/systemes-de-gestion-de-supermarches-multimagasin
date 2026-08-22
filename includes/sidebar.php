<?php

$user = currentUser();

$isAdmin =
    ($user['role'] ?? '') === 'admin';

$isCaissier =
    ($user['role'] ?? '') === 'caissier';

$isManager =
    ($user['role'] ?? '') === 'manager';

$settings = getSettings();

/* =========================================================
   MAGASIN
========================================================= */

$magasinId =
    $user['magasin_id'] ?? 0;

$magasin = null;

if ($magasinId > 0) {

    $stmtMagasin = $pdo->prepare("
        SELECT *
        FROM magasins
        WHERE id=?
        LIMIT 1
    ");

    $stmtMagasin->execute([$magasinId]);

    $magasin = $stmtMagasin->fetch();
}

/* =========================================================
   PAGE ACTIVE
========================================================= */

$currentPage =
    basename($_SERVER['PHP_SELF']);

function isActivePage($page, $currentPage)
{
    return $page === $currentPage
        ? 'sidebar-active'
        : '';
}

?>

<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

<style>

/* =========================================================
   GLOBAL
========================================================= */

.sidebar-wrapper{

    width:290px;

    position:fixed;

    top:0;
    left:0;

    height:100vh;

    overflow:hidden;

    z-index:60;

    background:
    linear-gradient(
        180deg,
        #020617 0%,
        #0f172a 40%,
        #111827 100%
    );

    box-shadow:
    15px 0 40px rgba(0,0,0,.35);

    transition:.3s ease;
}

@media(max-width:768px){

    .sidebar-wrapper{

        transform:translateX(-100%);
    }

    .sidebar-wrapper.open{

        transform:translateX(0);
    }
}

/* =========================================================
   SCROLL
========================================================= */

.sidebar-scroll{

    height:100vh;

    overflow-y:auto;

    padding-bottom:120px;
}

.sidebar-scroll::-webkit-scrollbar{

    width:5px;
}

.sidebar-scroll::-webkit-scrollbar-thumb{

    background:#334155;

    border-radius:50px;
}

/* =========================================================
   HEADER
========================================================= */

.sidebar-header{

    padding:24px;

    border-bottom:
    1px solid rgba(255,255,255,.06);
}

.logo-box{

    width:58px;
    height:58px;

    border-radius:18px;

    overflow:hidden;

    background:white;

    display:flex;

    align-items:center;
    justify-content:center;

    box-shadow:
    0 10px 25px rgba(0,0,0,.2);
}

.brand-title{

    font-size:20px;

    font-weight:900;

    color:white;
}

.brand-sub{

    font-size:12px;

    color:#94a3b8;
}

/* =========================================================
   USER BOX
========================================================= */

.user-card{

    margin-top:20px;

    background:
    rgba(255,255,255,.05);

    border:
    1px solid rgba(255,255,255,.05);

    border-radius:22px;

    padding:18px;

    position:relative;

    overflow:hidden;
}

.user-card::before{

    content:'';

    position:absolute;

    top:-40px;
    right:-40px;

    width:120px;
    height:120px;

    border-radius:999px;

    background:
    rgba(59,130,246,.12);
}

.user-avatar{

    width:60px;
    height:60px;

    border-radius:999px;

    background:
    linear-gradient(
        135deg,
        #2563eb,
        #3b82f6
    );

    display:flex;

    align-items:center;
    justify-content:center;

    color:white;

    font-size:24px;

    font-weight:900;
}

.role-badge{

    display:inline-block;

    margin-top:8px;

    padding:6px 12px;

    border-radius:999px;

    background:
    linear-gradient(
        90deg,
        #2563eb,
        #3b82f6
    );

    color:white;

    font-size:11px;

    font-weight:700;
}

/* =========================================================
   MENU
========================================================= */

.sidebar-nav{

    padding:18px;
}

.sidebar-title{

    font-size:11px;

    color:#64748b;

    text-transform:uppercase;

    letter-spacing:2px;

    margin-top:22px;

    margin-bottom:12px;

    padding-left:10px;
}

.sidebar-link{

    display:flex;

    align-items:center;

    gap:14px;

    padding:14px 16px;

    border-radius:18px;

    text-decoration:none;

    color:#e2e8f0;

    font-weight:600;

    transition:.25s ease;

    margin-bottom:8px;

    position:relative;
}

.sidebar-link:hover{

    background:
    rgba(255,255,255,.06);

    transform:translateX(5px);
}

.sidebar-link i{

    width:22px;

    text-align:center;

    font-size:16px;
}

/* ACTIVE */

.sidebar-active{

    background:
    linear-gradient(
        90deg,
        #2563eb,
        #3b82f6
    );

    color:white !important;

    box-shadow:
    0 10px 25px rgba(59,130,246,.35);
}

/* =========================================================
   MAGASIN BOX
========================================================= */

.magasin-card{

    margin-top:18px;

    background:
    linear-gradient(
        135deg,
        rgba(37,99,235,.15),
        rgba(59,130,246,.06)
    );

    border:
    1px solid rgba(255,255,255,.05);

    border-radius:20px;

    padding:15px;
}

.magasin-title{

    font-size:11px;

    color:#93c5fd;

    text-transform:uppercase;

    letter-spacing:1px;

    margin-bottom:6px;
}

/* =========================================================
   LOGOUT
========================================================= */

.logout-btn{

    display:block;

    text-align:center;

    margin-top:25px;

    padding:15px;

    border-radius:18px;

    background:
    linear-gradient(
        90deg,
        #dc2626,
        #ef4444
    );

    color:white;

    font-weight:700;

    text-decoration:none;

    transition:.25s ease;
}

.logout-btn:hover{

    transform:translateY(-2px);

    box-shadow:
    0 10px 25px rgba(239,68,68,.3);
}

/* =========================================================
   MOBILE
========================================================= */

.mobile-top{

    position:fixed;

    top:0;
    left:0;
    right:0;

    z-index:70;

    background:#020617;

    padding:14px 18px;

    display:none;
}

@media(max-width:768px){

    .mobile-top{

        display:flex;

        align-items:center;

        justify-content:space-between;
    }

    body{

        padding-top:70px;
    }
}

/* =========================================================
   OVERLAY
========================================================= */

#sidebarOverlay{

    position:fixed;

    inset:0;

    background:
    rgba(0,0,0,.6);

    z-index:55;

    display:none;
}

#sidebarOverlay.show{

    display:block;
}

</style>

<!-- MOBILE TOP -->

<div class="mobile-top text-white">

    <button
        onclick="toggleSidebar()"
        class="text-2xl"
    >
        ☰
    </button>

    <div class="font-bold">

        <?= e($settings['nom_boutique'] ?? 'Gestion Shop') ?>

    </div>

</div>

<!-- OVERLAY -->

<div
    id="sidebarOverlay"
    onclick="toggleSidebar()"
></div>

<!-- SIDEBAR -->

<aside
    id="sidebar"
    class="sidebar-wrapper"
>

    <div class="sidebar-scroll">

        <!-- HEADER -->

        <div class="sidebar-header">

            <div class="flex items-center gap-4">

                <div class="logo-box">

                    <?php if(!empty($settings['logo'])): ?>

                        <img
                            src="<?= e($settings['logo']) ?>"
                            style="width:100%;height:100%;object-fit:cover;"
                        >

                    <?php else: ?>

                        <i class="fa fa-store text-black text-2xl"></i>

                    <?php endif; ?>

                </div>

                <div>

                    <div class="brand-title">

                        <?= e($settings['nom_boutique'] ?? 'Gestion Shop') ?>

                    </div>

                    <div class="brand-sub">

                        <?= e($settings['pays'] ?? 'POS Premium') ?>

                    </div>

                </div>

            </div>

            
            

        <!-- NAV -->

        <nav class="sidebar-nav">

            <div class="sidebar-title">

                Tableau de bord

            </div>

            <a
                href="dashboard.php"
                class="sidebar-link <?= isActivePage('dashboard.php',$currentPage) ?>"
            >
                <i class="fa fa-chart-line text-blue-400"></i>
                Dashboard
            </a>

            <div class="sidebar-title">

                Caisse

            </div>

            <a
                href="caisse.php"
                class="sidebar-link <?= isActivePage('caisse.php',$currentPage) ?>"
            >
                <i class="fa fa-cash-register text-green-400"></i>
                Caisse
            </a>

            <a
                href="commandes.php"
                class="sidebar-link <?= isActivePage('commandes.php',$currentPage) ?>"
            >
                <i class="fa fa-shopping-cart text-yellow-400"></i>
                Commandes
            </a>

            <a
                href="sessions_caisse.php"
                class="sidebar-link <?= isActivePage('sessions_caisse.php',$currentPage) ?>"
            >
                <i class="fa fa-clock text-cyan-400"></i>
                Sessions caisse
            </a>

            <a
                href="ventes_historique.php"
                class="sidebar-link <?= isActivePage('ventes_historique.php',$currentPage) ?>"
            >
                <i class="fa fa-receipt text-orange-300"></i>
                Historique ventes
            </a>

            <?php if($isCaissier || $isManager || $isAdmin): ?>

            <div class="sidebar-title">

                Produits & Stock

            </div>

            <a
                href="produits.php"
                class="sidebar-link <?= isActivePage('produits.php',$currentPage) ?>"
            >
                <i class="fa fa-box text-orange-400"></i>
                Produits
            </a>

            <a
                href="stock_mouvements.php"
                class="sidebar-link <?= isActivePage('stock_mouvements.php',$currentPage) ?>"
            >
                <i class="fa fa-exchange-alt text-emerald-400"></i>
                Mouvements stock
            </a>

            <a
                href="historiques_produits.php"
                class="sidebar-link <?= isActivePage('historiques_produits.php',$currentPage) ?>"
            >
                <i class="fa fa-history text-pink-400"></i>
                Historique produits
            </a>

            <?php endif; ?>

            <?php if($isAdmin): ?>

            <div class="sidebar-title">

                Administration

            </div>

            <a
                href="categories.php"
                class="sidebar-link <?= isActivePage('categories.php',$currentPage) ?>"
            >
                <i class="fa fa-tags text-purple-400"></i>
                Catégories
            </a>

            <a
                href="fournisseurs.php"
                class="sidebar-link <?= isActivePage('fournisseurs.php',$currentPage) ?>"
            >
                <i class="fa fa-truck text-indigo-400"></i>
                Fournisseurs
            </a>

            <a
                href="rapports.php"
                class="sidebar-link <?= isActivePage('rapports.php',$currentPage) ?>"
            >
                <i class="fa fa-chart-pie text-cyan-400"></i>
                Rapports
            </a>

            <a
                href="transactions.php"
                class="sidebar-link <?= isActivePage('transactions.php',$currentPage) ?>"
            >
                <i class="fa fa-money-bill-wave text-green-400"></i>
                Transactions
            </a>

            <a
                href="utilisateurs.php"
                class="sidebar-link <?= isActivePage('utilisateurs.php',$currentPage) ?>"
            >
                <i class="fa fa-users text-rose-400"></i>
                Utilisateurs
            </a>

            <a
                href="historiques.php"
                class="sidebar-link <?= isActivePage('historiques.php',$currentPage) ?>"
            >
                <i class="fa fa-file-lines text-slate-300"></i>
                Historique système
            </a>

            <a
                href="settings.php"
                class="sidebar-link <?= isActivePage('settings.php',$currentPage) ?>"
            >
                <i class="fa fa-gear text-gray-300"></i>
                Paramètres
            </a>

            <a
                href="transferts-stock.php"
                class="sidebar-link <?= isActivePage('transferts-stock.php',$currentPage) ?>"
            >
                <i class="fa fa-store text-blue-300"></i>
                Multi magasins
            </a>

            <?php endif; ?>
            

            <div class="sidebar-title">

                Sécurité

            </div>
            <a
                href="backups/index.php"
                class="sidebar-link <?= isActivePage('security_logs.php',$currentPage) ?>"
            >
                <i class="fa fa-shield-halved text-red-400"></i>
                manager backup
            </a>

            <a
                href="security_logs.php"
                class="sidebar-link <?= isActivePage('security_logs.php',$currentPage) ?>"
            >
                <i class="fa fa-shield-halved text-red-400"></i>
                Logs sécurité
            </a>

            <div class="sidebar-title">

                Mon compte

            </div>

            <a
                href="profile.php"
                class="sidebar-link <?= isActivePage('profile.php',$currentPage) ?>"
            >
                <i class="fa fa-user text-cyan-300"></i>
                Mon profil
            </a>

            <a
                href="logout.php"
                class="logout-btn"
            >
                <i class="fa fa-sign-out-alt mr-2"></i>

                Déconnexion
            </a>

            

        </nav>

    </div>

</aside>

<script>

function toggleSidebar(){

    document
    .getElementById('sidebar')
    .classList
    .toggle('open');

    document
    .getElementById('sidebarOverlay')
    .classList
    .toggle('show');
}

/* AUTO CLOSE MOBILE */

document
.querySelectorAll('.sidebar-link')
.forEach(link=>{

    link.addEventListener('click',()=>{

        if(window.innerWidth < 768){

            document
            .getElementById('sidebar')
            .classList
            .remove('open');

            document
            .getElementById('sidebarOverlay')
            .classList
            .remove('show');
        }
    });

});

</script>