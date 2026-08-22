<?php
require_once 'config.php';
requireLogin();
requireCaissier();

$user = currentUser();
$settings = getSettings();

$devise =
    $settings['devise']
    ?? 'FCFA';

function historique(
    $pdo,
    $userId,
    $action,
    $details,
    $niveau='INFO'
){

    $ip =
        $_SERVER['REMOTE_ADDR']
        ?? 'UNKNOWN';

    $stmt =
        $pdo->prepare("
            INSERT INTO historiques
            (
                utilisateur_id,
                action,
                details,
                ip,
                niveau,
                created_at
            )
            VALUES
            (
                ?,?,?,?,?,NOW()
            )
        ");

    $stmt->execute([

        $userId,
        $action,
        $details,
        $ip,
        strtoupper($niveau)
    ]);
}

/* =========================
   ACCÈS ADMIN
========================= */
$isAdmin = isAdmin();

/* =========================
   FILTRES
========================= */
$start =
    $_GET['start']
    ?? date('Y-m-01');

$end =
    $_GET['end']
    ?? date('Y-m-d');

if(
    !preg_match(
        '/^\d{4}-\d{2}-\d{2}$/',
        $start
    )
){
    $start = date('Y-m-01');
}

if(
    !preg_match(
        '/^\d{4}-\d{2}-\d{2}$/',
        $end
    )
){
    $end = date('Y-m-d');
}
$caissier = $_GET['caissier'] ?? '';

$where = "WHERE DATE(v.date_vente) BETWEEN ? AND ?";
$params = [$start, $end];

if (!$isAdmin) {
    $where .= " AND v.magasin_id=?";
    $params[] = currentMagasinId();
}

if ($isAdmin && !empty($_GET['magasin_id'])) {
    $where .= " AND v.magasin_id=?";
    $params[] = (int)$_GET['magasin_id'];
}

if ($caissier !== '') {
    $where .= " AND v.utilisateur_id=?";
    $params[] = $caissier;
}

/* =========================
   EXPORT EXCEL (CSV PRO)
========================= */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {

historique(

    $pdo,

    $user['id'],

    'EXPORT_RAPPORT',

    'Export CSV ventes du '
    .$start
    .' au '
    .$end,

    'SUCCESS'
);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=rapport_ventes.csv');

    $out = fopen("php://output", "w");

    fputcsv($out, ['Produit', 'Quantité', 'Total (FCFA)']);

    $stmt = $pdo->prepare("
        SELECT p.nom,
               SUM(lv.quantite) qte,
               SUM(lv.sous_total) total
        FROM ligne_ventes lv
        JOIN produits p ON p.id = lv.produit_id
        JOIN ventes v ON v.id = lv.vente_id
        $where
        GROUP BY lv.produit_id
        ORDER BY total DESC
    ");

    $stmt->execute($params);

    while ($r = $stmt->fetch()) {
        fputcsv($out, [
            $r['nom'],
            $r['qte'],
            number_format($r['total'],2,'.','')
        ]);
    }

    fclose($out);
    exit;
}

/* =========================
   KPI GLOBAL
========================= */
$stmt = $pdo->prepare("
    SELECT

        COUNT(*) ventes,

        COALESCE(
            SUM(total),
            0
        ) ca,

        COALESCE(
            AVG(total),
            0
        ) panier_moyen,

        COALESCE(
            MAX(total),
            0
        ) meilleure_vente

    FROM ventes v

    $where
");

$stmt->execute($params);
$kpi = $stmt->fetch();

/* =========================
   TOP PRODUITS
========================= */
$stmt = $pdo->prepare("
    SELECT p.nom,
           SUM(lv.quantite) total
    FROM ligne_ventes lv
    JOIN produits p ON p.id = lv.produit_id
    JOIN ventes v ON v.id = lv.vente_id
    $where
    GROUP BY lv.produit_id
    ORDER BY total DESC
    LIMIT 7
");
$stmt->execute($params);
$topProducts = $stmt->fetchAll();

/* =========================
   TOP CAISSIERS
========================= */
$stmt = $pdo->prepare("
    SELECT u.nom,
           SUM(v.total) total
    FROM ventes v
    JOIN utilisateurs u ON u.id = v.utilisateur_id
    $where
    GROUP BY v.utilisateur_id
    ORDER BY total DESC
    LIMIT 5
");
$stmt->execute($params);
$topCashiers = $stmt->fetchAll();

/* =========================
   PRODUITS NON VENDUS
========================= */
    $nonSoldSql = "
        SELECT p.nom

        FROM produits p

        LEFT JOIN ligne_ventes lv
        ON lv.produit_id = p.id

        WHERE lv.id IS NULL";

    if (!$isAdmin) {
        $nonSoldSql .= " AND p.magasin_id=".(int)currentMagasinId();
    }

    $nonSoldSql .= " ORDER BY p.nom LIMIT 20";
    $nonSold = $pdo->query($nonSoldSql)->fetchAll();
include 'includes/header.php';
include 'includes/sidebar.php';
?>

<!-- ================= UI ENTREPRISE ================= -->
<div class="p-4 md:p-6">

<h1 class="text-2xl md:text-3xl font-bold mb-6">
📊 Rapports & Analyse Business
</h1>

<!-- ================= FILTERS ================= -->
<div class="bg-white dark:bg-slate-900 p-4 rounded-2xl shadow mb-6">

<form method="GET" class="grid md:grid-cols-4 gap-3">

    <input type="date" name="start"
           value="<?= $start ?>"
           class="border p-3 rounded-xl dark:bg-slate-800">

    <input type="date" name="end"
           value="<?= $end ?>"
           class="border p-3 rounded-xl dark:bg-slate-800">

    <select name="caissier"
            class="border p-3 rounded-xl dark:bg-slate-800">

        <option value="">Tous les caissiers</option>

        <?php
        $usersSql = "SELECT id, nom FROM utilisateurs";
        if (!$isAdmin) {
            $usersSql .= " WHERE magasin_id=".(int)currentMagasinId();
        }
        $users = $pdo->query($usersSql)->fetchAll();
        foreach ($users as $u):
        ?>
            <option value="<?= $u['id'] ?>"
                <?= ($caissier == $u['id']) ? 'selected' : '' ?>>
                <?= e($u['nom']) ?>
            </option>
        <?php endforeach; ?>

    </select>

    <button class="bg-blue-600 hover:bg-blue-700 text-white p-3 rounded-xl">
        🔎 Filtrer
    </button>

</form>

<!-- EXPORT -->
<div class="mt-4 flex gap-3">

    <a href="?export=csv&start=<?= $start ?>&end=<?= $end ?>"
       class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-xl">
        📤 Export Excel (CSV)
    </a>

    <button onclick="window.print()"
            class="bg-gray-700 hover:bg-gray-800 text-white px-4 py-2 rounded-xl">
        🖨️ Imprimer / PDF
    </button>

</div>

</div>

<!-- ================= KPI ================= -->
<div class="grid md:grid-cols-4 gap-4 mb-6">
    <div class="bg-gradient-to-r from-blue-600 to-blue-400 text-white p-5 rounded-2xl shadow">
        📦 Ventes
        <h2 class="text-3xl font-bold"><?= $kpi['ventes'] ?></h2>
    </div>

    <div class="bg-gradient-to-r from-green-600 to-green-400 text-white p-5 rounded-2xl shadow">
        💰 CA Total
        <h2 class="text-3xl font-bold"><?= number_format($kpi['ca'],2) ?> FCFA</h2>
    </div>
    <div class="bg-gradient-to-r from-purple-600 to-purple-400 text-white p-5 rounded-2xl shadow">

    🧾 Panier Moyen

    <h2 class="text-3xl font-bold">

        <?= number_format(
            $kpi['panier_moyen'],
            2
        ) ?>

        <?= e($devise) ?>

    </h2>

</div>

<div class="bg-gradient-to-r from-orange-600 to-orange-400 text-white p-5 rounded-2xl shadow">

    🚀 Meilleure Vente

    <h2 class="text-3xl font-bold">

        <?= number_format(
            $kpi['meilleure_vente'],
            2
        ) ?>

        <?= e($devise) ?>

    </h2>

</div>

</div>

<!-- ================= TOP PRODUITS ================= -->
<div class="bg-white dark:bg-slate-900 p-5 rounded-2xl shadow mb-6">

<h2 class="font-bold mb-4">🏆 Top Produits</h2>

<?php foreach($topProducts as $p): ?>
<div class="flex justify-between border-b py-2">
    <span><?= e($p['nom']) ?></span>
    <span class="font-bold"><?= $p['total'] ?></span>
</div>
<?php endforeach; ?>

</div>

<!-- ================= TOP CAISSIERS ================= -->
<div class="bg-white dark:bg-slate-900 p-5 rounded-2xl shadow mb-6">

<h2 class="font-bold mb-4">👨‍💼 Top Caissiers</h2>

<?php foreach($topCashiers as $c): ?>
<div class="flex justify-between border-b py-2">
    <span><?= e($c['nom']) ?></span>
    <span class="font-bold"><?= number_format($c['total'],2) ?></span>
</div>
<?php endforeach; ?>

</div>

<!-- ================= NON SOLD ================= -->
<div class="bg-white dark:bg-slate-900 p-5 rounded-2xl shadow">

<h2 class="font-bold mb-4">📉 Produits jamais vendus</h2>

<?php foreach($nonSold as $p): ?>
<div class="border-b py-2 text-gray-600">
    <?= e($p['nom']) ?>
</div>
<?php endforeach; ?>

</div>

</div>

<?php include 'includes/footer.php'; ?>