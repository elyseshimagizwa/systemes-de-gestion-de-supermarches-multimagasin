<?php

function clientOrderStatuses(): array
{
    return ['En attente', 'Confirmée', 'En préparation', 'Préparée', 'Prête', 'Récupérée', 'Annulée', 'Refusée', 'Expirée'];
}

function clientOrderStatusDates(): array
{
    return [
        'Confirmée' => 'date_confirmation',
        'En préparation' => 'date_preparation',
        'Préparée' => 'date_preparation',
        'Prête' => 'date_prete',
        'Récupérée' => 'date_retrait',
        'Annulée' => 'date_annulation',
    ];
}

function createClientNotification(PDO $pdo, int $userId, ?int $orderId, string $title, string $message): void
{
    $stmt = $pdo->prepare('INSERT INTO notifications_clients (utilisateur_id, commande_id, titre, message) VALUES (?, ?, ?, ?)');
    $stmt->execute([$userId, $orderId ?: null, $title, $message]);
}

function sendClientOrderEmail(array $order, string $status, array $settings): bool
{
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload) || empty($order['client_email'])) {
        return false;
    }

    require_once $autoload;
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = (string)($settings['smtp_host'] ?? '');
        $mail->Port = (int)($settings['smtp_port'] ?? 587);
        $mail->SMTPAuth = true;
        $mail->Username = (string)($settings['smtp_username'] ?? '');
        $mail->Password = (string)($settings['smtp_password'] ?? '');
        $mail->SMTPSecure = (string)($settings['smtp_secure'] ?? 'tls');
        $mail->CharSet = 'UTF-8';
        $mail->setFrom((string)($settings['smtp_from_email'] ?? $settings['email_admin'] ?? ''), (string)($settings['nom_boutique'] ?? 'Boutique'));
        $mail->addAddress((string)$order['client_email'], (string)$order['client_nom']);
        $mail->isHTML(true);
        $mail->Subject = 'Mise à jour de votre commande ' . $order['numero'];
        $mail->Body = '<h2>Commande ' . e($order['numero']) . '</h2><p>Statut : <strong>' . e($status) . '</strong></p><p>Votre code de retrait : <strong>' . e($order['code_retrait'] ?? '-') . '</strong></p>';
        $mail->send();
        return true;
    } catch (Throwable $exception) {
        error_log('Client order email failed: ' . $exception->getMessage());
        return false;
    }
}

function updateClientOrderStatus(PDO $pdo, int $orderId, string $newStatus, int $operatorId, string $comment = ''): array
{
    if (!in_array($newStatus, clientOrderStatuses(), true)) {
        throw new RuntimeException('Statut de commande invalide.');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT cc.*, u.nom AS client_nom, u.email AS client_email FROM commandes_clients cc JOIN utilisateurs u ON u.id=cc.utilisateur_id WHERE cc.id=? FOR UPDATE');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            throw new RuntimeException('Commande introuvable.');
        }

        $oldStatus = (string)$order['statut'];
        if ($oldStatus === $newStatus) {
            $pdo->commit();
            return $order;
        }

        if ($newStatus === 'Annulée' && $oldStatus !== 'Annulée') {
            $lines = $pdo->prepare('SELECT produit_id, quantite FROM lignes_commandes_clients WHERE commande_id=?');
            $lines->execute([$orderId]);
            $restore = $pdo->prepare('UPDATE produits SET quantite=quantite+? WHERE id=? AND magasin_id=?');
            foreach ($lines->fetchAll(PDO::FETCH_ASSOC) as $line) {
                $restore->execute([(int)$line['quantite'], (int)$line['produit_id'], (int)$order['magasin_id']]);
                $lot = $pdo->prepare('INSERT INTO lots_produits (produit_id, magasin_id, numero_lot, quantite_initiale, quantite_restante, prix_achat, date_expiration) SELECT id, ?, ?, ?, ?, prix_achat, date_peremption FROM produits WHERE id=? AND magasin_id=?');
                $lot->execute([(int)$order['magasin_id'], 'ANNULATION-WEB-' . $orderId . '-' . $line['produit_id'], (float)$line['quantite'], (float)$line['quantite'], (int)$line['produit_id'], (int)$order['magasin_id']]);
            }
        }

        $dateColumn = clientOrderStatusDates()[$newStatus] ?? null;
        $set = ['statut=?', 'traite_par=?'];
        $params = [$newStatus, $operatorId];
        if ($dateColumn) {
            $set[] = $dateColumn . '=NOW()';
        }
        $params[] = $orderId;
        $pdo->prepare('UPDATE commandes_clients SET ' . implode(', ', $set) . ' WHERE id=?')->execute($params);
        $pdo->prepare('INSERT INTO historique_commandes_clients (commande_id, ancien_statut, nouveau_statut, utilisateur_id, commentaire) VALUES (?, ?, ?, ?, ?)')->execute([$orderId, $oldStatus, $newStatus, $operatorId, $comment]);
        createClientNotification($pdo, (int)$order['utilisateur_id'], $orderId, 'Commande mise à jour', 'Votre commande ' . $order['numero'] . ' est maintenant : ' . $newStatus . '.');
        $pdo->commit();
        $order['statut'] = $newStatus;
        return $order;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}