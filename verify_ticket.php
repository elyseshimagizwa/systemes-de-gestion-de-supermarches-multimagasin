<?php

require_once 'config.php';

$ticket =
    trim($_GET['ticket'] ?? '');

if(!$ticket){

    exit("Ticket invalide");
}

$stmt = $pdo->prepare("
    SELECT
        v.*,
        u.nom AS vendeur,
        m.nom AS magasin
    FROM ventes v

    LEFT JOIN utilisateurs u
    ON u.id=v.utilisateur_id

    LEFT JOIN magasins m
    ON m.id=v.magasin_id

    WHERE v.numero_ticket=?
    LIMIT 1
");

$stmt->execute([$ticket]);

$vente = $stmt->fetch();

if(!$vente){

    exit("
    <h2 style='font-family:Arial;color:red'>
        Ticket introuvable
    </h2>
    ");
}

?>

<!DOCTYPE html>
<html>
<head>

<meta charset="UTF-8">

<title>Vérification Ticket</title>

<link rel="stylesheet" href="assets/tailwind.css">

</head>

<body class="bg-gray-100 p-10">

<div class="max-w-xl mx-auto bg-white rounded-3xl shadow-xl p-8">

    <h1 class="text-3xl font-black mb-6 text-green-600">

        ✅ Ticket Vérifié

    </h1>

    <div class="space-y-3 text-lg">

        <div>

            <strong>Ticket :</strong>

            <?= htmlspecialchars($vente['numero_ticket']) ?>

        </div>

        <div>

            <strong>Date :</strong>

            <?= htmlspecialchars($vente['date_vente']) ?>

        </div>

        <div>

            <strong>Magasin :</strong>

            <?= htmlspecialchars($vente['magasin']) ?>

        </div>

        <div>

            <strong>Vendeur :</strong>

            <?= htmlspecialchars($vente['vendeur']) ?>

        </div>

        <div>

            <strong>Total :</strong>

            <?= number_format($vente['total'],2) ?>

        </div>

        <div>

            <strong>Paiement :</strong>

            <?= htmlspecialchars($vente['mode_paiement']) ?>

        </div>

    </div>

</div>

</body>
</html>