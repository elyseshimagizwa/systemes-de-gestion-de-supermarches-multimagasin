<?php
require_once 'config.php';
require_once 'config-settings.php';

requireLogin();

/* =========================
   ACCÈS AUTORISÉ
   admin + caissier uniquement
========================= */
$user = currentUser();

$allowedRoles = ['admin', 'caissier'];

if (!in_array($user['role'], $allowedRoles)) {
    header("Location: dashboard.php?error=access_denied");
    exit;
}

$isAdmin = ($user['role'] === 'admin');

$settings = getSettings();

$devise = $settings['devise'] ?? 'FCFA';
$tvaRate = (float)($settings['tva'] ?? 0);

/* =========================
   FILTRES
========================= */

$search = $_GET['search'] ?? '';
$date1  = $_GET['date1'] ?? '';
$date2  = $_GET['date2'] ?? '';
$userId = $_GET['user'] ?? '';

$where = [];
$params = [];

/* SEARCH */
if($search){

    $where[] = "(v.id LIKE ?)";
    $params[] = "%$search%";
}

/* DATE */
if($date1){

    $where[] = "DATE(v.date_vente) >= ?";
    $params[] = $date1;
}

if($date2){

    $where[] = "DATE(v.date_vente) <= ?";
    $params[] = $date2;
}


// Démarrez la session au tout début du fichier si ce n'est pas déjà fait
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ==========================================================================
   1. GESTION DES FILTRES & PARAMÈTRES
   ========================================================================== */
$where = [];
$params = [];

// FILTRE OBLIGATOIRE : Uniquement le magasin de l'utilisateur connecté
$isGlobalAdmin =
    isAdmin();

$magasin_id =
    (int)($user['magasin_id'] ?? 0);

if(!$isGlobalAdmin){

    $where[] =
        "v.magasin_id=?";

    $params[] =
        $magasin_id; 
}
$magasin_filter =
    (int)($_GET['magasin_id'] ?? 0);
if(
    $isGlobalAdmin
    &&
    $magasin_filter > 0
){

    $where[] =
        "v.magasin_id=?";

    $params[] =
        $magasin_filter;
}

// FILTRE OPTIONNEL : Si un utilisateur/vendeur spécifique est sélectionné
if (!empty($userId)) {
    $where[] = "v.utilisateur_id = ?";
    $params[] = $userId;
}

/* ==========================================================================
   2. REQUÊTE SQL PRINCIPALE (Avec alias pour corriger le Warning 'nom')
   ========================================================================== */
$sql = "
SELECT
    v.*,
    u.nom AS utilisateur_nom, -- Résout le conflit de la clé 'nom'
    m.nom AS magasin_nom       -- Optionnel : pour afficher le nom du magasin
FROM ventes v
LEFT JOIN utilisateurs u ON u.id = v.utilisateur_id
LEFT JOIN magasins m ON m.id = v.magasin_id
";

/* Application des filtres WHERE */
if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

/* ==========================================================================
   3. PAGINATION
   ========================================================================== */
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

$sql .= " ORDER BY v.id DESC LIMIT $limit OFFSET $offset";

/* ==========================================================================
   4. REQUÊTE DE COMPTAGE (Optimisée sans JOIN inutiles)
   ========================================================================== */
$countSql = "SELECT COUNT(*) FROM ventes v";

if (!empty($where)) {
    $countSql .= " WHERE " . implode(" AND ", $where);
}

$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$totalPages = max(1, ceil($totalRows / $limit));

/* ==========================================================================
   5. EXÉCUTION & RÉCUPÉRATION DES DONNÉES
   ========================================================================== */
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$ventes = $stmt->fetchAll(PDO::FETCH_ASSOC); // FETCH_ASSOC évite les doublons d'index numériques

/* LISTE DES UTILISATEURS POUR LE FORMULAIRE DE FILTRE */
$usersSql = "
    SELECT id, nom
    FROM utilisateurs
    ";

if (!$isGlobalAdmin) {
    $usersSql .= " WHERE magasin_id=".(int)$magasin_id;
}

$users = $pdo->query($usersSql." ORDER BY nom ASC")->fetchAll(PDO::FETCH_ASSOC);


$magasins = [];

