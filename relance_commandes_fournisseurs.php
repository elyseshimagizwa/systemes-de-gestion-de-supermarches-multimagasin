<?php
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/config.php';
    requireAdmin();
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/supplier-orders.php';

$stmt = $pdo->query("SELECT c.*, f.nom fournisseur_nom, f.email, m.nom magasin_nom FROM commandes c JOIN fournisseurs f ON f.id=c.fournisseur_id JOIN magasins m ON m.id=c.magasin_id WHERE c.email_erreur IS NOT NULL AND c.prochaine_relance IS NOT NULL AND c.prochaine_relance<=NOW() AND c.email_tentatives<5 ORDER BY c.id LIMIT 50");
$lineStmt = $pdo->prepare('SELECT lc.quantite, lc.prix_achat, p.nom AS produit_nom FROM ligne_commandes lc JOIN produits p ON p.id=lc.produit_id WHERE lc.commande_id=?');
$update = $pdo->prepare('UPDATE commandes SET email_envoye=?, email_erreur=?, email_tentatives=email_tentatives+1, prochaine_relance=? WHERE id=?');
foreach ($stmt->fetchAll() as $order) {
    $lineStmt->execute([(int)$order['id']]);
    $result = sendSupplierOrderEmail($order, $order, $lineStmt->fetchAll(), getSettings());
    $update->execute([$result['sent'] ? date('Y-m-d H:i:s') : null, $result['sent'] ? null : $result['error'], $result['sent'] ? null : date('Y-m-d H:i:s', time() + 3600), (int)$order['id']]);
}
echo "Relance fournisseur terminée\n";