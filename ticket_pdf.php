
<?php



require_once 'config.php';
require_once 'config-settings.php';

requireLogin();

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');

$user = currentUser();

/*
|--------------------------------------------------------------------------
| UTILISATEUR
|--------------------------------------------------------------------------
*/

$role = $user['role'] ?? 'caissier';

$isSuperAdmin = false;
$isAdmin = ($role == 'admin');
$isManager = false;
$isCaissier = ($role == 'caissier');

/*
|--------------------------------------------------------------------------
| MAGASIN ACTIF
|--------------------------------------------------------------------------
*/

$currentMagasinId = currentMagasinId();

if($currentMagasinId<=0){

    exit("Aucun magasin actif.");

}

$settings = getSettings();

$tvaRate = (float)($settings['tva'] ?? 0);
$devise = trim($settings['devise'] ?? 'BIF');
$nomBoutique = trim($settings['nom_boutique'] ?? 'POS');
$logo = trim($settings['logo'] ?? '');

$stmtMagasin = $pdo->prepare("
   SELECT

id,
nom,
code,
ville,
telephone,
adresse,
statut

FROM magasins

WHERE id=?

LIMIT 1
");

$stmtMagasin->execute([

$currentMagasinId

]);

$magasin = $stmtMagasin->fetch();

$stmtProduits = $pdo->prepare("
  SELECT

id,
nom,
codebarre,
prix_vente,
quantite,
magasin_id

FROM produits

WHERE

quantite>0

AND magasin_id=?

ORDER BY nom ASC");

$stmtProduits->execute([

$currentMagasinId

]);

$produits = $stmtProduits->fetchAll();

/* =========================================================
   VENTE
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['valider'])) {

    verify_csrf();

    try {

        $items = json_decode(
            $_POST['panier'] ?? '[]',
            true
        );

        if(empty($items)) {
            throw new Exception("Panier vide");
        }

        $mode = trim($_POST['mode_paiement']);

        $montantRecu = (float)$_POST['montant_recu'];

        $pdo->beginTransaction();

        $totalHT = 0;

        $validatedItems = [];

        foreach($items as $it){

            $q = $pdo->prepare("
           SELECT *

FROM produits

WHERE

id=?

AND magasin_id=?

FOR UPDATE");

           $q->execute([

$it['id'],

$currentMagasinId

]);

            $p = $q->fetch();

            if(!$p){
                throw new Exception("Produit introuvable");
            }

            if($p['quantite'] < $it['qty']){
                throw new Exception("Stock insuffisant");
            }

            $sousTotal =
                $it['qty'] * $p['prix_vente'];

            $totalHT += $sousTotal;

            $validatedItems[] = [

                'id' => $p['id'],
                'nom' => $p['nom'],
                'qty' => $it['qty'],
                'prix' => $p['prix_vente'],
                'sous_total' => $sousTotal
            ];
        }

        $tva =
            $totalHT * ($tvaRate / 100);

        $totalTTC =
            $totalHT + $tva;

        $monnaie =
            max(0, $montantRecu - $totalTTC);

        $numeroTicket =
            'TK-'.date('YmdHis');

        $stmt = $pdo->prepare("
            INSERT INTO ventes
            (
                numero_ticket,
                utilisateur_id,
                magasin_id,
                total,
                montant_recu,
                monnaie,
                mode_paiement,
                tva,
                date_vente
            )
            VALUES
            (
                ?,?,?,?,?,?,?,?,NOW()
            )
        ");

       $stmt->execute([

$numeroTicket,

$user['id'],

$currentMagasinId,

$totalTTC,

$montantRecu,

$monnaie,

$mode,

$tva

]);

        $venteId = $pdo->lastInsertId();

        foreach($validatedItems as $item){

            $ligne = $pdo->prepare("
                INSERT INTO ligne_ventes
                (
                    vente_id,
                    produit_id,
                    quantite,
                    prix_unitaire,
                    sous_total
                )
                VALUES
                (?,?,?,?,?)
            ");

            $ligne->execute([

                $venteId,
                $item['id'],
                $item['qty'],
                $item['prix'],
                $item['sous_total']
            ]);

            $update = $pdo->prepare("
              UPDATE produits

SET quantite=quantite-?

WHERE

id=?

AND magasin_id=?");

            $update->execute([

$item['qty'],

$item['id'],

$currentMagasinId

]);
        }

        $pdo->commit();

        header("Location:caisse.php?ticket=".$venteId);

        exit;

    } catch(Throwable $e){

        if($pdo->inTransaction()){
            $pdo->rollBack();
        }

        die($e->getMessage());
    }
}

include 'includes/header.php';
include 'includes/sidebar.php';

?>

<link rel="stylesheet" href="assets/tailwind.css">

<script src="assets/vendor/qrcode.min.js"></script>

<style>

body{
    background:#f1f5f9;
}

.product-card{
    background:white;
    border-radius:20px;
    padding:18px;
    border:1px solid #e2e8f0;
    transition:.2s;
}

.product-card:hover{
    transform:translateY(-3px);
}

.ticket-box{
    width:80mm;
    background:white;
    padding:15px;
    font-family:monospace;
}

</style>

<div class="p-5">

<div class="flex justify-between mb-6">

<div>

<h1 class="text-4xl font-black">
POS CAISSE
</h1>

<div class="text-gray-500 mt-1">
<?= e($nomBoutique) ?>
</div>

</div>

<button
onclick="toggleCart()"
class="bg-black text-white px-5 py-3 rounded-2xl"
>

🛒 Panier

</button>

</div>

<div class="grid md:grid-cols-4 gap-4">

<?php foreach($produits as $p): ?>

<button
type="button"
onclick='addItem(<?= json_encode($p) ?>)'
class="product-card text-left"
>

<div class="font-bold text-lg">

<?= e($p['nom']) ?>

</div>

<div class="mt-2 text-green-600 font-bold">

<?= number_format($p['prix_vente'],2) ?>

<?= e($devise) ?>

</div>

<div class="mt-2 text-sm text-gray-500">

Stock :
<?= (int)$p['quantite'] ?>

</div>

</button>

<?php endforeach; ?>

</div>

</div>

<!-- PANIER -->

<div
id="cartPanel"
class="fixed top-0 right-0 w-[400px] h-full bg-white shadow-2xl hidden overflow-y-auto z-50"
>

<div class="p-5 flex justify-between border-b">

<h2 class="text-2xl font-black">

🛒 Panier

</h2>

<button onclick="toggleCart()">

✖

</button>

</div>

<div id="cart" class="p-5"></div>

<div class="p-5 border-t">

<form
method="POST"
onsubmit="return submitCart()"
>

<input
type="hidden"
name="csrf_token"
value="<?= csrf_token() ?>"
>

<input
type="hidden"
name="valider"
value="1"
>

<input
type="hidden"
name="panier"
id="panierField"
>

<select
name="mode_paiement"
class="w-full border rounded-xl p-3 mb-3"
>

<option>Espèces</option>
<option>Carte</option>
<option>Mobile Money</option>

</select>

<input
type="number"
step="0.01"
min="0"
name="montant_recu"
id="recu"
placeholder="Montant reçu"
class="w-full border rounded-xl p-3 mb-3"
>

<div class="text-2xl font-black">

Total :
<span id="total">0</span>

</div>

<button
class="w-full bg-green-600 text-white p-4 rounded-2xl mt-4 font-bold"
>

✔ Finaliser Vente

</button>

</form>

</div>

</div>

<script>

let cart = [];

function toggleCart(){

    document
    .getElementById('cartPanel')
    .classList
    .toggle('hidden');
}

function addItem(p){

    let found =
        cart.find(i => i.id == p.id);

    if(found){

        found.qty++;

    }else{

        cart.push({

            id:p.id,
            nom:p.nom,
            prix:p.prix_vente,
            qty:1
        });
    }

    render();
}

function removeItem(index){

    cart.splice(index,1);

    render();
}

function render(){

    let html = '';

    let total = 0;

    cart.forEach((i,index)=>{

        let s =
            i.qty * i.prix;

        total += s;

        html += `
        <div class="border-b pb-3 mb-3">

            <div class="font-bold">
                ${i.nom}
            </div>

            <div>
                ${i.qty} x ${i.prix}
            </div>

            <div class="font-black mt-1">
                ${s.toFixed(2)}
            </div>

            <button
            onclick="removeItem(${index})"
            class="text-red-500 text-sm mt-2"
            >
                Supprimer
            </button>

        </div>
        `;
    });

    document
    .getElementById('cart')
    .innerHTML = html;

    document
    .getElementById('total')
    .innerText =
        total.toFixed(2) + ' <?= e($devise) ?>';
}

function submitCart(){

    if(cart.length <= 0){

        alert("Panier vide");

        return false;
    }

    document
    .getElementById('panierField')
    .value =
        JSON.stringify(cart);

    return true;
}

</script>

<?php if(isset($_GET['ticket'])): ?>

<?php

$venteId = (int)$_GET['ticket'];

$stmt = $pdo->prepare("
  SELECT

v.*,

m.nom magasin,

u.nom utilisateur

FROM ventes v

LEFT JOIN magasins m

ON m.id=v.magasin_id

LEFT JOIN utilisateurs u

ON u.id=v.utilisateur_id

WHERE

v.id=?

AND v.magasin_id=?");

$stmt->execute([

$venteId,

$currentMagasinId

]);

$vente = $stmt->fetch();

$stmtLignes = $pdo->prepare("
   SELECT

lv.*,

p.nom,

p.code

FROM ligne_ventes lv

JOIN produits p

ON p.id=lv.produit_id

WHERE vente_id=?

ORDER BY lv.id");

$stmtLignes->execute([$venteId]);

$lignes = $stmtLignes->fetchAll();

?>

<div id="ticketPrint" class="hidden">

<div class="ticket-box">

<center>

<?php if($logo): ?>

<img
src="<?= e($logo) ?>"
style="width:80px;margin-bottom:10px;"
>

<?php endif; ?>

<?= e($nomBoutique) ?>

<br>

<?= e($magasin['nom']) ?>

<br>

<?= e($magasin['ville']) ?>

<br>

<?= e($magasin['telephone']) ?>

<hr>

Ticket :
<?= e($vente['numero_ticket']) ?>

<br>

<?= date('d/m/Y H:i') ?>

<hr>

</center>

<?php foreach($lignes as $l): ?>

<div style="margin-bottom:8px;">

<?= e($l['nom']) ?>

<br>

<?= (int)$l['quantite'] ?>

x

<?= number_format($l['prix_unitaire'],2) ?>

=

<?= number_format($l['sous_total'],2) ?>

</div>

<?php endforeach; ?>

<hr>

TOTAL :
<?= number_format($vente['total'],2) ?>

<?= e($devise) ?>

<br>

TVA :
<?= number_format($vente['tva'],2) ?>

<br>

Monnaie :
<?= number_format($vente['monnaie'],2) ?>

<hr>

<div
    id="qrcode"
    style="margin-top:15px;text-align:center;"
></div>

<div style="margin-top:10px;font-size:11px;text-align:center;">

    Vérification :

    <?= BASE_URL ?>/verify-ticket.php?ticket=<?= urlencode($vente['numero_ticket']) 
    ?>

</div>

</div>

<script>

new QRCode(
    document.getElementById("qrcode"),
    {
        text:
            "<?= BASE_URL ?>/verify-ticket.php?ticket=<?= urlencode($vente['numero_ticket']) ?>",

        width:120,
        height:120
    }
);

window.onload = () => {

    let content =
        document
        .getElementById('ticketPrint')
        .innerHTML;

    let copies = prompt(
        "Nombre de copies à imprimer ?",
        "2"
    );

    copies = parseInt(copies);

    if(isNaN(copies) || copies <= 0){

        copies = 1;
    }

    let w =
        window.open(
            '',
            '',
            'width=400,height=700'
        );

    let html = `
    <html>
    <head>
        <title>Ticket</title>

        <style>

            body{
                font-family:monospace;
                margin:0;
                padding:0;
            }

            .ticket-copy{
                margin-bottom:25px;
                page-break-after:always;
            }

        </style>

    </head>

    <body>
    `;

    for(let i=0; i<copies; i++){

        html += `
        <div class="ticket-copy">
            ${content}
        </div>
        `;
    }

    html += `
    </body>
    </html>
    `;

    w.document.write(html);

    w.document.close();

    w.focus();

    setTimeout(()=>{

        w.print();

    },500);
};

</script>

<?php endif; ?>

<?php include 'includes/footer.php'; ?>
