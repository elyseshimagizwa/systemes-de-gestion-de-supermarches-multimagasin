<?php
require_once 'config.php';

requireLogin();
$user = currentUser();

$isGlobalAdmin =
    ($user['role'] === 'super_admin'.'admin');

$magasin_id =
    $user['magasin_id'] ?? 0;

/* =========================================================
   CONFIG SECURITE
========================================================= */



if (isset($_SERVER['HTTPS'])) {
    ini_set('session.cookie_secure', 1);
}


/* =========================================================
   SECURITE HEADERS
========================================================= */

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

/* =========================================================
   FONCTIONS SECURITE
========================================================= */


function validatePassword($password)
{
    return strlen($password) >= 6;
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
                created_at,
                niveau,
                magasin_id
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                NOW(),
                ?,
                ?
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

/* =========================================================
   MAGASINS
========================================================= */

if($isGlobalAdmin){

    $magasins =
        $pdo->query("
            SELECT *
            FROM magasins
            WHERE statut='actif'
            ORDER BY nom ASC
        ")->fetchAll();

}else{

    $stmt = $pdo->prepare("
        SELECT *
        FROM magasins
        WHERE id=?
    ");

    $stmt->execute([
        $magasin_id
    ]);

    $magasins =
        $stmt->fetchAll();
}
/* =========================================================
   AJOUT UTILISATEUR
========================================================= */

if(
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['nom'])
    &&
    !isset($_POST['edit_id'])
){

    verify_csrf();

    try{

        $nom =
            clean($_POST['nom']);

        $email =
            filter_var(
                trim($_POST['email']),
                FILTER_VALIDATE_EMAIL
            );

        $password =
            trim($_POST['password']);

        $role =
            $_POST['role'] === 'admin'
            ? 'admin'
            : 'caissier';

        if($isGlobalAdmin){

    $newMagasinId =
        !empty($_POST['magasin_id'])
        ? (int)$_POST['magasin_id']
        : null;

}else{

    $newMagasinId =
        $magasin_id;
}

        /* =====================================
           VALIDATIONS
        ===================================== */

        if(
            empty($nom)
            ||
            empty($email)
            ||
            empty($password)
        ){

            throw new Exception(
                "Tous les champs sont obligatoires"
            );
        }

        if(!validatePassword($password)){

            throw new Exception(
                "Mot de passe trop court (6 caractères minimum)"
            );
        }

        if(
            $role !== 'admin'
            &&
            empty($magasin_id)
        ){

            throw new Exception(
                "Le magasin est obligatoire pour un caissier"
            );
        }

        /* =====================================
           EMAIL EXISTE
        ===================================== */

        $check =
            $pdo->prepare("
                SELECT id
                FROM utilisateurs
                WHERE email=?
                LIMIT 1
            ");

        $check->execute([$email]);

        if($check->fetch()){

            throw new Exception(
                "Cet email existe déjà"
            );
        }

        /* =====================================
           INSERT
        ===================================== */

        $stmt =
            $pdo->prepare("
                INSERT INTO utilisateurs
                (
                    nom,
                    email,
                    mot_de_passe,
                    role,
                    magasin_id,
                    created_at
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    NOW()
                )
            ");

        $stmt->execute([

            $nom,
            $email,

            password_hash(
                $password,
                PASSWORD_DEFAULT
            ),

            $role,
            $newMagasinId
        ]);

        $userId =
            $pdo->lastInsertId();

        /* =====================================
           HISTORIQUE
        ===================================== */

        historique(

            $pdo,

            currentUser()['id'],

            'AJOUT_UTILISATEUR',

            'Nouvel utilisateur : '
            .$nom
            .' | Role : '
            .$role
            .' | Magasin : '
            .$newMagasinId,

            'SUCCESS',

            $newMagasinId
        );

        flash(
            'success',
            '✅ Utilisateur ajouté avec succès'
        );

    }catch(Exception $e){

        flash(
            'error',
            $e->getMessage()
        );
    }

    header('Location: utilisateurs.php');
    exit;
}

/* =========================================================
   MODIFICATION UTILISATEUR
========================================================= */

if(
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['edit_id'])
){

    verify_csrf();

    try{

        $id =
            (int)$_POST['edit_id'];

        $nom =
            clean($_POST['nom']);

        $email =
            filter_var(
                trim($_POST['email']),
                FILTER_VALIDATE_EMAIL
            );

        $password =
            trim($_POST['password']);

        $role =
            $_POST['role'] === 'admin'
            ? 'admin'
            : 'caissier';

        $magasin_id =
            !empty($_POST['magasin_id'])
            ? (int)$_POST['magasin_id']
            : null;

        if(
            empty($nom)
            ||
            empty($email)
        ){

            throw new Exception(
                "Nom et email obligatoires"
            );
        }

        if(
            $role !== 'admin'
            &&
            empty($magasin_id)
        ){

            throw new Exception(
                "Le magasin est obligatoire"
            );
        }

        if(
            !empty($password)
            &&
            !validatePassword($password)
        ){

            throw new Exception(
                "Mot de passe trop court"
            );
        }

        /* =====================================
           CHECK EMAIL DUPLICATE
        ===================================== */

        $check =
            $pdo->prepare("
                SELECT id
                FROM utilisateurs
                WHERE email=?
                AND id != ?
                LIMIT 1
            ");

        $check->execute([
            $email,
            $id
        ]);

        if($check->fetch()){

            throw new Exception(
                "Cet email existe déjà"
            );
        }

        /* =====================================
           PASSWORD UPDATE
        ===================================== */

        if(!empty($password)){

            $stmt =
                $pdo->prepare("
                    UPDATE utilisateurs
                    SET
                        nom=?,
                        email=?,
                        mot_de_passe=?,
                        role=?,
                        magasin_id=?,
                        force_logout=1
                    WHERE id=?
                ");

            $stmt->execute([

                $nom,
                $email,

                password_hash(
                    $password,
                    PASSWORD_DEFAULT
                ),

                $role,
                $magasin_id,
                $id
            ]);

        }else{

            $stmt =
                $pdo->prepare("
                    UPDATE utilisateurs
                    SET
                        nom=?,
                        email=?,
                        role=?,
                        magasin_id=?,
                        force_logout=1
                    WHERE id=?
                ");

            $stmt->execute([

                $nom,
                $email,
                $role,
                $magasin_id,
                $id
            ]);
        }

        /* =====================================
           HISTORIQUE
        ===================================== */

        historique(

            $pdo,

            currentUser()['id'],

            'MODIFICATION_UTILISATEUR',

            'Utilisateur modifié : '
            .$nom
            .' | Nouveau magasin : '
            .$magasin_id,

            'INFO',

            $magasin_id
        );

        flash(
            'success',
            '✅ Utilisateur modifié'
        );

    }catch(Exception $e){

        flash(
            'error',
            $e->getMessage()
        );
    }

    header('Location: utilisateurs.php');
    exit;
}

/* =========================================================
   SUPPRESSION
========================================================= */

if(
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['delete_id'])
){

    verify_csrf();

    try{

        $id =
            (int)$_POST['delete_id'];

        if($id === (int)currentUser()['id']){

            throw new Exception(
                "Impossible de supprimer votre propre compte"
            );
        }

        /* =====================================
           RECUP USER
        ===================================== */

       $sql = "
SELECT *
FROM utilisateurs
WHERE id=?
";

$params = [$id];

if(!$isGlobalAdmin){

    $sql .= "
    AND magasin_id=?
    ";

    $params[] =
        $magasin_id;
}

$stmtUser =
    $pdo->prepare($sql);

$stmtUser->execute($params);


        $stmtUser->execute([$id]);

        $u =
            $stmtUser->fetch();

        if(!$u){

            throw new Exception(
                "Utilisateur introuvable"
            );
        }

        if($u['role'] === 'admin'){

            throw new Exception(
                "Impossible de supprimer un admin"
            );
        }

        /* =====================================
           DELETE
        ===================================== */

        $stmt =
            $pdo->prepare("
                DELETE FROM utilisateurs
                WHERE id=?
            ");

        $stmt->execute([$id]);

        historique(

            $pdo,

            currentUser()['id'],

            'SUPPRESSION_UTILISATEUR',

            'Utilisateur supprimé : '
            .$u['nom'],

            'DANGER',

            $u['magasin_id']
        );

        flash(
            'success',
            '✅ Utilisateur supprimé'
        );

    }catch(Exception $e){

        flash(
            'error',
            $e->getMessage()
        );
    }

    header('Location: utilisateurs.php');
    exit;
}

/* =========================================================
   EDIT USER
========================================================= */

$editUser = null;

if(isset($_GET['edit'])){

    $sql = "
SELECT *
FROM utilisateurs
WHERE id=?
";

$params = [
    (int)$_GET['edit']
];

if(!$isGlobalAdmin){

    $sql .= "
    AND magasin_id=?
    ";

    $params[] =
        $magasin_id;
}

$stmt =
    $pdo->prepare($sql);

$stmt->execute($params);

    $stmt->execute([
        (int)$_GET['edit'],
        
    ]);

    $editUser =
        $stmt->fetch();
}

/* =========================================================
   SEARCH
========================================================= */

$search =
    trim($_GET['search'] ?? '');

$sql = "
    SELECT

        u.*,

        m.nom AS magasin

    FROM utilisateurs u

    LEFT JOIN magasins m
    ON m.id = u.magasin_id

    WHERE 1=1
";


$params = [];

if($search !== ''){

    $sql .= "
        AND
        (
            u.nom LIKE ?
            OR u.email LIKE ?
        )
    ";
$params[] = "$search%";
$params[] = "$search%";
}
if(!$isGlobalAdmin){

    $sql .= "
    AND u.magasin_id=?
    ";

    $params[] =
        $magasin_id;
}

$page = max(1, (int)($_GET['page'] ?? 1));

$limit = 20;

$offset = ($page - 1) * $limit;

$stmtUsers =
    $pdo->prepare($sql);

$stmtUsers->execute($params);

$users =
    $stmtUsers->fetchAll();



/* =========================================================
   STATS
========================================================= */

$statsSql = "
SELECT

COUNT(*) total_users,

SUM(role='admin') total_admins,

SUM(role='caissier') total_caissiers

FROM utilisateurs

WHERE 1=1
";

$statsParams = [];

if(!$isGlobalAdmin){

    $statsSql .= "
    AND magasin_id=?
    ";

    $statsParams[] =
        $magasin_id;
}

$stats =
    $pdo->prepare($statsSql);

$stats->execute(
    $statsParams
);

$stats =
    $stats->fetch(PDO::FETCH_ASSOC);

$totalUsers = (int)$stats['total_users'];
$totalAdmins = (int)$stats['total_admins'];
$totalCaissiers = (int)$stats['total_caissiers'];

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="p-4 md:p-6 max-w-7xl mx-auto">

<!-- HEADER -->

<div class="flex flex-col md:flex-row justify-between items-center gap-4 mb-8">

    <div>

        <h1 class="text-3xl font-black text-gray-800">
            👤 Gestion Utilisateurs
        </h1>

        <p class="text-gray-500 mt-2">
            Gestion sécurisée des accès multi magasins
        </p>

    </div>

</div>

<!-- ALERT -->

<?php if($msg = flash('success')): ?>

<div class="bg-green-100 border border-green-300 text-green-700 p-4 rounded-2xl mb-6 shadow">

    <?= e($msg) ?>

</div>

<?php endif; ?>

<?php if($msg = flash('error')): ?>

<div class="bg-red-100 border border-red-300 text-red-700 p-4 rounded-2xl mb-6 shadow">

    <?= e($msg) ?>

</div>

<?php endif; ?>

<!-- KPI -->

<div class="grid md:grid-cols-3 gap-5 mb-8">

    <div class="bg-white rounded-3xl shadow p-6 border">

        <div class="text-gray-500 text-sm">
            Total utilisateurs
        </div>

        <div class="text-4xl font-black mt-2">
            <?= $totalUsers ?>
        </div>

    </div>

    <div class="bg-white rounded-3xl shadow p-6 border">

        <div class="text-gray-500 text-sm">
            Administrateurs
        </div>

        <div class="text-4xl font-black text-purple-600 mt-2">
            <?= $totalAdmins ?>
        </div>

    </div>

    <div class="bg-white rounded-3xl shadow p-6 border">

        <div class="text-gray-500 text-sm">
            Caissiers
        </div>

        <div class="text-4xl font-black text-blue-600 mt-2">
            <?= $totalCaissiers ?>
        </div>

    </div>

</div>

<!-- FORM -->

<div class="bg-white rounded-3xl shadow-2xl overflow-hidden mb-8">

<div class="bg-gradient-to-r from-indigo-600 to-blue-700 p-6 text-white">

    <h2 class="text-2xl font-black">

        <?= $editUser
            ? '✏ Modifier utilisateur'
            : '➕ Ajouter utilisateur' ?>

    </h2>

</div>

<form method="POST" autocomplete="off" class="p-6">

<input
    type="hidden"
    name="csrf_token"
    value="<?= csrf_token() ?>"
>

<?php if($editUser): ?>

<input
    type="hidden"
    name="edit_id"
    value="<?= $editUser['id'] ?>"
>

<?php endif; ?>

<div class="grid md:grid-cols-2 lg:grid-cols-4 gap-5">

    <!-- NOM -->

    <div>

        <label class="font-bold block mb-2">
            👤 Nom
        </label>

        <input
            type="text"
            name="nom"
            required
            maxlength="100"
            value="<?= e($editUser['nom'] ?? '') ?>"
            class="w-full border p-4 rounded-2xl focus:ring-2 focus:ring-indigo-400 outline-none"
        >

    </div>

    <!-- EMAIL -->

    <div>

        <label class="font-bold block mb-2">
            📧 Email
        </label>

        <input
            type="email"
            name="email"
            required
            maxlength="150"
            value="<?= e($editUser['email'] ?? '') ?>"
            class="w-full border p-4 rounded-2xl focus:ring-2 focus:ring-indigo-400 outline-none"
        >

    </div>

    <!-- PASSWORD -->

    <div>

        <label class="font-bold block mb-2">
            🔐 Mot de passe
        </label>

        <input
            type="password"
            name="password"
            <?= !$editUser ? 'required' : '' ?>
            minlength="6"
            class="w-full border p-4 rounded-2xl focus:ring-2 focus:ring-indigo-400 outline-none"
            placeholder="<?= $editUser
                ? 'Laisser vide pour garder'
                : 'Mot de passe sécurisé' ?>"
        >

    </div>

    <!-- ROLE -->

    <div>

        <label class="font-bold block mb-2">
            🛡 Role
        </label>

        <select
            name="role"
            id="roleSelect"
            class="w-full border p-4 rounded-2xl focus:ring-2 focus:ring-indigo-400 outline-none"
        >

            <option
                value="caissier"
                <?= ($editUser['role'] ?? '') === 'caissier'
                    ? 'selected'
                    : '' ?>
            >
                Caissier
            </option>

            <option
                value="admin"
                <?= ($editUser['role'] ?? '') === 'admin'
                    ? 'selected'
                    : '' ?>
            >
                Admin
            </option>

        </select>

    </div>

    <!-- MAGASIN -->

    <div id="magasinBlock">

        <label class="font-bold block mb-2">
            🏬 Magasin
        </label>

        <select
            name="magasin_id"
            class="w-full border p-4 rounded-2xl focus:ring-2 focus:ring-indigo-400 outline-none"
        >

            <option value="">
                Sélectionner
            </option>

            <?php foreach($magasins as $m): ?>

            <option
                value="<?= $m['id'] ?>"
                <?= ($editUser['magasin_id'] ?? '') == $m['id']
                    ? 'selected'
                    : '' ?>
            >

                <?= e($m['nom']) ?>

            </option>

            <?php endforeach; ?>

        </select>

    </div>

</div>

<div class="mt-6 flex gap-3">

    <button class="bg-indigo-600 hover:bg-indigo-700 text-white px-8 py-4 rounded-2xl font-bold transition">

        <?= $editUser
            ? '💾 Modifier'
            : '➕ Ajouter utilisateur' ?>

    </button>

    <?php if($editUser): ?>

    <a
        href="utilisateurs.php"
        class="bg-gray-200 hover:bg-gray-300 px-8 py-4 rounded-2xl font-bold transition"
    >

        Annuler

    </a>

    <?php endif; ?>

</div>

</form>

</div>

<!-- SEARCH -->

<div class="bg-white rounded-3xl shadow p-5 mb-8 border">

<form method="GET" class="flex gap-3">

    <input
        type="text"
        name="search"
        value="<?= e($search) ?>"
        placeholder="Rechercher un utilisateur..."
        class="w-full border p-4 rounded-2xl"
    >

    <button class="bg-black text-white px-8 rounded-2xl">

        🔍 Rechercher

    </button>

</form>

</div>

<!-- TABLE -->

<div class="bg-white rounded-3xl shadow-2xl overflow-hidden">

<div class="bg-gradient-to-r from-slate-800 to-slate-900 p-6 text-white">

    <h2 class="text-2xl font-black">
        📜 Liste utilisateurs
    </h2>

</div>

<div class="overflow-x-auto">

<table class="w-full">

<thead class="bg-slate-100">

<tr>

    <th class="p-4 text-left">
        Nom
    </th>

    <th class="p-4 text-left">
        Email
    </th>

    <th class="p-4 text-left">
        Role
    </th>

    <th class="p-4 text-left">
        Magasin
    </th>

    <th class="p-4 text-left">
        Date
    </th>

    <th class="p-4 text-left">
        Actions
    </th>

</tr>

</thead>

<tbody>

<?php foreach($users as $u): ?>

<tr class="border-t hover:bg-slate-50 transition">

    <td class="p-4 font-bold">

        <?= e($u['nom']) ?>

    </td>

    <td class="p-4">

        <?= e($u['email']) ?>

    </td>

    <td class="p-4">

        <?php if($u['role'] === 'admin'): ?>

        <span class="bg-purple-100 text-purple-700 px-3 py-1 rounded-full text-sm font-bold">
            👑 Admin
        </span>

        <?php else: ?>

        <span class="bg-blue-100 text-blue-700 px-3 py-1 rounded-full text-sm font-bold">
            Caissier
        </span>

        <?php endif; ?>

    </td>

    <td class="p-4">

        <?= e($u['magasin'] ?? '-') ?>

    </td>

    <td class="p-4 text-sm">

        <?= e($u['created_at']) ?>

    </td>

    <td class="p-4 flex gap-2 flex-wrap">

        <!-- EDIT -->

        <a
            href="utilisateurs.php?edit=<?= $u['id'] ?>"
            class="bg-yellow-500 hover:bg-yellow-600 text-white px-4 py-2 rounded-xl text-sm transition"
        >
            ✏ Modifier
        </a>

        <!-- DELETE -->

        <?php if($u['role'] !== 'admin'): ?>

        <form
            method="POST"
            onsubmit="return confirm('Supprimer cet utilisateur ?')"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= csrf_token() ?>"
            >

            <input
                type="hidden"
                name="delete_id"
                value="<?= $u['id'] ?>"
            >

            <button class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-xl text-sm transition">

                🗑 Supprimer

            </button>

        </form>

        <?php endif; ?>

    </td>

</tr>
<?$totalPages = 1;?>
<?php if(isset($totalPages) && $totalPages > 1): ?>
<div class="flex justify-center gap-2 p-6">

<?php for($i=1;$i<=$totalPages;$i++): ?>

<a
href="?search=<?= urlencode($search) ?>&page=<?= $i ?>"
class="
px-4 py-2 rounded-xl border
<?= $page==$i
? 'bg-blue-600 text-white'
: 'bg-white'
?>
">

<?= $i ?>

</a>

<?php endfor; ?>

</div>

<?php endif; ?>

<?php endforeach; ?>

<?php if(empty($users)): ?>

<tr>

    <td colspan="6" class="p-8 text-center text-gray-500">

        Aucun utilisateur trouvé

    </td>

</tr>

<?php endif; ?>

</tbody>

</table>

</div>

</div>

</div>

<script>

const roleSelect =
    document.getElementById('roleSelect');

const magasinBlock =
    document.getElementById('magasinBlock');

function toggleMagasin(){

    if(roleSelect.value === 'admin'){

        magasinBlock.style.display = 'none';

    }else{

        magasinBlock.style.display = 'block';
    }
}

toggleMagasin();

roleSelect.addEventListener(
    'change',
    toggleMagasin
);

</script>

<?php include 'includes/footer.php'; ?>