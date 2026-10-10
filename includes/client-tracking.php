<?php
/** Shared, owner-scoped data for the customer pages and their refresh endpoint. */
function clientTrackingData(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT id, commande_id, titre, message, lu, created_at FROM notifications_clients WHERE utilisateur_id=? ORDER BY id DESC LIMIT 30');
    $stmt->execute([$userId]);
    $notifications = $stmt->fetchAll();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications_clients WHERE utilisateur_id=? AND lu=0');
    $stmt->execute([$userId]);
    return ['notifications' => $notifications, 'unread' => (int)$stmt->fetchColumn()];
}

function renderClientOrders(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare('SELECT c.*, m.nom AS magasin_nom, m.adresse, m.ville FROM commandes_clients c JOIN magasins m ON m.id=c.magasin_id WHERE c.utilisateur_id=? ORDER BY c.id DESC');
    $stmt->execute([$userId]);
    $orders = $stmt->fetchAll();
    $lines = $pdo->prepare('SELECT l.* FROM lignes_commandes_clients l JOIN commandes_clients c ON c.id=l.commande_id WHERE c.id=? AND c.utilisateur_id=? ORDER BY l.id');
    $history = $pdo->prepare('SELECT h.nouveau_statut, h.created_at FROM historique_commandes_clients h JOIN commandes_clients c ON c.id=h.commande_id WHERE c.id=? AND c.utilisateur_id=? ORDER BY h.id');
    if (!$orders) echo '<p class="rounded-2xl bg-white p-6">Vous n’avez encore aucune commande. <a href="index.php">Découvrir les produits</a></p>';
    foreach ($orders as $order):
        $id = (int)$order['id'];
        $lines->execute([$id, $userId]);
        $history->execute([$id, $userId]);
        ?>
        <article id="commande-<?= $id ?>" class="rounded-2xl bg-white p-6 shadow-sm" style="scroll-margin-top:100px">
            <div class="flex flex-wrap justify-between gap-4">
                <div><h2 class="text-xl font-black"><?= e($order['numero']) ?></h2>
                <p><?= e($order['date_commande']) ?></p>
                <p>Retrait : <?= e($order['magasin_nom']) ?> — <?= e($order['adresse']) ?> <?= e($order['ville']) ?></p></div>
                <div><strong data-order-status="<?= $id ?>" class="rounded-full bg-lime-100 px-3 py-1 text-green-900"><?= e($order['statut']) ?></strong>
                <p class="mt-3 text-xl font-bold"><?= number_format((float)$order['total'], 2, ',', ' ') ?></p></div>
            </div>
            <p class="mt-4">Code de retrait : <strong><?= e($order['code_retrait'] ?? 'Non disponible') ?></strong></p>
            <p><?= e($order['mode_paiement']) ?></p>
            <?php if ($order['statut'] === 'Prête'): ?><p class="mt-3 rounded-xl bg-green-100 p-3">Votre commande est prête ! Présentez votre code au magasin.</p><?php endif; ?>
            <div class="mt-4 border-t pt-4">
                <?php foreach ($lines->fetchAll() as $line): ?>
                <p class="flex justify-between gap-4"><span><?= e($line['nom_produit']) ?> × <?= rtrim(rtrim(number_format((float)$line['quantite'], 3, '.', ''), '0'), '.') ?></span><span><?= number_format((float)$line['sous_total'], 2, ',', ' ') ?></span></p>
                <?php endforeach; ?>
            </div>
            <h3 class="mt-5 font-bold">Historique d’avancement</h3>
            <ol class="mt-2 space-y-2" aria-label="Historique de la commande">
                <?php foreach ($history->fetchAll() as $event): ?>
                <li><time><?= e($event['created_at']) ?></time> — <?= e($event['nouveau_statut']) ?></li>
                <?php endforeach; ?>
            </ol>
        </article>
    <?php endforeach;
}
