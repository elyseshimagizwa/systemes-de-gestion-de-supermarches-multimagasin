<?php
$navUser = currentUser();
$navClient = ($navUser['role'] ?? '') === 'client';
$navPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
?>
<nav id="client-navbar" aria-label="Navigation boutique" style="background:#123b2a;color:white;padding:18px;width:100%;box-sizing:border-box">
    <div style="max-width:1200px;margin:auto;display:flex;flex-wrap:wrap;align-items:center;gap:18px">
        <a href="index.php" style="font-weight:900;font-size:1.2rem;display:flex;align-items:center;gap:10px"><?php if (!empty($settings['logo'])): ?><img src="<?= e($settings['logo']) ?>" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:8px"><?php endif; ?><?= e($settings['nom_boutique'] ?? 'Boutique') ?></a>
        <a href="index.php#fonctionnalites">Fonctionnalités</a>
        <a href="index.php#services">Services</a>
        <a href="index.php#faq">FAQ</a>
        <?php if ($navClient): ?>
            <span>Bonjour, <?= e($navUser['nom']) ?></span>
            <a href="mes_commandes.php" <?= $navPage === 'mes_commandes.php' ? 'aria-current="page"' : '' ?>>Mes commandes</a>
            <a href="mes_commandes.php#notifications">Notifications (<span id="client-unread" aria-live="polite">…</span>)</a>
            <a href="logout.php">Déconnexion</a>
        <?php elseif ($navUser): ?>
            <a href="dashboard.php">Tableau de bord</a><a href="logout.php">Déconnexion</a>
        <?php else: ?>
            <a href="login.php">Login</a><a href="inscription_client.php">Inscription</a>
        <?php endif; ?>
        <?php if ($navPage === 'index.php'): ?>
            <button type="button" onclick="toggleCart()">Panier (<span id="cart-count"><?= (int)($cartCount ?? 0) ?></span>)</button>
        <?php else: ?><a href="index.php#panier">Panier</a><?php endif; ?>
    </div>
</nav>
<?php if ($navClient): ?>
<p id="client-update-message" role="status" style="margin:0;padding:0 18px"></p>
<script src="assets/client-tracking.js" defer></script>
<?php endif; ?>
