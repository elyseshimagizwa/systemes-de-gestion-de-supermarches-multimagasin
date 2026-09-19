<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/icons.php';

if (isLoggedIn()) {
    header((currentUser()['role'] ?? '') === 'client' ? 'Location: index.php' : 'Location: dashboard.php');
    exit;
}

$error = null;
$settings = getSettings();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $nom = trim((string)($_POST['nom'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $confirmation = (string)($_POST['confirmation'] ?? '');

    try {
        if ($nom === '' || mb_strlen($nom) < 2 || mb_strlen($nom) > 100) {
            throw new RuntimeException('Veuillez saisir votre nom complet.');
        }
        if (strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Veuillez saisir une adresse email valide.');
        }
        if (strlen($password) < 6 || strlen($password) > 72 || $password !== $confirmation) {
            throw new RuntimeException('Les mots de passe doivent correspondre (6 caractères minimum, 72 octets maximum).');
        }
        $check = $pdo->prepare('SELECT id FROM utilisateurs WHERE email=? LIMIT 1');
        $check->execute([$email]);
        if ($check->fetch()) {
            throw new RuntimeException('Cette adresse email possède déjà un compte.');
        }
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO utilisateurs (nom, email, mot_de_passe, role, magasin_id, statut) VALUES (?, ?, ?, 'client', NULL, 'actif')");
        $stmt->execute([$nom, $email, password_hash($password, PASSWORD_DEFAULT)]);
        $userId = (int)$pdo->lastInsertId();
        createUserSession(['id' => $userId]);
        $pdo->prepare('UPDATE utilisateurs SET derniere_connexion=NOW() WHERE id=?')->execute([$userId]);
        $pdo->commit();
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => $userId, 'nom' => $nom, 'email' => $email, 'role' => 'client', 'magasin_id' => null, 'multi_magasin' => 0];
        $_SESSION['last_activity'] = time();
        $_SESSION['last_regeneration'] = time();
        $_SESSION['ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
        $_SESSION['device'] = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . ($_SERVER['REMOTE_ADDR'] ?? ''));
        unset($_SESSION['magasin_actif']);
        header('Location: index.php', true, 303);
        exit;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($exception instanceof PDOException) {
            error_log('Inscription client : ' . $exception->getMessage());
            $error = $exception->getCode() === '23000' ? 'Cette adresse email possède déjà un compte.' : 'Inscription indisponible. Veuillez réessayer plus tard.';
        } else {
            $error = $exception->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Créer un compte client</title><link rel="stylesheet" href="assets/tailwind.css"><?php renderIconAssets('assets/vendor/fontawesome.min.css'); ?><style>body{background:#f6f7f2}.panel{background:linear-gradient(135deg,#123b2a,#27704d);color:#fff}</style></head>
<body class="min-h-screen p-5"><?php include __DIR__ . '/includes/client-navbar.php'; ?><main class="mx-auto grid min-h-[90vh] max-w-5xl items-center gap-8 md:grid-cols-2"><section class="panel rounded-3xl p-8 md:p-12"><i class="fa-solid fa-user-plus text-4xl text-lime-300"></i><h1 class="mt-6 text-4xl font-black">Votre compte client</h1><p class="mt-4 text-green-50">Créez votre espace privé pour commander et suivre vos commandes depuis la boutique.</p><a href="index.php" class="mt-8 inline-block font-bold text-lime-300">Retour à la boutique</a></section><form method="post" class="rounded-3xl bg-white p-8 shadow-sm ring-1 ring-black/5"><h2 class="text-2xl font-black">Inscription</h2><?php if ($error): ?><div class="mt-4 rounded-xl bg-red-100 p-3 text-red-800"><?= e($error) ?></div><?php endif; ?><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label class="mt-5 block font-bold">Nom complet<input required name="nom" maxlength="100" autocomplete="name" value="<?= e($nom ?? '') ?>" class="mt-1 w-full rounded-xl border p-3"></label><label class="mt-4 block font-bold">Email<input required type="email" name="email" maxlength="150" autocomplete="email" value="<?= e($email ?? '') ?>" class="mt-1 w-full rounded-xl border p-3"></label><label class="mt-4 block font-bold">Mot de passe<input required minlength="6" type="password" name="password" maxlength="72" autocomplete="new-password" class="mt-1 w-full rounded-xl border p-3"></label><label class="mt-4 block font-bold">Confirmer le mot de passe<input required minlength="6" type="password" name="confirmation" maxlength="72" autocomplete="new-password" class="mt-1 w-full rounded-xl border p-3"></label><button class="mt-6 w-full rounded-2xl bg-green-900 p-4 font-black text-white"><i class="fa-solid fa-arrow-right mr-2"></i>Créer mon compte</button><p class="mt-5 text-center text-sm text-gray-600">Déjà inscrit ? <a class="font-bold text-green-800" href="login.php">Se connecter</a></p></form></main></body></html>