if($isGlobalAdmin){

    $magasins =
        $pdo->query("
        SELECT
            id,
            nom
        FROM magasins
        ORDER BY nom
        ")
        ->fetchAll();
}

$statsJourSql = "
SELECT

COUNT(*) nb_ventes,

COALESCE(
SUM(total),
0
) total_jour

FROM ventes

WHERE DATE(date_vente)=CURDATE()
";

$statsParams = [];

if(!$isGlobalAdmin){

    $statsJourSql .= "
    AND magasin_id=?
    ";

    $statsParams[] =
        $magasin_id;
}

$statsJour = $pdo->prepare(
    $statsJourSql
);

$statsJour->execute(
    $statsParams
);

$jour =
    $statsJour->fetch();

$nbVentesJour =
    (int)$jour['nb_ventes'];

$totalJour =
    (float)$jour['total_jour']; 

    $produitsJourSql = "
SELECT

COALESCE(
SUM(lv.quantite),
0
)

FROM ligne_ventes lv

INNER JOIN ventes v
ON v.id=lv.vente_id

WHERE DATE(v.date_vente)=CURDATE()
";

$produitsParams = [];

if(!$isGlobalAdmin){

    $produitsJourSql .= "
    AND v.magasin_id=?
    ";

    $produitsParams[] =
        $magasin_id;
}

$stmtProduits =
    $pdo->prepare(
        $produitsJourSql
    );

$stmtProduits->execute(
    $produitsParams
);

$totalProduitsJour =
    (int)$stmtProduits
    ->fetchColumn();

    $venteMoyenne =

$nbVentesJour > 0

? $totalJour / $nbVentesJour

: 0;



include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="p-4 md:p-6">

<!-- HEADER -->
<div class="flex flex-col md:flex-row md:items-center md:justify-between mb-6 gap-4">

    <div>

        <h1 class="text-3xl font-bold">
            🧾 Historique des ventes
        </h1>

        <p class="text-gray-500 dark:text-gray-400">
            Gestion complète des tickets POS
        </p>

    </div>

    <div class="flex gap-3 flex-wrap">

        <div class="bg-white dark:bg-slate-800 shadow rounded-2xl p-4 min-w-[140px]">
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">

<div class="bg-white shadow rounded-2xl p-4">

<div class="text-gray-500 text-sm">
Ventes du jour
</div>

<div class="text-3xl font-bold text-blue-600">

<?= $nbVentesJour ?>

</div>

</div>

<div class="bg-white shadow rounded-2xl p-4">

<div class="text-gray-500 text-sm">
CA du jour
</div>

<div class="text-3xl font-bold text-green-600">

<?= number_format($totalJour,0,',',' ') ?>
<?= $devise ?>

</div>

</div>

<div class="bg-white shadow rounded-2xl p-4">

<div class="text-gray-500 text-sm">
Produits vendus
</div>

<div class="text-3xl font-bold text-purple-600">

<?= $totalProduitsJour ?>

</div>

</div>

<div class="bg-white shadow rounded-2xl p-4">

<div class="text-gray-500 text-sm">
Vente moyenne
</div>

<div class="text-3xl font-bold text-orange-600">

<?= number_format($venteMoyenne,0,',',' ') ?>
<?= $devise ?>

</div>

</div>

</div>

            <div class="text-2xl font-bold text-blue-600">
                <?= $tvaRate ?>%
            </div>

        </div>

    </div>

</div>

<!-- FILTRES -->
<div class="bg-white dark:bg-slate-800 p-4 rounded-2xl shadow mb-6">
<form method="GET"
class="grid md:grid-cols-6 gap-4">

    <input
        type="text"
        name="search"
        value="<?= e($search) ?>"
        placeholder="🔎 Ticket ID"
        class="border dark:border-slate-700
        dark:bg-slate-900 rounded-xl p-3"
    >

    <input
        type="date"
        name="date1"
        value="<?= e($date1) ?>"
        class="border dark:border-slate-700
        dark:bg-slate-900 rounded-xl p-3"
    >

    <input
        type="date"
        name="date2"
        value="<?= e($date2) ?>"
        class="border dark:border-slate-700
        dark:bg-slate-900 rounded-xl p-3"
    >

    <select
        name="user"
        class="border dark:border-slate-700
        dark:bg-slate-900 rounded-xl p-3"
    >

        <option value="">
            Tous utilisateurs
        </option>

        <?php foreach($users as $u): ?>

        <option
            value="<?= $u['id'] ?>"
            <?= $userId==$u['id'] ? 'selected':'' ?>
        >
            <?= e($u['nom']) ?>
        </option>

        <?php endforeach; ?>

    </select>

    <?php if($isGlobalAdmin): ?>

<select
name="magasin_id"
class="border rounded-xl p-3">

<option value="">
Tous magasins
</option>

<?php foreach($magasins as $m): ?>

<option
value="<?= $m['id'] ?>"
<?= $magasin_filter == $m['id']
? 'selected'
: '' ?>>

<?= e($m['nom']) ?>

</option>

<?php endforeach; ?>

</select>

<?php endif; ?>

    <button
        class="bg-blue-600 hover:bg-blue-700
        text-white rounded-xl font-bold"
    >
        🔍 Filtrer
    </button>

</form>

</div>

<!-- TABLE -->
<div class="bg-white dark:bg-slate-800 rounded-2xl shadow overflow-hidden">

<div class="overflow-auto">

<table class="w-full">

<thead class="bg-slate-100 dark:bg-slate-900">

<tr>

    <th class="p-4 text-left">#</th>
    <th class="p-4 text-left">Caissier</th>
     <th class="p-4 text-left">Magasin</th>
    <th class="p-4 text-left">Montant TTC</th>
    <th class="p-4 text-left">TVA</th>
    <th class="p-4 text-left">Paiement</th>
    <th class="p-4 text-left">Date</th>
    <th class="p-4 text-left">Actions</th>

</tr>

</thead>

<tbody
id="salesTable"
data-page="<?= $page ?>"

<?php foreach($ventes as $v): ?>

<?php

$total = (float)$v['total'];

$tva = isset($v['tva'])
    ? (float)$v['tva']
    : ($total * $tvaRate / 100);

?>

<tr class="border-b dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-900 transition">

    <td class="p-4 font-bold">
        #<?= $v['id'] ?>
    </td>

    <td class="p-4">
        <?= e($v['utilisateur_nom']) ?>
    </td>

    <td class="p-4">

<?= e($v['magasin_nom']) ?>

</td>

    <td class="p-4 text-green-600 font-bold">
        <?= number_format($total,2) ?> <?= $devise ?>
    </td>

    <td class="p-4 text-blue-600 font-semibold">
        <?= number_format($tva,2) ?> <?= $devise ?>
    </td>

    <td class="p-4">
        <span class="px-3 py-1 rounded-full text-sm bg-slate-200 dark:bg-slate-700">
            <?= e($v['mode_paiement']) ?>
        </span>
    </td>

    <td class="p-4">
        <?= e($v['date_vente']) ?>
    </td>

    <td class="p-4">

        <div class="flex gap-2 flex-wrap">

            <a href="ticket_pdf.php?id=<?= $v['id'] ?>"
               target="_blank"
               class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded-lg text-sm">

                🖨 Ticket

            </a>

            <button
                onclick="showDetails(<?= $v['id'] ?>)"
                class="bg-slate-700 hover:bg-slate-800 text-white px-3 py-2 rounded-lg text-sm"
            >
                👁 Détails
            </button>

            <a href="ticket_pdf.php?id=<?= $v['id'] ?>"
               target="_blank"
               class="bg-green-600 hover:bg-green-700 text-white px-3 py-2 rounded-lg text-sm">

                🔁 Réimprimer

            </a>

            <?php if($isAdmin): ?>

<?php if($v['statut']!='annulee'): ?>

<a
href="annuler_vente.php?id=<?= $v['id'] ?>"
onclick="return confirm('Voulez-vous vraiment annuler cette vente ?');"
class="bg-red-600 hover:bg-red-700 text-white px-3 py-2 rounded-lg text-sm">

❌ Annuler

</a>

<?php else: ?>

<span
class="bg-gray-400 text-white px-3 py-2 rounded-lg text-sm">

Déjà annulée

</span>

<?php endif; ?>

<?php endif; ?>

        </div>

    </td>

</tr>

<?php endforeach; ?>

<?php if(empty($ventes)): ?>

<tr>
    <td colspan="7" class="p-8 text-center text-gray-500">
        Aucune vente trouvée
    </td>
</tr>

<?php endif; ?>

</tbody>

</table>

</div>

</div>

<!--Ajouter la navigation des pages-->
<div class="flex justify-center mt-6 gap-2 flex-wrap">

<?php for($i=1;$i<=$totalPages;$i++): ?>

<a
href="?<?= http_build_query(
array_merge(
$_GET,
['page'=>$i]
)
) ?>"
class="
px-4 py-2 rounded-xl
<?= $page==$i
? 'bg-blue-600 text-white'
: 'bg-white dark:bg-slate-800'
?>
"
>

