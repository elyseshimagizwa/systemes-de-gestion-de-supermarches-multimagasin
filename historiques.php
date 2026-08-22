```php
<?php

require_once 'config.php';
require_once 'config-settings.php';

requireLogin();
requireAdmin();

$settings = getSettings();

$devise =
    $settings['devise'] ?? 'FCFA';

/* =========================================
   FILTERS
========================================= */

$periode =
    $_GET['periode'] ?? 'today';

$userId =
    $_GET['user'] ?? '';

$magasinId =
    $_GET['magasin'] ?? '';

$actionType =
    $_GET['type'] ?? '';

$where = [];
$params = [];

/* =========================================
   PERIODE
========================================= */

switch($periode){

    case 'today':

        $where[] =
            "DATE(h.created_at)=CURDATE()";

    break;

    case 'week':

        $where[] =
            "YEARWEEK(h.created_at,1)=YEARWEEK(CURDATE(),1)";

    break;

    case 'month':

        $where[] =
            "MONTH(h.created_at)=MONTH(CURDATE())
             AND YEAR(h.created_at)=YEAR(CURDATE())";

    break;

    case 'all':
    default:
    break;
}

/* =========================================
   USER
========================================= */

if($userId){

    $where[] =
        "h.utilisateur_id=?";

    $params[] =
        $userId;
}

/* =========================================
   MAGASIN
========================================= */

if($magasinId){

    $where[] =
        "h.magasin_id=?";

    $params[] =
        $magasinId;
}

/* =========================================
   TYPE
========================================= */

if($actionType){

    $where[] =
        "h.action=?";

    $params[] =
        $actionType;
}

/* =========================================
   QUERY
========================================= */

$sql = "

SELECT

    h.*,

    u.nom AS utilisateur_nom,
    u.email,

    m.nom AS magasin_nom

FROM historiques h

LEFT JOIN utilisateurs u
ON u.id = h.utilisateur_id

LEFT JOIN magasins m
ON m.id = h.magasin_id

";

if($where){

    $sql .=
        " WHERE "
        .
        implode(" AND ",$where);
}

$sql .= "
ORDER BY h.created_at DESC
";

/* =========================================
   EXECUTE
========================================= */

$stmt = $pdo->prepare($sql);

$stmt->execute($params);

$historiques =
    $stmt->fetchAll();

/* =========================================
   USERS
========================================= */

$users = $pdo->query("
    SELECT id, nom
    FROM utilisateurs
    ORDER BY nom ASC
")->fetchAll();

/* =========================================
   MAGASINS
========================================= */

$magasins = $pdo->query("
    SELECT id, nom
    FROM magasins
    ORDER BY nom ASC
")->fetchAll();

/* =========================================
   STATS
========================================= */

$totalActions =
    count($historiques);

$dangerCount = 0;
$successCount = 0;
$securityCount = 0;

foreach($historiques as $h){

    if(
        strpos(
            strtoupper($h['niveau']),
            'DANGER'
        ) !== false
    ){
        $dangerCount++;
    }

    if(
        strpos(
            strtoupper($h['niveau']),
            'SUCCESS'
        ) !== false
    ){
        $successCount++;
    }

    if(
        strpos(
            strtoupper($h['action']),
            'SECURITY'
        ) !== false
    ){
        $securityCount++;
    }
}

include 'includes/header.php';
include 'includes/sidebar.php';

?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.tailwindcss.com"></script>

<style>

body{

    background:#f1f5f9;
}

.shopify-card{

    background:white;

    border-radius:24px;

    box-shadow:
    0 10px 30px rgba(0,0,0,.06);
}

.timeline-item{

    border-left:4px solid #e2e8f0;

    padding-left:20px;

    position:relative;
}

.timeline-item::before{

    content:'';

    width:14px;
    height:14px;

    background:#3b82f6;

    border-radius:999px;

    position:absolute;

    left:-9px;
    top:6px;
}

.badge-success{

    background:#dcfce7;
    color:#166534;
}

.badge-danger{

    background:#fee2e2;
    color:#991b1b;
}

.badge-warning{

    background:#fef9c3;
    color:#854d0e;
}

.badge-security{

    background:#ede9fe;
    color:#5b21b6;
}

</style>

<div class="p-4 md:p-6">

<!-- HEADER -->

<div class="flex flex-col lg:flex-row justify-between gap-4 mb-6">

    <div>

        <h1 class="text-4xl font-black text-slate-800">

            📜 Historique Global

        </h1>

        <div class="text-slate-500 mt-2">

            Surveillance complète du système multi magasin

        </div>

    </div>

</div>

<!-- FILTERS -->

<div class="shopify-card p-5 mb-6">

<form method="GET"
      class="grid md:grid-cols-5 gap-4">

    <!-- PERIODE -->

    <select
        name="periode"
        class="border p-3 rounded-2xl"
    >

        <option value="today"
            <?= $periode=='today'?'selected':'' ?>>
            Aujourd'hui
        </option>

        <option value="week"
            <?= $periode=='week'?'selected':'' ?>>
            Cette semaine
        </option>

        <option value="month"
            <?= $periode=='month'?'selected':'' ?>>
            Ce mois
        </option>

        <option value="all"
            <?= $periode=='all'?'selected':'' ?>>
            Tout
        </option>

    </select>

    <!-- USER -->

    <select
        name="user"
        class="border p-3 rounded-2xl"
    >

        <option value="">
            Tous utilisateurs
        </option>

        <?php foreach($users as $u): ?>

        <option
            value="<?= $u['id'] ?>"
            <?= $userId==$u['id']
                ? 'selected'
                : ''
            ?>
        >

            <?= e($u['nom']) ?>

        </option>

        <?php endforeach; ?>

    </select>

    <!-- MAGASIN -->

    <select
        name="magasin"
        class="border p-3 rounded-2xl"
    >

        <option value="">
            Tous magasins
        </option>

        <?php foreach($magasins as $m): ?>

        <option
            value="<?= $m['id'] ?>"
            <?= $magasinId==$m['id']
                ? 'selected'
                : ''
            ?>
        >

            <?= e($m['nom']) ?>

        </option>

        <?php endforeach; ?>

    </select>

    <!-- TYPE -->

    <select
        name="type"
        class="border p-3 rounded-2xl"
    >

        <option value="">
            Tous types
        </option>

        <option value="LOGIN">
            LOGIN
        </option>

        <option value="VENTE">
            VENTE
        </option>

        <option value="DELETE">
            DELETE
        </option>

        <option value="SECURITY">
            SECURITY
        </option>

    </select>

    <!-- BTN -->

    <button
        class="bg-blue-600 text-white rounded-2xl font-bold"
    >

        🔎 Filtrer

    </button>

</form>

</div>

<!-- STATS -->

<div class="grid md:grid-cols-4 gap-4 mb-6">

    <div class="shopify-card p-5">

        <div class="text-gray-500">

            Actions Totales

        </div>

        <div class="text-3xl font-black text-blue-600">

            <?= $totalActions ?>

        </div>

    </div>

    <div class="shopify-card p-5">

        <div class="text-gray-500">

            Succès

        </div>

        <div class="text-3xl font-black text-green-600">

            <?= $successCount ?>

        </div>

    </div>

    <div class="shopify-card p-5">

        <div class="text-gray-500">

            Danger

        </div>

        <div class="text-3xl font-black text-red-600">

            <?= $dangerCount ?>

        </div>

    </div>

    <div class="shopify-card p-5">

        <div class="text-gray-500">

            Sécurité

        </div>

        <div class="text-3xl font-black text-purple-600">

            <?= $securityCount ?>

        </div>

    </div>

</div>

<!-- GRAPH -->

<div class="shopify-card p-5 mb-6">

    <h2 class="font-black text-xl mb-4">

        📊 Analyse IA

    </h2>

    <canvas id="chart"></canvas>

</div>

<!-- TIMELINE -->

<div class="shopify-card p-5">

    <h2 class="text-2xl font-black mb-6">

        🕒 Timeline des actions

    </h2>

    <div class="space-y-6">

        <?php foreach($historiques as $h): ?>

        <?php

        $badge = 'badge-success';

        if(
            strpos(
                strtoupper($h['niveau']),
                'DANGER'
            ) !== false
        ){
            $badge = 'badge-danger';
        }

        if(
            strpos(
                strtoupper($h['niveau']),
                'WARNING'
            ) !== false
        ){
            $badge = 'badge-warning';
        }

        if(
            strpos(
                strtoupper($h['action']),
                'SECURITY'
            ) !== false
        ){
            $badge = 'badge-security';
        }

        ?>

        <div class="timeline-item">

            <div class="flex flex-col lg:flex-row justify-between gap-4">

                <div class="flex-1">

                    <div class="flex items-center gap-3 flex-wrap">

                        <div class="font-black text-lg">

                            👤
                            <?= e($h['utilisateur_nom']) ?>

                        </div>

                        <span class="
                            px-3 py-1 rounded-full text-xs font-bold
                            <?= $badge ?>
                        ">

                            <?= e($h['action']) ?>

                        </span>

                    </div>

                    <div class="mt-2 text-gray-600">

                        <?= e($h['details']) ?>

                    </div>

                    <div class="mt-3 text-sm text-gray-500 flex flex-wrap gap-4">

                        <span>

                            🏬
                            <?= e($h['magasin_nom']) ?>

                        </span>

                        <span>

                            🌍 IP :
                            <?= e($h['ip']) ?>

                        </span>

                        <span>

                            🕒
                            <?= e($h['created_at']) ?>

                        </span>

                    </div>

                </div>

            </div>

        </div>

        <?php endforeach; ?>

    </div>

</div>

</div>

<script>

new Chart(
    document.getElementById('chart'),
    {
        type:'bar',

        data:{

            labels:[
                'SUCCESS',
                'DANGER',
                'SECURITY'
            ],

            datasets:[{

                label:'Actions',

                data:[
                    <?= $successCount ?>,
                    <?= $dangerCount ?>,
                    <?= $securityCount ?>
                ]

            }]
        }
    }
);

</script>

<?php include 'includes/footer.php'; ?>
```
