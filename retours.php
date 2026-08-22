
<?php
require_once 'config.php';
requireLogin();
requireRole('admin');

// Recherche vente
$vente = null;
$details = [];

if (isset($_GET['search'])) {
    $id = (int)$_GET['search'];

    $stmt = $pdo->prepare("SELECT * FROM ventes WHERE id=? AND magasin_id=?");
    $stmt->execute([$id, currentMagasinId()]);
    $vente = $stmt->fetch();

    if ($vente) {
        $d = $pdo->prepare("SELECT lv.*, p.nom FROM ligne_ventes lv
        JOIN produits p ON p.id=lv.produit_id WHERE lv.vente_id=?");
        $d->execute([$id]);
        $details = $d->fetchAll();
    }
}

// Traitement retour
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['retour'])) {
    verify_csrf();

    $vente_id = (int)$_POST['vente_id'];
    $produit_id = (int)$_POST['produit_id'];
    $quantite = (int)$_POST['quantite'];
    $motif = trim($_POST['motif']);

    $pdo->beginTransaction();
    try {
        // ligne vente
        $venteCheck = $pdo->prepare("SELECT id FROM ventes WHERE id=? AND magasin_id=? FOR UPDATE");
        $venteCheck->execute([$vente_id, currentMagasinId()]);
        if (!$venteCheck->fetch()) {
            throw new Exception('Vente introuvable ou magasin non autorisé');
        }

        $lv = $pdo->prepare("SELECT * FROM ligne_ventes WHERE vente_id=? AND produit_id=?");
        $lv->execute([$vente_id,$produit_id]);
        $line = $lv->fetch();

        $returned = $pdo->prepare("SELECT COALESCE(SUM(quantite),0) FROM retours WHERE vente_id=? AND produit_id=?");
        $returned->execute([$vente_id, $produit_id]);
        $remaining = $line ? (int)$line['quantite'] - (int)$returned->fetchColumn() : 0;

        if (!$line || $quantite <= 0 || $quantite > $remaining) {
            throw new Exception('Quantité invalide');
        }

        // remboursement partiel
        $remboursement = $line['prix_unitaire'] * $quantite;

        // stock actuel
        $p = $pdo->prepare("SELECT quantite FROM produits WHERE id=? AND magasin_id=? FOR UPDATE");
        $p->execute([$produit_id, currentMagasinId()]);
        $old = $p->fetchColumn();
        $new = $old + $quantite;

        $pdo->prepare("UPDATE produits SET quantite=? WHERE id=? AND magasin_id=?")->execute([$new,$produit_id,currentMagasinId()]);

        // retour
        $pdo->prepare("INSERT INTO retours (vente_id,produit_id,quantite,motif) VALUES (?,?,?,?)")
            ->execute([$vente_id,$produit_id,$quantite,$motif]);

        // mouvement stock
        $pdo->prepare("INSERT INTO stock_mouvements (produit_id,magasin_id,type,quantite,ancien_stock,nouveau_stock,motif,utilisateur_id)
        VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$produit_id,currentMagasinId(),'retour_client',$quantite,$old,$new,$motif,currentUser()['id']]);

        $pdo->commit();
        flash('success','Retour traité avec succès');
        header("Location: retours.php?search=$vente_id"); exit;

    } catch(Exception $e) {
        $pdo->rollBack();
        flash('error',$e->getMessage());
    }
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<h1 class="text-2xl font-bold mb-4">Retours Clients</h1>

<?php if($m=flash('success')): ?>
<div class="bg-green-100 text-green-700 p-3 rounded mb-3"><?php echo e($m); ?></div>
<?php endif; ?>
<?php if($m=flash('error')): ?>
<div class="bg-red-100 text-red-700 p-3 rounded mb-3"><?php echo e($m); ?></div>
<?php endif; ?>

<!-- RECHERCHE VENTE -->
<div class="bg-white p-4 rounded-2xl shadow mb-6">
<form method="GET" class="flex gap-3">
<input name="search" placeholder="ID vente" class="border p-3 rounded w-full" required>
<button class="bg-blue-600 text-white px-4 rounded">Rechercher</button>
</form>
</div>

<?php if($vente): ?>
<div class="grid md:grid-cols-2 gap-6">

<!-- DETAILS VENTE -->
<div class="bg-white p-4 rounded-2xl shadow">
<h2 class="text-xl font-bold mb-3">Détails Vente #<?php echo $vente['id']; ?></h2>
<?php foreach($details as $d): ?>
<div class="border p-2 rounded mb-2 flex justify-between">
<span><?php echo e($d['nom']); ?> (x<?php echo $d['quantite']; ?>)</span>
<span><?php echo number_format($d['sous_total'],2); ?></span>
</div>

<!-- FORM RETOUR -->
<form method="POST" class="flex gap-2 mt-2">
<input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
<input type="hidden" name="retour" value="1">
<input type="hidden" name="vente_id" value="<?php echo $vente['id']; ?>">
<input type="hidden" name="produit_id" value="<?php echo $d['produit_id']; ?>">
<input type="number" name="quantite" max="<?php echo $d['quantite']; ?>" placeholder="Qté retour" class="border p-2 rounded w-24">
<input name="motif" placeholder="Motif" class="border p-2 rounded w-full">
<button class="bg-red-600 text-white px-3 rounded">Retour</button>
</form>

<?php endforeach; ?>
</div>

<!-- INFO REMBOURSEMENT -->
<div class="bg-white p-4 rounded-2xl shadow">
<h2 class="text-xl font-bold mb-3">Infos</h2>
<p>Total vente: <?php echo number_format($vente['total'],2); ?></p>
<p>Mode paiement: <?php echo e($vente['mode_paiement']); ?></p>
<p>Date: <?php echo e($vente['date_vente']); ?></p>
</div>

</div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
