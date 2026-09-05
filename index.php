<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/icons.php';

$errors = [];
$success = null;
$old = ['nom' => '', 'email' => ''];

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['client_cart']) || !is_array($_SESSION['client_cart'])) {
    $_SESSION['client_cart'] = [];
}

$clientUser = currentUser();
$isClientLoggedIn = ($clientUser['role'] ?? '') === 'client';
if ($isClientLoggedIn) {
    $old = ['nom' => $clientUser['nom'], 'email' => $clientUser['email']];
}

try {
    $roleColumn = $pdo->query("SHOW COLUMNS FROM utilisateurs LIKE 'role'")->fetch();
    if ($roleColumn && strpos((string)$roleColumn['Type'], "'client'") === false) {
        $pdo->exec("ALTER TABLE utilisateurs MODIFY role enum('admin','caissier','client') NOT NULL DEFAULT 'caissier'");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS commandes_clients (
        id int NOT NULL AUTO_INCREMENT PRIMARY KEY,
        numero varchar(40) NOT NULL UNIQUE,
        utilisateur_id int NOT NULL,
        magasin_id int NOT NULL,
        total decimal(12,2) NOT NULL DEFAULT 0.00,
        statut varchar(30) NOT NULL DEFAULT 'En attente',
        date_commande timestamp NOT NULL DEFAULT current_timestamp(),
        KEY idx_client_orders_user (utilisateur_id),
        KEY idx_client_orders_store (magasin_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS lignes_commandes_clients (
        id int NOT NULL AUTO_INCREMENT PRIMARY KEY,
        commande_id int NOT NULL,
        produit_id int NOT NULL,
        nom_produit varchar(150) NOT NULL,
        quantite int NOT NULL,
        prix_unitaire decimal(10,2) NOT NULL,
        sous_total decimal(12,2) NOT NULL,
        KEY idx_client_order_lines_order (commande_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $exception) {
    $errors[] = 'Le catalogue client ne peut pas initialiser sa structure de commandes.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    verify_csrf();

    $old['nom'] = $isClientLoggedIn ? $clientUser['nom'] : trim((string)($_POST['nom'] ?? ''));
    $old['email'] = $isClientLoggedIn ? $clientUser['email'] : trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $magasinId = (int)($_POST['magasin_id'] ?? 0);
    $postedCart = json_decode((string)($_POST['cart'] ?? '{}'), true);
    if (is_array($postedCart)) {
        $_SESSION['client_cart'] = [];
        foreach ($postedCart as $productId => $quantity) {
            if ((int)$productId > 0 && (int)$quantity > 0) {
                $_SESSION['client_cart'][(int)$productId] = min((int)$quantity, 999);
            }
        }
    }
    $cart = $_SESSION['client_cart'];

    if ($old['nom'] === '' || strlen($old['nom']) < 2) {
        $errors[] = 'Veuillez saisir votre nom complet.';
    }
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Veuillez saisir une adresse email valide.';
    }
    if (!$isClientLoggedIn && strlen($password) < 6) {
        $errors[] = 'Le mot de passe doit contenir au moins 6 caractères.';
    }
    if (!$cart) {
        $errors[] = 'Votre panier est vide.';
    }

    $storeStmt = $pdo->prepare("SELECT id, nom, adresse, ville FROM magasins WHERE id=? AND statut='actif' LIMIT 1");
    $storeStmt->execute([$magasinId]);
    $store = $storeStmt->fetch();
    if (!$store) {
        $errors[] = 'Veuillez choisir un magasin de retrait valide.';
    }

    if (!$errors && !$isClientLoggedIn) {
        $existing = $pdo->prepare('SELECT id FROM utilisateurs WHERE email=? LIMIT 1');
        $existing->execute([$old['email']]);
        if ($existing->fetch()) {
            $errors[] = 'Cette adresse email possède déjà un compte. Utilisez une autre adresse.';
        }
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $productIds = array_map('intval', array_keys($cart));
            $marks = implode(',', array_fill(0, count($productIds), '?'));
            $productStmt = $pdo->prepare("SELECT id, nom, prix_vente, quantite FROM produits WHERE id IN ($marks) AND magasin_id=? FOR UPDATE");
            $productStmt->execute(array_merge($productIds, [$magasinId]));
            $products = [];
            foreach ($productStmt->fetchAll() as $product) {
                $products[(int)$product['id']] = $product;
            }

            $total = 0.0;
            $lines = [];
            foreach ($cart as $productId => $quantity) {
                $productId = (int)$productId;
                $quantity = max(0, (int)$quantity);
                if (!$quantity || !isset($products[$productId])) {
                    throw new RuntimeException('Un produit du panier n’est pas disponible dans ce magasin.');
                }
                if ($quantity > (int)$products[$productId]['quantite']) {
                    throw new RuntimeException('Le stock de « ' . $products[$productId]['nom'] . ' » est insuffisant.');
                }
                $price = (float)$products[$productId]['prix_vente'];
                $subtotal = $price * $quantity;
                $total += $subtotal;
                $lines[] = [$productId, $products[$productId]['nom'], $quantity, $price, $subtotal];
            }

            if ($isClientLoggedIn) {
                $userId = (int)$clientUser['id'];
            } else {
                $userStmt = $pdo->prepare("INSERT INTO utilisateurs (nom, email, mot_de_passe, role, magasin_id, statut) VALUES (?, ?, ?, 'client', ?, 'actif')");
                $userStmt->execute([$old['nom'], $old['email'], password_hash($password, PASSWORD_DEFAULT), $magasinId]);
                $userId = (int)$pdo->lastInsertId();
            }
            $number = 'WEB-' . date('YmdHis') . '-' . random_int(1000, 9999);

            $orderStmt = $pdo->prepare("INSERT INTO commandes_clients (numero, utilisateur_id, magasin_id, total, statut) VALUES (?, ?, ?, ?, 'En attente')");
            $orderStmt->execute([$number, $userId, $magasinId, $total]);
            $orderId = (int)$pdo->lastInsertId();

            $lineStmt = $pdo->prepare('INSERT INTO lignes_commandes_clients (commande_id, produit_id, nom_produit, quantite, prix_unitaire, sous_total) VALUES (?, ?, ?, ?, ?, ?)');
            $stockStmt = $pdo->prepare('UPDATE produits SET quantite=quantite-? WHERE id=? AND magasin_id=?');
            foreach ($lines as [$productId, $name, $quantity, $price, $subtotal]) {
                $lineStmt->execute([$orderId, $productId, $name, $quantity, $price, $subtotal]);
                $stockStmt->execute([$quantity, $productId, $magasinId]);
            }

            $pdo->commit();
            $_SESSION['client_cart'] = [];
            $success = ['number' => $number, 'total' => $total, 'store' => $store['nom']];
            $old = ['nom' => '', 'email' => ''];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $exception->getMessage();
        }
    }
}

$categoryId = (int)($_GET['categorie'] ?? 0);
$search = trim((string)($_GET['q'] ?? ''));
$categories = $pdo->query("SELECT c.id, c.nom, COUNT(p.id) AS produits_count FROM categories c JOIN produits p ON p.categorie_id=c.id AND p.magasin_id=c.magasin_id AND p.quantite>0 JOIN magasins m ON m.id=c.magasin_id AND m.statut='actif' GROUP BY c.id, c.nom ORDER BY c.nom ASC")->fetchAll();

$productSql = "SELECT p.id, p.nom, p.prix_vente, p.quantite, p.photos, c.nom AS categorie, m.nom AS magasin FROM produits p LEFT JOIN categories c ON c.id=p.categorie_id AND c.magasin_id=p.magasin_id JOIN magasins m ON m.id=p.magasin_id AND m.statut='actif' WHERE p.quantite>0";
$productParams = [];
if ($categoryId > 0) {
    $productSql .= ' AND p.categorie_id=?';
    $productParams[] = $categoryId;
}
if ($search !== '') {
    $productSql .= ' AND (p.nom LIKE ? OR c.nom LIKE ?)';
    $productParams[] = '%' . $search . '%';
    $productParams[] = '%' . $search . '%';
}
$productSql .= " ORDER BY (SELECT COALESCE(SUM(lv.quantite),0) FROM ligne_ventes lv JOIN ventes v ON v.id=lv.vente_id WHERE lv.produit_id=p.id) DESC, p.nom ASC LIMIT 24";
$productStmt = $pdo->prepare($productSql);
$productStmt->execute($productParams);
$products = $productStmt->fetchAll();
$stores = $pdo->query("SELECT id, nom, adresse, ville FROM magasins WHERE statut='actif' ORDER BY nom ASC")->fetchAll();
$cartCount = array_sum(array_map('intval', $_SESSION['client_cart']));

function clientPhoto(array $product): string
{
    $photos = json_decode((string)($product['photos'] ?? '[]'), true);
    return is_array($photos) && !empty($photos[0]) ? (string)$photos[0] : '';
}
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(getSettings()['nom_boutique'] ?? 'Boutique') ?> | Boutique en ligne</title>
    <link rel="stylesheet" href="assets/tailwind.css">
    <?php renderIconAssets('assets/vendor/fontawesome.min.css'); ?>
    <style>
        body{background:#f6f7f2;color:#17221b}.hero{background:linear-gradient(120deg,#123b2a,#27704d 58%,#d4e65c);color:white}.product-image{height:220px;object-fit:cover}.product-placeholder{height:220px;background:#e5eadf}.cart-panel{max-height:calc(100vh - 2rem);overflow:auto;top:1rem;right:1rem;width:min(380px,calc(100vw - 2rem))}.cart-line{border:1px solid #e5e7eb}.cart-quantity button{height:2rem;width:2rem;border-radius:.6rem;background:#e7f1e8;font-weight:900;color:#14532d}.cart-quantity button:hover{background:#cde5d1}
    </style>
</head>
<body>
<header class="hero">
    <nav class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-5 py-5"><a href="index.php" class="text-2xl font-black">Boutique</a><div class="flex flex-wrap items-center gap-4 text-sm font-bold"><a href="#fonctionnalites">Fonctionnalités</a><a href="#services">Services</a><a href="#faq">FAQ</a><?php if ($isClientLoggedIn): ?><a href="mes_commandes.php">Mes commandes</a><a href="logout.php">Déconnexion</a><?php else: ?><a href="inscription_client.php">Inscription</a><a href="login.php">Login</a><?php endif; ?><button type="button" onclick="toggleCart()" class="rounded-full bg-white px-5 py-3 font-bold text-green-950"><i class="fa-solid fa-bag-shopping mr-2"></i>Panier (<span id="cart-count"><?= $cartCount ?></span>)</button></div></nav>
    <div class="mx-auto max-w-7xl px-5 pb-16 pt-10"><p class="mb-3 font-bold uppercase tracking-widest text-lime-200">Disponible près de chez vous</p><h1 class="max-w-3xl text-4xl font-black md:text-6xl">Les produits que vous aimez, simplement.</h1><p class="mt-5 max-w-xl text-lg text-green-50">Découvrez les produits les plus vendus et choisissez votre magasin de retrait.</p></div>
</header>
<main class="mx-auto max-w-7xl px-5 py-10">
    <?php if ($success): ?><div class="mb-8 rounded-2xl bg-green-100 p-5 text-green-900"><strong>Commande <?= e($success['number']) ?> enregistrée.</strong><br>Total : <?= number_format($success['total'], 2, ',', ' ') ?>. Retrait au : <?= e($success['store']) ?>.</div><?php endif; ?>
    <?php if ($errors): ?><div class="mb-8 rounded-2xl bg-red-100 p-5 text-red-800"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
    <form method="get" class="mb-5 flex flex-col gap-3 sm:flex-row"><label class="flex flex-1 items-center gap-3 rounded-2xl bg-white px-4 py-3 shadow-sm ring-1 ring-black/5"><i class="fa-solid fa-magnifying-glass text-green-800"></i><input name="q" value="<?= e($search) ?>" placeholder="Rechercher un produit ou une catégorie" class="w-full bg-transparent outline-none"></label><?php if ($categoryId > 0): ?><input type="hidden" name="categorie" value="<?= $categoryId ?>"><?php endif; ?><button class="rounded-2xl bg-green-900 px-6 py-3 font-bold text-white"><i class="fa-solid fa-search mr-2"></i>Rechercher</button><?php if ($search !== ''): ?><a href="<?= $categoryId ? '?categorie=' . $categoryId : 'index.php' ?>" class="rounded-2xl bg-white px-6 py-3 text-center font-bold text-green-900">Effacer</a><?php endif; ?></form>
    <div class="mb-8 flex flex-wrap items-center gap-3"><a href="<?= $search !== '' ? '?q=' . urlencode($search) : 'index.php' ?>" class="rounded-full px-4 py-2 <?= !$categoryId ? 'bg-green-900 text-white' : 'bg-white' ?>">Tous</a><?php foreach ($categories as $category): ?><a href="?categorie=<?= (int)$category['id'] ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>" class="rounded-full px-4 py-2 <?= $categoryId === (int)$category['id'] ? 'bg-green-900 text-white' : 'bg-white' ?>"><?= e($category['nom']) ?> <span class="text-xs opacity-70"><?= (int)$category['produits_count'] ?></span></a><?php endforeach; ?></div>
    <div class="grid grid-cols-1 items-start gap-8 xl:grid-cols-[minmax(0,1fr)_300px]"><section class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($products as $product): $photo = clientPhoto($product); ?>
        <article class="overflow-hidden rounded-3xl bg-white shadow-sm ring-1 ring-black/5"><div><?= $photo ? '<img class="product-image w-full" src="'.e($photo).'" alt="'.e($product['nom']).'" loading="lazy">' : '<div class="product-placeholder flex items-center justify-center text-6xl">📦</div>' ?></div><div class="p-5"><p class="text-xs font-bold uppercase tracking-wider text-green-700"><?= e($product['categorie'] ?? 'Produit') ?></p><h2 class="mt-2 text-xl font-black"><?= e($product['nom']) ?></h2><p class="mt-1 text-sm text-gray-500"><i class="fa-solid fa-location-dot mr-1"></i><?= e($product['magasin']) ?></p><div class="mt-4 flex items-center justify-between"><strong class="text-xl text-green-700"><?= number_format((float)$product['prix_vente'], 2, ',', ' ') ?></strong><span class="text-sm text-gray-500">Stock <?= (int)$product['quantite'] ?></span></div><button type="button" onclick="addToCart(<?= (int)$product['id'] ?>)" class="mt-5 w-full rounded-2xl bg-green-900 px-4 py-3 font-bold text-white hover:bg-green-800"><i class="fa-solid fa-cart-plus mr-2"></i>Ajouter au panier</button></div></article>
        <?php endforeach; ?>
    </section>
    <aside class="space-y-5 xl:sticky xl:top-5"><div id="fonctionnalites" class="rounded-2xl bg-green-950 p-6 text-white"><h2 class="text-xl font-black">Fonctionnalités</h2><p class="mt-3 text-green-100">Catalogue par catégories, panier rapide et suivi de commande.</p></div><div id="services" class="rounded-2xl bg-white p-6 shadow-sm"><h2 class="text-xl font-black">Services</h2><p class="mt-3 text-gray-600">Choisissez votre magasin de retrait et commandez selon le stock disponible.</p></div><div id="faq" class="rounded-2xl bg-white p-6 shadow-sm"><h2 class="text-xl font-black">FAQ</h2><details class="mt-4"><summary class="cursor-pointer font-bold">Comment récupérer ma commande ?</summary><p class="mt-2 text-sm text-gray-600">Nous préparons votre commande dans le magasin choisi. Consultez son statut dans “Mes commandes”.</p></details><details class="mt-3"><summary class="cursor-pointer font-bold">Puis-je suivre ma commande ?</summary><p class="mt-2 text-sm text-gray-600">Oui, connectez-vous à votre compte client.</p></details></div></aside></div>
    <?php if (!$products): ?><p class="rounded-2xl bg-white p-8 text-center">Aucun produit disponible dans cette catégorie.</p><?php endif; ?>
</main>
<div id="cart-overlay" onclick="toggleCart()" class="fixed inset-0 z-40 hidden bg-black/40"></div>
<aside id="cart-panel" class="cart-panel fixed z-50 h-auto max-h-[calc(100vh-2rem)] bg-white p-6 shadow-2xl"><div class="flex items-center justify-between"><h2 class="text-2xl font-black"><i class="fa-solid fa-bag-shopping mr-2 text-green-700"></i>Votre panier</h2><button type="button" onclick="toggleCart()" class="text-2xl" aria-label="Fermer"><i class="fa-solid fa-xmark"></i></button></div><p class="mt-2 text-sm text-gray-500">Vérifiez les quantités puis choisissez votre magasin de retrait.</p><div id="cart-lines" class="my-6 space-y-3"></div><p class="flex justify-between border-t py-4 text-xl font-black"><span>Total</span><span id="cart-total">0</span></p><form method="post" class="space-y-3"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="place_order" value="1"><?php if (!$isClientLoggedIn): ?><label class="block text-sm font-bold">Nom complet<input required name="nom" value="<?= e($old['nom']) ?>" class="mt-1 w-full rounded-xl border p-3"></label><label class="block text-sm font-bold">Email<input required type="email" name="email" value="<?= e($old['email']) ?>" class="mt-1 w-full rounded-xl border p-3"></label><label class="block text-sm font-bold">Mot de passe<input required type="password" name="password" minlength="6" class="mt-1 w-full rounded-xl border p-3"></label><?php else: ?><p class="rounded-xl bg-green-50 p-3 text-sm text-green-900">Commande pour <?= e($clientUser['nom']) ?></p><?php endif; ?><label class="block text-sm font-bold">Magasin de retrait<select required name="magasin_id" class="mt-1 w-full rounded-xl border p-3"><option value="">Choisir un magasin</option><?php foreach ($stores as $store): ?><option value="<?= (int)$store['id'] ?>"><?= e($store['nom']) ?><?= $store['ville'] ? ' - '.e($store['ville']) : '' ?></option><?php endforeach; ?></select></label><input type="hidden" id="cart-input" name="cart" value=""><button class="w-full rounded-2xl bg-lime-500 p-4 font-black text-green-950"><i class="fa-solid fa-check mr-2"></i><?= $isClientLoggedIn ? 'Valider ma commande' : 'Créer mon compte et commander' ?></button></form></aside>
<script>
const products = <?= json_encode(array_column($products, null, 'id'), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;
const serverCart = <?= json_encode($_SESSION['client_cart']) ?>;
let cart = serverCart;
<?php if ($success): ?>
localStorage.removeItem('client_cart');
<?php else: ?>
try {
    if (Object.keys(cart).length === 0) {
        const savedCart = JSON.parse(localStorage.getItem('client_cart') || '{}');
        if (savedCart && typeof savedCart === 'object') {
            cart = savedCart;
        }
    }
} catch (error) {
    localStorage.removeItem('client_cart');
}
<?php endif; ?>
function saveCart(){ document.getElementById('cart-input').value = JSON.stringify(cart); localStorage.setItem('client_cart', JSON.stringify(cart)); }
function addToCart(id){ cart[id] = Math.min((cart[id] || 0) + 1, Number(products[id].quantite)); renderCart(); toggleCart(true); }
function changeQty(id, amount){ cart[id] = Math.max(0, Math.min((cart[id] || 0) + amount, Number(products[id].quantite))); if (!cart[id]) delete cart[id]; renderCart(); }
function toggleCart(force){ const panel=document.getElementById('cart-panel'), overlay=document.getElementById('cart-overlay'); const open=force === true ? true : panel.classList.contains('hidden'); panel.classList.toggle('hidden', !open); overlay.classList.toggle('hidden', !open); }
function renderCart(){ let total=0,count=0,html=''; Object.entries(cart).forEach(([id,qty])=>{const p=products[id]; if(!p)return; const line=Number(p.prix_vente)*qty; total+=line;count+=qty;html+=`<div class="cart-line rounded-xl bg-gray-50 p-3"><div class="flex items-start justify-between gap-3"><strong>${escapeHtml(p.nom)}</strong><button type="button" onclick="changeQty(${id},-${qty})" class="text-red-600" aria-label="Supprimer"><i class="fa-solid fa-trash"></i></button></div><div class="mt-3 flex items-center justify-between"><span class="text-sm text-gray-500">${Number(p.prix_vente).toFixed(2)} × ${qty}</span><span class="cart-quantity flex gap-2"><button type="button" onclick="changeQty(${id},-1)" aria-label="Diminuer">−</button><span class="flex min-w-8 items-center justify-center font-bold">${qty}</span><button type="button" onclick="changeQty(${id},1)" aria-label="Augmenter">+</button></span></div></div>`}); document.getElementById('cart-lines').innerHTML=html || '<p class="rounded-xl bg-gray-50 p-4 text-gray-500">Votre panier est vide.</p>';document.getElementById('cart-total').textContent=total.toFixed(2);document.getElementById('cart-count').textContent=count;saveCart();}
function escapeHtml(value){const div=document.createElement('div');div.textContent=value;return div.innerHTML;} renderCart();
</script>
</body>
</html>
