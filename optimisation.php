<?php
require_once 'config.php';
requireLogin();
requireRole('admin');

// Optimisation tables
$tables = ['produits','ventes','ligne_ventes','stock_mouvements'];

foreach ($tables as $t) {
    $pdo->exec("OPTIMIZE TABLE $t");
}

flash('success','Optimisation base de données terminée');
header('Location: dashboard.php');
exit;