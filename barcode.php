<?php
require_once 'config.php';
requireLogin();

use Picqer\Barcode\BarcodeGeneratorHTML;

/* =========================
   PRODUIT
========================= */
$id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("
    SELECT * FROM produits WHERE id=?
");
$stmt->execute([$id]);
$p = $stmt->fetch();

if (!$p) exit("Produit introuvable");

$generator = new BarcodeGeneratorHTML();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Code Barres</title>
</head>
<body style="font-family: Arial; text-align:center;">

<h2><?php echo e($p['nom']); ?></h2>

<div style="margin:20px;">
    <?php echo $generator->getBarcode($p['codebarre'], $generator::TYPE_CODE_128); ?>
</div>

<p><b><?php echo e($p['codebarre']); ?></b></p>

</body>
</html>