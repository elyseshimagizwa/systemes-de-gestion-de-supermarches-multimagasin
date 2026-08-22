<?php
require_once 'config.php';
require_once 'config-settings.php';

requireLogin();

/* =========================================================
   SETTINGS
========================================================= */

$settings = getSettings();

$devise = $settings['devise'] ?? 'BIF';

$user = currentUser();

/*=========================================================
ROLES
=========================================================*/

$role = $user['role'] ?? 'utilisateur';

$isSuperAdmin = false;
$isAdmin       = ($role == 'admin');
$isManager     = false;
$isCaissier    = ($role == 'caissier');

/*=========================================================
TENANT
=========================================================*/

/*=========================================================
MAGASIN ACTIF
=========================================================*/

$currentMagasinId =
$_SESSION['magasin_id']
??
$user['magasin_id']
??
0;
$magasin_id = currentMagasinId();
/*=========================================================
ADMIN PEUT CHANGER DE MAGASIN
=========================================================*/

$selectedMagasinId =
$currentMagasinId;

if(

($isSuperAdmin || $isAdmin)

&&

isset($_GET['magasin'])

)

{

$selectedMagasinId =
(int)$_GET['magasin'];

    if ($selectedMagasinId > 0) {
        $isGlobalView = false;
    }

}

/*=========================================================
MODE GLOBAL
=========================================================*/

$isGlobalView = $isAdmin;

if ($isAdmin && isset($_GET['global']) && $_GET['global'] === '0') {
    $isGlobalView = false;
}
/* =========================================================
   FILTRES
========================================================= */

$dateDebut =
    $_GET['date_debut']
    ?? date('Y-m-01');

$dateFin =
    $_GET['date_fin']
    ?? date('Y-m-d');


    /*=========================================================
FILTRES SQL
=========================================================*/

$where = "";

$params = [];

if(

!$isGlobalView

&&

$selectedMagasinId

)

{

    $where .= " AND magasin_id=? ";

    $params[] = $selectedMagasinId;

}
/* =========================================================
   AJAX KPI
========================================================= */

