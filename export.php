<?php
require_once 'config.php';
requireLogin();
requireRole('admin');

$type = $_GET['type'] ?? 'produits';

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="export_'.$type.'.csv"');

$output = fopen('php://output', 'w');

if ($type === 'produits') {
    fputcsv($output, ['ID','Nom','Codebarre','Prix Achat','Prix Vente','Quantité']);
    $stmt = $pdo->query("SELECT * FROM produits");
    while ($row = $stmt->fetch()) {
        fputcsv($output, $row);
    }
}

if ($type === 'ventes') {
    fputcsv($output, ['ID','Utilisateur','Total','Paiement','Date']);
    $stmt = $pdo->query("SELECT * FROM ventes");
    while ($row = $stmt->fetch()) {
        fputcsv($output, $row);
    }
}

if ($type === 'mouvements') {
    fputcsv($output, ['ID','Produit','Type','Quantité','Ancien','Nouveau','Date']);
    $stmt = $pdo->query("SELECT * FROM stock_mouvements");
    while ($row = $stmt->fetch()) {
        fputcsv($output, $row);
    }
}

fclose($output);
exit;