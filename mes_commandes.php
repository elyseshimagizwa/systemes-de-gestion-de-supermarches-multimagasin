<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/client-orders.php';
requireLogin();
$user = currentUser();
$settings = getSettings();
if (($user['role'] ?? '') !== 'client') { header('Location: dashboard.php'); exit; }
$success = $_SESSION['client_order_success'] ?? null;
unset($_SESSION['client_order_success']);
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $notificationId = filter_var($_POST['notification_id'] ?? null, FILTER_VALIDATE_INT);
    if ($notificationId && $notificationId > 0) {
        $stmt = $pdo->prepare('UPDATE notifications_clients SET lu=1 WHERE id=? AND utilisateur_id=?');
        $stmt->execute([$notificationId, (int)$user['id']]);
    }
    header('Location: mes_commandes.php#notifications', true, 303);
    exit;
}
require_once __DIR__ . '/includes/client-tracking.php';
try {
    $tracking = clientTrackingData($pdo, (int)$user['id']);
} catch (Throwable $exception) {
    error_log('Notifications client : ' . $exception->getMessage());
    $tracking = ['notifications' => []];
    $error = 'Le suivi est temporairement indisponible.';
}

?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mes commandes</title>
<link rel="stylesheet" href="assets/tailwind.css">
<?php renderIconAssets('assets/vendor/fontawesome.min.css'); ?>
</head>
<body class="min-h-screen bg-[#f6f7f2]">
<?php include __DIR__ . '/includes/client-navbar.php'; ?>
<main class="mx-auto max-w-5xl px-5 py-10">
<div class="mb-8">
<h1 class="text-3xl font-black">Mes commandes</h1>
<p class="mt-2 text-gray-600">Suivez l’état de vos commandes et leur magasin de retrait.</p>
</div>
<?php if ($success): ?>
<p role="status" class="mb-5 rounded-xl bg-green-100 p-4">Votre commande <?= e($success['number']) ?> a été enregistrée.</p>
<script>try { localStorage.removeItem('client_cart'); } catch (error) {}</script>
<?php endif; ?>
<?php if ($error): ?><p role="alert"><?= e($error) ?></p><?php endif; ?>
<button id="refresh-tracking" type="button" class="mb-5 rounded-xl bg-white p-3">Actualiser le suivi</button>
<div id="client-orders" class="space-y-5">
<?php
try { renderClientOrders($pdo, (int)$user['id']); }
catch (Throwable $exception) {
    error_log('Commandes client : ' . $exception->getMessage());
    echo '<p role="alert">Impossible de charger vos commandes. Veuillez réessayer.</p>';
}
?>
</div>
<section id="notifications" class="mt-8">
<h2 class="text-2xl font-bold">Notifications</h2>
<p class="mt-2">Les 30 dernières notifications. Actualisation toutes les 20 secondes lorsque cette page est visible.</p>
<div id="client-notifications" class="mt-4 space-y-3" data-csrf="<?= e(csrf_token()) ?>">
<?php if (!$tracking['notifications']): ?><p>Aucune notification pour le moment.</p><?php endif; ?>
<?php foreach ($tracking['notifications'] as $notification): ?>
<article class="rounded-xl bg-white p-4">
<strong><?= e($notification['titre']) ?><?= !$notification['lu'] ? ' — Non lue' : '' ?></strong>
<p><?= e($notification['message']) ?></p><p><?= e($notification['created_at']) ?></p>
<?php if ($notification['commande_id']): ?><a href="#commande-<?= (int)$notification['commande_id'] ?>">Voir la commande</a><?php endif; ?>
<?php if (!$notification['lu']): ?>
<form method="post" action="mes_commandes.php#notifications">
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
<input type="hidden" name="notification_id" value="<?= (int)$notification['id'] ?>">
<button type="submit">Marquer comme lue</button>
</form>
<?php endif; ?></article>
<?php endforeach; ?>
</div></section>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