if (isset($_GET['ajax'])) {

    header('Content-Type: application/json');

    /* =========================
       KPI
    ========================== */

    if ($_GET['ajax'] === 'kpi') {

        $stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(montant),0)
            FROM transactions_financieres
           WHERE type='recette'
           AND 1=1
           $where
            AND DATE(created_at)
            BETWEEN ? AND ?
        ");

       $paramsRecette = $params;

$paramsRecette[] = $dateDebut;
$paramsRecette[] = $dateFin;

$stmt->execute($paramsRecette);

        $recettes = $stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(montant),0)
            FROM transactions_financieres
            WHERE type='depense'
            $where
            AND DATE(created_at)
            BETWEEN ? AND ?
        ");

        $paramsDepense = $params;
        $paramsDepense[] = $dateDebut;
        $paramsDepense[] = $dateFin;
        $stmt->execute($paramsDepense);

        $depenses = $stmt->fetchColumn();

        $benefice =
            $recettes - $depenses;

        echo json_encode([

            "recettes" =>
                number_format($recettes,2),

            "depenses" =>
                number_format($depenses,2),

            "benefice" =>
                number_format($benefice,2)
        ]);

        exit;
    }

    /* =========================
       LIVE TRANSACTIONS
    ========================== */

    if ($_GET['ajax'] === 'transactions') {

        $stmt = $pdo->prepare("
            SELECT

             t.*,

             u.nom utilisateur,

            m.nom magasin

            FROM transactions_financieres t

            LEFT JOIN utilisateurs u
            ON u.id=t.utilisateur_id
            
            LEFT JOIN magasins m
            ON m.id=t.magasin_id

            WHERE 1=1
            $where

            AND DATE(t.created_at)
            BETWEEN ? AND ?

            ORDER BY t.id DESC

            LIMIT 10
        ");

        $paramsTransactions = $params;
        $paramsTransactions[] = $dateDebut;
        $paramsTransactions[] = $dateFin;
        $stmt->execute($paramsTransactions);

        $data = $stmt->fetchAll();

        echo json_encode($data);

        exit;
    }

    /* =========================
       GRAPH
    ========================== */

    if ($_GET['ajax'] === 'graph') {

        $stmt = $pdo->prepare("
            SELECT

                DATE(created_at) as date,

                SUM(
                    CASE
                    WHEN type='recette'
                    THEN montant
                    ELSE -montant
                    END
                ) as total

            FROM transactions_financieres

            WHERE 1=1
            $where

            AND DATE(created_at)
            BETWEEN ? AND ?

            GROUP BY DATE(created_at)
        ");

        $paramsGraph = $params;
        $paramsGraph[] = $dateDebut;
        $paramsGraph[] = $dateFin;
        $stmt->execute($paramsGraph);

        $data = $stmt->fetchAll();

        echo json_encode($data);

        exit;
    }
}

/* =========================================================
   AJOUT TRANSACTION
========================================================= */

if (

    $_SERVER['REQUEST_METHOD'] === 'POST'

    &&

    isset($_POST['ajouter'])
) {

    verify_csrf();

    $type =
        $_POST['type'];

    $categorie =
        trim($_POST['categorie']);

    $description =
        trim($_POST['description']);

    $montant =
        (float)$_POST['montant'];

    if ($montant <= 0) {

        flash(
            'error',
            'Montant invalide'
        );

        header("Location: transactions.php");

        exit;
    }

    $sessionCaisseId = currentOpenCaisseId($user['id'], $magasin_id);

    if ($sessionCaisseId === null) {
        flash('error', 'Ouvrez une session de caisse avant d’ajouter une recette ou une dépense.');
        header("Location: transactions.php");
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO transactions_financieres
        (
            magasin_id,
            session_caisse_id,
            type,
            categorie,
            description,
            montant,
            utilisateur_id,
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
            ?,
            NOW()
        )
    ");

    $stmt->execute([

        $magasin_id,

        $sessionCaisseId,

        $type,

        $categorie,

        $description,

        $montant,

        $user['id']
    ]);

    /* =========================
       HISTORIQUE
    ========================== */

    $pdo->prepare("
        INSERT INTO historiques
        (
            utilisateur_id,
            magasin_id,
            action,
            details,
            ip,
            created_at,
            niveau
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            ?,
            NOW(),
            ?
        )
    ")->execute([

        $user['id'],

        $magasin_id,

        strtoupper($type),

        $type . " : "
        . $description
        . " | "
        . $montant
        . " "
        . $devise,

        $_SERVER['REMOTE_ADDR'],

        $type == 'recette'
            ? 'success'
            : 'danger'
    ]);

    flash(
        'success',
        '✅ Transaction ajoutée'
    );

    header("Location: transactions.php");

    exit;
}

/* =========================================================
   DELETE
========================================================= */

if (

    isset($_GET['delete'])

    &&

    $isAdmin
) {

    $id = (int)$_GET['delete'];

    $q = $pdo->prepare("
        SELECT *
        FROM transactions_financieres
        WHERE id=?
        AND magasin_id=?
    ");

    $q->execute([

        $id,

        $magasin_id
    ]);

    $t = $q->fetch();

    if ($t) {

        $pdo->prepare("
            DELETE FROM transactions_financieres
            WHERE id=?
            AND magasin_id=?
        ")->execute([

            $id,

            $magasin_id
        ]);

        /* HISTORIQUE */

        $pdo->prepare("
            INSERT INTO historiques
            (
                utilisateur_id,
                magasin_id,
                action,
                details,
                ip,
                created_at,
                niveau
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?,
                NOW(),
                ?
            )
        ")->execute([

            $user['id'],

            $magasin_id,

            'SUPPRESSION TRANSACTION',

            'Suppression : '
            . $t['description']
            . ' | '
            . $t['montant']
            . ' '
            . $devise,

            $_SERVER['REMOTE_ADDR'],

            'danger'
        ]);
    }

    flash(
        'success',
        '🗑 Transaction supprimée'
    );

    header("Location: transactions.php");

    exit;
}

/* =========================================================
   UPDATE
========================================================= */

