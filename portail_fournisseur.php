<?php
require_once __DIR__ . '/config.php';
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$stmt = $pdo->prepare('SELECT c.*, f.nom fournisseur_nom, f.email fournisseur_email, m.nom magasin_nom FROM commandes c JOIN fournisseurs f ON f.id=c.fournisseur_id JOIN magasins m ON m.id=c.magasin_id WHERE c.portail_token=? LIMIT 1');
$stmt->execute([$token]);
$order = $stmt->fetch();
$message = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $order) {
    $action = $_POST['action'] ?? '';
    if ($action === 'accept') {
        $pdo->prepare("UPDATE commandes SET fournisseur_statut='Acceptée', date_confirmation_fournisseur=NOW(), fournisseur_commentaire=? WHERE id=?")->execute([trim((string)($_POST['commentaire'] ?? '')), $order['id']]);
        $message = 'Commande acceptée. Merci.';
        $pdo->prepare("INSERT INTO historiques (utilisateur_id, magasin_id, action, details, ip, niveau) SELECT NULL, magasin_id, 'COMMANDE_FOURNISSEUR_ACCEPTEE', CONCAT('Commande fournisseur #', id), 'PORTAIL_FOURNISSEUR', 'SUCCESS' FROM commandes WHERE id=?")->execute([$order['id']]);
    } elseif ($action === 'refuse') {
        $pdo->prepare("UPDATE commandes SET fournisseur_statut='Refusée', fournisseur_commentaire=? WHERE id=?")->execute([trim((string)($_POST['commentaire'] ?? '')), $order['id']]);
        $message = 'Commande refusée. Le magasin sera contacté.';
    }
    $stmt->execute([$token]);
    $order = $stmt->fetch();
}
if (!$order) { http_response_code(404); exit('Lien fournisseur invalide ou expiré.'); }
$lines = $pdo->prepare('SELECT lc.quantite, lc.prix_achat, p.nom produit_nom FROM ligne_commandes lc JOIN produits p ON p.id=lc.produit_id WHERE lc.commande_id=?');
$lines->execute([$order['id']]);
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Portail fournisseur</title><link rel="stylesheet" href="assets/tailwind.css"></head><body class="min-h-screen bg-slate-100 p-5"><main class="mx-auto max-w-3xl rounded-3xl bg-white p-6 shadow"><h1 class="text-3xl font-black">Commande #<?= e($order['id']) ?></h1><p class="mt-2 text-gray-600">Magasin : <?= e($order['magasin_nom']) ?></p><?php if($message): ?><div class="my-5 rounded-xl bg-green-100 p-4 text-green-800"><?= e($message) ?></div><?php endif; ?><p class="mt-5 font-bold">Statut : <?= e($order['fournisseur_statut']) ?></p><div class="my-5 divide-y"><?php foreach($lines->fetchAll() as $line): ?><div class="flex justify-between py-3"><span><?= e($line['produit_nom']) ?></span><span><?= (int)$line['quantite'] ?></span></div><?php endforeach; ?></div><form method="post" class="space-y-3"><input type="hidden" name="token" value="<?= e($token) ?>"><textarea name="commentaire" placeholder="Commentaire ou délai prévu" class="w-full rounded-xl border p-3"></textarea><div class="flex gap-3"><button name="action" value="accept" class="rounded-xl bg-green-700 px-5 py-3 font-bold text-white">Accepter</button><button name="action" value="refuse" class="rounded-xl bg-red-700 px-5 py-3 font-bold text-white">Refuser</button></div></form></main></body></html>