<?php
require_once 'config.php';
requireCaissier();

$receptionId = (int)($_GET['id'] ?? 0);
$commandeId = (int)($_GET['commande_id'] ?? 0);

if ($receptionId > 0) {
    $stmt = $pdo->prepare('SELECT r.*, c.id AS commande_id, f.nom AS fournisseur, m.nom AS magasin, u.nom AS utilisateur_nom FROM receptions_fournisseurs r JOIN commandes c ON c.id=r.commande_id JOIN fournisseurs f ON f.id=c.fournisseur_id JOIN magasins m ON m.id=r.magasin_id JOIN utilisateurs u ON u.id=r.utilisateur_id WHERE r.id=? AND r.magasin_id=?');
    $stmt->execute([$receptionId, (int)currentMagasinId()]);
} else {
    $stmt = $pdo->prepare('SELECT r.*, c.id AS commande_id, f.nom AS fournisseur, m.nom AS magasin, u.nom AS utilisateur_nom FROM receptions_fournisseurs r JOIN commandes c ON c.id=r.commande_id JOIN fournisseurs f ON f.id=c.fournisseur_id JOIN magasins m ON m.id=r.magasin_id JOIN utilisateurs u ON u.id=r.utilisateur_id WHERE r.commande_id=? AND r.magasin_id=? ORDER BY r.id DESC LIMIT 1');
    $stmt->execute([$commandeId, (int)currentMagasinId()]);
}
$reception = $stmt->fetch();
if (!$reception) {
    http_response_code(404);
    exit('Bon de réception introuvable.');
}

$linesStmt = $pdo->prepare('SELECT p.nom AS produit_nom, lc.quantite AS quantite_commandee, lr.* FROM lignes_receptions_fournisseurs lr JOIN ligne_commandes lc ON lc.id=lr.ligne_commande_id JOIN produits p ON p.id=lc.produit_id WHERE lr.reception_id=? ORDER BY lr.id');
$linesStmt->execute([(int)$reception['id']]);
$lines = $linesStmt->fetchAll();
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Bon de réception <?= e($reception['numero_bon']) ?></title>
<style>
body{font-family:Arial,sans-serif;color:#17221b;margin:32px}h1{margin-bottom:4px}.meta{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:24px 0}table{width:100%;border-collapse:collapse;margin-top:20px}th,td{border:1px solid #b8c2ba;padding:9px;text-align:left}th{background:#eaf0eb}.right{text-align:right}.actions{margin-bottom:24px}button,a{padding:9px 14px;border:1px solid #52665a;background:#fff;color:#17221b;text-decoration:none;border-radius:5px}@media print{.actions{display:none}body{margin:12mm}}
</style>
</head>
<body>
<div class="actions"><button type="button" onclick="window.print()">Imprimer</button> <a href="commandes.php">Retour aux commandes</a></div>
<h1>Bon de réception</h1>
<strong><?= e($reception['numero_bon']) ?></strong>
<div class="meta">
<div><strong>Magasin :</strong> <?= e($reception['magasin']) ?><br><strong>Fournisseur :</strong> <?= e($reception['fournisseur']) ?></div>
<div><strong>Commande :</strong> #<?= (int)$reception['commande_id'] ?><br><strong>Date :</strong> <?= e($reception['created_at']) ?><br><strong>Réceptionné par :</strong> <?= e($reception['utilisateur_nom']) ?></div>
</div>
<table>
<thead><tr><th>Produit</th><th>Commandé</th><th>Reçu</th><th>Endommagé</th><th>Manquant</th><th>Prix prévu</th><th>Prix reçu</th><th>Écart</th></tr></thead>
<tbody>
<?php foreach ($lines as $line): $ecart = (float)$line['prix_recu'] - (float)$line['prix_prevu']; ?>
<tr>
<td><?= e($line['produit_nom']) ?></td><td><?= e($line['quantite_commandee']) ?></td><td><?= e($line['quantite_recue']) ?></td><td><?= e($line['quantite_endommagee']) ?></td><td><?= e($line['quantite_manquante']) ?></td><td><?= e(number_format((float)$line['prix_prevu'], 2, ',', ' ')) ?></td><td><?= e(number_format((float)$line['prix_recu'], 2, ',', ' ')) ?></td><td><?= e(number_format($ecart, 2, ',', ' ')) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php if (!empty($reception['commentaire'])): ?><p><strong>Commentaire :</strong> <?= nl2br(e($reception['commentaire'])) ?></p><?php endif; ?>
</body>
</html>
