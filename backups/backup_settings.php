<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config-settings.php';

requireLogin();

$user = currentUser();
$settings = getSettings();

/* =========================================================
   SECURITY
========================================================= */

if (($user['role'] ?? '') !== 'admin') {

    die("⛔ Accès refusé");
}

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

/* =========================================================
   SETTINGS FILE
========================================================= */

$settingsFile = __DIR__ . '/../../storage/backup_settings.json';

/* =========================================================
   DEFAULT SETTINGS
========================================================= */

$defaultSettings = [

    'auto_backup'          => true,
    'backup_frequency'     => 'daily',
    'backup_time'          => '02:00',
    'max_backups'          => 30,
    'compress_backup'      => true,
    'email_notification'   => false,
    'notification_email'   => '',
    'auto_delete_old'      => true,
    'include_uploads'      => false,
    'maintenance_mode'     => false,
    'backup_retention_days'=> 30
];

/* =========================================================
   CREATE SETTINGS FILE
========================================================= */

if (!file_exists($settingsFile)) {

    if (!is_dir(dirname($settingsFile))) {

        mkdir(dirname($settingsFile), 0777, true);
    }

    file_put_contents(
        $settingsFile,
        json_encode($defaultSettings, JSON_PRETTY_PRINT)
    );
}

/* =========================================================
   LOAD SETTINGS
========================================================= */

$backupSettings = $defaultSettings;

try {

    $content = file_get_contents($settingsFile);

    $json = json_decode($content, true);

    if (is_array($json)) {

        $backupSettings = array_merge(
            $defaultSettings,
            $json
        );
    }

} catch (Exception $e) {

    $backupSettings = $defaultSettings;
}

/* =========================================================
   SAVE SETTINGS
========================================================= */

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        $newSettings = [

            'auto_backup' =>
                isset($_POST['auto_backup']),

            'backup_frequency' =>
                $_POST['backup_frequency'] ?? 'daily',

            'backup_time' =>
                $_POST['backup_time'] ?? '02:00',

            'max_backups' =>
                max(
                    1,
                    (int)($_POST['max_backups'] ?? 30)
                ),

            'compress_backup' =>
                isset($_POST['compress_backup']),

            'email_notification' =>
                isset($_POST['email_notification']),

            'notification_email' =>
                trim($_POST['notification_email'] ?? ''),

            'auto_delete_old' =>
                isset($_POST['auto_delete_old']),

            'include_uploads' =>
                isset($_POST['include_uploads']),

            'maintenance_mode' =>
                isset($_POST['maintenance_mode']),

            'backup_retention_days' =>
                max(
                    1,
                    (int)($_POST['backup_retention_days'] ?? 30)
                )
        ];

        file_put_contents(
            $settingsFile,
            json_encode(
                $newSettings,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            )
        );

        $backupSettings = $newSettings;

        $success =
            "✅ Paramètres sauvegardés avec succès.";

    } catch (Exception $e) {

        $error =
            "❌ Erreur lors de la sauvegarde.";
    }
}

?>

<!DOCTYPE html>
<html lang="fr">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>

Paramètres Backup | <?= e($settings['nom_boutique'] ?? 'POS PREMIUM') ?>

</title>

<script src="https://cdn.tailwindcss.com"></script>

<link
rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
/>

<script>

tailwind.config = {

    darkMode:'class'
}

</script>

<style>

body{

    font-family:Inter,sans-serif;

    background:
    linear-gradient(
        135deg,
        #f8fafc 0%,
        #eef2ff 50%,
        #f1f5f9 100%
    );
}

.dark body{

    background:
    linear-gradient(
        135deg,
        #020617 0%,
        #0f172a 50%,
        #111827 100%
    );
}

.glass{

    background:
    rgba(255,255,255,.85);

    backdrop-filter:
    blur(16px);

    border:
    1px solid rgba(255,255,255,.25);

    box-shadow:
    0 10px 30px rgba(0,0,0,.08);
}

.dark .glass{

    background:
    rgba(15,23,42,.85);

    border:
    1px solid rgba(255,255,255,.06);
}

.input{

    width:100%;

    padding:14px 18px;

    border-radius:16px;

    border:1px solid #cbd5e1;

    background:white;
}

.dark .input{

    background:#0f172a;

    border-color:#334155;

    color:white;
}

