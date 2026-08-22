<?php

require_once 'config.php';

requireLogin();

$user = currentUser();

/* =========================================================
   SETTINGS
========================================================= */

$settings = getSettings();

$devise =
    $settings['devise']
    ?? 'BIF';

$nomBoutique =
    $settings['nom_boutique']
    ?? 'Boutique';

$isAdmin =
    ($user['role'] ?? '') === 'admin';

$magasin_id =
    (int)($user['magasin_id'] ?? 0);

if($magasin_id <= 0){

    exit("
    <div style='padding:30px'>
        ⛔ Aucun magasin assigné
    </div>
    ");
}

/* =========================================================
   RECHERCHE
========================================================= */

$search =
    trim($_GET['search'] ?? '');

/* =========================================================
   FERMETURE AUTO
========================================================= */

$autoClose = $pdo->prepare("
    UPDATE sessions_caisse
    SET

        total_ventes = (
            SELECT COALESCE(SUM(total),0)
            FROM ventes
            WHERE ventes.magasin_id = sessions_caisse.magasin_id
            AND ventes.date_vente >= sessions_caisse.date_ouverture
        ),

        montant_attendu =
            solde_depart +
            (
                SELECT COALESCE(SUM(total),0)
                FROM ventes
                WHERE ventes.magasin_id = sessions_caisse.magasin_id
                AND ventes.date_vente >= sessions_caisse.date_ouverture
            ),

        montant_reel =
            solde_depart +
            (
                SELECT COALESCE(SUM(total),0)
                FROM ventes
                WHERE ventes.magasin_id = sessions_caisse.magasin_id
                AND ventes.date_vente >= sessions_caisse.date_ouverture
            ),

        difference_caisse = 0,

        statut='fermee',
        statut_validation='auto',

        date_fermeture=NOW()

    WHERE statut='ouverte'
    AND DATE(date_ouverture) < CURDATE()
");

$autoClose->execute();

/* =========================================================
   MAGASIN
========================================================= */

$stmtMagasin = $pdo->prepare("
    SELECT *
    FROM magasins
    WHERE id=?
    LIMIT 1
");

$stmtMagasin->execute([
    $magasin_id
]);

$magasin =
    $stmtMagasin->fetch();

/* =========================================================
   DERNIER RESTE
========================================================= */

$dernier_reste = 0;

$stmtLast = $pdo->prepare("
    SELECT montant_reel
    FROM sessions_caisse
    WHERE utilisateur_id=?
    AND magasin_id=?
    AND statut='fermee'
    ORDER BY id DESC
    LIMIT 1
");

$stmtLast->execute([
    $user['id'],
    $magasin_id
]);

$last =
    $stmtLast->fetch();

if($last){

    $dernier_reste =
        (float)$last['montant_reel'];
}

/* =========================================================
   SESSION OUVERTE
========================================================= */

$sessionOuverte = null;
$sessionsOuvertes = [];

if($isAdmin){

    $stmtOpen = $pdo->prepare("
        SELECT
            sc.*,
            u.nom AS caissier

        FROM sessions_caisse sc

        LEFT JOIN utilisateurs u
        ON u.id=sc.utilisateur_id

        WHERE sc.magasin_id=?
        AND sc.statut='ouverte'

        ORDER BY sc.id DESC
    ");

    $stmtOpen->execute([
        $magasin_id
    ]);

    $sessionsOuvertes =
        $stmtOpen->fetchAll();

}else{

    $stmtOpen = $pdo->prepare("
        SELECT *
        FROM sessions_caisse
        WHERE utilisateur_id=?
        AND magasin_id=?
        AND statut='ouverte'
        LIMIT 1
    ");

    $stmtOpen->execute([
        $user['id'],
        $magasin_id
    ]);

    $sessionOuverte =
        $stmtOpen->fetch();
}

/* =========================================================
   VALIDATION ADMIN
========================================================= */

if(
    $isAdmin
    &&
    isset($_GET['valider'])
){

    $id =
        (int)$_GET['valider'];

    $stmtCheck = $pdo->prepare("
        SELECT id
        FROM sessions_caisse
        WHERE id=?
        LIMIT 1
    ");

    $stmtCheck->execute([$id]);

    if($stmtCheck->fetch()){

        $valider = $pdo->prepare("
    UPDATE sessions_caisse
    SET
        statut='fermee',
        statut_validation='validee',

        valide_par=?,
        date_validation=NOW()

    WHERE id=?
");

$valider->execute([
    $user['id'],
    $id
]);

        flash(
            'success',
            '✅ Fermeture validée avec succès'
        );
    }

    header("Location:sessions_caisse.php");
    exit;
}
/* =========================================================
   OUVERTURE CAISSE
========================================================= */

if(
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['ouvrir'])
){

    verify_csrf();

   $check = $pdo->prepare("
    SELECT id
    FROM sessions_caisse
    WHERE utilisateur_id=?
    AND magasin_id=?
    AND DATE(date_ouverture)=CURDATE()
    LIMIT 1
");

    $check->execute([
        $user['id'],
        $magasin_id
    ]);

    if($check->fetch()){

        flash(
            'error',
            '⚠️ Une caisse est déjà ouverte'
        );

        header("Location:sessions_caisse.php");
        exit;
    }

    $montant_initial =
        (float)($_POST['montant_initial'] ?? 0);

    $reste_veille =
        (float)($_POST['reste_veille'] ?? 0);

    $solde_depart =
        $montant_initial +
        $reste_veille;

    $insert = $pdo->prepare("
        INSERT INTO sessions_caisse
        (
            utilisateur_id,
            magasin_id,

            montant_initial,
            reste_veille,
            solde_depart,

            total_ventes,
            montant_attendu,
            montant_reel,
            difference_caisse,

            date_ouverture,

            statut,
            statut_validation
        )
        VALUES
        (
            ?,
            ?,

            ?,
            ?,
            ?,

            0,
            0,
            0,
            0,

            NOW(),

            'ouverte',
            'attente'
        )
    ");

    $insert->execute([
        $user['id'],
        $magasin_id,

        $montant_initial,
        $reste_veille,
        $solde_depart
    ]);

    flash(
        'success',
        '🟢 Caisse ouverte avec succès'
    );

    header("Location:sessions_caisse.php");
    exit;
}

/* =========================================================
   FERMETURE CAISSE
========================================================= */

if(
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['fermer'])
){

    verify_csrf();

    $session_id =
        (int)$_POST['session_id'];

    $montant_reel =
        (float)$_POST['montant_reel'];
        if($montant_reel <= 0){

    flash(
        'error',
        'Veuillez saisir le montant réel.'
    );

    header("Location:sessions_caisse.php");
    exit;
}

    $commentaire =
        trim($_POST['commentaire_ecart'] ?? '');

    $stmt = $pdo->prepare("
        SELECT *
        FROM sessions_caisse
        WHERE id=?
        AND statut='ouverte'
        LIMIT 1
    ");

    $stmt->execute([
        $session_id
    ]);

    $session =
        $stmt->fetch();

    if(!$session){

        flash(
            'error',
            'Session introuvable'
        );

        header("Location:sessions_caisse.php");
        exit;
    }

    if(
        !$isAdmin
        &&
        $session['utilisateur_id'] != $user['id']
    ){

        flash(
            'error',
            '⛔ Action refusée'
        );

        header("Location:sessions_caisse.php");
        exit;
    }

    $stmtVentes = $pdo->prepare("
        SELECT COALESCE(SUM(total),0)
        FROM ventes
        WHERE magasin_id=?
        AND date_vente >= ?
    ");

    $stmtVentes->execute([
        $session['magasin_id'],
        $session['date_ouverture']
    ]);

    $total_ventes =
        (float)$stmtVentes->fetchColumn();

    $montant_attendu =
        (float)$session['solde_depart']
        +
        $total_ventes;

    $difference =
        $montant_reel
        -
        $montant_attendu;

    if(abs($difference) > 0){

        $statutFinal =
            'en_attente_validation';

        $statutValidation =
            'en_attente_validation';

    }else{

        $statutFinal =
            'fermee';

        $statutValidation =
            'validee';
    }

    $update = $pdo->prepare("
        UPDATE sessions_caisse
        SET

            total_ventes=?,
            montant_attendu=?,
            montant_reel=?,
            difference_caisse=?,
            commentaire_ecart=?,

            date_fermeture=NOW(),

            statut=?,
            statut_validation=?

        WHERE id=?
    ");

    $update->execute([

        $total_ventes,
        $montant_attendu,
        $montant_reel,
        $difference,
        $commentaire,

        $statutFinal,
        $statutValidation,

        $session_id
    ]);

    flash(
        'success',
        '🔒 Caisse fermée avec succès'
    );

    header("Location:sessions_caisse.php");
    exit;
}

/* =========================================================
   HISTORIQUE SESSIONS
========================================================= */

$sql = "

SELECT

    sc.*,

    u.nom AS utilisateur_nom,

    uv.nom AS validateur_nom,

    m.nom AS magasin_nom,

    (
        SELECT COUNT(*)
        FROM ventes v
        WHERE v.magasin_id = sc.magasin_id
        AND v.date_vente >= sc.date_ouverture
        AND (
            sc.date_fermeture IS NULL
            OR v.date_vente <= sc.date_fermeture
        )
    ) AS nb_recus

FROM sessions_caisse sc

LEFT JOIN utilisateurs u
ON u.id=sc.utilisateur_id

LEFT JOIN magasins m
ON m.id=sc.magasin_id

LEFT JOIN utilisateurs uv
ON uv.id = sc.valide_par

WHERE sc.magasin_id=?
";

$paramsHistorique = [
    $magasin_id
];

if(!$isAdmin){

    $sql .= "
        AND sc.utilisateur_id=?
    ";

    $paramsHistorique[] =
        $user['id'];
}

if($search !== ''){

    $sql .= "
        AND
        (
            u.nom LIKE ?
            OR sc.id LIKE ?
        )
    ";

    $paramsHistorique[] =
        "%$search%";

    $paramsHistorique[] =
        "%$search%";
}

$sql .= "
    ORDER BY sc.id DESC
";

$list = $pdo->prepare($sql);

$list->execute(
    $paramsHistorique
);

$sessions =
    $list->fetchAll();

/* =========================================================
   KPI
========================================================= */

$totalSessions =
    count($sessions);

$totalOuvertes =
    0;

$totalFermees =
    0;

$montantGlobal =
    0;

foreach($sessions as $s){

    if($s['statut'] === 'ouverte'){
        $totalOuvertes++;
    }

    if($s['statut'] === 'fermee'){
        $totalFermees++;
    }

    $montantGlobal +=
        (float)$s['total_ventes'];
}

/* =========================================================
   EXPORT CSV
========================================================= */

if(isset($_GET['export'])){

    header(
        'Content-Type:text/csv'
    );

    header(
        'Content-Disposition:attachment; filename=sessions_caisse.csv'
    );

    $out =
        fopen(
            'php://output',
            'w'
        );

    fputcsv($out,[

        'Session',
        'Caissier',
        'Ouverture',
        'Fermeture',
        'Ventes',
        'Attendu',
        'Reel',
        'Difference',
        'Recus'
    ]);

    foreach($sessions as $s){

        fputcsv($out,[

            $s['id'],
            $s['utilisateur_nom'],
            $s['date_ouverture'],
            $s['date_fermeture'],
            $s['total_ventes'],
            $s['montant_attendu'],
            $s['montant_reel'],
            $s['difference_caisse'],
            $s['nb_recus']
        ]);
    }

    fclose($out);
    exit;
}
include 'includes/header.php';
include 'includes/sidebar.php';
?>

<script src="https://cdn.tailwindcss.com"></script>

<div class="p-6 bg-slate-100 dark:bg-slate-900 min-h-screen">

<div class="max-w-7xl mx-auto">

<!-- TITRE -->

<div class="flex flex-col md:flex-row justify-between items-center mb-8">

    <div>

        <h1 class="text-4xl font-black text-slate-800 dark:text-white">
            💰 Gestion de caisse
        </h1>

        <p class="text-slate-500 mt-2">
            <?= e($nomBoutique) ?>
            •
            <?= e($magasin['nom'] ?? '-') ?>
        </p>

    </div>

    <div class="mt-4 md:mt-0">

        <a
            href="?export=1"
            class="bg-emerald-600 hover:bg-emerald-700 text-white px-5 py-3 rounded-2xl font-bold"
        >
            📥 Export CSV
        </a>

    </div>

</div>

<!-- KPI -->

<div class="grid md:grid-cols-4 gap-5 mb-8">

    <div class="bg-white dark:bg-slate-800 rounded-3xl shadow p-5">

        <div class="text-slate-500">
            Sessions
        </div>

        <div class="text-4xl font-black">
            <?= $totalSessions ?>
        </div>

    </div>

    <div class="bg-green-50 dark:bg-green-900 rounded-3xl shadow p-5">

        <div class="text-green-700 dark:text-green-200">
            Sessions ouvertes
        </div>

        <div class="text-4xl font-black text-green-700 dark:text-green-100">
            <?= $totalOuvertes ?>
        </div>

    </div>

    <div class="bg-slate-50 dark:bg-slate-700 rounded-3xl shadow p-5">

        <div class="text-slate-600 dark:text-slate-200">
            Sessions fermées
        </div>

        <div class="text-4xl font-black">
            <?= $totalFermees ?>
        </div>

    </div>

    <div class="bg-blue-50 dark:bg-blue-900 rounded-3xl shadow p-5">

        <div class="text-blue-700 dark:text-blue-200">
            Chiffre d'affaires
        </div>

        <div class="text-2xl font-black text-blue-700 dark:text-blue-100">
            <?= number_format($montantGlobal,2).' '.$devise ?>
        </div>

    </div>

</div>

<!-- ALERTES -->

<?php
$nbAttente = 0;

foreach($sessions as $tmp){

    if(
        $tmp['statut']=='en_attente_validation'
        ||
        $tmp['statut_validation']=='en_attente_validation'
    ){
        $nbAttente++;
    }
}
?>

<?php if($nbAttente > 0): ?>

<div class="bg-orange-100 border border-orange-300 text-orange-800 p-5 rounded-3xl mb-6">

    <div class="font-black text-xl">
        ⚠️ Contrôles à effectuer
    </div>

    <div class="mt-2">
        <?= $nbAttente ?>
        session(s) nécessitent une validation administrateur.
    </div>

</div>

<?php endif; ?>

<!-- MESSAGES -->

<?php if($m = flash('success')): ?>

<div class="bg-green-100 text-green-700 p-4 rounded-2xl mb-5">

    <?= e($m) ?>

</div>

<?php endif; ?>

<?php if($m = flash('error')): ?>

<div class="bg-red-100 text-red-700 p-4 rounded-2xl mb-5">

    <?= e($m) ?>

</div>

<?php endif; ?>

<!-- OUVERTURE CAISSE -->

<?php if(!$sessionOuverte): ?>

<div class="bg-white dark:bg-slate-800 rounded-3xl shadow p-6 mb-6">

    <h2 class="text-2xl font-black mb-5">
        🟢 Ouvrir une caisse
    </h2>

    <form method="POST">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= csrf_token() ?>"
        >

        <div class="grid md:grid-cols-3 gap-4">

            <div>

                <label class="font-bold">
                    Montant initial
                </label>

                <input
                    type="number"
                    step="0.01"
                    name="montant_initial"
                    value="0"
                    required
                    class="w-full border rounded-2xl p-4 mt-2 dark:bg-slate-700"
                >

            </div>

            <div>

                <label class="font-bold">
                    Reste veille
                </label>

                <input
                    type="number"
                    step="0.01"
                    readonly
                    name="reste_veille"
                    value="<?= $dernier_reste ?>"
                    class="w-full border rounded-2xl p-4 mt-2 bg-slate-100 dark:bg-slate-700"
                >

            </div>

            <div class="flex items-end">

                <button
                    type="submit"
                    name="ouvrir"
                    class="bg-blue-600 hover:bg-blue-700 text-white w-full p-4 rounded-2xl font-bold"
                >

                    🟢 Ouvrir caisse

                </button>

            </div>

        </div>

    </form>

</div>

<?php endif; ?>

<!-- SESSION OUVERTE -->

<?php if($sessionOuverte): ?>

<div class="bg-green-50 border border-green-200 rounded-3xl p-6 mb-6">

    <h2 class="text-2xl font-black text-green-700">

        🟢 Session active

    </h2>

    <div class="mt-3">

        Session #
        <?= $sessionOuverte['id'] ?>

    </div>

    <div class="mt-2">

        Ouverte le
        <?= e($sessionOuverte['date_ouverture']) ?>

    </div>

</div>

<?php endif; ?>

<!-- SESSIONS OUVERTES ADMIN -->

<?php if($isAdmin && !empty($sessionsOuvertes)): ?>

<div class="bg-white dark:bg-slate-800 rounded-3xl shadow p-6 mb-6">

    <h2 class="text-2xl font-black text-green-700 mb-5">

        🟢 Sessions ouvertes actuellement

    </h2>

    <div class="space-y-3">

        <?php foreach($sessionsOuvertes as $open): ?>

        <div class="flex justify-between items-center border rounded-2xl p-4">

            <div>

                <div class="font-bold">

                    <?= e($open['caissier']) ?>

                </div>

                <div class="text-sm text-slate-500">

                    Session #<?= $open['id'] ?>

                </div>

            </div>

            <button
                onclick="ouvrirModalFermeture(<?= $open['id'] ?>)"
                class="bg-red-600 hover:bg-red-700 text-white px-5 py-2 rounded-xl font-bold"
            >

                🔒 Fermer

            </button>

        </div>

        <?php endforeach; ?>

    </div>

</div>

<?php endif; ?>

<!-- RECHERCHE -->

<div class="bg-white dark:bg-slate-800 rounded-3xl shadow p-5 mb-6">

<form method="GET">

    <input
        type="text"
        name="search"
        value="<?= e($search) ?>"
        placeholder="🔎 Rechercher une session ou un caissier..."
        class="w-full border rounded-2xl p-4 dark:bg-slate-700"
    >

</form>

</div>
<!-- HISTORIQUE -->

<div class="bg-white dark:bg-slate-800 rounded-3xl shadow overflow-hidden">

<div class="p-5 border-b">

<h2 class="text-2xl font-black">
📋 Historique des sessions
</h2>

</div>

<div class="overflow-x-auto">

<table class="w-full">

<thead class="bg-slate-100 dark:bg-slate-700">

<tr>

<th class="p-4 text-left">ID</th>
<th class="p-4 text-left">Caissier</th>
<th class="p-4 text-left">Ouverture</th>
<th class="p-4 text-left">Fermeture</th>
<th class="p-4 text-left">Ventes</th>
<th class="p-4 text-left">Attendu</th>
<th class="p-4 text-left">Réel</th>
<th class="p-4 text-left">Écart</th>
<th class="p-4 text-left">Statut</th>
<th class="p-4 text-left">Validation</th>
<th class="p-4 text-left">Validé par</th>
<th class="p-4 text-left">Date validation</th>
<th class="p-4 text-center">Actions</th>

</tr>

</thead>

<tbody>

<?php foreach($sessions as $s): ?>

<tr class="border-t hover:bg-slate-50 dark:hover:bg-slate-700">

<td class="p-4 font-bold">
#<?= (int)$s['id'] ?>
</td>

<td class="p-4">
<?= e($s['utilisateur_nom']) ?>
</td>

<td class="p-4">
<?= e($s['date_ouverture']) ?>
</td>

<td class="p-4">
<?= e($s['date_fermeture'] ?: '-') ?>
</td>

<td class="p-4 font-bold text-blue-600">
<?= number_format($s['total_ventes'],2).' '.$devise ?>
</td>

<td class="p-4 font-bold text-green-600">
<?= number_format($s['montant_attendu'],2).' '.$devise ?>
</td>

<td class="p-4">
<?= number_format($s['montant_reel'],2).' '.$devise ?>
</td>

<td class="p-4">

<?php if($s['difference_caisse'] > 0): ?>

<span class="bg-green-100 text-green-700 px-3 py-1 rounded-full font-bold">
+<?= number_format($s['difference_caisse'],2).' '.$devise ?>
</span>

<?php elseif($s['difference_caisse'] < 0): ?>

<span class="bg-red-100 text-red-700 px-3 py-1 rounded-full font-bold">
<?= number_format($s['difference_caisse'],2).' '.$devise ?>
</span>

<?php else: ?>

<span class="bg-slate-100 px-3 py-1 rounded-full">
0.00
</span>

<?php endif; ?>

</td>

<td class="p-4">

<?php if($s['statut']=='ouverte'): ?>

<span class="bg-green-100 text-green-700 px-3 py-1 rounded-full">
🟢 Ouverte
</span>

<?php elseif($s['statut']=='en_attente_validation'): ?>

<span class="bg-orange-100 text-orange-700 px-3 py-1 rounded-full">
⏳ Contrôle
</span>

<?php else: ?>

<span class="bg-slate-200 text-slate-700 px-3 py-1 rounded-full">
🔒 Fermée
</span>

<?php endif; ?>

</td>

<td class="p-4">

<?php if($s['statut_validation']=='validee'): ?>

<span class="bg-green-100 text-green-700 px-3 py-1 rounded-full">
✅ Validée
</span>

<?php elseif($s['statut_validation']=='auto'): ?>

<span class="bg-blue-100 text-blue-700 px-3 py-1 rounded-full">
🤖 Auto
</span>

<?php elseif(
$s['statut_validation']=='attente'
||
$s['statut_validation']=='en_attente_validation'
): ?>

<span class="bg-orange-100 text-orange-700 px-3 py-1 rounded-full">
⚠️ Vérifier
</span>

<?php else: ?>

<span class="bg-red-100 text-red-700 px-3 py-1 rounded-full">
🔍 Contrôle
</span>

<?php endif; ?>

</td>
<td class="p-4">

<?php if(!empty($s['validateur_nom'])): ?>

    <span class="bg-green-100 text-green-700 px-3 py-1 rounded-full">

        👤 <?= e($s['validateur_nom']) ?>

    </span>

<?php else: ?>

    <span class="text-slate-400">
        —
    </span>

<?php endif; ?>

</td>
<td class="p-4">

<?php if(!empty($s['date_validation'])): ?>

    <?= e($s['date_validation']) ?>

<?php else: ?>

    —

<?php endif; ?>

</td>

<td class="p-4 text-center">

<div class="flex justify-center gap-2 flex-wrap">

<button
type="button"
onclick="ouvrirDetails(<?= (int)$s['id'] ?>)"
class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-xl"
>
🔎 Détails
</button>

<?php if($s['statut']=='ouverte'): ?>
<button
type="button"
onclick="ouvrirModalFermeture(<?= (int)$s['id'] ?>)"
class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-xl"
>
🔒 Fermer
</button>

<?php endif; ?>

<?php if(
$isAdmin
&&
(
$s['statut']=='en_attente_validation'
||
$s['statut_validation']=='en_attente_validation'
||
$s['statut_validation']=='attente'
)
): ?>

<a
href="?valider=<?= (int)$s['id'] ?>"
onclick="return confirm('Valider cette session ?')"
class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-xl font-bold"
>
✅ Valider
</a>

<?php endif; ?>

</div>

</td>

</tr>

<tr id="detail-data-<?= (int)$s['id'] ?>" class="hidden">

<td colspan="11">

<div
data-caissier="<?= e($s['utilisateur_nom']) ?>"
data-ouverture="<?= e($s['date_ouverture']) ?>"
data-fermeture="<?= e($s['date_fermeture']) ?>"
data-ventes="<?= number_format($s['total_ventes'],2) ?>"
data-attendu="<?= number_format($s['montant_attendu'],2) ?>"
data-reel="<?= number_format($s['montant_reel'],2) ?>"
data-diff="<?= number_format($s['difference_caisse'],2) ?>"
data-commentaire="<?= e($s['commentaire_ecart'] ?? '-') ?>"
data-recus="<?= (int)($s['nb_recus'] ?? 0) ?>"
data-validateur="<?= e($s['validateur_nom'] ?? '-') ?>"
data-datevalidation="<?= e($s['date_validation'] ?? '-') ?>"
>
</div>

</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

</div>

<!-- MODAL DETAILS -->

<div
id="modalDetails"
class="fixed inset-0 bg-black/70 hidden items-center justify-center z-50"
>

<div class="bg-white rounded-3xl shadow-2xl w-full max-w-3xl p-6">

<h2 class="text-2xl font-black mb-5">
🔎 Contrôle de session caisse
</h2>

<div id="detailsContent"></div>

<div class="mt-5 text-right">

<button
onclick="fermerDetails()"
class="bg-slate-700 text-white px-6 py-3 rounded-xl"
>
Fermer
</button>

</div>

</div>

</div>
<div
id="modalFermeture"
class="fixed inset-0 bg-black/70 hidden items-center justify-center z-50"
>

<div class="bg-white rounded-3xl p-6 w-full max-w-lg">

<h2 class="text-2xl font-black text-red-600 mb-5">
🔒 Fermer la caisse
</h2>

<form method="POST">

<input
type="hidden"
name="csrf_token"
value="<?= csrf_token() ?>"
>

<input
type="hidden"
id="session_id"
name="session_id"
>

<div class="mb-4">

<label class="font-bold">
💵 Montant réel trouvé en caisse
</label>

<input
type="number"
step="0.01"
required
name="montant_reel"
class="w-full border rounded-xl p-4 mt-2"
>

</div>

<div class="mb-4">

<label class="font-bold">
📝 Commentaire
</label>

<textarea
name="commentaire_ecart"
class="w-full border rounded-xl p-4 mt-2"
placeholder="Expliquez un éventuel écart..."
></textarea>

</div>

<div class="flex gap-3">

<button
type="submit"
name="fermer"
class="bg-red-600 text-white px-6 py-3 rounded-xl flex-1"
>
🔒 Confirmer fermeture
</button>

<button
type="button"
onclick="fermerModal()"
class="bg-slate-300 px-6 py-3 rounded-xl"
>
Annuler
</button>

</div>

</form>

</div>

</div>

<script>

function ouvrirDetails(id)
{
    let box =
    document.querySelector(
        '#detail-data-'+id+' div'
    );

    let html = `

    <div class="grid md:grid-cols-2 gap-5">

        <div>
            <strong>👤 Caissier</strong><br>
            ${box.dataset.caissier}
        </div>

        <div>
            <strong>🧾 Nombre de reçus</strong><br>
            ${box.dataset.recus}
        </div>

        <div>
            <strong>🕒 Ouverture</strong><br>
            ${box.dataset.ouverture}
        </div>

        <div>
            <strong>🔒 Fermeture</strong><br>
            ${box.dataset.fermeture}
        </div>

        <div>
            <strong>💰 Total ventes</strong><br>
            ${box.dataset.ventes} <?= $devise ?>
        </div>

        <div>
            <strong>📦 Montant attendu</strong><br>
            ${box.dataset.attendu} <?= $devise ?>
        </div>

        <div>
            <strong>💵 Montant réel</strong><br>
            ${box.dataset.reel} <?= $devise ?>
        </div>

        <div>
            <strong>⚠️ Écart</strong><br>
            ${box.dataset.diff} <?= $devise ?>
        </div>

    </div>

    <div class="mt-6">

        <strong>📝 Commentaire du caissier</strong>

        <div class="border rounded-xl p-4 mt-2 bg-slate-50">

            ${box.dataset.commentaire}

        </div>

    </div>
    `;

    document.getElementById(
        'detailsContent'
    ).innerHTML = html;

    document
        .getElementById('modalDetails')
        .classList.remove('hidden');

    document
        .getElementById('modalDetails')
        .classList.add('flex');
}

function fermerDetails()
{
    document
        .getElementById('modalDetails')
        .classList.add('hidden');

    document
        .getElementById('modalDetails')
        .classList.remove('flex');
}

document
.getElementById('modalDetails')
.addEventListener('click', function(e){

    if(e.target === this){
        fermerDetails();
    }

});


function ouvrirModalFermeture(id)
{
    document.getElementById('session_id').value = id;

    document
        .getElementById('modalFermeture')
        .classList.remove('hidden');

    document
        .getElementById('modalFermeture')
        .classList.add('flex');
}

function fermerModal()
{
    document
        .getElementById('modalFermeture')
        .classList.add('hidden');

    document
        .getElementById('modalFermeture')
        .classList.remove('flex');
}
<div>
    <strong>✅ Validé par</strong><br>
    ${box.dataset.validateur}
</div>

<div>
    <strong>📅 Date validation</strong><br>
    ${box.dataset.datevalidation}
</div>

</script>

</div>

</div>

<?php include 'includes/footer.php'; ?>