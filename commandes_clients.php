<?php
require_once 'config.php';
requireLogin();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['statut'], $_POST['commande_id'])) {
    verify_csrf();
    $allowed = ['En attente', 'Confirmée', 'Préparée', 'Récupérée', 'Annulée'];
    $status = (string)$_POST['statut'];
    if (in_array($status, $allowed, true)) {
        $stmt = $pdo->prepare('UPDATE commandes_clients SET statut=? WHERE id=?');
        $stmt->execute([$status, (int)$_POST['commande_id']]);
    }
    header('Location: commandes_clients.php');
    exit;
}

$orders = $pdo->query("SELECT cc.*, u.nom AS client_nom, u.email AS client_email, m.nom AS magasin_nom FROM commandes_clients cc JOIN utilisateurs u ON u.id=cc.utilisateur_id JOIN magasins m ON m.id=cc.magasin_id ORDER BY cc.date_commande DESC")->fetchAll();
$lineStmt = $pdo->prepare('SELECT nom_produit, quantite, prix_unitaire, sous_total FROM lignes_commandes_clients WHERE commande_id=? ORDER BY id');
include 'includes/header.php';
include 'includes/sidebar.php';
?>
<main class="main-content p-6"><div class="mx-auto max-w-7xl"><div class="mb-8"><p class="text-sm font-bold uppercase tracking-wider text-green-700">Vente en ligne</p><h1 class="text-3xl font-black">Commandes clients</h1></div><?php if (!$orders): ?><div class="rounded-2xl bg-white p-8">Aucune commande client pour le moment.</div><?php endif; ?><div class="space-y-5"><?php foreach ($orders as $order): $lineStmt->execute([(int)$order['id']]); $lines = $lineStmt->fetchAll(); ?><article class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-black/5"><div class="flex flex-wrap items-start justify-between gap-4"><div><h2 class="text-xl font-black"><?= e($order['numero']) ?></h2><p class="text-gray-600"><?= e($order['client_nom']) ?> · <?= e($order['client_email']) ?></p><p class="text-sm text-gray-500">Retrait : <?= e($order['magasin_nom']) ?> · <?= e($order['date_commande']) ?></p></div><div class="text-right"><strong class="text-xl text-green-700"><?= number_format((float)$order['total'], 2, ',', ' ') ?></strong><form method="post" class="mt-2"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="commande_id" value="<?= (int)$order['id'] ?>"><select name="statut" onchange="this.form.submit()" class="rounded-xl border p-2"><?php foreach (['En attente','Confirmée','Préparée','Récupérée','Annulée'] as $status): ?><option <?= $order['statut'] === $status ? 'selected' : '' ?>><?= e($status) ?></option><?php endforeach; ?></select></form></div></div><div class="mt-4 border-t pt-4 text-sm"><?php foreach ($lines as $line): ?><div class="flex justify-between py-1"><span><?= e($line['nom_produit']) ?> × <?= (int)$line['quantite'] ?></span><span><?= number_format((float)$line['sous_total'], 2, ',', ' ') ?></span></div><?php endforeach; ?></div></article><?php endforeach; ?></div></div></main>
<?php include 'includes/footer.php'; ?>