.input:focus{

    outline:none;

    border-color:#3b82f6;

    box-shadow:
    0 0 0 4px rgba(59,130,246,.15);
}

.switch{

    width:55px;
    height:30px;

    position:relative;

    display:inline-block;
}

.switch input{

    display:none;
}

.slider{

    position:absolute;

    inset:0;

    background:#cbd5e1;

    border-radius:999px;

    transition:.3s;
}

.slider:before{

    content:'';

    position:absolute;

    width:22px;
    height:22px;

    left:4px;
    top:4px;

    background:white;

    border-radius:50%;

    transition:.3s;
}

.switch input:checked + .slider{

    background:#2563eb;
}

.switch input:checked + .slider:before{

    transform:translateX(25px);
}

.btn{

    padding:14px 22px;

    border-radius:16px;

    font-weight:700;

    transition:.25s;
}

.btn:hover{

    transform:translateY(-2px);
}

</style>

</head>

<body class="text-slate-800 dark:text-white min-h-screen">

<div class="max-w-5xl mx-auto p-6">

    <!-- HEADER -->

    <div class="glass rounded-3xl p-6 mb-6 flex items-center justify-between">

        <div>

            <h1 class="text-3xl font-black flex items-center gap-3">

                <i class="fa-solid fa-gears text-blue-600"></i>

                Paramètres Backup

            </h1>

            <p class="text-slate-500 dark:text-slate-400 mt-2">

                Configuration automatique des sauvegardes système

            </p>

        </div>

        <button
            onclick="toggleDark()"
            class="btn bg-slate-900 text-white dark:bg-yellow-400 dark:text-black"
        >

            🌙 Theme

        </button>

    </div>

    <!-- ALERTS -->

    <?php if($success): ?>

    <div class="mb-6 p-5 rounded-2xl bg-green-100 text-green-700 font-bold">

        <?= $success ?>

    </div>

    <?php endif; ?>

    <?php if($error): ?>

    <div class="mb-6 p-5 rounded-2xl bg-red-100 text-red-700 font-bold">

        <?= $error ?>

    </div>

    <?php endif; ?>

    <!-- FORM -->

    <form method="POST" class="glass rounded-3xl p-8 space-y-8">

        <!-- AUTO BACKUP -->

        <div class="flex items-center justify-between">

            <div>

                <h2 class="font-bold text-xl">

                    Sauvegarde automatique

                </h2>

                <p class="text-slate-500 text-sm mt-1">

                    Active les backups automatiques

                </p>

            </div>

            <label class="switch">

                <input
                    type="checkbox"
                    name="auto_backup"
                    <?= $backupSettings['auto_backup'] ? 'checked' : '' ?>
                >

                <span class="slider"></span>

            </label>

        </div>

        <!-- FREQUENCY -->

        <div>

            <label class="font-bold block mb-3">

                Fréquence Backup

            </label>

            <select
                name="backup_frequency"
                class="input"
            >

                <option value="hourly"
                    <?= $backupSettings['backup_frequency'] === 'hourly' ? 'selected' : '' ?>
                >

                    Chaque heure

                </option>

                <option value="daily"
                    <?= $backupSettings['backup_frequency'] === 'daily' ? 'selected' : '' ?>
                >

                    Quotidien

                </option>

                <option value="weekly"
                    <?= $backupSettings['backup_frequency'] === 'weekly' ? 'selected' : '' ?>
                >

                    Hebdomadaire

                </option>

                <option value="monthly"
                    <?= $backupSettings['backup_frequency'] === 'monthly' ? 'selected' : '' ?>
                >

                    Mensuel

                </option>

            </select>

        </div>

        <!-- TIME -->

        <div>

            <label class="font-bold block mb-3">

                Heure du Backup

            </label>

            <input
                type="time"
                name="backup_time"
                value="<?= e($backupSettings['backup_time']) ?>"
                class="input"
            >

        </div>

        <!-- MAX BACKUPS -->

        <div>

            <label class="font-bold block mb-3">

                Nombre maximum de backups

            </label>

            <input
                type="number"
                min="1"
                name="max_backups"
                value="<?= e($backupSettings['max_backups']) ?>"
                class="input"
            >

        </div>

        <!-- RETENTION -->

        <div>

            <label class="font-bold block mb-3">

                Durée conservation (jours)

            </label>

            <input
                type="number"
                min="1"
                name="backup_retention_days"
                value="<?= e($backupSettings['backup_retention_days']) ?>"
                class="input"
            >

        </div>

        <!-- OPTIONS -->

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

            <!-- COMPRESS -->

            <div class="flex items-center justify-between p-5 rounded-2xl bg-slate-100 dark:bg-slate-800">

                <div>

                    <div class="font-bold">

                        Compression ZIP

                    </div>

                    <div class="text-sm text-slate-500">

                        Réduire la taille backup

                    </div>

                </div>

                <label class="switch">

                    <input
                        type="checkbox"
                        name="compress_backup"
                        <?= $backupSettings['compress_backup'] ? 'checked' : '' ?>
                    >

                    <span class="slider"></span>

                </label>

            </div>

            <!-- AUTO DELETE -->

            <div class="flex items-center justify-between p-5 rounded-2xl bg-slate-100 dark:bg-slate-800">

                <div>

                    <div class="font-bold">

                        Suppression auto

                    </div>

                    <div class="text-sm text-slate-500">

                        Supprimer anciens backups

                    </div>

                </div>

                <label class="switch">

                    <input
                        type="checkbox"
                        name="auto_delete_old"
                        <?= $backupSettings['auto_delete_old'] ? 'checked' : '' ?>
                    >

                    <span class="slider"></span>

                </label>

            </div>

            <!-- INCLUDE UPLOADS -->

            <div class="flex items-center justify-between p-5 rounded-2xl bg-slate-100 dark:bg-slate-800">

                <div>

                    <div class="font-bold">

                        Inclure uploads

                    </div>

                    <div class="text-sm text-slate-500">

                        Sauvegarder images/fichiers

                    </div>

                </div>

                <label class="switch">

                    <input
                        type="checkbox"
                        name="include_uploads"
                        <?= $backupSettings['include_uploads'] ? 'checked' : '' ?>
                    >

                    <span class="slider"></span>

                </label>

            </div>

            <!-- MAINTENANCE -->

            <div class="flex items-center justify-between p-5 rounded-2xl bg-slate-100 dark:bg-slate-800">

                <div>

                    <div class="font-bold">

                        Maintenance mode

                    </div>

                    <div class="text-sm text-slate-500">

                        Bloquer système pendant backup

                    </div>

                </div>

                <label class="switch">

                    <input
                        type="checkbox"
                        name="maintenance_mode"
                        <?= $backupSettings['maintenance_mode'] ? 'checked' : '' ?>
                    >

                    <span class="slider"></span>

                </label>

            </div>

        </div>

        <!-- EMAIL -->

        <div class="space-y-5">

            <div class="flex items-center justify-between">

                <div>

                    <div class="font-bold text-xl">

                        Notification Email

                    </div>

                    <div class="text-sm text-slate-500">

                        Recevoir notifications backup

                    </div>

                </div>

                <label class="switch">

                    <input
                        type="checkbox"
                        name="email_notification"
                        <?= $backupSettings['email_notification'] ? 'checked' : '' ?>
                    >

                    <span class="slider"></span>

                </label>

            </div>

            <input
                type="email"
                name="notification_email"
                value="<?= e($backupSettings['notification_email']) ?>"
                placeholder="admin@email.com"
                class="input"
            >

        </div>

        <!-- BUTTONS -->

        <div class="flex flex-wrap gap-4 pt-5">

            <button
                type="submit"
                class="btn bg-blue-600 hover:bg-blue-700 text-white"
            >

                <i class="fa-solid fa-floppy-disk"></i>

                Sauvegarder paramètres

            </button>

            <a
                href="index.php"
                class="btn bg-slate-200 hover:bg-slate-300 dark:bg-slate-700 dark:hover:bg-slate-600"
            >

                <i class="fa-solid fa-arrow-left"></i>

                Retour

            </a>

        </div>

    </form>

</div>

<script>

if(localStorage.getItem("theme")==="dark"){

    document.documentElement.classList.add("dark");
}

function toggleDark(){

    document.documentElement.classList.toggle("dark");

    if(document.documentElement.classList.contains("dark")){

        localStorage.setItem("theme","dark");

    }else{

        localStorage.setItem("theme","light");
    }
}

</script>

</body>
</html>