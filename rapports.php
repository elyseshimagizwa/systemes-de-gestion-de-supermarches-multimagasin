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
    $niveau='INFO',
    $magasinId = null
){

    $ip =
        $_SERVER['REMOTE_ADDR']
        ?? 'UNKNOWN';

    $stmt =
        $pdo->prepare("
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
                ?,?,?,?,?,?,NOW()
            )
        ");

    $stmt->execute([

        $userId,
        $magasinId,
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

$selectedMagasinId = $isAdmin ? (int)($_GET['magasin_id'] ?? 0) : (int)currentMagasinId();
$scopeParams = [];
$scopeWhere = '';
if ($selectedMagasinId > 0) {
    $scopeWhere = ' AND v.magasin_id=?';
    $scopeParams[] = $selectedMagasinId;
}

$pdo->exec("CREATE TABLE IF NOT EXISTS paiements_tva (
    id int NOT NULL AUTO_INCREMENT PRIMARY KEY,
    magasin_id int NOT NULL,
    mois char(7) NOT NULL,
    montant decimal(12,2) NOT NULL,
    paye_par int DEFAULT NULL,
    date_paiement timestamp NOT NULL DEFAULT current_timestamp(),
    UNIQUE KEY uq_tva_magasin_mois (magasin_id, mois)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

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

$currentMonth = date('Y-m');
$previousMonth = date('Y-m', strtotime('first day of last month'));
$paymentMessage = null;
$paymentError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['payer_tva'])) {
    verify_csrf();
    $paymentStoreId = (int)($_POST['magasin_id'] ?? 0);
    $paymentMonth = (string)($_POST['mois'] ?? '');
    if (!$isAdmin && $paymentStoreId !== (int)currentMagasinId()) {
        $paymentError = 'Magasin non autorisé.';
    } elseif ($paymentStoreId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $paymentMonth)) {
        $paymentError = 'Période ou magasin invalide.';
    } else {
        $paymentStmt = $pdo->prepare('SELECT montant FROM paiements_tva WHERE magasin_id=? AND mois=?');
        $paymentStmt->execute([$paymentStoreId, $paymentMonth]);
        if ($paymentStmt->fetch()) {
            $paymentError = 'La TVA de ce mois est déjà marquée comme payée.';
        } else {
            $paymentDate = $paymentMonth . '-01';
            $paymentEnd = date('Y-m-d', strtotime($paymentDate . ' +1 month'));
            $taxStmt = $pdo->prepare('SELECT COALESCE(SUM(v.tva), 0) FROM ventes v WHERE v.magasin_id=? AND v.date_vente>=? AND v.date_vente<?');
            $taxStmt->execute([$paymentStoreId, $paymentDate, $paymentEnd]);
            $taxAmount = (float)$taxStmt->fetchColumn();
            $insertPayment = $pdo->prepare('INSERT INTO paiements_tva (magasin_id, mois, montant, paye_par) VALUES (?, ?, ?, ?)');
            $insertPayment->execute([$paymentStoreId, $paymentMonth, $taxAmount, $user['id']]);
            historique($pdo, $user['id'], 'PAIEMENT_TVA', 'TVA payée pour ' . $paymentMonth . ' : ' . $taxAmount . ' ' . $devise, 'SUCCESS', $paymentStoreId);
            $paymentMessage = 'La TVA du mois ' . $paymentMonth . ' a été marquée comme payée.';
        }
    }
}

$where = "WHERE DATE(v.date_vente) BETWEEN ? AND ?";
$params = [$start, $end];

if ($selectedMagasinId > 0) {
    $where .= " AND v.magasin_id=?";
    $params[] = $selectedMagasinId;
}

if ($caissier !== '') {
    $where .= " AND v.utilisateur_id=?";
    $params[] = $caissier;
}

/* =========================
   EXPORT EXCEL (CSV PRO)
========================= */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {

    $exportKpiStmt = $pdo->prepare("SELECT COALESCE(SUM(total), 0) ca FROM ventes v $where");
    $exportKpiStmt->execute($params);
    $exportCa = (float)$exportKpiStmt->fetchColumn();
    $exportTvaStmt = $pdo->prepare("SELECT COALESCE(SUM(tva), 0) FROM ventes v $where");
    $exportTvaStmt->execute($params);
    $exportTva = (float)$exportTvaStmt->fetchColumn();
    $exportFinancialStmt = $pdo->prepare("SELECT COALESCE(SUM(lv.quantite * p.prix_achat), 0) cout, COALESCE(SUM(lv.sous_total - (lv.quantite * p.prix_achat)), 0) benefice FROM ligne_ventes lv JOIN produits p ON p.id=lv.produit_id JOIN ventes v ON v.id=lv.vente_id AND p.magasin_id=v.magasin_id $where");
    $exportFinancialStmt->execute($params);
    $exportFinancial = $exportFinancialStmt->fetch();
    $exportMoneyStmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='recette' THEN montant ELSE 0 END), 0) recettes, COALESCE(SUM(CASE WHEN type='depense' THEN montant ELSE 0 END), 0) depenses FROM transactions_financieres WHERE DATE(created_at) BETWEEN ? AND ?" . ($selectedMagasinId > 0 ? ' AND magasin_id=?' : ''));
    $exportMoneyParams = [$start, $end];
    if ($selectedMagasinId > 0) $exportMoneyParams[] = $selectedMagasinId;
    $exportMoneyStmt->execute($exportMoneyParams);
    $exportMoney = $exportMoneyStmt->fetch();