if (

    $_SERVER['REQUEST_METHOD'] === 'POST'

    &&

    isset($_POST['modifier'])
) {

    verify_csrf();

    $stmt = $pdo->prepare("
        UPDATE transactions_financieres
        SET
            type=?,
            categorie=?,
            description=?,
            montant=?
        WHERE id=?
        AND magasin_id=?
    ");

    $stmt->execute([

        $_POST['type'],

        $_POST['categorie'],

        $_POST['description'],

        $_POST['montant'],

        $_POST['id'],

        $magasin_id
    ]);

    /* =========================
       HISTORIQUE
    ========================== */

    $pdo->prepare("
        INSERT INTO historiques
        (
            utilisateur_id,
            magasin_id,
            action,
            details,
            ip,
            created_at,
            niveau
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            ?,
            NOW(),
            ?
        )
    ")->execute([

        $user['id'],

        $magasin_id,

        'MODIFICATION TRANSACTION',

        'Modification transaction ID : '
        . $_POST['id'],

        $_SERVER['REMOTE_ADDR'],

        'warning'
    ]);

    flash(
        'success',
        '✏ Transaction modifiée'
    );

    header("Location: transactions.php");

    exit;
}

/* =========================================================
   EDIT MODE
========================================================= */

$edit = null;

if (isset($_GET['edit'])) {

    $stmt = $pdo->prepare("
        SELECT *
        FROM transactions_financieres
        WHERE 1=1
        AND magasin_id=?
    ");

    $stmt->execute([

        (int)$_GET['edit'],

        $magasin_id
    ]);

    $edit = $stmt->fetch();
}

/* =========================================================
   TRANSACTIONS
========================================================= */

$stmt = $pdo->prepare("
    SELECT

        t.*,

         u.nom utilisateur,

            m.nom magasin

            FROM transactions_financieres t

            LEFT JOIN utilisateurs u
            ON u.id=t.utilisateur_id
            
            LEFT JOIN magasins m
            ON m.id=t.magasin_id


    WHERE t.magasin_id=?

    AND DATE(t.created_at)
    BETWEEN ? AND ?

    ORDER BY t.id DESC
");

$stmt->execute([

    $magasin_id,

    $dateDebut,

    $dateFin
]);

$transactions =
    $stmt->fetchAll();

/* =========================================================
   MAGASIN
========================================================= */

$stmtMagasin = $pdo->prepare("
    SELECT *
    FROM magasins
    WHERE id=?
");

$stmtMagasin->execute([
    $magasin_id
]);

$magasin =
    $stmtMagasin->fetch();

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="p-4 md:p-6">

<!-- HEADER -->

<div class="flex flex-col md:flex-row justify-between md:items-center gap-4 mb-6">

    <div>

        <h1 class="text-3xl font-bold">
            💰 Comptabilité
        </h1>

        <p class="text-gray-500">
            Transactions multi magasin
        </p>

    </div>

    <div class="bg-blue-100 text-blue-700 px-4 py-3 rounded-2xl font-bold">

        🏬
        <?= e($magasin['nom'] ?? 'Magasin') ?>

    </div>

</div>

<!-- ALERT -->

<?php if($m = flash('success')): ?>

<div class="bg-green-100 text-green-700 p-3 rounded-xl mb-4">

    <?= e($m) ?>

</div>

<?php endif; ?>

<?php if($m = flash('error')): ?>

<div class="bg-red-100 text-red-700 p-3 rounded-xl mb-4">

    <?= e($m) ?>

</div>

<?php endif; ?>

<!-- FILTRES -->

<div class="bg-white p-4 rounded-2xl shadow border mb-6">

<form method="GET" class="grid md:grid-cols-3 gap-4">

    <div>

        <label class="font-bold block mb-2">
            Date début
        </label>

        <input
        type="date"
        name="date_debut"
        value="<?= $dateDebut ?>"
        class="border p-3 rounded-xl w-full">

    </div>

    <div>

        <label class="font-bold block mb-2">
            Date fin
        </label>

        <input
        type="date"
        name="date_fin"
        value="<?= $dateFin ?>"
        class="border p-3 rounded-xl w-full">

    </div>

    <div class="flex items-end">

        <button class="bg-black text-white p-3 rounded-xl w-full">

            Filtrer

        </button>

    </div>

</form>

</div>

<!-- KPI -->

<div class="grid md:grid-cols-3 gap-4 mb-6">



    <div class="bg-white p-5 rounded-2xl shadow border">

        <p class="text-gray-500">
            💵 Recettes
        </p>

        <h2 id="kpiRecettes"
            class="text-3xl font-bold text-green-600">
            0
        </h2>

    </div>

    <div class="bg-white p-5 rounded-2xl shadow border">

        <p class="text-gray-500">
            💸 Dépenses
        </p>

        <h2 id="kpiDepenses"
            class="text-3xl font-bold text-red-600">
            0
        </h2>

    </div>

    <div class="bg-white p-5 rounded-2xl shadow border">

        <p class="text-gray-500">
            📈 Bénéfice
        </p>

        <h2 id="kpiBenefice"
            class="text-3xl font-bold text-blue-600">
            0
        </h2>

    </div>

</div>

<!-- BOUTON TOGGLE -->

<div class="mb-6">

<button
onclick="toggleForm()"
class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-3 rounded-2xl shadow">

    <?= $edit
        ? '✏ Modifier Transaction'
        : '➕ Nouvelle Transaction'
    ?>

</button>

</div>

<!-- FORMULAIRE -->

<div
id="transactionForm"
class="<?= $edit ? '' : 'hidden' ?> bg-white rounded-2xl shadow border p-5 mb-6">

<h2 class="text-xl font-bold mb-4">

<?= $edit
    ? '✏ Modifier Transaction'
    : '➕ Nouvelle Transaction'
?>

</h2>

<form method="POST" class="grid md:grid-cols-2 lg:grid-cols-5 gap-4">

<input
type="hidden"
name="csrf_token"
value="<?= csrf_token() ?>">

<?php if($edit): ?>

<input type="hidden" name="modifier" value="1">

<input
type="hidden"
name="id"
value="<?= $edit['id'] ?>">

<?php else: ?>

<input type="hidden" name="ajouter" value="1">

<?php endif; ?>

<select
name="type"
id="type"
onchange="updateCategories()"
required
class="border p-3 rounded-xl">

<option value="">Type</option>

<option
value="recette"
<?= (($edit['type'] ?? '') == 'recette') ? 'selected' : '' ?>>

Recette

</option>

<option
value="depense"
<?= (($edit['type'] ?? '') == 'depense') ? 'selected' : '' ?>>

Dépense

</option>

</select>

<select
name="categorie"
id="categorie"
required
class="border p-3 rounded-xl">
</select>

<input
type="text"
name="description"
required
value="<?= e($edit['description'] ?? '') ?>"
placeholder="Description"
class="border p-3 rounded-xl">

<input
type="number"
step="0.01"
name="montant"
required
value="<?= $edit['montant'] ?? '' ?>"
placeholder="Montant"
class="border p-3 rounded-xl">

<button
class="bg-green-600 text-white rounded-xl p-3">

<?= $edit ? 'Modifier' : 'Ajouter' ?>

</button>

</form>

</div>

<!-- GRAPH -->

<div class="bg-white rounded-2xl shadow border p-5 mb-6">

<h3 class="font-bold mb-4">
    📊 Evolution Financière
</h3>

<canvas id="chart"></canvas>

</div>

<!-- LIVE -->

<div class="bg-white rounded-2xl shadow border p-5 mb-6">

<h3 class="font-bold mb-4">
    🔴 Transactions Live
</h3>

<div id="liveTransactions"></div>

</div>

<!-- TABLE -->

<div class="bg-white rounded-2xl shadow border overflow-auto">

<table class="w-full">

<thead class="bg-gray-100">

<tr>

<th class="p-3 text-left">#</th>
<th class="p-3 text-left">magasins</th>
<th class="p-3 text-left">Type</th>
<th class="p-3 text-left">Catégorie</th>
<th class="p-3 text-left">Description</th>
<th class="p-3 text-left">Montant</th>
<th class="p-3 text-left">Utilisateur</th>
<th class="p-3 text-left">Date</th>
<th class="p-3 text-left">Actions</th>

</tr>

</thead>

<tbody>

<?php foreach($transactions as $t): ?>

<tr class="border-b hover:bg-gray-50">

<td class="p-3">
    <?= $t['id'] ?>
</td>

<td>

<?= e($t['magasin']) ?>

</td>

<td class="p-3">

<?php if($t['type']=='recette'): ?>

<span class="bg-green-100 text-green-700 px-3 py-1 rounded-full text-sm">

💰 Recette

</span>

<?php else: ?>

<span class="bg-red-100 text-red-700 px-3 py-1 rounded-full text-sm">

💸 Dépense

</span>

<?php endif; ?>

</td>

<td class="p-3">
    <?= e($t['categorie']) ?>
</td>

<td class="p-3">
    <?= e($t['description']) ?>
</td>

<td class="p-3 font-bold">

<?= number_format($t['montant'],2) ?>
<?= $devise ?>

</td>

<td class="p-3">
    <?= e($t['utilisateur']) ?>
</td>

<td class="p-3">
    <?= $t['created_at'] ?>
</td>

<td class="p-3 flex gap-2">

<a
href="?edit=<?= $t['id'] ?>"
class="bg-blue-600 text-white px-3 py-1 rounded">

✏

</a>

<?php if($isAdmin): ?>

<a
href="?delete=<?= $t['id'] ?>"
onclick="return confirm('Supprimer ?')"
class="bg-red-600 text-white px-3 py-1 rounded">

🗑

</a>

<?php endif; ?>

</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

</div>

<script src="assets/vendor/chart.min.js"></script>

<script>

/* =========================================================
   TOGGLE FORM
========================================================= */

function toggleForm(){

    let form =
        document.getElementById('transactionForm');

    form.classList.toggle('hidden');
}

/* =========================================================
   CATEGORIES
========================================================= */

function updateCategories(){

    let type =
        document.getElementById('type').value;

    let cat =
        document.getElementById('categorie');

    cat.innerHTML = "";

    let options = [];

    if(type === 'recette'){

        options = [

            'Vente POS',
            'Service',
            'Livraison',
            'Autres revenus'
        ];

    }else if(type === 'depense'){

        options = [

            'Loyer',
            'Transport',
            'Salaire',
            'Internet',
            'Electricité',
            'Achat matériel'
        ];
    }

    options.forEach(o=>{

        let opt =
            document.createElement('option');

        opt.value = o;

        opt.innerText = o;

        cat.appendChild(opt);
    });

    <?php if($edit): ?>

    cat.value =
        "<?= $edit['categorie'] ?>";

    <?php endif; ?>
}

updateCategories();

/* =========================================================
   KPI
========================================================= */

async function loadKPI(){

    let r = await fetch(

        "?ajax=kpi&date_debut=<?= $dateDebut ?>&date_fin=<?= $dateFin ?>"
    );

    let d = await r.json();

    document.getElementById('kpiRecettes')
    .innerText =
        d.recettes + " <?= $devise ?>";

    document.getElementById('kpiDepenses')
    .innerText =
        d.depenses + " <?= $devise ?>";

    document.getElementById('kpiBenefice')
    .innerText =
        d.benefice + " <?= $devise ?>";
}

/* =========================================================
   LIVE TRANSACTIONS
========================================================= */

async function loadTransactions(){

    let r = await fetch(

        "?ajax=transactions&date_debut=<?= $dateDebut ?>&date_fin=<?= $dateFin ?>"
    );

    let data = await r.json();

    let html = "";

    data.forEach(t=>{

        html += `
        <div class="flex justify-between border-b py-2">

            <span>
                ${t.description}
            </span>

            <b class="${
                t.type=='recette'
                ? 'text-green-600'
                : 'text-red-600'
            }">

            ${
                t.type=='recette'
                ? '+'
                : '-'
            }

            ${t.montant}
            <?= $devise ?>

            </b>

        </div>
        `;
    });

    document.getElementById(
        'liveTransactions'
    ).innerHTML = html;
}

/* =========================================================
   GRAPH
========================================================= */

let chart;

async function loadGraph(){

    let r = await fetch(

        "?ajax=graph&date_debut=<?= $dateDebut ?>&date_fin=<?= $dateFin ?>"
    );

    let data = await r.json();

    let labels =
        data.map(i=>i.date);

    let values =
        data.map(i=>i.total);

    if(!chart){

        chart = new Chart(

            document.getElementById('chart'),

            {
                type:'bar',

                data:{
                    labels:labels,

                    datasets:[
                        {
                            data:values
                        }
                    ]
                }
            }
        );

    }else{

        chart.data.labels =
            labels;

        chart.data.datasets[0].data =
            values;

        chart.update();
    }
}

/* =========================================================
   AUTO REFRESH
========================================================= */

loadKPI();
loadTransactions();
loadGraph();

setInterval(loadKPI,5000);

setInterval(loadTransactions,3000);

setInterval(loadGraph,7000);

</script>

<?php include 'includes/footer.php'; ?>