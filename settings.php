<?php
require_once 'config.php';

requireRole('admin');

$user = currentUser();

function generateMagasinCode($pdo, $excludedId = null)
{
    $sql = "SELECT code FROM magasins WHERE code IS NOT NULL AND code <> ''";
    $params = [];

    if ($excludedId !== null) {
        $sql .= " AND id<>?";
        $params[] = (int)$excludedId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $nextNumber = 1;

    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $existingCode) {
        if (preg_match('/^MAG(\d+)$/i', trim($existingCode), $matches)) {
            $nextNumber = max($nextNumber, (int)$matches[1] + 1);
        }
    }

    do {
        $code = 'MAG'.str_pad((string)$nextNumber, 3, '0', STR_PAD_LEFT);
        $check = $pdo->prepare("SELECT id FROM magasins WHERE code=? LIMIT 1");
        $check->execute([$code]);
        $nextNumber++;
    } while ($check->fetch());

    return $code;
}

function historique(
    $pdo,
    $userId,
    $action,
    $details,
    $niveau='INFO',
    $magasin_id=null
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
                magasin_id,
                created_at
            )
            VALUES
            (
                ?,?,?,?,?,?,
                NOW()
            )
        ");

    $stmt->execute([

        $userId,
        $action,
        $details,
        $ip,
        strtoupper($niveau),
        $magasin_id
    ]);
}

/* =========================
   SETTINGS
========================= */
$settings = getSettings();

/* =========================
   DEFAULT SETTINGS
========================= */
$settings['nom_boutique'] =
    $settings['nom_boutique'] ?? '';

$settings['logo'] =
    $settings['logo'] ?? '';

$settings['telephone'] =
    $settings['telephone'] ?? '';

$settings['email_admin'] =
    $settings['email_admin'] ?? '';

$settings['adresse'] =
    $settings['adresse'] ?? '';

$settings['pays'] =
    $settings['pays'] ?? '';

$settings['devise'] =
    $settings['devise'] ?? 'BIF';

$settings['tva'] =
    $settings['tva'] ?? 0;

