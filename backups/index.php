<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config-settings.php';

requireLogin();

$user       = currentUser();
$settings   = getSettings();

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
   SAVE PREVIOUS PAGE
========================================================= */

$backUrl =
    $_SERVER['HTTP_REFERER']
    ??
    '../dashboard.php';

/* =========================================================
   BACKUP DIRECTORY
========================================================= */

$backupDir =
    __DIR__ . '/../../storage/backups/';

if (!is_dir($backupDir)) {

    mkdir($backupDir, 0777, true);
}

/* =========================================================
   LOG FILE
========================================================= */

$logFile =
    __DIR__ . '/../../storage/logs/backup_logs.txt';

if (!is_dir(dirname($logFile))) {

    mkdir(dirname($logFile), 0777, true);
}

if (!file_exists($logFile)) {

    file_put_contents($logFile, '');
}

/* =========================================================
   LOAD BACKUPS
========================================================= */

$backupFiles = [];
$totalSize   = 0;

if (is_dir($backupDir)) {

    $files = scandir($backupDir);

    foreach ($files as $file) {

        if (
            $file === '.'
            ||
            $file === '..'
        ) {

            continue;
        }

        $fullPath =
            $backupDir . $file;

        if (is_file($fullPath)) {

            $size =
                filesize($fullPath);

            $backupFiles[] = [

                'name' => $file,
                'size' => $size,
                'date' => filemtime($fullPath),
                'path' => $fullPath
            ];

            $totalSize += $size;
        }
    }
}

/* =========================================================
   SORT DESC
========================================================= */

usort($backupFiles, function ($a, $b) {

    return $b['date'] - $a['date'];
});

/* =========================================================
   STATS
========================================================= */

$totalBackups =
    count($backupFiles);

$lastBackup =
    $backupFiles[0]['date'] ?? null;

/* =========================================================
   LOAD SETTINGS
========================================================= */

$backupSettingsFile =
    __DIR__ . '/../../storage/settings/backup_settings.json';

$backupSettings = [

    'auto_backup' => true,
    'frequency'   => 'daily',
    'max_backups' => 10
];

if (file_exists($backupSettingsFile)) {

    $json =
        json_decode(
            file_get_contents($backupSettingsFile),
            true
        );

    if ($json) {

        $backupSettings = $json;
    }
}

/* =========================================================
   LOAD LOGS
========================================================= */

$logs = [];

$content =
    file_get_contents($logFile);

$lines =
    explode("\n", trim($content));

$lines =
    array_filter($lines);

$logs =
    array_reverse($lines);

$recentLogs =
    array_slice($logs, 0, 5);

/* =========================================================
   FORMAT SIZE
========================================================= */

function formatBytes($size, $precision = 2)
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];

    $size = max($size, 0);

    $pow = floor(($size ? log($size) : 0) / log(1024));

    $pow = min($pow, count($units) - 1);

    $size /= pow(1024, $pow);

    return round($size, $precision) . ' ' . $units[$pow];
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

Backup Manager | <?= e($settings['nom_boutique'] ?? 'POS PREMIUM') ?>

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

    font-family:
    Inter,
    sans-serif;

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

.card{

    border-radius:24px;

    padding:24px;

    transition:.25s;
}

.card:hover{

    transform:translateY(-3px);
}

.btn{

    padding:12px 20px;

    border-radius:16px;

    font-weight:700;

    transition:.25s;

    display:flex;

    align-items:center;

    gap:10px;
}

.btn:hover{

    transform:translateY(-2px);
}

.table-row:hover{

    background:
    rgba(59,130,246,.05);
}

.scrollbar::-webkit-scrollbar{

    width:8px;
}

.scrollbar::-webkit-scrollbar-thumb{

    background:#94a3b8;

    border-radius:999px;
}

</style>

</head>

<body class="text-slate-800 dark:text-white min-h-screen">

