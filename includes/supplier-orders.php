<?php

function supplierOrderPdf(array $supplier, array $order, array $lines): string
{
    $text = "BON DE COMMANDE FOURNISSEUR\nCommande #" . $order['id'] . "\nFournisseur: " . ($supplier['nom'] ?? '') . "\nMagasin: " . ($order['magasin_nom'] ?? '') . "\n\n";
    foreach ($lines as $line) {
        $text .= ($line['produit_nom'] ?? '') . ' | Qte: ' . (int)$line['quantite'] . ' | Prix: ' . number_format((float)$line['prix_achat'], 2, ',', ' ') . "\n";
    }

    $stream = "BT /F1 11 Tf 50 780 Td (" . str_replace(['\\', '(', ')', "\n"], ['\\\\', '\\(', '\\)', ' '], $text) . ") Tj ET";
    return "%PDF-1.4\n1 0 obj<< /Type /Catalog /Pages 2 0 R >>endobj\n2 0 obj<< /Type /Pages /Kids [3 0 R] /Count 1 >>endobj\n3 0 obj<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>endobj\n4 0 obj<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>endobj\n5 0 obj<< /Length " . strlen($stream) . " >>stream\n$stream\nendstream endobj\ntrailer<< /Root 1 0 R >>\n%%EOF";
}

function sendSupplierOrderEmail(array $supplier, array $order, array $lines, array $settings): array
{
    if (!filter_var($supplier['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
        return ['sent' => false, 'error' => 'Le fournisseur ne possède pas une adresse email valide.'];
    }

    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) {
        return ['sent' => false, 'error' => 'PHPMailer est indisponible.'];
    }

    require_once $autoload;
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $portalUrl = '';
        $mail->isSMTP();
        $mail->Host = (string)($settings['smtp_host'] ?? '');
        $mail->Port = (int)($settings['smtp_port'] ?? 587);
        $mail->SMTPAuth = true;
        $mail->Username = (string)($settings['smtp_username'] ?? '');
        $mail->Password = (string)($settings['smtp_password'] ?? '');
        $mail->SMTPSecure = (string)($settings['smtp_secure'] ?? 'tls');
        $mail->CharSet = 'UTF-8';
        $mail->setFrom((string)($settings['smtp_from_email'] ?? $settings['email_admin'] ?? ''), (string)($settings['nom_boutique'] ?? 'Boutique'));
        $mail->addAddress((string)$supplier['email'], (string)$supplier['nom']);
        if (!empty($order['portal_token'])) {
            $portalUrl = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/saas/portail_fournisseur.php?token=' . rawurlencode($order['portal_token']);
            $mail->addStringAttachment(supplierOrderPdf($supplier, $order, $lines), 'bon-commande-' . $order['id'] . '.pdf', 'base64', 'application/pdf');
        }
        $mail->isHTML(true);
        $mail->Subject = ($order['email_subject'] ?? 'Nouvelle commande fournisseur') . ' #' . $order['id'];

        $rows = '';
        $total = 0.0;
        foreach ($lines as $line) {
            $subtotal = (float)$line['quantite'] * (float)$line['prix_achat'];
            $total += $subtotal;
            $rows .= '<tr><td style="padding:8px;border-bottom:1px solid #ddd">' . e($line['produit_nom']) . '</td><td style="padding:8px;border-bottom:1px solid #ddd">' . (int)$line['quantite'] . '</td><td style="padding:8px;border-bottom:1px solid #ddd">' . number_format((float)$line['prix_achat'], 2, ',', ' ') . '</td><td style="padding:8px;border-bottom:1px solid #ddd">' . number_format($subtotal, 2, ',', ' ') . '</td></tr>';
        }

        $portalLink = !empty($portalUrl) ? '<p><a href="' . e($portalUrl) . '">Consulter et accepter la commande</a></p>' : '';
        $intro = ($order['email_message'] ?? 'Veuillez préparer les marchandises suivantes') . ' pour ' . e($order['magasin_nom']) . '.';
        $mail->Body = '<h2>Commande fournisseur #' . e($order['id']) . '</h2><p>Bonjour ' . e($supplier['nom']) . ',</p><p>' . $intro . '</p><table style="border-collapse:collapse;width:100%"><thead><tr><th style="text-align:left;padding:8px">Produit</th><th style="text-align:left;padding:8px">Quantité</th><th style="text-align:left;padding:8px">Prix achat</th><th style="text-align:left;padding:8px">Sous-total</th></tr></thead><tbody>' . $rows . '</tbody></table><p><strong>Total estimé : ' . number_format($total, 2, ',', ' ') . '</strong></p>' . $portalLink . '<p>Merci de confirmer la disponibilité et le délai de livraison.</p>';
        $mail->send();
        return ['sent' => true, 'error' => null];
    } catch (Throwable $exception) {
        error_log('Supplier order email failed: ' . $exception->getMessage());
        return ['sent' => false, 'error' => $exception->getMessage()];
    }
}