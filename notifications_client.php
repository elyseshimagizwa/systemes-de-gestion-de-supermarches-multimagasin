<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/icons.php';
requireLogin();
$user = currentUser();
$settings = getSettings();
if (($user['role'] ?? '') !== 'client') {
    header('Location: dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'])) {
    verify_csrf();
    $stmt = $pdo->prepare('UPDATE notifications_clients SET lu=1 WHERE utilisateur_id=? AND id=?');
    $stmt->execute([(int)$user['id'], (int)$_POST['mark_read']]);
    header('Location: notifications_client.php');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM notifications_clients WHERE utilisateur_id=? ORDER BY created_at DESC LIMIT 50');
$stmt->execute([(int)$user['id']]);
$notifications = $stmt->fetchAll();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Notifications | <?= e($settings['nom_boutique'] ?? 'Boutique') ?></title>
    <link rel="stylesheet" href="assets/tailwind.css">
    <?php renderIconAssets('assets/vendor/fontawesome.min.css'); ?>
</head>
<body class="min-h-screen bg-[#f6f7f2]">
<header class="bg-green-950 px-5 py-5 text-white">
    <nav class="mx-auto flex max-w-5xl items-center justify-between gap-4">
        <a href="index.php" class="flex items-center gap-3 text-2xl font-black"><?php if (!empty($settings['logo'])): ?><img src="<?= e($settings['logo']) ?>" alt="<?= e($settings['nom_boutique'] ?? 'Boutique') ?>" class="h-10 w-10 rounded-xl object-cover"><?php endif; ?><span><?= e($settings['nom_boutique'] ?? 'Boutique') ?></span></a>
        <div class="flex gap-4"><a href="mes_commandes.php">Mes commandes</a><a href="logout.php" class="text-lime-300">Déconnexion</a></div>
    </nav>
</header>
<main class="mx-auto max-w-5xl px-5 py-10">
    <div class="mb-8"><h1 class="text-3xl font-black">Notifications</h1><p class="mt-2 text-gray-600">Retrouvez les mises à jour de vos commandes.</p></div>
    <?php if (!$notifications): ?><div class="rounded-2xl bg-white p-8 shadow-sm">Aucune notification.</div><?php endif; ?>
    <div class="space-y-4">
        <?php foreach ($notifications as $notification): ?>
            <article class="rounded-2xl <?= $notification['lu'] ? 'bg-white' : 'bg-lime-50 ring-2 ring-lime-200' ?> p-5 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div><h2 class="font-black"><?= e($notification['titre']) ?></h2><p class="mt-2 text-gray-700"><?= e($notification['message']) ?></p><time class="mt-3 block text-sm text-gray-400"><?= e($notification['created_at']) ?></time></div>
                    <?php if (!$notification['lu']): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button name="mark_read" value="<?= (int)$notification['id'] ?>" class="rounded-xl bg-green-900 px-4 py-2 text-sm font-bold text-white">Marquer comme lue</button></form><?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
