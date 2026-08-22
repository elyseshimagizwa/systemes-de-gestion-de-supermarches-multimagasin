<?php
require_once 'config.php';
requireLogin();

use Dompdf\Dompdf;

if (currentUser()['role'] !== 'admin') {
    exit("Accès refusé");
}

/* =========================
   DONNEES
========================= */
$ca_today = $pdo->query("
    SELECT COALESCE(SUM(total),0)
    FROM ventes
    WHERE DATE(date_vente)=CURDATE()
")->fetchColumn();

$ca_month = $pdo->query("
    SELECT COALESCE(SUM(total),0)
    FROM ventes
    WHERE MONTH(date_vente)=MONTH(CURDATE())
")->fetchColumn();

$stock = $pdo->query("
    SELECT COALESCE(SUM(quantite),0)
    FROM produits
")->fetchColumn();

/* =========================
   HTML
========================= */
$html = "
<h1>📊 RAPPORT DASHBOARD</h1>
<hr>
<p><b>CA Aujourd'hui:</b> $ca_today</p>
<p><b>CA Mois:</b> $ca_month</p>
<p><b>Stock Total:</b> $stock</p>
";

$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream("dashboard.pdf");