/* =========================
   AJOUT MAGASIN
========================= */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['add_magasin'])
) {

    verify_csrf();

    $nom =
        trim($_POST['nom']);

    $code =
        trim($_POST['code']);

    if ($code === '') {
        $code = generateMagasinCode($pdo);
    }

    $telephone =
        trim($_POST['telephone_magasin']);

    $email =
        trim($_POST['email']);

    $adresse =
        trim($_POST['adresse_magasin']);

    $ville =
        trim($_POST['ville']);

    $pays =
        trim($_POST['pays_magasin']);

    $statut =
        $_POST['statut'] ?? 'actif';

    if(empty($nom)){

        flash(
            'error',
            'Nom magasin obligatoire'
        );

        header("Location: settings.php");
        exit;
    }

    /* CHECK CODE */
    $check =
        $pdo->prepare("
            SELECT id
            FROM magasins
            WHERE code=?
        ");

    $check->execute([$code]);

    if($check->fetch()){

            flash(
                'error',
                'Code déjà utilisé'
            );

            header("Location: settings.php");
            exit;
    }

    $stmt =
        $pdo->prepare("
            INSERT INTO magasins
            (
                nom,
                code,
                telephone,
                email,
                adresse,
                ville,
                pays,
                statut,
                created_at
            )
            VALUES
            (
                ?,?,?,?,?,?,?,?,
                NOW()
            )
        ");

    $stmt->execute([

        $nom,
        $code,
        $telephone,
        $email,
        $adresse,
        $ville,
        $pays,
        $statut
    ]);
    $magasinId =
    $pdo->lastInsertId();

historique(

    $pdo,

    $user['id'],

    'AJOUT_MAGASIN',

    'Nouveau magasin : '.$nom,

    'SUCCESS',

    $magasinId
);

    flash(
        'success',
        '✅ Magasin ajouté'
    );

    header("Location: settings.php");
    exit;
}

/* =========================
   UPDATE MAGASIN
========================= */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['update_magasin'])
) {

    verify_csrf();

    $id =
        (int)$_POST['magasin_id'];

    $nom =
        trim($_POST['nom']);

    $code =
        trim($_POST['code']);

    if ($code === '') {
        $code = generateMagasinCode($pdo, $id);
    }

    $telephone =
        trim($_POST['telephone_magasin']);

    $email =
        trim($_POST['email']);

    $adresse =
        trim($_POST['adresse_magasin']);

    $ville =
        trim($_POST['ville']);

    $pays =
        trim($_POST['pays_magasin']);

    $statut =
        $_POST['statut'];

    $check =
        $pdo->prepare("
            SELECT id
            FROM magasins
            WHERE code=?
            AND id != ?
        ");

    $check->execute([
        $code,
        $id
    ]);

    if($check->fetch()){

        flash(
            'error',
            'Code magasin déjà utilisé'
        );

        header("Location: settings.php");
        exit;
    }

    $stmt =
        $pdo->prepare("
            UPDATE magasins

            SET
                nom=?,
                code=?,
                telephone=?,
                email=?,
                adresse=?,
                ville=?,
                pays=?,
                statut=?

            WHERE id=?
        ");

    $stmt->execute([

        $nom,
        $code,
        $telephone,
        $email,
        $adresse,
        $ville,
        $pays,
        $statut,
        $id
    ]);
    historique(

    $pdo,

    $user['id'],

    'MODIFICATION_MAGASIN',

    'Magasin modifié : '.$nom,

    'INFO',

    $id
);

    flash(
        'success',
        '✅ Magasin modifié'
    );

    header("Location: settings.php");
    exit;
}

/* =========================
   DELETE MAGASIN
========================= */
if(
    $_SERVER['REQUEST_METHOD']==='POST'
    &&
    isset($_POST['delete_magasin'])
){

    verify_csrf();

    $id =
        (int)$_POST['delete_magasin'];

    $q =
        $pdo->prepare("
            SELECT *
            FROM magasins
            WHERE id=?
        ");

    $q->execute([$id]);

    $magasin =
        $q->fetch();

    if(!$magasin){

        flash(
            'error',
            'Magasin introuvable'
        );

        header("Location: settings.php");
        exit;
    }

    $checkUsers =
        $pdo->prepare("
            SELECT COUNT(*)
            FROM utilisateurs
            WHERE magasin_id=?
        ");

    $checkUsers->execute([$id]);

    if($checkUsers->fetchColumn() > 0){

        flash(
            'error',
            'Impossible : utilisateurs liés au magasin'
        );

        header("Location: settings.php");
        exit;
    }

    $pdo->prepare("
        DELETE FROM magasins
        WHERE id=?
    ")->execute([$id]);

    historique(

        $pdo,

        $user['id'],

        'SUPPRESSION_MAGASIN',

        'Magasin supprimé : '.$magasin['nom'],

        'DANGER',

        $id
    );

    flash(
        'success',
        '🗑️ Magasin supprimé'
    );

    header("Location: settings.php");
    exit;
}

/* =========================
   UPDATE SETTINGS
========================= */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['save_settings'])
) {

    verify_csrf();

    $nom =
        trim($_POST['nom_boutique']);

    $telephone =
        trim($_POST['telephone']);

    $email_admin =
        trim($_POST['email_admin']);

    $adresse =
        trim($_POST['adresse']);

    $pays =
        trim($_POST['pays']);

    $devise =
        trim($_POST['devise']);

    $tva =
        (float)$_POST['tva'];

    $logoPath =
        $settings['logo'];

    /* LOGO */
    if (
        isset($_FILES['logo'])
        &&
        !empty($_FILES['logo']['name'])
    ) {

        $allowed = [
            'jpg',
            'jpeg',
            'png',
            'webp'
        ];

        $ext =
            strtolower(
                pathinfo(
                    $_FILES['logo']['name'],
                    PATHINFO_EXTENSION
                )
            );

        if(in_array($ext,$allowed)){

            $dir =
                "uploads/settings/";

            if(!is_dir($dir)){

                mkdir(
                    $dir,
                    0777,
                    true
                );
            }

            $fileName =
                time().'_'.uniqid().'.'.$ext;

            $destination =
                $dir.$fileName;

            if(
                move_uploaded_file(
                    $_FILES['logo']['tmp_name'],
                    $destination
                )
            ){

                $logoPath =
                    $destination;
            }
        }
    }

    $stmt =
        $pdo->prepare("
            UPDATE settings

            SET
                nom_boutique=?,
                logo=?,
                telephone=?,
                email_admin=?,
                adresse=?,
                pays=?,
                devise=?,
                tva=?,
                updated_at=NOW()

            WHERE id=1
        ");

    $stmt->execute([

        $nom,
        $logoPath,
        $telephone,
        $email_admin,
        $adresse,
        $pays,
        $devise,
        $tva
    ]);

    flash(
        'success',
        '✅ Paramètres sauvegardés'
    );

    header("Location: settings.php");
    exit;
}

/* =========================
   MAGASINS
========================= */
$magasins =
    $pdo->query("
        SELECT *
        FROM magasins
        ORDER BY id DESC
    ")->fetchAll();

$nextMagasinCode = generateMagasinCode($pdo);

/* =========================
   STATS
========================= */
$stats =
    $pdo->query("
        SELECT

            COUNT(*) total,

            SUM(
                CASE
                    WHEN statut='actif'
                    THEN 1
                    ELSE 0
                END
            ) actifs

        FROM magasins
    ")
    ->fetch();

$totalMagasins =
    (int)$stats['total'];

$totalActifs =
    (int)$stats['actifs'];

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="p-4 md:p-6 max-w-7xl mx-auto">

<div class="flex flex-wrap gap-3 mb-6">

    <button
        onclick="toggleSection('settingsSection')"
        class="bg-blue-600 text-white px-5 py-3 rounded-2xl font-bold"
    >
        ⚙️ Paramètres
    </button>

    <button
        onclick="toggleSection('magasinSection')"
        class="bg-emerald-600 text-white px-5 py-3 rounded-2xl font-bold"
    >
        🏬 Nouveau magasin
    </button>

</div>

<?php if($msg = flash('success')): ?>

<div class="bg-green-100 text-green-700 p-4 rounded-2xl mb-4">

    <?= e($msg) ?>

</div>

<?php endif; ?>

<?php if($msg = flash('error')): ?>

<div class="bg-red-100 text-red-700 p-4 rounded-2xl mb-4">

    <?= e($msg) ?>

</div>

<?php endif; ?>
<div class="grid md:grid-cols-2 gap-5 mb-8">

    <div class="bg-white rounded-3xl shadow p-6">

        <div class="text-gray-500">
            Total magasins
        </div>

        <div class="text-4xl font-black">

            <?= $totalMagasins ?>

        </div>

    </div>

    <div class="bg-white rounded-3xl shadow p-6">

        <div class="text-gray-500">
            Magasins actifs
        </div>

        <div class="text-4xl font-black text-green-600">

            <?= $totalActifs ?>

        </div>

    </div>

</div>

<!-- SETTINGS -->

<div
    id="settingsSection"
    class="hidden bg-white dark:bg-slate-900 rounded-3xl shadow-xl p-6 mb-8"
>

<h2 class="text-2xl font-black mb-5">

    ⚙️ Paramètres système

</h2>

<form
    method="POST"
    enctype="multipart/form-data"
    class="space-y-5"
>

<input
    type="hidden"
    name="csrf_token"
    value="<?= csrf_token() ?>"
>

<input
    type="hidden"
    name="save_settings"
    value="1"
>

<div class="grid md:grid-cols-2 gap-5">

<input
    type="text"
    name="nom_boutique"
    value="<?= e($settings['nom_boutique']) ?>"
    placeholder="Nom boutique"
    class="border p-4 rounded-2xl"
>

<input
    type="text"
    name="telephone"
    value="<?= e($settings['telephone']) ?>"
    placeholder="Téléphone"
    class="border p-4 rounded-2xl"
>

<input
    type="email"
    name="email_admin"
    value="<?= e($settings['email_admin']) ?>"
    placeholder="Email"
    class="border p-4 rounded-2xl"
>

<input
    type="text"
    name="pays"
    value="<?= e($settings['pays']) ?>"
    placeholder="Pays"
    class="border p-4 rounded-2xl"
>

</div>

<textarea
    name="adresse"
    rows="4"
    placeholder="Adresse"
    class="w-full border p-4 rounded-2xl"
><?= e($settings['adresse']) ?></textarea>

<button class="bg-blue-600 text-white px-8 py-4 rounded-2xl font-bold">

    💾 Sauvegarder

</button>

</form>

</div>

<!-- AJOUT MAGASIN -->
<div
    id="magasinSection"
    class="hidden bg-white dark:bg-slate-900 rounded-3xl shadow-xl p-6 mb-8"
>

<h2 class="text-2xl font-black mb-5">

    🏬 Ajouter magasin

</h2>

<form method="POST">

<input
    type="hidden"
    name="csrf_token"
    value="<?= csrf_token() ?>"
>

<input
    type="hidden"
    name="add_magasin"
    value="1"
>

<div class="grid md:grid-cols-2 lg:grid-cols-3 gap-5">

<input
    type="text"
    name="nom"
    placeholder="Ex. : Magasin Centre-ville"
    required
    class="border p-4 rounded-2xl"
>

<input
    type="text"
    name="code"
    placeholder="Vide = <?= e($nextMagasinCode) ?> automatique"
    class="border p-4 rounded-2xl"
>

<input
    type="text"
    name="telephone_magasin"
    placeholder="Ex. : +257 79 00 00 00"
    class="border p-4 rounded-2xl"
>

<input
    type="email"
    name="email"
    placeholder="Ex. : magasin@entreprise.bi"
    class="border p-4 rounded-2xl"
>

<input
    type="text"
    name="ville"
    placeholder="Ex. : Bujumbura"
    class="border p-4 rounded-2xl"
>

<input
    type="text"
    name="pays_magasin"
    placeholder="Ex. : Burundi"
    class="border p-4 rounded-2xl"
>

</div>

<textarea
    name="adresse_magasin"
    rows="3"
    placeholder="Ex. : Avenue de la Paix, numéro 10"
    class="w-full border p-4 rounded-2xl mt-5"
></textarea>

<select
    name="statut"
    class="border p-4 rounded-2xl mt-5"
>

<option value="actif">
    Actif
</option>

<option value="inactif">
    Inactif
</option>

</select>

<div class="mt-5">

<button class="bg-emerald-600 text-white px-8 py-4 rounded-2xl font-bold">

    ➕ Ajouter

</button>

</div>

</form>

</div>

<!-- TABLE -->
<div class="bg-white dark:bg-slate-900 rounded-3xl shadow-xl overflow-x-auto">

<table class="w-full">

<thead class="bg-slate-100 dark:bg-slate-800">

<tr>

<th class="p-4 text-left">Nom</th>
<th class="p-4 text-left">Code</th>
<th class="p-4 text-left">Ville</th>
<th class="p-4 text-left">Téléphone</th>
<th class="p-4 text-left">Statut</th>
<th class="p-4 text-left">Actions</th>

</tr>

</thead>

<tbody>

<?php foreach($magasins as $m): ?>

<tr class="border-t">

<td class="p-4 font-bold">
    <?= e($m['nom'] ?? '') ?>
</td>

<td class="p-4">
    <?= e($m['code'] ?? '') ?>
</td>

<td class="p-4">
    <?= e($m['ville'] ?? '') ?>
</td>

<td class="p-4">
    <?= e($m['telephone'] ?? '') ?>
</td>

<td class="p-4">

<?php if(($m['statut'] ?? '') === 'actif'): ?>

<span class="bg-green-100 text-green-700 px-3 py-1 rounded-full text-sm">

    Actif

</span>

<?php else: ?>

<span class="bg-red-100 text-red-700 px-3 py-1 rounded-full text-sm">

    Inactif

</span>

<?php endif; ?>

</td>

<td class="p-4 flex gap-2">

<button
    onclick="toggleSection('edit<?= $m['id'] ?>')"
    class="bg-blue-600 text-white px-4 py-2 rounded-xl"
>
    ✏️
</button>

<form
    method="POST"
    onsubmit="return confirm('Supprimer ce magasin ?')"
>

<input
    type="hidden"
    name="csrf_token"
    value="<?= csrf_token() ?>"
>

<input
    type="hidden"
    name="delete_magasin"
    value="<?= $m['id'] ?>"
>

<button
    class="bg-red-600 text-white px-4 py-2 rounded-xl"
>
    🗑️
</button>

</form>

</td>

</tr>

<!-- EDIT -->
<tr
    id="edit<?= $m['id'] ?>"
    class="hidden bg-slate-50 dark:bg-slate-800"
>

<td colspan="6" class="p-5">

<form method="POST">

<input
    type="hidden"
    name="csrf_token"
    value="<?= csrf_token() ?>"
>

<input
    type="hidden"
    name="update_magasin"
    value="1"
>

<input
    type="hidden"
    name="magasin_id"
    value="<?= $m['id'] ?>"
>

<div class="grid md:grid-cols-2 lg:grid-cols-3 gap-4">

<input
    type="text"
    name="nom"
    value="<?= e($m['nom'] ?? '') ?>"
    placeholder="Ex. : Nom du magasin"
    class="border p-3 rounded-xl"
>

<input
    type="text"
    name="code"
    value="<?= e($m['code'] ?? '') ?>"
    placeholder="Vide = nouveau code automatique"
    class="border p-3 rounded-xl"
>

<input
    type="text"
    name="telephone_magasin"
    value="<?= e($m['telephone'] ?? '') ?>"
    placeholder="Ex. : +257 79 00 00 00"
    class="border p-3 rounded-xl"
>

<input
    type="email"
    name="email"
    value="<?= e($m['email'] ?? '') ?>"
    placeholder="Ex. : magasin@entreprise.bi"
    class="border p-3 rounded-xl"
>

<input
    type="text"
    name="ville"
    value="<?= e($m['ville'] ?? '') ?>"
    placeholder="Ex. : Bujumbura"
    class="border p-3 rounded-xl"
>

<input
    type="text"
    name="pays_magasin"
    value="<?= e($m['pays'] ?? '') ?>"
    placeholder="Ex. : Burundi"
    class="border p-3 rounded-xl"
>

</div>

<textarea
    name="adresse_magasin"
    rows="3"
    placeholder="Ex. : Avenue de la Paix, numéro 10"
    class="w-full border p-3 rounded-xl mt-4"
><?= e($m['adresse'] ?? '') ?></textarea>

<select
    name="statut"
    class="border p-3 rounded-xl mt-4"
>

<option
    value="actif"
    <?= ($m['statut'] ?? '') === 'actif' ? 'selected' : '' ?>
>
    Actif
</option>

<option
    value="inactif"
    <?= ($m['statut'] ?? '') === 'inactif' ? 'selected' : '' ?>
>
    Inactif
</option>

</select>

<div class="mt-4">

<button class="bg-blue-600 text-white px-6 py-3 rounded-xl">

    💾 Modifier

</button>

</div>

</form>

</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

</div>

<script>

function toggleSection(id){

    let section =
        document.getElementById(id);

    if(section){

        section.classList.toggle('hidden');

        section.scrollIntoView({
            behavior:'smooth'
        });
    }
}

</script>

<?php include 'includes/footer.php'; ?>