<?= $i ?>

</a>

<?php endfor; ?>

</div>
<!-- MODAL -->
<div id="modal" class="fixed inset-0 bg-black/70 hidden items-center justify-center z-50 p-4">

<div class="bg-white dark:bg-slate-800 w-full max-w-3xl rounded-2xl p-6">

    <div class="flex justify-between items-center mb-4">

        <h2 class="text-2xl font-bold">
            🧾 Détails Ticket
        </h2>

        <button onclick="closeModal()"
            class="bg-red-600 hover:bg-red-700 text-white w-10 h-10 rounded-full">
            ✖
        </button>

    </div>

    <div id="modalContent">
        <div class="text-center py-10 text-gray-500">
            Chargement...
        </div>
    </div>

</div>

</div>

</div>

<script>

async function showDetails(id){

    document.getElementById("modal").classList.remove("hidden");
    document.getElementById("modal").classList.add("flex");


    //Optimisation AJAX du détail ticket


    let r = await fetch(
    "vente_details_ajax.php?id="+id,
    {
        cache:"force-cache"
    }
);
    document.getElementById("modalContent").innerHTML = await r.text();
}

function closeModal(){
    document.getElementById("modal").classList.add("hidden");
    document.getElementById("modal").classList.remove("flex");
}
let loading = false;

window.addEventListener(
'scroll',
async ()=>{

if(loading) return;

if(
window.innerHeight +
window.scrollY
>=
document.body.offsetHeight - 300
){

loading = true;

console.log(
"Prêt pour chargement page suivante"
);

/*
Ici plus tard :
historique-ventes.php?ajax=1&page=2
*/

loading = false;

}

});

</script>

<?php include 'includes/footer.php'; ?>