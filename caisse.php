<?php

declare(strict_types=1);

require_once 'config.php';
require_once 'config-settings.php';

requireLogin();

/* =========================================================
   HEADERS SECURITE
========================================================= */

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

/* =========================================================
   USER
========================================================= */

$user = currentUser();

$isAdmin =
    ($user['role'] ?? '') === 'admin';

$isGlobalAdmin = $isAdmin;

$magasin_id =
    (int)($user['magasin_id'] ?? 0);

if ($magasin_id <= 0) {

    http_response_code(403);

    exit("
    <div style='padding:30px;font-family:Arial'>
        ⛔ Aucun magasin assigné
    </div>
    ");
}

/* =========================================================
   SETTINGS
========================================================= */

$settings = getSettings();

$tvaRate =
    (float)($settings['tva'] ?? 0);

$devise =
    trim($settings['devise'] ?? 'BIF');

$nomBoutique =
    trim($settings['nom_boutique'] ?? 'Boutique');

/* =========================================================
   MAGASIN
========================================================= */

$stmtMagasin = $pdo->prepare("
    SELECT *
    FROM magasins
    WHERE id=?
    LIMIT 1
");

$stmtMagasin->execute([
    $magasin_id
]);

$magasin =
    $stmtMagasin->fetch();

    if($isGlobalAdmin){

    $selectedMagasin =
        (int)($_GET['magasin_id'] ?? 0);

    if($selectedMagasin > 0){

        $magasin_id =
            $selectedMagasin;

        $stmtMagasin = $pdo->prepare("
            SELECT *
            FROM magasins
            WHERE id=?
        ");

        $stmtMagasin->execute([
            $magasin_id
        ]);

        $magasin =
            $stmtMagasin->fetch();
    }
}

if (!$magasin) {

    http_response_code(403);

    exit("
    <div style='padding:30px;font-family:Arial'>
        ⛔ Magasin introuvable
    </div>
    ");
}

/* =========================================================
   SESSION CAISSE
========================================================= */

$stmt = $pdo->prepare("
    SELECT id
FROM sessions_caisse
WHERE utilisateur_id=?
AND magasin_id=?
AND statut='ouverte'
LIMIT 1
");

$stmt->execute([
    (int)$user['id'],
    $magasin_id
]);

$session = $stmt->fetch();

if (!$session) {

    exit("
    <div style='padding:30px;font-family:Arial'>
        🔴 Ouvrez une session de caisse avant de vendre.<br><br>

        <a href='sessions_caisse.php'>
            ➡ Ouvrir Session
        </a>
    </div>
    ");
}

/* =========================================================
   FONCTIONS SECURITE
========================================================= */

function cleanString(?string $value): string
{
    return trim(strip_tags($value ?? ''));
}

function secureFloat($value): float
{
    return round((float)$value, 2);
}

function secureInt($value): int
{
    return (int)$value;
}

/* =========================================================
   VENTE
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['valider'])
) {

    verify_csrf();

    try {

        $allowedModes = [
            'Espèces',
            'Carte',
            'Mobile Money'
        ];

        $mode =
            cleanString(
                $_POST['mode_paiement'] ?? 'Espèces'
            );

        if (!in_array($mode, $allowedModes, true)) {

            throw new Exception(
                "Mode de paiement invalide"
            );
        }

        $montantRecu =
            secureFloat(
                $_POST['montant_recu'] ?? 0
            );

        if ($montantRecu < 0) {

            throw new Exception(
                "Montant reçu invalide"
            );
        }

        $panierJson =
            $_POST['panier'] ?? '[]';

        if (
            strlen($panierJson)
            > 100000
        ) {

            throw new Exception(
                "Panier trop volumineux"
            );
        }

        $items =
            json_decode(
                $panierJson,
                true,
                512,
                JSON_THROW_ON_ERROR
            );

        if (
            !is_array($items)
            ||
            empty($items)
        ) {

            throw new Exception(
                "Panier vide"
            );
        }

        if (count($items) > 200) {

            throw new Exception(
                "Trop de produits"
            );
        }

        $pdo->beginTransaction();

        $totalHT = 0;

        $validatedItems = [];

        foreach ($items as $it) {

            $produit_id =
                secureInt($it['id'] ?? 0);

            $qty =
                secureInt($it['qty'] ?? 0);

            if (
                $produit_id <= 0
                ||
                $qty <= 0
            ) {

                throw new Exception(
                    "Produit invalide"
                );
            }

            if ($qty > 10000) {

                throw new Exception(
                    "Quantité trop élevée"
                );
            }

            $q = $pdo->prepare("
                SELECT
                    id,
                    nom,
                    prix_vente,
                    quantite,
                    magasin_id
                FROM produits
                WHERE id=?
                AND magasin_id=?
                FOR UPDATE
            ");

            $q->execute([

                $produit_id,

                $magasin_id
            ]);

            $p = $q->fetch();

            if (!$p) {

                throw new Exception(
                    "Produit introuvable"
                );
            }

            $stock =
                secureInt($p['quantite']);

            if ($stock < $qty) {

                throw new Exception(
                    "Stock insuffisant : "
                    . e($p['nom'])
                );
            }

            $prix =
                secureFloat(
                    $p['prix_vente']
                );

            $sousTotal =
                secureFloat(
                    $prix * $qty
                );

            $totalHT +=
                $sousTotal;

            $validatedItems[] = [

                'id' => $produit_id,

                'qty' => $qty,

                'nom' => $p['nom'],

                'prix_vente' => $prix,

                'ancien_stock' => $stock,

                'nouveau_stock' =>
                    $stock - $qty,

                'sous_total' => $sousTotal
            ];
        }

        $tva =
            secureFloat(
                $totalHT *
                ($tvaRate / 100)
            );

        $totalTTC =
            secureFloat(
                $totalHT + $tva
            );

        if (
            $mode === 'Espèces'
            &&
            $montantRecu < $totalTTC
        ) {

            throw new Exception(
                "Montant insuffisant"
            );
        }

        $monnaie =
            secureFloat(
                max(
                    0,
                    $montantRecu - $totalTTC
                )
            );

        $stmt = $pdo->prepare("
            INSERT INTO ventes
            (
                utilisateur_id,
                magasin_id,
                session_caisse_id,
                total,
                montant_recu,
                monnaie,
                mode_paiement,
                date_vente,
                tva
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                NOW(),
                ?
            )
        ");
        mail(
    "mcshimel4@gmail.com",
    "Nouvelle vente",
    "Une nouvelle vente vient d'être enregistrée"
);

        $stmt->execute([

            secureInt($user['id']),

            $magasin_id,
            $session['id'],

            $totalTTC,

            $montantRecu,

            $monnaie,

            $mode,

            $tva
        ]);

        $venteId =
            secureInt(
                $pdo->lastInsertId()
            );

        foreach ($validatedItems as $item) {

            $updateStock =
                $pdo->prepare("
                    UPDATE produits
                    SET quantite=?
                    WHERE id=?
                    AND magasin_id=?
                ");

            $updateStock->execute([

                $item['nouveau_stock'],

                $item['id'],

                $magasin_id
            ]);

            $ligne =
                $pdo->prepare("
                    INSERT INTO ligne_ventes
                    (
                        vente_id,
                        produit_id,
                        quantite,
                        prix_unitaire,
                        sous_total
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?,
                        ?
                    )
                ");

            $ligne->execute([

                $venteId,

                $item['id'],

                $item['qty'],

                $item['prix_vente'],

                $item['sous_total']
            ]);

            $stockHistory =
                $pdo->prepare("
                    INSERT INTO stock_mouvements
                    (
                        magasin_id,
                        produit_id,
                        type,
                        quantite,
                        ancien_stock,
                        nouveau_stock,
                        motif,
                        utilisateur_id,
                        date_mouvement
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        'sortie',
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        NOW()
                    )
                ");

            $stockHistory->execute([

                $magasin_id,

                $item['id'],

                $item['qty'],

                $item['ancien_stock'],

                $item['nouveau_stock'],

                'Vente caisse',

                secureInt($user['id'])
            ]);

            if (
                $item['nouveau_stock']
                <= 5
            ) {

                $alert =
                    $pdo->prepare("
                        INSERT INTO alertes_stock
                        (
                            produit_id,
                            message,
                            created_at
                        )
                        VALUES
                        (
                            ?,
                            ?,
                            NOW()
                        )
                    ");

                $alert->execute([

                    $item['id'],

                    'Stock faible : '
                    .$item['nom']
                ]);
            }
        }

        $historique =
            $pdo->prepare("
                INSERT INTO historiques
                (
                    utilisateur_id,
                    magasin_id,
                    action,
                    details,
                    ip,
                    created_at,
                    niveau
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    NOW(),
                    ?
                )
            ");

        $historique->execute([

            secureInt($user['id']),

            $magasin_id,

            'VENTE',

            'Nouvelle vente #'
            .$venteId
            .' | Total : '
            .$totalTTC
            .' '
            .$devise,

            $_SERVER['REMOTE_ADDR']
            ?? 'UNKNOWN',

            'SUCCESS'
        ]);

        if (function_exists('logAction')) {

            logAction(

                "VENTE",

                "Nouvelle vente #".$venteId,

                "SUCCESS"
            );
        }

        $pdo->commit();

        session_regenerate_id(true);

        header(
            "Location: ticket_pdf.php?id="
            .$venteId
        );

        exit;

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {

            $pdo->rollBack();
        }

        flash(
            'error',
            $e->getMessage()
        );

        header("Location: caisse.php");

        exit;
    }
}

/* =========================================================
   kpi
========================================================= */

$stmtStats =
$pdo->prepare("
SELECT

COUNT(*) nb_ventes,

COALESCE(SUM(total),0) ca

FROM ventes

WHERE magasin_id=?
AND DATE(date_vente)=CURDATE()
");

$stmtStats->execute([
    $magasin_id
]);

$stats =
$stmtStats->fetch();

/* =========================================================
   AJAX PRODUITS
========================================================= */

if (
    isset($_GET['ajax'])
    &&
    $_GET['ajax'] === 'products'
) {

    header('Content-Type: application/json');

    $page =
        max(
            1,
            (int)($_GET['page'] ?? 1)
        );

    $limit = 40;

    $offset =
        ($page - 1) * $limit;

    $stmt = $pdo->prepare("
        SELECT
            id,
            nom,
            prix_vente,
            quantite,
            codebarre,
            photos
        FROM produits
        WHERE quantite > 0
        AND magasin_id=?
        ORDER BY nom ASC
        LIMIT ?
        OFFSET ?
    ");

    $stmt->bindValue(
        1,
        $magasin_id,
        PDO::PARAM_INT
    );

    $stmt->bindValue(
        2,
        $limit,
        PDO::PARAM_INT
    );

    $stmt->bindValue(
        3,
        $offset,
        PDO::PARAM_INT
    );

    $stmt->execute();

    echo json_encode(
        $stmt->fetchAll()
    );

    exit;
}

/* =========================================================
   STATISTIQUES DU JOUR
========================================================= */

$statsJour = $pdo->prepare("
    SELECT

        COUNT(*) AS nb_ventes,

        COALESCE(SUM(total),0) AS montant_jour,

        COALESCE(AVG(total),0) AS vente_moyenne

    FROM ventes

    WHERE magasin_id=?
    AND DATE(date_vente)=CURDATE()
");

$statsJour->execute([
    $magasin_id
]);

$statsJourData = $statsJour->fetch();

$nbVentesJour =
    (int)($statsJourData['nb_ventes'] ?? 0);

$montantJour =
    (float)($statsJourData['montant_jour'] ?? 0);

$venteMoyenne =
    (float)($statsJourData['vente_moyenne'] ?? 0);


/* =========================================================
   PRODUITS VENDUS DU JOUR
========================================================= */

$produitsVendus = $pdo->prepare("
    SELECT

        COALESCE(
            SUM(lv.quantite),
            0
        ) AS total_produits

    FROM ligne_ventes lv

    INNER JOIN ventes v
        ON v.id = lv.vente_id

    WHERE v.magasin_id=?
    AND DATE(v.date_vente)=CURDATE()
");

$produitsVendus->execute([
    $magasin_id
]);

$totalProduitsVendus =
    (int)$produitsVendus->fetchColumn();


/* =========================================================
   FORMATAGE POUR AFFICHAGE
========================================================= */

$nbVentesJourAffiche =
    number_format($nbVentesJour);

$montantJourAffiche =
    number_format(
        $montantJour,
        2
    );

$totalProduitsVendusAffiche =
    number_format(
        $totalProduitsVendus
    );

$venteMoyenneAffiche =
    number_format(
        $venteMoyenne,
        2
    );



/* ==========================================
   SESSION OUVERTE
========================================== */

$sessionStats = $pdo->prepare("
    SELECT *
    FROM sessions_caisse
    WHERE utilisateur_id=?
    AND magasin_id=?
    AND statut='ouverte'
    LIMIT 1
");

$sessionStats->execute([
    $user['id'],
    $magasin_id
]);

$sessionActive = $sessionStats->fetch();

/* ==========================================
   VALEURS PAR DEFAUT
========================================== */

$totalVentes = 0;
$chiffreAffairesSession = 0;
$totalEntrees = 0;
$totalSorties = 0;
$soldeActuel = 0;

if($sessionActive){

    /* ==============================
       TOTAL VENTES
    ============================== */

    $ventes = $pdo->prepare("
        SELECT
            COALESCE(SUM(total),0) AS chiffre_affaires,
            COALESCE(SUM(
                CASE
                    WHEN mode_paiement='Espèces'
                    THEN montant_recu - monnaie
                    ELSE 0
                END
            ),0) AS especes
        FROM ventes
        WHERE session_caisse_id=?
        AND magasin_id=?
    ");

    $ventes->execute([
        $sessionActive['id'],
        $magasin_id
    ]);

    $venteTotals = $ventes->fetch();

    $chiffreAffairesSession = (float)$venteTotals['chiffre_affaires'];
    $totalVentes = (float)$venteTotals['especes'];

    /* ==============================
       ENTREES
    ============================== */

    $entrees = $pdo->prepare("
        SELECT
            COALESCE(
                SUM(montant),
                0
            )
        FROM transactions_financieres
        WHERE session_caisse_id=?
        AND type='recette'
    ");

    $entrees->execute([
        $sessionActive['id']
    ]);

    $totalEntrees =
        (float)$entrees->fetchColumn();

    /* ==============================
       SORTIES
    ============================== */

    $sorties = $pdo->prepare("
        SELECT
            COALESCE(
                SUM(montant),
                0
            )
        FROM transactions_financieres
        WHERE session_caisse_id=?
        AND type='depense'
    ");

    $sorties->execute([
        $sessionActive['id']
    ]);

    $totalSorties =
        (float)$sorties->fetchColumn();

    /* ==============================
       SOLDE ACTUEL
    ============================== */

    $soldeActuel =
        (float)$sessionActive['solde_depart']
        +
        $totalVentes
        +
        $totalEntrees
        -
        $totalSorties;

    /* ==============================
       MAJ SESSION
    ============================== */

    $updateSession = $pdo->prepare("
        UPDATE sessions_caisse
        SET
            total_ventes=?,
            montant_attendu=?
        WHERE id=?
    ");

    $updateSession->execute([

        $chiffreAffairesSession,

        $soldeActuel,

        $sessionActive['id']
    ]);
}



include 'includes/header.php';
include 'includes/sidebar.php';
?>

<link rel="stylesheet" href="assets/tailwind.css">

<script>

const TVA_RATE =
    <?= json_encode($tvaRate) ?>;

const DEVISE =
    <?= json_encode($devise) ?>;

</script>

<style>

body{
    background:#f3f4f6;
}

.shopify-card{
    background:white;
    border-radius:24px;
    box-shadow:
    0 10px 30px rgba(0,0,0,.06);
}

.product-card{
    background:white;
    border-radius:22px;
    padding:12px;
    transition:.2s;
    border:1px solid #e5e7eb;
}

.product-card:hover{
    transform:translateY(-3px);
    box-shadow:
    0 10px 25px rgba(0,0,0,.08);
}

.product-price{
    color:#16a34a;
    font-weight:bold;
}

.product-image{
    width:100%;
    height:150px;
    object-fit:cover;
    border-radius:16px;
    background:#f3f4f6;
}

.product-image-placeholder{
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:2.5rem;
}

.cart-item{
    background:#f9fafb;
    border-radius:16px;
    padding:12px;
    margin-bottom:10px;
}

.pos-btn{
    border-radius:18px;
    transition:.2s;
}

.pos-btn:hover{
    transform:scale(1.02);
}

/* =========================================
   PANIER FLOTTANT
========================================= */

#cartPanel{

    transition:.3s;
}

@media(max-width:768px){

    #cartPanel{

        width:95%;
        right:2.5%;
        top:90px;
    }
}

</style>

<div class="p-4 md:p-6">

<div class="flex flex-col lg:flex-row justify-between gap-4 mb-6">

    <div>

        <h1 class="text-4xl font-black text-slate-800">
        
        </h1>

        <div class="mt-2 text-slate-500">
            <h1><?= e($nomBoutique) ?></h1>
        </div>

        <div class="mt-2 text-blue-600 font-bold">
            🏬 <?= e($magasin['nom']) ?>
        </div>

    </div>

    <div class="shopify-card p-5">

        <div class="text-sm text-gray-500">
            Session caisse
        </div>

        <div class="text-green-600 font-bold text-xl">
            🟢 Ouverte
        </div>

    </div>
<!-- =========================================
     STATISTIQUES DU JOUR
========================================= -->

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">

    <!-- VENTES -->

    <div class="bg-white rounded-3xl shadow-lg p-6 border">

        <div class="text-gray-500 text-sm">
            Ventes du jour
        </div>

        <div class="mt-3 text-4xl font-black text-blue-600">

            🧾 <?= number_format($nbVentesJour) ?>

        </div>

    </div>

    <!-- CHIFFRE D'AFFAIRES -->

    <div class="bg-white rounded-3xl shadow-lg p-6 border">

        <div class="text-gray-500 text-sm">
            Entrées du jour
        </div>

        <div class="mt-3 text-3xl font-black text-green-600">

            💰 <?= number_format($montantJour,2) ?>
            <?= e($devise) ?>

        </div>

    </div>

    <!-- PRODUITS VENDUS -->

    <div class="bg-white rounded-3xl shadow-lg p-6 border">

        <div class="text-gray-500 text-sm">
            Produits vendus
        </div>

        <div class="mt-3 text-4xl font-black text-orange-500">

            📦 <?= number_format((int)$totalProduitsVendus) ?>

        </div>

    </div>

    <!-- VENTE MOYENNE -->

    <div class="bg-white rounded-3xl shadow-lg p-6 border">

        <div class="text-gray-500 text-sm">
            Vente moyenne
        </div>

        <div class="mt-3 text-3xl font-black text-purple-600">

            📈 <?= number_format($venteMoyenne,2) ?>
            <?= e($devise) ?>

        </div>

    </div>

</div>

<div class="grid md:grid-cols-5 gap-4 mb-6">


<!-- SOLDE DEPART -->

<div class="bg-blue-50 border border-blue-200 rounded-3xl p-5 shadow">

    <div class="text-gray-500 text-sm">
        💼 Solde départ
    </div>

    <div class="text-2xl font-black text-blue-700 mt-2">

        <?= number_format(
            (float)($sessionActive['solde_depart'] ?? 0),
            0,
            ',',
            ' '
        ) ?>

        <?= e($devise) ?>

    </div>

</div>

<!-- ENTREES -->

<div class="bg-cyan-50 border border-cyan-200 rounded-3xl p-5 shadow">

    <div class="text-gray-500 text-sm">
        ⬇ Entrées
    </div>

    <div class="text-2xl font-black text-cyan-700 mt-2">

        <?= number_format(
            $totalEntrees,
            0,
            ',',
            ' '
        ) ?>

        <?= e($devise) ?>

    </div>

</div>

<!-- SORTIES -->

<div class="bg-red-50 border border-red-200 rounded-3xl p-5 shadow">

    <div class="text-gray-500 text-sm">
        ⬆ Sorties
    </div>

    <div class="text-2xl font-black text-red-700 mt-2">

        <?= number_format(
            $totalSorties,
            0,
            ',',
            ' '
        ) ?>

        <?= e($devise) ?>

    </div>

</div>

<!-- SOLDE ACTUEL -->

<div class="bg-yellow-50 border-2 border-yellow-500 rounded-3xl p-5 shadow-lg">

    <div class="text-gray-500 text-sm">
        💰 Solde actuel caisse
    </div>

    <div class="text-3xl font-black text-yellow-700 mt-2">

        <?= number_format(
            $soldeActuel,
            0,
            ',',
            ' '
        ) ?>

        <?= e($devise) ?>

    </div>

</div>

</div>

    <?php if($isGlobalAdmin): ?>

<form method="GET">

<select
name="magasin_id"
onchange="this.form.submit()"
class="border rounded-xl p-3">

<?php

$allMagasins =
$pdo->query("
SELECT *
FROM magasins
ORDER BY nom
")->fetchAll();

foreach($allMagasins as $m):

?>

<option
value="<?= $m['id'] ?>"
<?= $magasin_id == $m['id']
? 'selected'
: '' ?>>

<?= e($m['nom']) ?>

</option>

<?php endforeach; ?>

</select>

</form>

<?php endif; ?>

</div>

<?php if($m = flash('error')): ?>

<div class="bg-red-100 text-red-700 p-4 rounded-2xl mb-4">

    <?= e($m) ?>
    <?= $stats['nb_ventes'] ?>
    <?= number_format((float)$stats['ca'],
2
) ?>

</div>

<?php endif; ?>

<!-- =========================================
     BOUTON PANIER FLOTTANT
========================================= -->

<button
    id="cartToggle"
    onclick="toggleCart()"
    class="fixed top-5 right-5 z-50 bg-black text-white w-16 h-16 rounded-full shadow-2xl flex items-center justify-center text-2xl hover:scale-105 transition"
>

    🛒

    <span
        id="cartCount"
        class="absolute -top-2 -right-2 bg-red-500 text-white text-xs w-6 h-6 rounded-full flex items-center justify-center font-bold hidden"
    >

        0

    </span>

</button>

<div class="grid xl:grid-cols-1 gap-6">

<!-- PRODUITS -->

<div class="shopify-card p-5">

    <div class="grid md:grid-cols-2 gap-4 mb-5">

        <input
            id="search"
            class="border p-4 rounded-2xl"
            placeholder="🔎 Rechercher produit..."
            maxlength="100"
        >

        <video
            id="preview"
            class="w-full rounded-2xl hidden"
        ></video>

    </div>

    <button
        type="button"
        onclick="startScanner()"
        class="bg-black text-white px-5 py-3 rounded-2xl mb-5 pos-btn"
    >

        📷 Scanner Code Barre

    </button>
<div
    id="productList"
    class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4"
>
</div>

</div>

</div>

<!-- =========================================
     PANIER FLOTTANT
========================================= -->

<div
    id="cartPanel"
    class="fixed top-24 right-5 w-[380px] max-w-[95%] bg-white rounded-3xl shadow-2xl border z-40 hidden flex flex-col max-h-[85vh]"
>

<div class="flex items-center justify-between p-5 border-b">

    <h2 class="font-black text-2xl">

        🛒 Panier

    </h2>

    <button
        onclick="toggleCart()"
        class="text-gray-500 hover:text-red-500 text-xl"
    >

        ✖

    </button>

</div>

<div
    id="cart"
    class="flex-1 overflow-y-auto p-5"
></div>

<div class="border-t pt-4 mt-4 p-5">

    <div class="space-y-2 text-lg">

        <div class="flex justify-between">

            <span>HT</span>

            <span id="ht">0</span>

        </div>

        <div class="flex justify-between">

            <span>
                TVA <?= e((string)$tvaRate) ?>%
            </span>

            <span id="tva">0</span>

        </div>

        <div class="flex justify-between text-2xl font-black">

            <span>Total</span>

            <span id="total">
                0
            </span>

        </div>

    </div>

    <form
        method="POST"
        onsubmit="return submitCart()"
        class="mt-5"
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
            class="w-full p-4 rounded-2xl border mb-3"
        >

            <option>
                Espèces
            </option>

            <option>
                Carte
            </option>

            <option>
                Mobile Money
            </option>

        </select>

        <input
            id="recu"
            name="montant_recu"
            class="w-full p-4 rounded-2xl border"
            placeholder="Montant reçu"
            type="number"
            min="0"
            step="0.01"
        >

        <div class="mt-4 text-xl font-bold">

            💰 Monnaie :
            <span id="change">0</span>

        </div>

        <button
            class="w-full bg-green-600 text-white p-4 mt-5 rounded-2xl font-bold text-lg pos-btn"
        >

            ✔ Finaliser Vente

        </button>

    </form>

</div>

</div>

<script src="assets/vendor/zxing.min.js"></script>

<script>

let cart = [];

let produits = [];
let currentPage = 1;
let loadingProducts = false;

function productImage(product){

    let photos = [];

    try {
        photos = Array.isArray(product.photos)
            ? product.photos
            : JSON.parse(product.photos || '[]');
    } catch (error) {
        photos = [];
    }

    return Array.isArray(photos) && photos.length > 0
        ? photos[0]
        : '';
}

/*Ajouter le Lazy Loading*/
async function loadProducts(){

    if(loadingProducts){
        return;
    }

    loadingProducts = true;

    let r =
        await fetch(
            '?ajax=products&page='
            + currentPage
        );

    let data =
        await r.json();

    produits.push(...data);

    let html = '';

    data.forEach(p=>{

        const image = productImage(p);

        html += `
        <button
            type="button"
            onclick='addItem(${JSON.stringify(p)})'
            data-name="${p.nom.toLowerCase()}"
            class="product-card text-left"
        >

            ${image
                ? `<img src="${escapeHtml(image)}" alt="${escapeHtml(p.nom)}" class="product-image" loading="lazy" decoding="async">`
                : `<div class="product-image product-image-placeholder">📦</div>`}

            <div class="mt-3 text-lg font-bold text-slate-800">

                ${p.nom}

            </div>

            <div class="mt-2 product-price">

                ${parseFloat(p.prix_vente).toFixed(2)}
                ${DEVISE}

            </div>

            <div class="mt-2 text-sm text-gray-500">

                Stock : ${p.quantite}

            </div>

        </button>
        `;
    });

    document
        .getElementById('productList')
        .insertAdjacentHTML(
            'beforeend',
            html
        );

    currentPage++;

    loadingProducts = false;
}
/* =========================================
   PANIER FLOTTANT
========================================= */

function toggleCart(){

    const panel =
        document.getElementById('cartPanel');

    panel.classList.toggle('hidden');
}

function openCart(){

    document
        .getElementById('cartPanel')
        .classList
        .remove('hidden');
}

function updateCartBadge(){

    let total = 0;

    cart.forEach(i => {

        total += i.qty;
    });

    const badge =
        document.getElementById('cartCount');

    badge.innerText = total;

    if(total > 0){

        badge.classList.remove('hidden');

    }else{

        badge.classList.add('hidden');
    }
}

/* =========================================================
   BEEP
========================================================= */

function beep(){

    new Audio(
        "https://actions.google.com/sounds/v1/alarms/beep_short.ogg"
    ).play();
}

/* =========================================================
   AJOUT PRODUIT
========================================================= */

function addItem(p){

    if(!p || !p.id){
        return;
    }

    let found =
        cart.find(
            i => i.id == p.id
        );

    if(found){

        if(found.qty >= p.quantite){

            alert("Stock maximum atteint");

            return;
        }

        found.qty++;

    }else{

        cart.push({

            id:p.id,
            nom:p.nom,
            prix_vente:parseFloat(p.prix_vente),
            quantite:parseInt(p.quantite),
            qty:1,
            codebarre:p.codebarre,
            image:productImage(p)
        });
    }

    beep();

    openCart();

    updateCartBadge();

    render();
}

/* =========================================================
   AUGMENTER
========================================================= */

function increaseQty(index){

    if(
        cart[index].qty
        >=
        cart[index].quantite
    ){

        alert("Stock insuffisant");

        return;
    }

    cart[index].qty++;

    render();
}

/* =========================================================
   DIMINUER
========================================================= */

function decreaseQty(index){

    if(cart[index].qty > 1){

        cart[index].qty--;

    }else{

        cart.splice(index,1);
    }

    render();
}

/* =========================================================
   REMOVE
========================================================= */

function removeItem(index){

    cart.splice(index,1);

    updateCartBadge();

    render();
}

/* =========================================================
   SCANNER
========================================================= */

function startScanner(){

    document
        .getElementById('preview')
        .classList
        .remove('hidden');

    const codeReader =
        new ZXing.BrowserBarcodeReader();

    codeReader.decodeFromVideoDevice(

        null,

        'preview',

        (result)=>{

            if(result){

                let p =
                    produits.find(

                        x =>
                        x.codebarre
                        ==
                        result.text
                    );

                if(p){

                    addItem(p);
                }
            }
        }
    );
}

/* =========================================================
   MONNAIE
========================================================= */

function calc(total){

    let r =
        parseFloat(
            document
            .getElementById('recu')
            .value
        ) || 0;

    document
        .getElementById('change')
        .innerText =

        Math.max(
            0,
            r - total
        ).toFixed(2)

        + " "

        + DEVISE;
}

/* =========================================================
   RENDER
========================================================= */

function render(){

    let html = '';

    let ht = 0;

    cart.forEach((i,index)=>{

        let s =
            i.qty *
            i.prix_vente;

        ht += s;

        html += `
        <div class="cart-item">

            <div class="flex justify-between gap-3">

                <div class="w-16 h-16 flex-shrink-0">

                    ${i.image
                        ? `<img src="${escapeHtml(i.image)}" alt="${escapeHtml(i.nom)}" class="w-16 h-16 object-cover rounded-xl" loading="lazy">`
                        : `<div class="w-16 h-16 rounded-xl bg-gray-200 flex items-center justify-center text-2xl">📦</div>`}

                </div>

                <div class="flex-1">

                    <div class="font-bold text-lg">

                        ${escapeHtml(i.nom)}

                    </div>

                    <div class="text-sm text-gray-500 mt-1">

                        ${i.prix_vente}
                        ${DEVISE}
                        / unité

                    </div>

                    <div class="flex items-center gap-2 mt-3">

                        <button
                            type="button"
                            onclick="decreaseQty(${index})"
                            class="w-9 h-9 rounded-xl bg-red-500 text-white font-bold text-lg"
                        >

                            -

                        </button>

                        <div class="px-4 py-2 bg-gray-100 rounded-xl font-bold">

                            ${i.qty}

                        </div>

                        <button
                            type="button"
                            onclick="increaseQty(${index})"
                            class="w-9 h-9 rounded-xl bg-green-600 text-white font-bold text-lg"
                        >

                            +

                        </button>

                    </div>

                </div>

                <div class="text-right">

                    <div class="font-black text-lg">

                        ${s.toFixed(2)}
                        ${DEVISE}

                    </div>

                    <button
                        type="button"
                        onclick="removeItem(${index})"
                        class="text-red-500 text-sm mt-2"
                    >

                        🗑 Supprimer

                    </button>

                </div>

            </div>

        </div>
        `;
    });

    let tva =
        ht * (TVA_RATE / 100);

    let total =
        ht + tva;

    document
        .getElementById('cart')
        .innerHTML = html;

    document
        .getElementById('ht')
        .innerText =
        ht.toFixed(2)
        + " "
        + DEVISE;

    document
        .getElementById('tva')
        .innerText =
        tva.toFixed(2)
        + " "
        + DEVISE;

    document
        .getElementById('total')
        .innerText =
        total.toFixed(2)
        + " "
        + DEVISE;

    calc(total);

    updateCartBadge();
}

/* =========================================================
   ESCAPE HTML
========================================================= */

function escapeHtml(text){

    const div =
        document.createElement('div');

    div.innerText = text;

    return div.innerHTML;
}

/* =========================================================
   SUBMIT
========================================================= */

function submitCart(){

    if(cart.length === 0){

        alert("Panier vide");

        return false;
    }

    document
        .getElementById('panierField')
        .value =
        JSON.stringify(cart);

    return true;
}

/* =========================================================
   SEARCH
========================================================= */

document
.getElementById('search')
.oninput = function(){

    let v =
        this.value.toLowerCase();

    document
        .querySelectorAll(
            '#productList button'
        )

        .forEach(b=>{

            b.style.display =

                b.dataset.name
                .includes(v)

                ? 'block'

                : 'none';
        });
};

document
.getElementById('recu')

.addEventListener(
    'input',
    ()=>{

        let total =
            parseFloat(

                document
                .getElementById('total')
                .innerText

            ) || 0;

        calc(total);
    }
);
window.onload = () => {

    let content =
        document
        .getElementById('ticketPrint')
        .innerHTML;

    let copies = prompt(
        "Nombre de copies à imprimer ?",
        "1"
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

loadProducts();

window.addEventListener(
    'scroll',
    ()=>{

        if(

            window.innerHeight +
            window.scrollY

            >=

            document.body.offsetHeight
            - 500

        ){

            loadProducts();
        }
    }
);

</script>

<?php include 'includes/footer.php'; ?>