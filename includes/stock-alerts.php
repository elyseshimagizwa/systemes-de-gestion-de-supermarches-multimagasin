<?php

function sendLowStockSupplierAlerts(PDO $pdo, array $settings): array
{
    $result = ['sent' => 0, 'failed' => 0, 'resolved' => 0];
    $low = $pdo->query("SELECT p.id, p.nom, p.codebarre, p.quantite, p.seuil_alerte, p.prix_achat, p.magasin_id, f.id fournisseur_id, f.nom fournisseur_nom, f.email fournisseur_email, m.nom magasin_nom, m.adresse magasin_adresse, m.telephone magasin_telephone, m.email magasin_email FROM produits p JOIN magasins m ON m.id=p.magasin_id LEFT JOIN fournisseurs f ON f.id=p.fournisseur_id WHERE p.quantite<=p.seuil_alerte AND m.statut='actif' ORDER BY f.id, p.magasin_id, p.nom")->fetchAll(PDO::FETCH_ASSOC);
    $lowKeys = [];
    foreach ($low as $product) {
        $key = (int)$product['id'] . ':' . (int)$product['magasin_id'];
        $lowKeys[$key] = true;
    }

    $open = $pdo->query('SELECT id, produit_id, magasin_id FROM alertes_stock_fournisseurs WHERE resolue=0')->fetchAll(PDO::FETCH_ASSOC);
    $resolve = $pdo->prepare('UPDATE alertes_stock_fournisseurs SET resolue=1 WHERE id=?');
    foreach ($open as $alert) {
        if (!isset($lowKeys[(int)$alert['produit_id'] . ':' . (int)$alert['magasin_id']])) {
            $resolve->execute([(int)$alert['id']]);
            $result['resolved']++;
        }
    }

    $groups = [];
    foreach ($low as $product) {
        if (!filter_var($product['fournisseur_email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $key = (int)$product['fournisseur_id'] . ':' . (int)$product['magasin_id'];
        $groups[$key]['supplier'] = $product;
        $groups[$key]['products'][] = $product;
    }

    foreach ($groups as $group) {
        $supplier = $group['supplier'];
        $products = $group['products'];
        $shouldSend = false;
        foreach ($products as $product) {
            $stmt = $pdo->prepare('SELECT resolue, dernier_envoi FROM alertes_stock_fournisseurs WHERE produit_id=? AND magasin_id=?');
            $stmt->execute([(int)$product['id'], (int)$product['magasin_id']]);
            $alert = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$alert || (int)$alert['resolue'] === 1 || empty($alert['dernier_envoi']) || strtotime($alert['dernier_envoi']) < time() - 86400) {
                $shouldSend = true;
            }
        }
        if (!$shouldSend) continue;

        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (!is_file($autoload)) {
            $result['failed']++;
            continue;
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
            $from = $settings['smtp_from_email'] ?? $settings['email_admin'] ?? $supplier['magasin_email'];
            $mail->setFrom((string)$from, (string)($settings['nom_boutique'] ?? 'Boutique'));
            $mail->addAddress((string)$supplier['fournisseur_email'], (string)$supplier['fournisseur_nom']);
            $rows = '';
            foreach ($products as $product) {
                $rows .= '<tr><td style="padding:8px;border-bottom:1px solid #ddd">' . e($product['nom']) . '</td><td style="padding:8px;border-bottom:1px solid #ddd">' . (int)$product['quantite'] . '</td><td style="padding:8px;border-bottom:1px solid #ddd">' . (int)$product['seuil_alerte'] . '</td></tr>';
            }
            $mail->isHTML(true);
            $mail->Subject = 'Alerte de stock - ' . $supplier['magasin_nom'];
            $mail->Body = '<h2>Demande de réapprovisionnement</h2><p>Bonjour ' . e($supplier['fournisseur_nom']) . ',</p><p>Le stock des produits suivants est inférieur ou égal au seuil d’alerte dans <strong>' . e($supplier['magasin_nom']) . '</strong>.</p><table style="border-collapse:collapse;width:100%"><tr><th style="text-align:left;padding:8px">Produit</th><th style="text-align:left;padding:8px">Stock</th><th style="text-align:left;padding:8px">Seuil</th></tr>' . $rows . '</table><p>Boutique : ' . e($settings['nom_boutique'] ?? '') . '<br>Adresse : ' . e($settings['adresse'] ?? $supplier['magasin_adresse']) . '<br>Téléphone : ' . e($settings['telephone'] ?? $supplier['magasin_telephone']) . '<br>Email : ' . e($settings['email_admin'] ?? '') . '</p><p>Merci de nous communiquer vos disponibilités et délais.</p>';
            $mail->send();
            foreach ($products as $product) {
                $pdo->prepare('INSERT INTO alertes_stock_fournisseurs (produit_id, magasin_id, fournisseur_id, dernier_envoi, tentatives, derniere_erreur, resolue) VALUES (?, ?, ?, NOW(), 1, NULL, 0) ON DUPLICATE KEY UPDATE fournisseur_id=VALUES(fournisseur_id), dernier_envoi=NOW(), tentatives=tentatives+1, derniere_erreur=NULL, resolue=0')->execute([(int)$product['id'], (int)$product['magasin_id'], (int)$product['fournisseur_id']]);
            }
            $result['sent']++;
        } catch (Throwable $exception) {
            foreach ($products as $product) {
                $pdo->prepare('INSERT INTO alertes_stock_fournisseurs (produit_id, magasin_id, fournisseur_id, tentatives, derniere_erreur, resolue) VALUES (?, ?, ?, 1, ?, 0) ON DUPLICATE KEY UPDATE tentatives=tentatives+1, derniere_erreur=?')->execute([(int)$product['id'], (int)$product['magasin_id'], (int)$product['fournisseur_id'], $exception->getMessage(), $exception->getMessage()]);
            }
            $result['failed']++;
            error_log('Low stock supplier alert failed: ' . $exception->getMessage());
        }
    }
    return $result;
}