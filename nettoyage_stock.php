<?php
require_once 'config.php';
requireLogin();
requireRole('admin');

// Supprimer produits expirés automatiquement
$pdo->exec("UPDATE produits SET quantite=0 WHERE date_peremption < CURDATE()");

// Log mouvement
$pdo->exec("INSERT INTO stock_mouvements (produit_id,type,quantite,ancien_stock,nouveau_stock,motif,utilisateur_id)
SELECT id,'perte',quantite,quantite,0,'Expiration automatique',1 FROM produits WHERE date_peremption < CURDATE()");

flash('success','Nettoyage stock effectué');
header('Location: dashboard.php');
exit;