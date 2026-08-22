
<?php
require_once 'config.php';
requireLogin();

/* ======================================================
   CONFIGURATION
====================================================== */

$user = currentUser();

$magasin_id = $user['magasin_id'] ?? null;

$isAdmin =
    ($user['role'] ?? '') === 'admin';

$isGlobalAdmin =
(
    $isAdmin
    &&
    (
        empty($magasin_id)
        ||
        isset($_GET['global'])
    )
);
$whereMagasin = "";
$paramsMagasin = [];

if(!$isGlobalAdmin && $magasin_id){

    $whereMagasin =
        " AND p.magasin_id=? ";

    $paramsMagasin[] =
        $magasin_id;
}

/* ======================================================
   AJOUTER COLONNE PHOTOS SI N'EXISTE PAS
====================================================== */

try {

    $checkColumn = $pdo->query("
        SHOW COLUMNS FROM produits LIKE 'photos'
    ");

    if($checkColumn->rowCount() == 0){

        $pdo->exec("
            ALTER TABLE produits
            ADD photos LONGTEXT NULL
        ");
    }

} catch(Exception $e){}

/* ======================================================
   DOSSIER UPLOAD
====================================================== */

$uploadDir = "uploads/produits/";

if(!file_exists($uploadDir)){

    mkdir($uploadDir, 0777, true);
}

/* ======================================================
   FONCTIONS
====================================================== */

function ajouterHistorique(
    $pdo,
    $action,
    $details,
    $niveau = 'info'
){

    $historique = $pdo->prepare("
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
    ");

    $historique->execute([

        currentUser()['id'] ?? null,

        currentUser()['magasin_id'] ?? null,

        $action,

        $details,

        $_SERVER['REMOTE_ADDR'] ?? 'IP inconnue',

        $niveau
    ]);
}

function stockBadge($quantite, $seuil)
{
    if ($quantite <= 0) {

        return [
            'class' => 'bg-red-100 text-red-700',
            'text' => 'Rupture'
        ];

    } elseif ($quantite <= $seuil) {

        return [
            'class' => 'bg-yellow-100 text-yellow-700',
            'text' => 'Faible'
        ];
    }

    return [
        'class' => 'bg-green-100 text-green-700',
        'text' => 'Disponible'
    ];
}

/* ======================================================
   SECURITE + COMPRESSION IMAGE
====================================================== */

function uploadImages($files, $oldImages = [])
{
    global $uploadDir;

    $uploadedImages = [];

    if(!empty($oldImages)){

        $uploadedImages = $oldImages;
    }

    if(!isset($files['name'][0])){

        return json_encode($uploadedImages);
    }

    $allowed = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/jpg'
    ];

    foreach($files['tmp_name'] as $key => $tmpName){

        if(empty($tmpName)) continue;

        $mime = mime_content_type($tmpName);

        if(!in_array($mime, $allowed)){

            continue;
        }

        if($files['size'][$key] > 5 * 1024 * 1024){

            continue;
        }

        $extension = pathinfo(
            $files['name'][$key],
            PATHINFO_EXTENSION
        );

        $newName =
    bin2hex(random_bytes(16))
    . '.jpg';

        $destination =
            $uploadDir . $newName;

        compressImage(
            $tmpName,
            $destination,
            75
        );

        $uploadedImages[] = $destination;
    }

    return json_encode($uploadedImages);
}

/* ======================================================
   COMPRESSION IMAGE
====================================================== */

function compressImage(
    $source,
    $destination,
    $quality = 75
){

    $info = getimagesize($source);

    if(!$info){

        return false;
    }

    if($info['mime'] == 'image/jpeg'){

        $image =
            imagecreatefromjpeg($source);

    } elseif($info['mime'] == 'image/png'){

        $image =
            imagecreatefrompng($source);

    } elseif($info['mime'] == 'image/webp'){

        $image =
            imagecreatefromwebp($source);

    } else {

        return false;
    }

    imagejpeg(
        $image,
        $destination,
        $quality
    );

    imagedestroy($image);

    return true;
}

/* ======================================================
   SUPPRESSION IMAGE
====================================================== */

if(
    isset($_GET['delete_image'])
    &&
    isset($_GET['product'])
){

    verify_csrf();

    $productId = (int)$_GET['product'];

    $stmt = $pdo->prepare("
        SELECT *
        FROM produits
        WHERE id=?
        AND magasin_id=?
    ");

    $stmt->execute([
        $productId,
        $magasin_id
    ]);

    $produit = $stmt->fetch();

    if($produit){

        $photos =
            json_decode(
                $produit['photos'] ?? '[]',
                true
            );

        $imageToDelete =
            $_GET['delete_image'];

        if(
            ($key = array_search(
                $imageToDelete,
                $photos
            )) !== false
        ){

            unset($photos[$key]);

            if(file_exists($imageToDelete)){

                unlink($imageToDelete);
            }

            $update = $pdo->prepare("
                UPDATE produits
                SET photos=?
                WHERE id=?
            ");

            $update->execute([
                json_encode(array_values($photos)),
                $productId
            ]);

            ajouterHistorique(
                $pdo,
                'SUPPRESSION IMAGE',
                'Image supprimée produit ID : '
                . $productId,
                'warning'
            );

            $stock_history = $pdo->prepare("
                INSERT INTO stock_mouvements
                (
                    magasin_id,
                    produit_id,
                    type,
                    quantite,
                    ancien_stock,
                    nouveau_stock,
                    motif,
                    utilisateur_id,
                    date_mouvement
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
                    ?,
                    NOW()
                )
            ");

            $stock_history->execute([

                $magasin_id,
                $productId,
                'modification',
                0,
                $produit['quantite'],
                $produit['quantite'],
                'Suppression image produit',
                currentUser()['id']
            ]);
        }
    }

    flash('success', '🗑 Image supprimée');

    header('Location: produits.php?edit=' . $productId);

    exit;
}

/* ======================================================
   AJOUT PRODUIT
====================================================== */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['ajouter'])
) {

    verify_csrf();
    $pdo->beginTransaction();

try {

    $photos =
        uploadImages($_FILES['photos']);

    $stmt = $pdo->prepare("
        INSERT INTO produits
        (
            magasin_id,
            nom,
            codebarre,
            prix_achat,
            prix_vente,
            quantite,
            seuil_alerte,
            date_peremption,
            fournisseur_id,
            categorie_id,
            photos,
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
            ?,
            ?,
            ?,
            ?,
            NOW()
        )
    ");

    $stmt->execute([

        $magasin_id,

        trim($_POST['nom']),

        trim($_POST['codebarre']),

        $_POST['prix_achat'],

        $_POST['prix_vente'],

        $_POST['quantite'],

        $_POST['seuil_alerte'],

        $_POST['date_peremption'] ?: null,

        $_POST['fournisseur_id'] ?: null,

        $_POST['categorie_id'] ?: null,

        $photos
    ]);

    $produit_id = $pdo->lastInsertId();

    ajouterHistorique(

        $pdo,

        'AJOUT PRODUIT',

        'Ajout produit : '
        . $_POST['nom']
        . ' | Stock : '
        . $_POST['quantite']
        . ' | Photos ajoutées',

        'success'
    );

    $stock_history = $pdo->prepare("
        INSERT INTO stock_mouvements
        (
            magasin_id,
            produit_id,
            type,
            quantite,
            ancien_stock,
            nouveau_stock,
            motif,
            utilisateur_id,
            date_mouvement
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
            ?,
            NOW()
        )
    ");

    $stock_history->execute([

        $magasin_id,

        $produit_id,

        'entree',

        $_POST['quantite'],

        0,

        $_POST['quantite'],

        'Ajout nouveau produit + photos',

        currentUser()['id']
    ]);
    $pdo->commit();

    flash('success', '✅ Produit ajouté avec succès');

    } catch(Throwable $e){

    if($pdo->inTransaction()){

        $pdo->rollBack();
    }

    flash(
        'error',
        $e->getMessage()
    );
}

header('Location: produits.php');
exit;
}

/* ======================================================
   UPDATE PRODUIT
====================================================== */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['update'])
) {

    verify_csrf();
    $pdo->beginTransaction();

try {

    $stmtOld = $pdo->prepare("
        SELECT *
        FROM produits
        WHERE id=?
        AND magasin_id=?
    ");

    $stmtOld->execute([

        $_POST['id'],

        $magasin_id
    ]);

    $ancienProduit = $stmtOld->fetch();

    if (!$ancienProduit) {

        flash('error', 'Produit introuvable');

        header('Location: produits.php');

        exit;
    }

    $ancien_stock =
        $ancienProduit['quantite'];

    $oldPhotos =
        json_decode(
            $ancienProduit['photos'] ?? '[]',
            true
        );

    $photos =
        uploadImages(
            $_FILES['photos'],
            $oldPhotos
        );

    $stmt = $pdo->prepare("
        UPDATE produits SET

            nom=?,
            codebarre=?,
            prix_achat=?,
            prix_vente=?,
            quantite=?,
            seuil_alerte=?,
            date_peremption=?,
            fournisseur_id=?,
            categorie_id=?,
            photos=?

        WHERE id=?
        AND magasin_id=?
    ");

    $stmt->execute([

        trim($_POST['nom']),

        trim($_POST['codebarre']),

        $_POST['prix_achat'],

        $_POST['prix_vente'],

        $_POST['quantite'],

        $_POST['seuil_alerte'],

        $_POST['date_peremption'] ?: null,

        $_POST['fournisseur_id'] ?: null,

        $_POST['categorie_id'] ?: null,

        $photos,

        $_POST['id'],

        $magasin_id
    ]);

    ajouterHistorique(

        $pdo,

        'MODIFICATION PRODUIT',

        'Produit modifié : '
        . $_POST['nom']
        . ' + images',

        'warning'
    );

    $stock_history = $pdo->prepare("
        INSERT INTO stock_mouvements
        (
            magasin_id,
            produit_id,
            type,
            quantite,
            ancien_stock,
            nouveau_stock,
            motif,
            utilisateur_id,
            date_mouvement
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
            ?,
            NOW()
        )
    ");

    $stock_history->execute([

        $magasin_id,

        $_POST['id'],

        'modification',

        $_POST['quantite'],

        $ancien_stock,

        $_POST['quantite'],

        'Modification produit + images',

        currentUser()['id']
    ]);
    $pdo->commit();}
    catch (Exception $e) {
    // Gestion de l'erreur
    echo "Une erreur est survenue : " . $e->getMessage();
}

    flash('success', '✅ Produit modifié avec succès');

    header('Location: produits.php');

    exit;
}

/* ======================================================
   ENTREE / SORTIE STOCK
====================================================== */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['stock_action'])
) {

    verify_csrf();

    $produit_id =
        (int)$_POST['produit_id'];

    $quantite_action =
        (int)$_POST['quantite_action'];

    $stock_action =
        $_POST['stock_action'];

    if ($quantite_action <= 0) {

        flash('error', 'Quantité invalide');

        header('Location: produits.php');

        exit;
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM produits
        WHERE id=?
        AND magasin_id=?
    ");

    $stmt->execute([

        $produit_id,

        $magasin_id
    ]);

    $produit = $stmt->fetch();

    if (!$produit) {

        flash('error', 'Produit introuvable');

        header('Location: produits.php');

        exit;
    }

    $ancien_stock =
        $produit['quantite'];

    if ($stock_action == 'entree') {

        $nouveau_stock =
            $ancien_stock + $quantite_action;

        $typeAction = 'ENTREE STOCK';

    } else {

        $nouveau_stock =
            $ancien_stock - $quantite_action;

        if ($nouveau_stock < 0) {

            $nouveau_stock = 0;
        }

        $typeAction = 'SORTIE STOCK';
    }

    $update = $pdo->prepare("
        UPDATE produits
        SET quantite=?
        WHERE id=?
        AND magasin_id=?
    ");

    $update->execute([

        $nouveau_stock,

        $produit_id,

        $magasin_id
    ]);

    $historiqueStock = $pdo->prepare("
        INSERT INTO stock_mouvements
        (
            magasin_id,
            produit_id,
            type,
            quantite,
            ancien_stock,
            nouveau_stock,
            motif,
            utilisateur_id,
            date_mouvement
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
            ?,
            NOW()
        )
    ");

    $motif =
        ($stock_action == 'entree')
        ? 'Entrée stock'
        : 'Sortie stock';

    $historiqueStock->execute([

        $magasin_id,

        $produit_id,

        $stock_action,

        $quantite_action,

        $ancien_stock,

        $nouveau_stock,

        $motif,

        currentUser()['id']
    ]);

    ajouterHistorique(

        $pdo,

        $typeAction,

        'Produit : '
        . $produit['nom']
        . ' | Qté : '
        . $quantite_action
        . ' | Ancien : '
        . $ancien_stock
        . ' | Nouveau : '
        . $nouveau_stock,

        'info'
    );

    flash('success', '✅ Stock mis à jour');

    header('Location: produits.php');

    exit;
}

/* ======================================================
   DELETE PRODUIT
====================================================== */

if (
    isset($_GET['delete'])
    &&
    currentUser()['role'] === 'admin'
) {

    verify_csrf();

    $id = (int)$_GET['delete'];

    $stmtProduit = $pdo->prepare("
        SELECT *
        FROM produits
        WHERE id=?
        AND magasin_id=?
    ");

    $stmtProduit->execute([

        $id,

        $magasin_id
    ]);

    $produitSupprime = $stmtProduit->fetch();

    if ($produitSupprime) {

        $photos =
            json_decode(
                $produitSupprime['photos'] ?? '[]',
                true
            );

        foreach($photos as $img){

            if(file_exists($img)){

                unlink($img);
            }
        }

        ajouterHistorique(

            $pdo,

            'SUPPRESSION PRODUIT',

            'Produit supprimé : '
            . $produitSupprime['nom']
            . ' | Stock restant : '
            . $produitSupprime['quantite'],

            'danger'
        );

        $historiqueStock = $pdo->prepare("
            INSERT INTO stock_mouvements
            (
                magasin_id,
                produit_id,
                type,
                quantite,
                ancien_stock,
                nouveau_stock,
                motif,
                utilisateur_id,
                date_mouvement
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
                ?,
                NOW()
            )
        ");

        $historiqueStock->execute([

            $magasin_id,

            $id,

            'suppression',

            $produitSupprime['quantite'],

            $produitSupprime['quantite'],

            0,

            'Suppression produit',

            currentUser()['id']
        ]);

        $delete = $pdo->prepare("
            DELETE FROM produits
            WHERE id=?
            AND magasin_id=?
        ");

        $delete->execute([

            $id,

            $magasin_id
        ]);

        flash('success', '🗑 Produit supprimé');

    } else {

        flash('error', 'Produit introuvable');
    }

    header('Location: produits.php');

    exit;
}

/* ======================================================
   MODE EDIT
====================================================== */

$editProduct = null;

if (isset($_GET['edit'])) {

    $stmt = $pdo->prepare("
        SELECT *
        FROM produits
        WHERE id=?
        AND magasin_id=?
    ");

    $stmt->execute([

        (int)$_GET['edit'],

        $magasin_id
    ]);

    $editProduct = $stmt->fetch();
}

/* ======================================================
   SEARCH
====================================================== */

$search = trim($_GET['search'] ?? '');
$categorie_filter = (int)($_GET['categorie_filter'] ?? 0);
$magasin_filter =
(int)($_GET['magasin_filter'] ?? 0);

if(
    $isGlobalAdmin
    &&
    $magasin_filter > 0
){

    $sql .= "
    AND p.magasin_id=?
    ";

    $params[] =
        $magasin_filter;
}

$sql = "


    SELECT
p.*,
c.nom AS categorie,
f.nom AS fournisseur,
m.nom AS magasin

FROM produits p

LEFT JOIN categories c
ON c.id = p.categorie_id

LEFT JOIN fournisseurs f
ON f.id = p.fournisseur_id

LEFT JOIN magasins m
ON m.id=p.magasin_id

WHERE 1=1
$whereMagasin
";

$params = $paramsMagasin;
/* ===============================
   RECHERCHE TEXTE
================================ */

if ($search !== '') {

    $sql .= "
    AND
    (
        p.nom LIKE ?
        OR p.codebarre LIKE ?
    )
    ";

    $params[] = "%$search%";
    $params[] = "%$search%";
}

/* ===============================
   FILTRE CATEGORIE
================================ */

if ($categorie_filter > 0) {

    $sql .= "
    AND p.categorie_id = ?
    ";

    $params[] = $categorie_filter;
}

/* ===============================
   TRI
================================ */

/* ===============================
   PAGINATION
================================ */

$page = max(
    1,
    (int)($_GET['page'] ?? 1)
);

$limit = 24;

$offset =
    ($page - 1) * $limit;

$sql .= "
ORDER BY p.nom ASC
LIMIT $limit
OFFSET $offset
";
$countSql = "
SELECT COUNT(*)

FROM produits p

WHERE p.magasin_id=?
";

$countParams = [$magasin_id];

if ($search !== '') {

    $countSql .= "
    AND (
        p.nom LIKE ?
        OR p.codebarre LIKE ?
    )
    ";

    $countParams[] = "%$search%";
    $countParams[] = "%$search%";
}

if ($categorie_filter > 0) {

    $countSql .= "
    AND p.categorie_id=?
    ";

    $countParams[] = $categorie_filter;
}

$countStmt = $pdo->prepare($countSql);

$countStmt->execute($countParams);

$totalRows =
    (int)$countStmt->fetchColumn();

$totalPages =
    ceil($totalRows / $limit);

$stmt = $pdo->prepare($sql);

$stmt->execute($params);

$produits = $stmt->fetchAll();

/* ======================================================
   STATS
====================================================== */

/* ======================================================
   STATS
====================================================== */

$statsSql = "
SELECT

COUNT(*) total_produits,

SUM(
    CASE
        WHEN quantite <= seuil_alerte
        THEN 1
        ELSE 0
    END
) stock_faible,

SUM(
    prix_achat * quantite
) valeur_stock

FROM produits

WHERE 1=1
";

$statsParams = [];

/* FILTRE MAGASIN */

if(!$isGlobalAdmin){

    $statsSql .= "
        AND magasin_id=?
    ";

    $statsParams[] = $magasin_id;
}

/* EXECUTION */

$stats = $pdo->prepare($statsSql);

$stats->execute($statsParams);

$s = $stats->fetch();

/* RESULTATS */

$totalProduits =
    $s['total_produits'] ?? 0;

$stockFaible =
    $s['stock_faible'] ?? 0;

$valeurStock =
    $s['valeur_stock'] ?? 0;

$categories = $pdo->query("
    SELECT *
    FROM categories
    ORDER BY nom ASC
")->fetchAll();

$fournisseurs = $pdo->query("
    SELECT *
    FROM fournisseurs
    ORDER BY nom ASC
")->fetchAll();

$stmtMagasin = $pdo->prepare("
    SELECT *
    FROM magasins
    WHERE id=?
");

$stmtMagasin->execute([
    $magasin_id
]);

$magasin = $stmtMagasin->fetch();

 if($totalPages > 1): ?>

<div class="flex justify-center gap-2 mt-8 flex-wrap">

<?php for($i=1;$i<=$totalPages;$i++): ?>

<a
href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&categorie_filter=<?= $categorie_filter ?>"
class="<?= $page==$i
? 'bg-blue-600 text-white'
: 'bg-white' ?>
px-4 py-2 rounded-xl border">

<?= $i ?>

</a>

<?php endfor; ?>

</div>

<?php endif; 

$magasins =
$pdo->query("
SELECT *
FROM magasins
ORDER BY nom
")->fetchAll();

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="p-4 md:p-6 bg-gray-50 min-h-screen">

<?php if($msg = flash('success')): ?>
<div class="bg-green-100 border border-green-300 text-green-700 p-4 rounded-2xl mb-4 shadow">
    <?= e($msg) ?>
</div>
<?php endif; ?>

<?php if($msg = flash('error')): ?>
<div class="bg-red-100 border border-red-300 text-red-700 p-4 rounded-2xl mb-4 shadow">
    <?= e($msg) ?>
</div>
<?php endif; ?>

<div class="flex flex-col lg:flex-row justify-between lg:items-center gap-4 mb-6">

    <div>

        <h1 class="text-4xl font-black text-gray-800">
            📦 Gestion Produits
        </h1>

    </div>
    <div>
        <form method="GET" class="flex flex-col lg:flex-row gap-3">

    <!-- RECHERCHE -->

    <input
    type="text"
    name="search"
    value="<?= e($search) ?>"
    placeholder="🔍 Recherche produit..."
    class="border-2 border-gray-200 focus:border-black outline-none p-4 rounded-2xl w-full">

    <!-- FILTRE CATEGORIE -->

    <select
    name="categorie_filter"
    class="border-2 border-gray-200 focus:border-black outline-none p-4 rounded-2xl w-full lg:w-72">

        <option value="">
            📂 Toutes catégories
        </option>

        <?php foreach($categories as $c): ?>

        <option
        value="<?= $c['id'] ?>"
        <?= ($categorie_filter == $c['id']) ? 'selected' : '' ?>>

            <?= e($c['nom']) ?>

        </option>

        <?php endforeach; ?>

    </select>
    <?php if($isGlobalAdmin): ?>

<select
name="magasin_filter"
class="border p-4 rounded-2xl">

<option value="">
Tous les magasins
</option>

<?php foreach($magasins as $mg): ?>

<option
value="<?= $mg['id'] ?>"
<?= ($_GET['magasin_filter'] ?? '') == $mg['id']
? 'selected'
: '' ?>>

<?= e($mg['nom']) ?>

</option>

<?php endforeach; ?>

</select>

<?php endif; ?>

    <!-- BOUTON -->

    <button
    class="bg-black hover:bg-gray-800 transition text-white px-8 rounded-2xl">

        🔎 Rechercher...

    </button>

    <!-- RESET -->

    <a
    href="produits.php"
    class="bg-gray-200 hover:bg-gray-300 transition px-8 py-4 rounded-2xl text-center">

        🔄 Reset

    </a>

</form>
    </div>

    <div class="flex flex-wrap gap-3">

        <button
        onclick="toggleProduitForm()"
        class="bg-black hover:bg-gray-800 transition text-white px-6 py-3 rounded-2xl shadow-lg">

            ➕ Ajouter Produit

        </button>

    </div>

</div>

<div
id="productForm"
class="<?= $editProduct ? '' : 'hidden' ?> bg-white rounded-3xl shadow border p-6 mb-8">

<h2 class="text-3xl font-black mb-6">

    <?= $editProduct ? '✏ Modifier Produit' : '➕ Nouveau Produit' ?>

</h2>

<form
method="POST"
enctype="multipart/form-data"
class="grid md:grid-cols-2 gap-5">

<input
type="hidden"
name="csrf_token"
value="<?= csrf_token() ?>">

<?php if($editProduct): ?>

<input
type="hidden"
name="id"
value="<?= $editProduct['id'] ?>">

<?php endif; ?>

<div>
<label class="font-semibold block mb-2">
Nom produit
</label>

<input
type="text"
name="nom"
required
value="<?= e($editProduct['nom'] ?? '') ?>"
class="border-2 border-gray-200 p-4 rounded-2xl w-full">
</div>

<div>
<label class="font-semibold block mb-2">
Code barre
</label>

<input
type="text"
name="codebarre"
value="<?= e($editProduct['codebarre'] ?? '') ?>"
class="border-2 border-gray-200 p-4 rounded-2xl w-full">
</div>

<div>
<label class="font-semibold block mb-2">
Prix achat
</label>

<input
type="number"
step="0.01"
name="prix_achat"
required
value="<?= e($editProduct['prix_achat'] ?? '') ?>"
class="border-2 border-gray-200 p-4 rounded-2xl w-full">
</div>

<div>
<label class="font-semibold block mb-2">
Prix vente
</label>

<input
type="number"
step="0.01"
name="prix_vente"
required
value="<?= e($editProduct['prix_vente'] ?? '') ?>"
class="border-2 border-gray-200 p-4 rounded-2xl w-full">
</div>

<div>
<label class="font-semibold block mb-2">
Quantité
</label>

<input
type="number"
name="quantite"
required
value="<?= e($editProduct['quantite'] ?? 0) ?>"
class="border-2 border-gray-200 p-4 rounded-2xl w-full">
</div>

<div>
<label class="font-semibold block mb-2">
Seuil alerte
</label>

<input
type="number"
name="seuil_alerte"
required
value="<?= e($editProduct['seuil_alerte'] ?? 1) ?>"
class="border-2 border-gray-200 p-4 rounded-2xl w-full">
</div>

<div>
<label class="font-semibold block mb-2">
Date péremption
</label>

<input
type="date"
name="date_peremption"
value="<?= e($editProduct['date_peremption'] ?? '') ?>"
class="border-2 border-gray-200 p-4 rounded-2xl w-full">
</div>

<div>
<label class="font-semibold block mb-2">
Catégorie
</label>

<select
name="categorie_id"
class="border-2 border-gray-200 p-4 rounded-2xl w-full">

<option value="">Catégorie</option>

<?php foreach($categories as $c): ?>

<option
value="<?= $c['id'] ?>"
<?= (($editProduct['categorie_id'] ?? '') == $c['id']) ? 'selected' : '' ?>>

<?= e($c['nom']) ?>

</option>

<?php endforeach; ?>
<?php if($isGlobalAdmin): ?>

<div class="text-blue-600 font-bold mb-2">

🏪 <?= e($p['magasin']) ?>

</div>

<?php endif; ?>

</select>
</div>

<div class="md:col-span-2">
<label class="font-semibold block mb-2">
Fournisseur
</label>

<select
name="fournisseur_id"
class="border-2 border-gray-200 p-4 rounded-2xl w-full">

<option value="">Fournisseur</option>

<?php foreach($fournisseurs as $f): ?>

<option
value="<?= $f['id'] ?>"
<?= (($editProduct['fournisseur_id'] ?? '') == $f['id']) ? 'selected' : '' ?>>

<?= e($f['nom']) ?>

</option>

<?php endforeach; ?>

</select>
</div>

<!-- IMAGES -->

<div class="md:col-span-2">

<label class="font-semibold block mb-3">
📷 Photos Produit
</label>

<input
type="file"
name="photos[]"
multiple
accept="image/*"
class="border-2 border-gray-200 p-4 rounded-2xl w-full">

</div>

<?php
if($editProduct){

$imgs =
    json_decode(
        $editProduct['photos'] ?? '[]',
        true
    );

if(!empty($imgs)):
?>

<div class="md:col-span-2">

<div class="grid grid-cols-2 md:grid-cols-4 gap-4">

<?php foreach($imgs as $img): ?>

<div class="relative">

<img
src="<?= e($img) ?>"
class="w-full h-40 object-cover rounded-2xl border">

<a
href="?edit=<?= $editProduct['id'] ?>&delete_image=<?= urlencode($img) ?>&csrf_token=<?= csrf_token() ?>"
onclick="return confirm('Supprimer cette image ?')"
class="absolute top-2 right-2 bg-red-600 text-white px-2 py-1 rounded-lg text-xs">

X

</a>

</div>

<?php endforeach; ?>

</div>

</div>

<?php endif; } ?>

<div class="md:col-span-2 flex flex-wrap gap-3 mt-3">

<?php if($editProduct): ?>

<button
type="submit"
name="update"
class="bg-blue-600 hover:bg-blue-700 transition text-white px-7 py-4 rounded-2xl shadow">

💾 Modifier

</button>

<?php else: ?>

<button
type="submit"
name="ajouter"
class="bg-green-600 hover:bg-green-700 transition text-white px-7 py-4 rounded-2xl shadow">

✅ Ajouter Produit

</button>

<?php endif; ?>

</div>

</form>

</div>

<!-- PRODUITS -->

<div class="grid md:grid-cols-2 xl:grid-cols-3 gap-6">

<?php foreach($produits as $p): ?>

<?php
$badge =
    stockBadge(
        $p['quantite'],
        $p['seuil_alerte']
    );

$images =
    json_decode(
        $p['photos'] ?? '[]',
        true
    );
?>

<div class="bg-white rounded-3xl shadow-lg border overflow-hidden">

<?php if(!empty($images)): ?>

<img
loading="lazy"
decoding="async"
src="<?= e($images[0]) ?>"
class="w-full h-60 object-cover"
alt="<?= e($p['nom']) ?>">

<?php else: ?>

<div class="w-full h-60 bg-gray-100 flex items-center justify-center text-6xl">
📦
</div>

<?php endif; ?>

<div class="p-6">

<h2 class="font-black text-2xl text-gray-800 mb-2">
<?= e($p['nom']) ?>
</h2>

<div class="text-sm text-gray-500 mb-3">
<?= e($p['codebarre']) ?>
</div>

<span class="px-4 py-2 rounded-full text-sm font-bold <?= $badge['class'] ?>">

<?= $badge['text'] ?> :
<?= $p['quantite'] ?>

</span>

<div class="space-y-3 text-sm my-5">

<div class="flex justify-between">
<span>💰 Vente</span>
<b><?= number_format($p['prix_vente'],2) ?></b>
</div>

<div class="flex justify-between">
<span>📦 Achat</span>
<b><?= number_format($p['prix_achat'],2) ?></b>
</div>

<div class="flex justify-between">
<span>🏷 Catégorie</span>
<b><?= e($p['categorie'] ?? '-') ?></b>
</div>

<div class="flex justify-between">
<span>🚚 Fournisseur</span>
<b><?= e($p['fournisseur'] ?? '-') ?></b>
</div>

</div>

<form method="POST" class="mb-5">

<input
type="hidden"
name="csrf_token"
value="<?= csrf_token() ?>">

<input
type="hidden"
name="produit_id"
value="<?= $p['id'] ?>">

<div class="flex gap-2">

<button
type="submit"
name="stock_action"
value="sortie"
class="bg-red-500 text-white px-5 rounded-2xl">

-

</button>

<input
type="number"
name="quantite_action"
value="1"
min="1"
class="border-2 border-gray-200 p-3 rounded-2xl w-full text-center font-bold">

<button
type="submit"
name="stock_action"
value="entree"
class="bg-green-500 text-white px-5 rounded-2xl">

+

</button>

</div>

</form>

<div class="grid grid-cols-2 gap-3">

<a
href="?edit=<?= $p['id'] ?>"
class="bg-blue-600 text-white text-center p-4 rounded-2xl font-semibold">

✏ Modifier

</a>

<?php if(currentUser()['role']=='admin'): ?>

<a
href="?delete=<?= $p['id'] ?>&csrf_token=<?= csrf_token() ?>"
onclick="return confirm('Supprimer ce produit ?')"
class="bg-red-600 text-white text-center p-4 rounded-2xl font-semibold">

🗑 Supprimer

</a>

<?php endif; ?>

</div>

</div>

</div>

<?php endforeach; ?>

</div>

<script>

function toggleProduitForm(){

    const form =
        document.getElementById('productForm');

    form.classList.toggle('hidden');

    window.scrollTo({
        top: 0,
        behavior: 'smooth'
    });
}

</script>

<?php include 'includes/footer.php'; ?>