historique(

    $pdo,

    $user['id'],

    'EXPORT_RAPPORT',

    'Export CSV ventes du '
    .$start
    .' au '
    .$end,

    'SUCCESS',
    $selectedMagasinId > 0 ? $selectedMagasinId : null
);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=rapport_ventes.csv');

    $out = fopen("php://output", "w");

    fputcsv($out, ['RAPPORT FINANCIER', 'Période', $start . ' au ' . $end]);
    fputcsv($out, ['Magasin', $selectedMagasinId > 0 ? $selectedMagasinId : 'Tous les magasins']);
    fputcsv($out, []);
    fputcsv($out, ['Indicateur', 'Montant']);
    fputcsv($out, ['Chiffre affaires TTC', number_format($exportCa, 2, '.', '')]);
    fputcsv($out, ['TVA', number_format($exportTva, 2, '.', '')]);
    fputcsv($out, ['Coût achat produits vendus', number_format((float)$exportFinancial['cout'], 2, '.', '')]);
    fputcsv($out, ['Bénéfice brut', number_format((float)$exportFinancial['benefice'], 2, '.', '')]);
    fputcsv($out, ['Recettes financières', number_format((float)$exportMoney['recettes'], 2, '.', '')]);
    fputcsv($out, ['Dépenses financières', number_format((float)$exportMoney['depenses'], 2, '.', '')]);
    fputcsv($out, ['Solde', number_format($exportCa + (float)$exportMoney['recettes'] - (float)$exportMoney['depenses'], 2, '.', '')]);
    fputcsv($out, []);
    fputcsv($out, ['Produit', 'Quantité', 'Ventes HT', 'Coût achat', 'Bénéfice brut']);

    $stmt = $pdo->prepare("
         SELECT p.nom,
             SUM(lv.quantite) qte,
             SUM(lv.sous_total) total,
             SUM(lv.quantite * p.prix_achat) cout,
             SUM(lv.sous_total - (lv.quantite * p.prix_achat)) benefice
        FROM ligne_ventes lv
        JOIN produits p ON p.id = lv.produit_id
        JOIN ventes v ON v.id = lv.vente_id
        $where
        GROUP BY lv.produit_id
        ORDER BY total DESC
    ");

    $stmt->execute($params);

    while ($r = $stmt->fetch()) {
        fputcsv($out, [$r['nom'], $r['qte'], number_format($r['total'], 2, '.', ''), number_format($r['cout'], 2, '.', ''), number_format($r['benefice'], 2, '.', '')]);
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

$financialWhere = $where;
$financialParams = $params;
$financialStmt = $pdo->prepare("SELECT
    COALESCE(SUM(lv.quantite * p.prix_achat), 0) AS cout_achat,
    COALESCE(SUM(lv.sous_total), 0) AS ventes_ht,
    COALESCE(SUM(lv.sous_total - (lv.quantite * p.prix_achat)), 0) AS benefice_brut
    FROM ligne_ventes lv
    JOIN ventes v ON v.id=lv.vente_id
    JOIN produits p ON p.id=lv.produit_id AND p.magasin_id=v.magasin_id
    $financialWhere");
$financialStmt->execute($financialParams);
$financial = $financialStmt->fetch();
$financial['tva'] = (float)$kpi['ca'] - (float)$financial['ventes_ht'];

$moneyWhere = [];
$moneyParams = [];
if ($selectedMagasinId > 0) {
    $moneyWhere[] = 'magasin_id=?';
    $moneyParams[] = $selectedMagasinId;
}
$moneyWhereSql = $moneyWhere ? ' AND ' . implode(' AND ', $moneyWhere) : '';
$moneyStmt = $pdo->prepare("SELECT
    COALESCE(SUM(CASE WHEN type='recette' THEN montant ELSE 0 END), 0) AS recettes,
    COALESCE(SUM(CASE WHEN type='depense' THEN montant ELSE 0 END), 0) AS depenses
    FROM transactions_financieres
    WHERE DATE(created_at) BETWEEN ? AND ? $moneyWhereSql");
$moneyStmt->execute(array_merge([$start, $end], $moneyParams));
$money = $moneyStmt->fetch();
$money['total_encaisse'] = (float)$kpi['ca'] + (float)$money['recettes'];
$money['solde'] = $money['total_encaisse'] - (float)$money['depenses'];

$periodTaxes = [];
foreach ([$previousMonth, $currentMonth] as $taxMonth) {
    $taxStart = $taxMonth . '-01';
    $taxEnd = date('Y-m-d', strtotime($taxStart . ' +1 month'));
    $taxParams = [$taxStart, $taxEnd];
    $taxSql = 'SELECT COALESCE(SUM(tva), 0) FROM ventes WHERE date_vente>=? AND date_vente<?';
    if ($selectedMagasinId > 0) {
        $taxSql .= ' AND magasin_id=?';
        $taxParams[] = $selectedMagasinId;
    }
    $taxQuery = $pdo->prepare($taxSql);
    $taxQuery->execute($taxParams);
    $paidQuery = $pdo->prepare('SELECT montant, date_paiement FROM paiements_tva WHERE magasin_id=? AND mois=?');
    $paidQuery->execute([$selectedMagasinId, $taxMonth]);
    $paid = $paidQuery->fetch() ?: null;
    $periodTaxes[$taxMonth] = ['montant' => (float)$taxQuery->fetchColumn(), 'paye' => $paid];
}

$magasinOptions = $isAdmin ? $pdo->query("SELECT id, nom FROM magasins WHERE statut='actif' ORDER BY nom")->fetchAll() : [];

/* =========================
   TOP PRODUITS
========================= */
$stmt = $pdo->prepare("
    SELECT p.nom,
           SUM(lv.quantite) total,
           SUM(lv.sous_total) chiffre_affaires
    FROM ligne_ventes lv
    JOIN produits p ON p.id = lv.produit_id
    JOIN ventes v ON v.id = lv.vente_id
        AND p.magasin_id=v.magasin_id
    $where
    GROUP BY lv.produit_id
    ORDER BY chiffre_affaires DESC
    LIMIT 5
");
$stmt->execute($params);
$topProducts = $stmt->fetchAll();

/* =========================
   TOP CAISSIERS
========================= */
$stmt = $pdo->prepare("
    SELECT u.nom,
           m.nom AS magasin_nom,
           SUM(v.total) total,
           COUNT(v.id) ventes
    FROM ventes v
    JOIN utilisateurs u ON u.id = v.utilisateur_id
    LEFT JOIN magasins m ON m.id = v.magasin_id
    $where
    GROUP BY v.utilisateur_id, v.magasin_id
    ORDER BY total DESC
    LIMIT 5
");
$stmt->execute($params);
$topCashiers = $stmt->fetchAll();

/* =========================
   TOP MAGASINS
========================= */
$storeWhere = "WHERE DATE(v.date_vente) BETWEEN ? AND ?";
$storeParams = [$start, $end];
if ($selectedMagasinId > 0) {
    $storeWhere .= ' AND v.magasin_id=?';
    $storeParams[] = $selectedMagasinId;
}
$storeStmt = $pdo->prepare("SELECT m.nom AS magasin_nom, SUM(v.total) total, COUNT(v.id) ventes FROM ventes v JOIN magasins m ON m.id=v.magasin_id $storeWhere GROUP BY v.magasin_id ORDER BY total DESC LIMIT 5");
$storeStmt->execute($storeParams);
$topStores = $storeStmt->fetchAll();
$storesTotal = array_sum(array_map(static fn(array $store): float => (float)$store['total'], $topStores));
$allStoresTotalStmt = $pdo->prepare("SELECT COALESCE(SUM(v.total), 0) FROM ventes v $storeWhere");
$allStoresTotalStmt->execute($storeParams);
$allStoresTotal = (float)$allStoresTotalStmt->fetchColumn();

/* =========================
   CA CAISSE DU JOUR
========================= */
$todayWhere = 'WHERE DATE(v.date_vente)=CURDATE()';
$todayParams = [];
if (!$isAdmin || $selectedMagasinId > 0) {
    $todayWhere .= ' AND v.magasin_id=?';
    $todayParams[] = $isAdmin && $selectedMagasinId > 0 ? $selectedMagasinId : (int)currentMagasinId();
}
$todayStmt = $pdo->prepare("SELECT COUNT(*) ventes, COALESCE(SUM(v.total), 0) ca, COALESCE(MAX(v.total), 0) meilleure_vente FROM ventes v $todayWhere");
$todayStmt->execute($todayParams);
$todayKpi = $todayStmt->fetch();
$todayStoresStmt = $pdo->prepare("SELECT m.nom AS magasin_nom, COUNT(v.id) ventes, COALESCE(SUM(v.total), 0) ca, COALESCE(MAX(v.total), 0) meilleure_vente FROM ventes v JOIN magasins m ON m.id=v.magasin_id $todayWhere GROUP BY v.magasin_id ORDER BY ca DESC");
$todayStoresStmt->execute($todayParams);
$todayStores = $todayStoresStmt->fetchAll();
$bestTodayStmt = $pdo->prepare("SELECT v.id, v.total, v.date_vente, m.nom AS magasin_nom, u.nom AS caissier_nom FROM ventes v JOIN magasins m ON m.id=v.magasin_id LEFT JOIN utilisateurs u ON u.id=v.utilisateur_id $todayWhere ORDER BY v.total DESC, v.id DESC LIMIT 1");
$bestTodayStmt->execute($todayParams);
$bestToday = $bestTodayStmt->fetch() ?: null;

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
<style>
@media print {
    nav, aside, form, button, a, .sidebar, .no-print { display: none !important; }
    body, main, .main-content { background: #fff !important; color: #000 !important; }
    .shadow, .rounded-2xl { box-shadow: none !important; }
}
</style>
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

<?php if ($paymentMessage): ?><div class="mb-6 rounded-xl bg-green-100 p-4 text-green-800"><?= e($paymentMessage) ?></div><?php endif; ?>
<?php if ($paymentError): ?><div class="mb-6 rounded-xl bg-red-100 p-4 text-red-800"><?= e($paymentError) ?></div><?php endif; ?>

<?php if ($isAdmin): ?>
<div class="mb-6 rounded-2xl bg-white p-4 shadow">
    <form method="get" class="flex flex-wrap items-center gap-3">
        <input type="hidden" name="start" value="<?= e($start) ?>">
        <input type="hidden" name="end" value="<?= e($end) ?>">
        <input type="hidden" name="caissier" value="<?= e($caissier) ?>">
        <label class="font-bold">Magasin
            <select name="magasin_id" class="ml-2 rounded-xl border p-3">
                <option value="0">Tous les magasins</option>
                <?php foreach ($magasinOptions as $store): ?><option value="<?= (int)$store['id'] ?>" <?= $selectedMagasinId === (int)$store['id'] ? 'selected' : '' ?>><?= e($store['nom']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <button class="rounded-xl bg-slate-900 px-4 py-3 font-bold text-white">Appliquer</button>
    </form>
</div>
<?php endif; ?>

<div class="mb-6 grid gap-4 md:grid-cols-3">
    <div class="rounded-2xl bg-white p-5 shadow"><p class="text-sm text-gray-500">Coût des produits vendus</p><h2 class="text-2xl font-black"><?= number_format((float)$financial['cout_achat'], 2, ',', ' ') ?> <?= e($devise) ?></h2></div>
    <div class="rounded-2xl bg-white p-5 shadow"><p class="text-sm text-gray-500">Bénéfice brut</p><h2 class="text-2xl font-black text-green-700"><?= number_format((float)$financial['benefice_brut'], 2, ',', ' ') ?> <?= e($devise) ?></h2><p class="text-xs text-gray-500">Ventes hors TVA moins coût d’achat</p></div>
    <div class="rounded-2xl bg-white p-5 shadow"><p class="text-sm text-gray-500">TVA sur la période</p><h2 class="text-2xl font-black text-orange-600"><?= number_format((float)$financial['tva'], 2, ',', ' ') ?> <?= e($devise) ?></h2></div>
    <div class="rounded-2xl bg-white p-5 shadow"><p class="text-sm text-gray-500">Recettes enregistrées</p><h2 class="text-2xl font-black"><?= number_format((float)$money['recettes'], 2, ',', ' ') ?> <?= e($devise) ?></h2></div>
    <div class="rounded-2xl bg-white p-5 shadow"><p class="text-sm text-gray-500">Dépenses enregistrées</p><h2 class="text-2xl font-black text-red-700"><?= number_format((float)$money['depenses'], 2, ',', ' ') ?> <?= e($devise) ?></h2></div>
    <div class="rounded-2xl bg-white p-5 shadow"><p class="text-sm text-gray-500">Montant encaissé / solde</p><h2 class="text-2xl font-black"><?= number_format((float)$money['total_encaisse'], 2, ',', ' ') ?> <?= e($devise) ?></h2><p class="text-sm text-gray-600">Solde après dépenses : <?= number_format((float)$money['solde'], 2, ',', ' ') ?> <?= e($devise) ?></p></div>
</div>

<div class="mb-6 grid gap-4 md:grid-cols-2">
<?php foreach ($periodTaxes as $taxMonth => $tax): ?>
    <div class="rounded-2xl bg-white p-5 shadow">
        <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="text-xl font-black">TVA <?= e($taxMonth) ?></h2><p class="text-sm text-gray-500">TVA déclarée selon les ventes enregistrées</p></div><strong class="text-xl text-orange-600"><?= number_format($tax['montant'], 2, ',', ' ') ?> <?= e($devise) ?></strong></div>
        <?php if ($tax['paye']): ?><p class="mt-4 rounded-xl bg-green-100 p-3 text-green-800">Payée le <?= e($tax['paye']['date_paiement']) ?>.</p>
        <?php elseif ($taxMonth === $previousMonth && $selectedMagasinId > 0): ?><form method="post" class="mt-4"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="payer_tva" value="1"><input type="hidden" name="mois" value="<?= e($taxMonth) ?>"><input type="hidden" name="magasin_id" value="<?= (int)$selectedMagasinId ?>"><button class="rounded-xl bg-orange-600 px-4 py-3 font-bold text-white">Payer la TVA du mois passé</button></form>
        <?php else: ?><p class="mt-4 rounded-xl bg-yellow-100 p-3 text-yellow-800">Non payée. Le paiement est disponible pour le mois clôturé.</p><?php endif; ?>
    </div>
<?php endforeach; ?>
</div>

<div class="mb-6 rounded-2xl bg-slate-900 p-5 text-white shadow">
    <h2 class="text-xl font-black">Notions sur l’argent</h2>
    <p class="mt-2 text-slate-200">Le chiffre d’affaires est le total des ventes. Le bénéfice brut retire le coût d’achat des produits vendus. Les recettes et dépenses viennent du registre financier. Le solde présenté ne remplace pas un rapprochement bancaire.</p>
</div>

<section class="mb-6 rounded-2xl bg-white p-5 shadow">
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div><h2 class="text-2xl font-black">📊 CA caisse aujourd’hui</h2><p class="text-sm text-gray-500">Ventes enregistrées depuis minuit<?= $isAdmin && $selectedMagasinId === 0 ? ' dans tous les magasins' : ' dans le magasin autorisé' ?>.</p></div>
        <strong class="rounded-xl bg-green-100 px-4 py-3 text-xl text-green-800"><?= number_format((float)$todayKpi['ca'], 2, ',', ' ') ?> <?= e($devise) ?></strong>
    </div>
    <div class="grid gap-4 md:grid-cols-3">
        <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-gray-500">Total ventes</p><strong class="text-2xl"><?= (int)$todayKpi['ventes'] ?></strong></div>
        <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-gray-500">Meilleure vente du jour</p><strong class="text-2xl text-orange-600"><?= number_format((float)$todayKpi['meilleure_vente'], 2, ',', ' ') ?> <?= e($devise) ?></strong></div>
        <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-gray-500">Répartition par magasin</p><strong class="text-2xl"><?= count($todayStores) ?></strong><span class="ml-1 text-sm text-gray-500">magasin(s)</span></div>
    </div>
    <?php if ($bestToday): ?><div class="mt-4 rounded-xl border border-orange-100 bg-orange-50 p-4 text-orange-900"><strong>🏆 Meilleure vente :</strong> <?= number_format((float)$bestToday['total'], 2, ',', ' ') ?> <?= e($devise) ?> · <?= e($bestToday['magasin_nom']) ?> · Caissier : <?= e($bestToday['caissier_nom'] ?? 'N/A') ?> · <?= e($bestToday['date_vente']) ?></div><?php endif; ?>
    <div class="mt-5 overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-gray-100"><tr><th class="p-3 text-left">Magasin</th><th class="p-3 text-center">Ventes</th><th class="p-3 text-right">CA du jour</th><th class="p-3 text-right">Part du total</th><th class="p-3 text-right">Meilleure vente</th></tr></thead><tbody>
        <?php foreach ($todayStores as $store): $todayPercentage = (float)$todayKpi['ca'] > 0 ? ((float)$store['ca'] / (float)$todayKpi['ca']) * 100 : 0; ?><tr class="border-t"><td class="p-3 font-bold"><?= e($store['magasin_nom']) ?></td><td class="p-3 text-center"><?= (int)$store['ventes'] ?></td><td class="p-3 text-right font-bold text-green-700"><?= number_format((float)$store['ca'], 2, ',', ' ') ?> <?= e($devise) ?></td><td class="p-3 text-right"><?= number_format($todayPercentage, 1, ',', ' ') ?>%</td><td class="p-3 text-right text-orange-600"><?= number_format((float)$store['meilleure_vente'], 2, ',', ' ') ?> <?= e($devise) ?></td></tr><?php endforeach; ?>
        <?php if (!$todayStores): ?><tr><td colspan="5" class="p-5 text-center text-gray-500">Aucune vente aujourd’hui.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>

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

<!-- ================= CLASSEMENTS ================= -->
<div class="grid gap-6 xl:grid-cols-3 mb-6">
    <section class="bg-white dark:bg-slate-900 p-5 rounded-2xl shadow">
        <h2 class="mb-4 font-bold">📊 Top 5 produits</h2>
        <div class="relative h-72"><canvas id="topProductsChart"></canvas></div>
    </section>

    <section class="bg-white dark:bg-slate-900 p-5 rounded-2xl shadow">
        <h2 class="mb-4 font-bold">📈 Top 5 caissiers</h2>
        <div class="relative h-72"><canvas id="topCashiersChart"></canvas></div>
    </section>

    <section class="bg-white dark:bg-slate-900 p-5 rounded-2xl shadow">
        <h2 class="mb-4 font-bold">📊 Top 5 magasins</h2>
        <div class="relative h-72"><canvas id="topStoresChart"></canvas></div>
    </section>
</div>

<script src="assets/vendor/chart.min.js"></script>
<script>
const chartCurrency = <?= json_encode($devise, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const productLabels = <?= json_encode(array_map(static fn(array $item): string => (string)$item['nom'], $topProducts), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const productValues = <?= json_encode(array_map(static fn(array $item): float => (float)$item['chiffre_affaires'], $topProducts)) ?>;
const cashierLabels = <?= json_encode(array_map(static fn(array $item): string => (string)$item['nom'] . ' - ' . (string)($item['magasin_nom'] ?? 'Magasin inconnu'), $topCashiers), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const cashierValues = <?= json_encode(array_map(static fn(array $item): float => (float)$item['total'], $topCashiers)) ?>;
const storeLabels = <?= json_encode(array_map(static fn(array $item): string => (string)$item['magasin_nom'], $topStores), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const storeValues = <?= json_encode(array_map(static fn(array $item): float => $allStoresTotal > 0 ? ((float)$item['total'] / $allStoresTotal) * 100 : 0, $topStores)) ?>;
const chartOptions = { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: (context) => `${Number(context.raw).toLocaleString('fr-FR', {maximumFractionDigits: 2})} ${chartCurrency}` } } }, scales: { x: { beginAtZero: true, ticks: { callback: (value) => Number(value).toLocaleString('fr-FR') } } } };
function renderBarChart(id, labels, values, color) {
    const element = document.getElementById(id);
    if (!element || !labels.length) return;
    new Chart(element, { type: 'bar', data: { labels, datasets: [{ data: values, backgroundColor: color, borderRadius: 6, barThickness: 22 }] }, options: { ...chartOptions, indexAxis: 'y' } });
}
renderBarChart('topProductsChart', productLabels, productValues, '#16a34a');
renderBarChart('topCashiersChart', cashierLabels, cashierValues, '#2563eb');
const storeChart = document.getElementById('topStoresChart');
if (storeChart && storeLabels.length) {
    new Chart(storeChart, { type: 'doughnut', data: { labels: storeLabels, datasets: [{ data: storeValues, backgroundColor: ['#f97316', '#0ea5e9', '#22c55e', '#a855f7', '#eab308'], borderWidth: 2 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: (context) => `${context.label}: ${Number(context.raw).toLocaleString('fr-FR', {maximumFractionDigits: 1})}%` } } } } });
}
</script>

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