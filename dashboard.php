<?php

require_once 'config.php';
require_once 'config-settings.php';

/* =========================================================
   USER
========================================================= */

$user = currentUser();

$role =
    $user['role'] ?? 'utilisateur';

$allowedRoles = [
    'admin',
    'caissier'
];

if (!in_array($role, $allowedRoles)) {

    die("
        <div style='padding:30px;font-family:Arial;color:red'>
            ⛔ Accès refusé
        </div>
    ");
}

$isAdmin =
    ($role === 'admin');

$isCaissier =
    ($role === 'caissier');

/* =========================================================
   MULTI MAGASIN
========================================================= */

$magasin_id =
    (int)currentMagasinId();

$selectedMagasinId = (int)($_GET['magasin_id'] ?? 0);

if ($isAdmin && $selectedMagasinId > 0 && canAccessMagasin($selectedMagasinId)) {
    $magasin_id = $selectedMagasinId;
    setMagasinActif($selectedMagasinId);
}

$magasin = null;

if ($magasin_id > 0) {
    $stmtMag = $pdo->prepare("SELECT * FROM magasins WHERE id=? AND statut='actif' LIMIT 1");
    $stmtMag->execute([$magasin_id]);
    $magasin = $stmtMag->fetch();
}

$isGlobalAdmin = $isAdmin && $magasin_id <= 0;

/* =========================================================
   FILTRES
========================================================= */

$whereVente = "";
$whereProduit = "";
$whereTransaction = "";
$whereUtilisateur = "";
$whereSession = "";

$paramsVente = [];
$paramsProduit = [];
$paramsTransaction = [];
$paramsUtilisateur = [];
$paramsSession = [];

if (!$isGlobalAdmin && $magasin_id) {

    $whereVente =
        " AND v.magasin_id=? ";

    $whereProduit =
        " AND p.magasin_id=? ";

    $whereTransaction =
        " AND magasin_id=? ";

    $whereUtilisateur =
        " AND u.magasin_id=? ";

    $whereSession =
        " AND magasin_id=? ";

    $paramsVente[] = $magasin_id;
    $paramsProduit[] = $magasin_id;
    $paramsTransaction[] = $magasin_id;
    $paramsUtilisateur[] = $magasin_id;
    $paramsSession[] = $magasin_id;
}

if ($isAdmin && $magasin_id > 0) {
    $whereVente = " AND v.magasin_id=? ";
    $whereProduit = " AND p.magasin_id=? ";
    $whereTransaction = " AND magasin_id=? ";
    $whereUtilisateur = " AND u.magasin_id=? ";
    $whereSession = " AND magasin_id=? ";

    $paramsVente[] = $magasin_id;
    $paramsProduit[] = $magasin_id;
    $paramsTransaction[] = $magasin_id;
    $paramsUtilisateur[] = $magasin_id;
    $paramsSession[] = $magasin_id;
}

/* =========================================================
   SETTINGS
========================================================= */

$settings = getSettings();

$devise =
    $settings['devise'] ?? 'FCFA';

/* =========================================================
   AJAX
========================================================= */



if (isset($_GET['ajax'])) {

    header('Content-Type: application/json');

    /* =====================================================
       SALES LIVE
    ===================================================== */

    if ($_GET['ajax'] === 'sales') {

        $sql = "
            SELECT
                v.id,
                v.total,
                v.date_vente,
                u.nom as utilisateur,
                m.nom as magasin

            FROM ventes v

            LEFT JOIN utilisateurs u
            ON u.id = v.utilisateur_id

            LEFT JOIN magasins m
            ON m.id = v.magasin_id

            WHERE 1=1
            $whereVente

            ORDER BY v.id DESC

            LIMIT 10
        ";

        $stmt = $pdo->prepare($sql);

        $stmt->execute($paramsVente);

        echo json_encode(
            $stmt->fetchAll()
        );

        exit;
    }

    /* =====================================================
       KPI

    ===================================================== */

    

    if ($_GET['ajax'] === 'kpi') {

    $response['magasin_actif'] =
    $magasin['nom']
    ??
    'Tous les magasins';

    $sqlCaisse = "
SELECT COUNT(*)
FROM sessions_caisse
WHERE statut='ouverte'
";

if(!$isGlobalAdmin && $magasin_id){

    $sqlCaisse .=
        " AND magasin_id=".$magasin_id;
}

$response['caisses_ouvertes'] =
    $pdo->query($sqlCaisse)
        ->fetchColumn();

    $sqlOnline = "
SELECT COUNT(*)
FROM connexions_utilisateurs
WHERE derniere_activite >
DATE_SUB(
    NOW(),
    INTERVAL 10 MINUTE
)
";

$response['online'] =
    $pdo->query($sqlOnline)
        ->fetchColumn();

        $sqlToday = "
            SELECT COALESCE(SUM(v.total),0)
            FROM ventes v
            WHERE v.date_vente >= CURDATE()
            AND v.date_vente < CURDATE() + INTERVAL 1 DAY
            $whereVente
        ";

        $stmtToday = $pdo->prepare($sqlToday);

        $stmtToday->execute($paramsVente);

        $todaySales =
            $stmtToday->fetchColumn();

        $sqlTransactions = "
            SELECT COUNT(*)
            FROM ventes v
            WHERE DATE(v.date_vente)=CURDATE()
            $whereVente
        ";

        $stmtTransactions = $pdo->prepare($sqlTransactions);

        $stmtTransactions->execute($paramsVente);

        $todayTransactions =
            $stmtTransactions->fetchColumn();

        $response = [

            "today" =>
                number_format($todaySales,2),

            "transactions" =>
                $todayTransactions
        ];

        if ($isAdmin) {

            /* BENEFICE */

            $sqlProfit = "
                SELECT COALESCE(
                    SUM(
                        lv.sous_total -
                        (
                            p.prix_achat
                            *
                            lv.quantite
                        )
                    ),0
                )

                FROM ligne_ventes lv

                JOIN produits p
                ON p.id = lv.produit_id

                JOIN ventes v
                ON v.id = lv.vente_id

                WHERE DATE(v.date_vente)=CURDATE()
                $whereVente
            ";

            $stmtProfit = $pdo->prepare($sqlProfit);

            $stmtProfit->execute($paramsVente);

            $profit =
                $stmtProfit->fetchColumn();

            /* BENEFICE DE LA SEMAINE */

            $sqlWeeklyProfit = "
                SELECT COALESCE(
                    SUM(
                        lv.sous_total -
                        (p.prix_achat * lv.quantite)
                    ),0
                )
                FROM ligne_ventes lv
                JOIN produits p ON p.id = lv.produit_id
                JOIN ventes v ON v.id = lv.vente_id
                WHERE YEARWEEK(v.date_vente, 1) = YEARWEEK(CURDATE(), 1)
                $whereVente
            ";

            $stmtWeeklyProfit = $pdo->prepare($sqlWeeklyProfit);
            $stmtWeeklyProfit->execute($paramsVente);
            $weeklyProfit = $stmtWeeklyProfit->fetchColumn();

            /* VALEUR DES PRODUITS EN STOCK */

            $sqlStockValue = "
                SELECT COALESCE(SUM(p.prix_vente * p.quantite), 0)
                FROM produits p
                WHERE p.quantite > 0
                $whereProduit
            ";

            $stmtStockValue = $pdo->prepare($sqlStockValue);
            $stmtStockValue->execute($paramsProduit);
            $stockValue = $stmtStockValue->fetchColumn();

            /* STOCK */

            $sqlStock = "
                SELECT COUNT(*)
                FROM produits p
                WHERE p.quantite < 5
                $whereProduit
            ";

            $stmtStock = $pdo->prepare($sqlStock);

            $stmtStock->execute($paramsProduit);

            $stockLow =
                $stmtStock->fetchColumn();

            /* MAGASINS */

            $magasins =
                $pdo->query("
                    SELECT COUNT(*)
                    FROM magasins
                ")->fetchColumn();

            /* EMPLOYES */

            $sqlEmployes = "
                SELECT COUNT(*)
                FROM utilisateurs u
                WHERE 1=1
                $whereUtilisateur
            ";

            $stmtEmployes = $pdo->prepare($sqlEmployes);

            $stmtEmployes->execute($paramsUtilisateur);

            $employes =
                $stmtEmployes->fetchColumn();

            $response["profit"] =
                number_format($profit,2);

            $response["profit_semaine"] =
                number_format($weeklyProfit, 2);

            $response["valeur_stock"] =
                number_format($stockValue, 2);

            $response["stock"] =
                $stockLow;

            $response["magasins"] =
                $magasins;

            $response["employes"] =
                $employes;
        }
        $sqlProduits = "
SELECT COUNT(*)
FROM produits p
WHERE 1=1
$whereProduit
";

$stmtProduits = $pdo->prepare($sqlProduits);

$stmtProduits->execute($paramsProduit);

$response['produits'] =
    $stmtProduits->fetchColumn();

        $json = json_encode($response);

echo $json;

        exit;
    }

    if ($_GET['ajax'] === 'transactions') {

   $sql = "
SELECT *
FROM ventes
WHERE 1=1
$whereTransaction
ORDER BY id DESC
LIMIT 15
";

$stmt = $pdo->prepare($sql);

$stmt->execute($paramsTransaction);

echo json_encode(
    $stmt->fetchAll()
);

exit;

    echo json_encode(
        $pdo->query($sql)->fetchAll()
    );

    exit;
}
if ($_GET['ajax'] === 'expired_products') {

   $sql = "
SELECT
    p.nom,
    p.date_peremption
FROM produits p
WHERE p.date_peremption <= CURDATE()
$whereProduit
";

$stmt = $pdo->prepare($sql);

$stmt->execute($paramsProduit);

echo json_encode(
    $stmt->fetchAll()
);

exit;

    echo json_encode(
        $pdo->query($sql)->fetchAll()
    );

    exit;
}

    /* =====================================================
       GRAPH SALES
    ===================================================== */

    if ($_GET['ajax'] === 'profit_graph') {

    $sql = "
    SELECT
        DATE(v.date_vente) date,
        SUM(
            lv.sous_total -
            (p.prix_achat * lv.quantite)
        ) profit
    FROM ligne_ventes lv
    JOIN produits p
        ON p.id = lv.produit_id
    JOIN ventes v
        ON v.id = lv.vente_id
    GROUP BY DATE(v.date_vente)
    ORDER BY date ASC
    LIMIT 30
    ";

    echo json_encode(
        $pdo->query($sql)->fetchAll()
    );

    exit;
}

    if ($_GET['ajax'] === 'graph') {
        if ($_GET['ajax'] === 'profit_graph') {

    $sql = "
    SELECT
        DATE(v.date_vente) date,
        SUM(
            lv.sous_total -
            (p.prix_achat * lv.quantite)
        ) profit

    FROM ligne_ventes lv

    JOIN produits p
    ON p.id = lv.produit_id

    JOIN ventes v
    ON v.id = lv.vente_id

    GROUP BY DATE(v.date_vente)

    ORDER BY date ASC

    LIMIT 30
    ";

    echo json_encode(
        $pdo->query($sql)->fetchAll()
    );

    exit;
}

        if (!$isAdmin) {

            echo json_encode([]);

            exit;
        }

        $sql = "
            SELECT
                DATE(v.date_vente) as date,
                SUM(v.total) as total

            FROM ventes v

            WHERE v.date_vente >=
            CURDATE() - INTERVAL 7 DAY

            $whereVente

            GROUP BY DATE(v.date_vente)
        ";

        $stmt = $pdo->prepare($sql);

        $stmt->execute($paramsVente);

        echo json_encode(
            $stmt->fetchAll()
        );

        exit;
    }

    /* =====================================================
       COMPARAISON MAGASINS
    ===================================================== */

    if ($_GET['ajax'] === 'compare_magasin') {

        if (!$isAdmin) {

            echo json_encode([]);

            exit;
        }

        $data = $pdo->query("
            SELECT
                m.nom,
                COALESCE(SUM(v.total),0) as total

            FROM magasins m

            LEFT JOIN ventes v
            ON v.magasin_id = m.id
            AND DATE(v.date_vente)=CURDATE()

            GROUP BY m.id

            ORDER BY total DESC
        ")->fetchAll();

        echo json_encode($data);

        exit;
    }

    /* =====================================================
       TOP PRODUCTS
    ===================================================== */
    if ($_GET['ajax'] === 'notifications') {

    $notifications = [];
$sql = "
SELECT
    p.nom,
    p.quantite
FROM produits p
WHERE p.quantite <= p.seuil_alerte
$whereProduit
LIMIT 10
";

$stmt = $pdo->prepare($sql);

$stmt->execute($paramsProduit);

$data = $stmt->fetchAll();

    $data =
        $pdo->query($sql)->fetchAll();

    foreach($data as $p){

        $notifications[] = [

            "type" => "warning",

            "message" =>
            "Stock faible : ".
            $p['nom'].
            " (".
            $p['quantite'].
            ")"
        ];
    }

    echo json_encode($notifications);

    exit;
}

    if ($_GET['ajax'] === 'top_products') {

    /* =====================================================
   NOTIFICATIONS
===================================================== */


       $sql = "
SELECT
    p.nom,
    SUM(lv.quantite) total

FROM ligne_ventes lv

INNER JOIN produits p
    ON p.id = lv.produit_id

INNER JOIN ventes v
    ON v.id = lv.vente_id

WHERE 1=1
$whereVente

GROUP BY p.id

ORDER BY total DESC

LIMIT 5
";
$stmt = $pdo->prepare($sql);

$stmt->execute($paramsVente);

        $stmt->execute($paramsProduit);

        echo json_encode(
            $stmt->fetchAll()
        );

        exit;
    }
}

/* =========================================================
   SESSION CAISSE
========================================================= */

$sqlSession = "
    SELECT id
    FROM sessions_caisse
    WHERE statut='ouverte'
";

$paramsSessionFull = [];

if ($isCaissier) {
    $sqlSession .= " AND utilisateur_id=?";
    $paramsSessionFull[] = $user['id'];
}

if ($magasin_id > 0) {
    $sqlSession .= " AND magasin_id=?";
    $paramsSessionFull[] = $magasin_id;
}

$sqlSession .= " LIMIT 1";

$stmt = $pdo->prepare($sqlSession);

$stmt->execute($paramsSessionFull);

$sessionCaisse =
    $stmt->fetch();

/* =========================================================
   INCLUDE
========================================================= */

include 'includes/header.php';

include 'includes/sidebar.php';

?>

<style>

/* =========================================================
   DASHBOARD STYLE
========================================================= */

.dashboard-glass{

    background:
    rgba(255,255,255,.82);

    backdrop-filter:
    blur(18px);

    border:
    1px solid rgba(255,255,255,.18);

    box-shadow:
    0 10px 40px rgba(0,0,0,.08);
}

.dark .dashboard-glass{

    background:
    rgba(15,23,42,.88);

    border:
    1px solid rgba(255,255,255,.04);
}

.dashboard-card{

    position:relative;

    overflow:hidden;

    border-radius:28px;

    padding:24px;

    color:white;

    transition:.3s ease;

    box-shadow:
    0 12px 30px rgba(0,0,0,.18);
}

.dashboard-card:hover{

    transform:
    translateY(-4px);
}

.dashboard-card::before{

    content:'';

    position:absolute;

    width:180px;
    height:180px;

    background:
    rgba(255,255,255,.08);

    border-radius:999px;

    top:-60px;
    right:-60px;
}

.dashboard-blue{

    background:
    linear-gradient(
        135deg,
        #2563eb,
        #1d4ed8
    );
}

.dashboard-cyan{

    background:
    linear-gradient(
        135deg,
        #0891b2,
        #06b6d4
    );
}

.dashboard-green{

    background:
    linear-gradient(
        135deg,
        #16a34a,
        #22c55e
    );
}

.dashboard-orange{

    background:
    linear-gradient(
        135deg,
        #ea580c,
        #fb923c
    );
}

.dashboard-purple{

    background:
    linear-gradient(
        135deg,
        #7c3aed,
        #8b5cf6
    );
}

.dashboard-pink{

    background:
    linear-gradient(
        135deg,
        #db2777,
        #f472b6
    );
}

.dashboard-title{

    font-size:14px;

    opacity:.85;

    margin-bottom:12px;
}

.dashboard-number{

    font-size:34px;

    font-weight:900;

    line-height:1;
}

.live-item{

    border-radius:20px;

    padding:16px;

    transition:.25s;

    background:
    rgba(248,250,252,.9);

    border:
    1px solid #e2e8f0;
}

.live-item:hover{

    transform:translateX(4px);
}

.dark .live-item{

    background:
    rgba(15,23,42,.9);

    border:
    1px solid #334155;
}

.chart-box{

    border-radius:28px;

    padding:24px;
}

.fade-dashboard{

    animation:fadeDashboard .35s ease;
}

@keyframes fadeDashboard{

    from{

        opacity:0;
        transform:translateY(10px);
    }

    to{

        opacity:1;
        transform:translateY(0);
    }
}

</style>

<div class="md:ml-[280px] p-4 md:p-6 fade-dashboard">

    <!-- TOP HEADER -->

    <div class="dashboard-glass rounded-[30px] p-6 mb-6">

        <div class="flex flex-col xl:flex-row xl:items-center xl:justify-between gap-5">

            <div>

                <h1 class="text-3xl md:text-4xl font-black text-slate-800 dark:text-white">

                    📊 Dashboard 

                </h1>

                <p class="text-slate-500 mt-2 text-lg">

                    Bienvenue
                    <?= e($user['nom']) ?>

                    (
                    <?= ucfirst($role) ?>
                    )
                </p>

                

                <?php if($magasin): ?>

                    <div class="mt-4 inline-flex items-center gap-3 bg-blue-100 text-blue-700 px-5 py-3 rounded-2xl font-bold">

                        🏪
                        <?= e($magasin['nom']) ?>

                    </div>

                <?php endif; ?>

            </div>

            <div class="dashboard-glass rounded-3xl px-8 py-6 text-center">

                <div
                    id="clock"
                    class="text-3xl font-black text-slate-800 dark:text-white"
                ></div>

                <div class="mt-3 <?= $sessionCaisse ? 'text-green-600' : 'text-red-600' ?> font-bold">

                    <?= $sessionCaisse
                        ? '🟢 Caisse ouverte'
                        : '🔴 Caisse fermée'
                    ?>

                </div>

            </div>

        </div>

    </div>

    <!-- KPI -->

    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-6 gap-5 mb-6">

        <div class="dashboard-card dashboard-blue">

            <div class="dashboard-title">
                💰 CA Aujourd'hui
            </div>

            <div
                id="kpiToday"
                class="dashboard-number"
            >
                0
            </div>

        </div>

        <div class="dashboard-card dashboard-cyan">

            <div class="dashboard-title">
                🧾 Transactions
            </div>

            <div
                id="kpiTransactions"
                class="dashboard-number"
            >
                0
            </div>

        </div>

        <?php if($isAdmin): ?>

        <div class="dashboard-card dashboard-green">

            <div class="dashboard-title">
                📈 Bénéfice
            </div>

            <div
                id="kpiProfit"
                class="dashboard-number"
            >
                0
            </div>

        </div>

        <div class="dashboard-card dashboard-green">

            <div class="dashboard-title">
                📅 Bénéfice semaine
            </div>

            <div
                id="kpiWeeklyProfit"
                class="dashboard-number"
            >
                0
            </div>

        </div>

        <div class="dashboard-card dashboard-cyan">

            <div class="dashboard-title">
                💼 Valeur du stock
            </div>

            <div
                id="kpiStockValue"
                class="dashboard-number"
            >
                0
            </div>

        </div>

        <div class="dashboard-card dashboard-orange">

            <div class="dashboard-title">
                ⚠ Stock faible
            </div>

            <div
                id="kpiStock"
                class="dashboard-number"
            >
                0
            </div>

        </div>

        <div class="dashboard-card dashboard-purple">

            <div class="dashboard-title">
                🏪 Magasins
            </div>

            <div
                id="kpiMagasins"
                class="dashboard-number"
            >
                0
            </div>

        </div>

        <div class="dashboard-card dashboard-pink">

            <div class="dashboard-title">
                👨‍💼 Employés
            </div>

            <div
                id="kpiEmployes"
                class="dashboard-number"
            >
                0
            </div>

        </div>
        <div class="dashboard-card dashboard-blue">

    <div class="dashboard-title">
        📦 Produits
    </div>

    <div id="kpiProduits"
         class="dashboard-number">
         0
    </div>

</div>

        <?php endif; ?>

    </div>

    <!-- CHARTS -->

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-6">

        <div
    id="magasinChartBlock"
    class="dashboard-glass chart-box">

            <h3 class="text-2xl font-black mb-5 text-slate-800 dark:text-white">

                📊 Evolution des ventes

            </h3>

            <div class="relative h-[340px]">

                <canvas id="chartSales"></canvas>

            </div>

        </div>

        <?php if($isAdmin): ?>

        <div
    id="salesChartBlock"
    class="dashboard-glass chart-box">

            <h3 class="text-2xl font-black mb-5 text-slate-800 dark:text-white">

                🏪 Comparaison magasins

            </h3>

            <div class="relative h-[340px]">

                <canvas id="chartMagasin"></canvas>

            </div>

        </div>

        <?php endif; ?>

    </div>

    <!-- LIVE + PRODUCTS -->

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">

        <!-- SALES -->

        <div class="dashboard-glass rounded-[30px] p-6">

            <div class="flex items-center justify-between mb-5">

                <h3 class="text-2xl font-black text-slate-800 dark:text-white">

                    🔴 Ventes Live

                </h3>

                <div class="animate-pulse text-red-500 font-bold">

                    LIVE

                </div>

            </div>

            <div
                id="sales"
                class="space-y-3"
            ></div>

        </div>
        <div class="dashboard-glass rounded-[30px] p-6">

    <h3 class="text-2xl font-black mb-5">

        💳 Dernières Transactions

    </h3>

    <div id="transactions"></div>

</div>

        <!-- TOP -->

<div
    id="topProductsBlock"
    class="dashboard-glass rounded-[30px] p-6">
            <h3 class="text-2xl font-black mb-5 text-slate-800 dark:text-white">

                🏆 Top Produits

            </h3>

            <div
                id="topProducts"
                class="space-y-3"
            ></div>

        </div>

    </div>

</div>

<script src="assets/vendor/chart.min.js"></script>

<script>

/* =========================================================
   CHARTS
========================================================= */

let chartSales;
let chartMagasin;

/* =========================================================
   CLOCK
========================================================= */

setInterval(()=>{

    document
    .getElementById("clock")
    .innerText =
        new Date().toLocaleTimeString();

},1000);

/* =========================================================
   KPI
========================================================= */

async function loadKPI(){

    let r =
        await fetch("?ajax=kpi");

    let d =
        await r.json();

    document
    .getElementById("kpiToday")
    .innerText =
        d.today + " <?= $devise ?>";

    document
    .getElementById("kpiTransactions")
    .innerText =
        d.transactions;

        document
.getElementById("kpiProduits")
.innerText = d.produits;

    <?php if($isAdmin): ?>

    document
    .getElementById("kpiProfit")
    .innerText =
        d.profit + " <?= $devise ?>";

    document
    .getElementById("kpiWeeklyProfit")
    .innerText =
        d.profit_semaine + " <?= $devise ?>";

    document
    .getElementById("kpiStockValue")
    .innerText =
        d.valeur_stock + " <?= $devise ?>";

    document
    .getElementById("kpiStock")
    .innerText =
        d.stock;

    document
    .getElementById("kpiMagasins")
    .innerText =
        d.magasins;

    document
    .getElementById("kpiEmployes")
    .innerText =
        d.employes;

    <?php endif; ?>
}

/* =========================================================
   SALES LIVE
========================================================= */

async function loadSales(){

    let r =
        await fetch("?ajax=sales");

    let data =
        await r.json();

    let html = "";

    data.forEach(v=>{

        html += `
        <div class="live-item flex justify-between items-center">

            <div>

                <div class="font-black text-lg">

                    Vente #${v.id}

                </div>

                <div class="text-sm text-slate-500 mt-1">

                    ${v.utilisateur}

                </div>

                <div class="text-xs text-blue-600 mt-2 font-bold">

                    🏪 ${v.magasin ?? 'N/A'}

                </div>

            </div>

            <div class="text-right">

                <div class="text-2xl font-black text-green-600">

                    ${v.total}

                </div>

                <div class="text-xs text-slate-500">

                    <?= $devise ?>

                </div>

            </div>

        </div>
        `;
    });

    document
    .getElementById("sales")
    .innerHTML =
        html;
}

/* =========================================================
   GRAPH SALES
========================================================= */

async function loadGraphSales(){

    let r =
        await fetch("?ajax=graph");

    let data =
        await r.json();

    let labels =
        data.map(i=>i.date);

    let values =
        data.map(i=>i.total);

    if(!chartSales){

        chartSales =
            new Chart(

                document.getElementById("chartSales"),

                {
                    type:'line',

                    data:{

                        labels:labels,

                        datasets:[

                            {

                                label:'Ventes',

                                data:values,

                                borderWidth:4,

                                tension:.4,

                                fill:true
                            }
                        ]
                    },

                    options:{

                        responsive:true,

                        maintainAspectRatio:false
                    }
                }
            );

    }else{

        chartSales.data.labels =
            labels;

        chartSales.data.datasets[0].data =
            values;

        chartSales.update();
    }
}

/* =========================================================
   MAGASINS
========================================================= */

<?php if($isAdmin): ?>

async function loadCompareMagasin(){

    let r =
        await fetch("?ajax=compare_magasin");

    let data =
        await r.json();

    let labels =
        data.map(i=>i.nom);

    let values =
        data.map(i=>i.total);

    if(!chartMagasin){

        chartMagasin =
            new Chart(

                document.getElementById("chartMagasin"),

                {
                    type:'bar',

                    data:{

                        labels:labels,

                        datasets:[

                            {

                                label:'CA Magasins',

                                data:values,

                                borderRadius:14
                            }
                        ]
                    },

                    options:{

                        responsive:true,

                        maintainAspectRatio:false
                    }
                }
            );

    }else{

        chartMagasin.data.labels =
            labels;

        chartMagasin.data.datasets[0].data =
            values;

        chartMagasin.update();
    }
}

<?php endif; ?>

/* =========================================================
   TOP PRODUCTS
========================================================= */

async function loadTopProducts(){

    let r =
        await fetch("?ajax=top_products");

    let data =
        await r.json();

    let html = "";

    data.forEach((p,index)=>{

        html += `
        <div class="live-item flex justify-between items-center">

            <div class="flex items-center gap-4">

                <div class="w-10 h-10 rounded-full bg-blue-600 text-white flex items-center justify-center font-black">

                    ${index+1}

                </div>

                <div class="font-bold">

                    ${p.nom}

                </div>

            </div>

            <div class="text-xl font-black text-slate-700 dark:text-white">

                ${p.total}

            </div>

        </div>
        `;
    });

    document
    .getElementById("topProducts")
    .innerHTML =
        html;
}

/* =========================================================
   AUTO LOAD
========================================================= */
/* ==========================
   CHARGEMENT IMMEDIAT
========================== */

loadKPI();
loadSales();

setInterval(loadKPI,10000);
setInterval(loadSales,5000);

/* ==========================
   LAZY LOADING
========================== */

const observer = new IntersectionObserver(

(entries)=>{

entries.forEach(entry=>{

if(!entry.isIntersecting)
return;

/* TOP PRODUITS */

if(entry.target.id==="topProductsBlock"){

loadTopProducts();

setInterval(loadTopProducts,15000);

observer.unobserve(entry.target);
}

/* GRAPH VENTES */

if(entry.target.id==="salesChartBlock"){

loadGraphSales();

setInterval(loadGraphSales,15000);

observer.unobserve(entry.target);
}

/* GRAPH MAGASINS */

if(entry.target.id==="magasinChartBlock"){

loadCompareMagasin();

setInterval(loadCompareMagasin,10000);

observer.unobserve(entry.target);
}

});

},
{
threshold:0.2
}
);

/* OBSERVER */

let topBlock =
document.getElementById("topProductsBlock");

if(topBlock)
observer.observe(topBlock);

let salesChart =
document.getElementById("salesChartBlock");

if(salesChart)
observer.observe(salesChart);

let magasinChart =
document.getElementById("magasinChartBlock");

if(magasinChart)
observer.observe(magasinChart);
document
.getElementById("kpiMagasinActif")
.innerText =
    d.magasin_actif;

document
.getElementById("kpiOnline")
.innerText =
    d.online;

document
.getElementById("kpiCaisses")
.innerText =
    d.caisses_ouvertes;

</script>

<?php include 'includes/footer.php'; ?>