<div class="p-6 max-w-7xl mx-auto">

    <!-- HEADER -->

    <div class="glass rounded-3xl p-6 mb-6 flex flex-col lg:flex-row items-start lg:items-center justify-between gap-5">

        <div>

            <h1 class="text-4xl font-black flex items-center gap-4">

                <i class="fa-solid fa-database text-blue-600"></i>

                Backup Manager

            </h1>

            <p class="text-slate-500 dark:text-slate-400 mt-3">

                Gestion complète des sauvegardes système

            </p>

        </div>

        <!-- ACTIONS -->

        <div class="flex flex-wrap gap-3">

            <!-- BACK -->

            <a
                href="../dashboard.php"
                class="btn bg-slate-700 hover:bg-slate-800 text-white"
            >

                <i class="fa-solid fa-arrow-left"></i>

                Retour

            </a>

            <!-- CREATE -->

            <a
                href="create_backup.php"
                class="btn bg-blue-600 hover:bg-blue-700 text-white"
            >

                <i class="fa-solid fa-plus"></i>

                Nouveau Backup

            </a>

            <!-- UPLOAD -->

            <a
                href="upload_backup.php"
                class="btn bg-green-600 hover:bg-green-700 text-white"
            >

                <i class="fa-solid fa-upload"></i>

                Upload Backup

            </a>

            <!-- SETTINGS -->

            <a
                href="backup_settings.php"
                class="btn bg-purple-600 hover:bg-purple-700 text-white"
            >

                <i class="fa-solid fa-gear"></i>

                Paramètres

            </a>

            <!-- LOGS -->

            <a
                href="backup_logs.php"
                class="btn bg-orange-500 hover:bg-orange-600 text-white"
            >

                <i class="fa-solid fa-clock-rotate-left"></i>

                Logs

            </a>

            <!-- DARK -->

            <button
                onclick="toggleDark()"
                class="btn bg-slate-900 text-white dark:bg-yellow-400 dark:text-black"
            >

                🌙 Theme

            </button>

        </div>

    </div>

    <!-- STATS -->

    <div class="grid grid-cols-1 md:grid-cols-4 gap-5 mb-6">

        <!-- TOTAL -->

        <div class="glass card">

            <div class="flex items-center justify-between">

                <div>

                    <div class="text-slate-500 text-sm">

                        Total Backups

                    </div>

                    <div class="text-4xl font-black mt-2">

                        <?= $totalBackups ?>

                    </div>

                </div>

                <div class="w-16 h-16 rounded-2xl bg-blue-100 text-blue-600 flex items-center justify-center text-3xl">

                    <i class="fa-solid fa-box-archive"></i>

                </div>

            </div>

        </div>

        <!-- SIZE -->

        <div class="glass card">

            <div class="flex items-center justify-between">

                <div>

                    <div class="text-slate-500 text-sm">

                        Taille Totale

                    </div>

                    <div class="text-3xl font-black mt-2">

                        <?= formatBytes($totalSize) ?>

                    </div>

                </div>

                <div class="w-16 h-16 rounded-2xl bg-green-100 text-green-600 flex items-center justify-center text-3xl">

                    <i class="fa-solid fa-hard-drive"></i>

                </div>

            </div>

        </div>

        <!-- LAST -->

        <div class="glass card">

            <div class="flex items-center justify-between">

                <div>

                    <div class="text-slate-500 text-sm">

                        Dernier Backup

                    </div>

                    <div class="text-lg font-bold mt-3">

                        <?= $lastBackup ? date('d/m/Y H:i', $lastBackup) : 'Aucun' ?>

                    </div>

                </div>

                <div class="w-16 h-16 rounded-2xl bg-purple-100 text-purple-600 flex items-center justify-center text-3xl">

                    <i class="fa-solid fa-clock"></i>

                </div>

            </div>

        </div>

        <!-- AUTO -->

        <div class="glass card">

            <div class="flex items-center justify-between">

                <div>

                    <div class="text-slate-500 text-sm">

                        Backup Auto

                    </div>

                    <div class="text-2xl font-black mt-3">

                        <?= $backupSettings['auto_backup'] ? 'ACTIF' : 'OFF' ?>

                    </div>

                </div>

                <div class="w-16 h-16 rounded-2xl bg-yellow-100 text-yellow-600 flex items-center justify-center text-3xl">

                    <i class="fa-solid fa-rotate"></i>

                </div>

            </div>

        </div>

    </div>

    <!-- TABLE -->

    <div class="glass rounded-3xl overflow-hidden mb-6">

        <div class="p-6 border-b border-slate-200 dark:border-slate-700 flex items-center justify-between">

            <h2 class="text-2xl font-black">

                Sauvegardes Disponibles

            </h2>

            <div class="text-sm text-slate-500">

                <?= $totalBackups ?> fichier(s)

            </div>

        </div>

        <div class="overflow-x-auto scrollbar">

            <table class="w-full">

                <thead class="bg-slate-100 dark:bg-slate-800">

                    <tr>

                        <th class="text-left px-6 py-4">

                            Fichier

                        </th>

                        <th class="text-left px-6 py-4">

                            Taille

                        </th>

                        <th class="text-left px-6 py-4">

                            Date

                        </th>

                        <th class="text-center px-6 py-4">

                            Actions

                        </th>

                    </tr>

                </thead>

                <tbody>

                <?php if($backupFiles): ?>

                    <?php foreach($backupFiles as $backup): ?>

                    <tr class="border-b border-slate-200 dark:border-slate-700 table-row transition">

                        <!-- FILE -->

                        <td class="px-6 py-5">

                            <div class="flex items-center gap-4">

                                <div class="w-14 h-14 rounded-2xl bg-blue-100 text-blue-600 flex items-center justify-center text-2xl">

                                    <i class="fa-solid fa-file-zipper"></i>

                                </div>

                                <div>

                                    <div class="font-bold break-all">

                                        <?= e($backup['name']) ?>

                                    </div>

                                    <div class="text-sm text-slate-500">

                                        Sauvegarde SQL

                                    </div>

                                </div>

                            </div>

                        </td>

                        <!-- SIZE -->

                        <td class="px-6 py-5 font-semibold">

                            <?= formatBytes($backup['size']) ?>

                        </td>

                        <!-- DATE -->

                        <td class="px-6 py-5">

                            <?= date('d/m/Y H:i:s', $backup['date']) ?>

                        </td>

                        <!-- ACTIONS -->

                        <td class="px-6 py-5">

                            <div class="flex flex-wrap justify-center gap-3">

                                <!-- DOWNLOAD -->

                                <a
                                    href="backup_action.php?action=download&file=<?= urlencode($backup['name']) ?>"
                                    class="btn bg-green-600 hover:bg-green-700 text-white"
                                >

                                    <i class="fa-solid fa-download"></i>

                                    Télécharger

                                </a>

                                <!-- RESTORE -->

                                <a
                                    href="backup_action.php?action=restore&file=<?= urlencode($backup['name']) ?>"
                                    onclick="return confirm('Restaurer cette sauvegarde ?')"
                                    class="btn bg-yellow-500 hover:bg-yellow-600 text-white"
                                >

                                    <i class="fa-solid fa-rotate-left"></i>

                                    Restaurer

                                </a>

                                <!-- DELETE -->

                                <a
                                    href="backup_action.php?action=delete&file=<?= urlencode($backup['name']) ?>"
                                    onclick="return confirm('Supprimer cette sauvegarde ?')"
                                    class="btn bg-red-600 hover:bg-red-700 text-white"
                                >

                                    <i class="fa-solid fa-trash"></i>

                                    Supprimer

                                </a>

                            </div>

                        </td>

                    </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>

                        <td
                            colspan="4"
                            class="text-center py-24"
                        >

                            <div class="flex flex-col items-center gap-5">

                                <div class="w-28 h-28 rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-5xl text-slate-400">

                                    <i class="fa-solid fa-database"></i>

                                </div>

                                <div>

                                    <div class="text-3xl font-black">

                                        Aucun backup trouvé

                                    </div>

                                    <div class="text-slate-500 mt-3">

                                        Créez votre première sauvegarde système

                                    </div>

                                </div>

                                <a
                                    href="create_backup.php"
                                    class="btn bg-blue-600 hover:bg-blue-700 text-white"
                                >

                                    <i class="fa-solid fa-plus"></i>

                                    Créer Backup

                                </a>

                            </div>

                        </td>

                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

    <!-- RECENT LOGS -->

    <div class="glass rounded-3xl overflow-hidden">

        <div class="p-6 border-b border-slate-200 dark:border-slate-700 flex items-center justify-between">

            <h2 class="text-2xl font-black">

                Dernières Activités

            </h2>

            <a
                href="backup_logs.php"
                class="text-blue-600 font-bold"
            >

                Voir tout →

            </a>

        </div>

        <div class="p-6 space-y-4">

            <?php if($recentLogs): ?>

                <?php foreach($recentLogs as $log): ?>

                    <div class="p-4 rounded-2xl bg-slate-100 dark:bg-slate-800">

                        <div class="font-mono text-sm break-all">

                            <?= e($log) ?>

                        </div>

                    </div>

                <?php endforeach; ?>

            <?php else: ?>

                <div class="text-center py-10 text-slate-500">

                    Aucun log disponible

                </div>

            <?php endif; ?>

        </div>

    </div>

</div>

<script>

/* =========================================================
   DARK MODE
========================================================= */

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