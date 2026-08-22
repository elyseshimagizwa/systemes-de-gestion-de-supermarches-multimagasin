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
   LOG FILE
========================================================= */

$logFile =
    __DIR__ . '/../../storage/logs/backup_logs.txt';

/* =========================================================
   CREATE LOG DIRECTORY
========================================================= */

if (!is_dir(dirname($logFile))) {

    mkdir(dirname($logFile), 0777, true);
}

/* =========================================================
   CREATE LOG FILE
========================================================= */

if (!file_exists($logFile)) {

    file_put_contents(
        $logFile,
        ""
    );
}

/* =========================================================
   CLEAR LOGS
========================================================= */

$success = '';
$error   = '';

if (isset($_GET['clear'])) {

    try {

        file_put_contents(
            $logFile,
            ""
        );

        $success =
            "✅ Tous les logs ont été supprimés.";

    } catch(Exception $e){

        $error =
            "❌ Impossible de supprimer les logs.";
    }
}

/* =========================================================
   LOAD LOGS
========================================================= */

$logs = [];

try {

    $content =
        file_get_contents($logFile);

    $lines =
        explode("\n", trim($content));

    $lines =
        array_filter($lines);

    $lines =
        array_reverse($lines);

    foreach($lines as $line){

        $logs[] = $line;
    }

} catch(Exception $e){

    $logs = [];
}

/* =========================================================
   STATS
========================================================= */

$totalLogs =
    count($logs);

$successLogs = 0;
$errorLogs   = 0;

foreach($logs as $log){

    if (
        stripos($log, 'SUCCESS') !== false
    ) {

        $successLogs++;
    }

    if (
        stripos($log, 'ERROR') !== false
    ) {

        $errorLogs++;
    }
}

/* =========================================================
   DARK MODE
========================================================= */

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

Backup Logs | <?= e($settings['nom_boutique'] ?? 'POS PREMIUM') ?>

</title>

<link rel="stylesheet" href="../assets/tailwind.css">

<link
rel="stylesheet"
href="../assets/vendor/fontawesome.min.css"
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

    transform:translateY(-2px);
}

.log-success{

    border-left:5px solid #16a34a;
}

.log-error{

    border-left:5px solid #dc2626;
}

.log-info{

    border-left:5px solid #2563eb;
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

<div class="max-w-7xl mx-auto p-6">

    <!-- HEADER -->

    <div class="glass rounded-3xl p-6 mb-6 flex flex-col lg:flex-row items-start lg:items-center justify-between gap-5">

        <div>

            <h1 class="text-3xl font-black flex items-center gap-3">

                <i class="fa-solid fa-clock-rotate-left text-blue-600"></i>

                Backup Logs

            </h1>

            <p class="text-slate-500 dark:text-slate-400 mt-2">

                Historique complet des sauvegardes système

            </p>

        </div>

        <!-- ACTIONS -->

        <div class="flex flex-wrap gap-3">

            <a
                href="index.php"
                class="btn bg-blue-600 hover:bg-blue-700 text-white"
            >

                <i class="fa-solid fa-arrow-left"></i>

                Retour

            </a>

            <a
                href="?clear=1"
                onclick="return confirm('Supprimer tous les logs ?')"
                class="btn bg-red-600 hover:bg-red-700 text-white"
            >

                <i class="fa-solid fa-trash"></i>

                Vider Logs

            </a>

            <button
                onclick="toggleDark()"
                class="btn bg-slate-900 text-white dark:bg-yellow-400 dark:text-black"
            >

                🌙 Theme

            </button>

        </div>

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

    <!-- STATS -->

    <div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-6">

        <!-- TOTAL -->

        <div class="glass card">

            <div class="flex items-center justify-between">

                <div>

                    <div class="text-slate-500 text-sm">

                        Total Logs

                    </div>

                    <div class="text-4xl font-black mt-2">

                        <?= $totalLogs ?>

                    </div>

                </div>

                <div class="w-16 h-16 rounded-2xl bg-blue-100 text-blue-600 flex items-center justify-center text-3xl">

                    <i class="fa-solid fa-list"></i>

                </div>

            </div>

        </div>

        <!-- SUCCESS -->

        <div class="glass card">

            <div class="flex items-center justify-between">

                <div>

                    <div class="text-slate-500 text-sm">

                        Backups réussis

                    </div>

                    <div class="text-4xl font-black mt-2 text-green-600">

                        <?= $successLogs ?>

                    </div>

                </div>

                <div class="w-16 h-16 rounded-2xl bg-green-100 text-green-600 flex items-center justify-center text-3xl">

                    <i class="fa-solid fa-circle-check"></i>

                </div>

            </div>

        </div>

        <!-- ERRORS -->

        <div class="glass card">

            <div class="flex items-center justify-between">

                <div>

                    <div class="text-slate-500 text-sm">

                        Erreurs

                    </div>

                    <div class="text-4xl font-black mt-2 text-red-600">

                        <?= $errorLogs ?>

                    </div>

                </div>

                <div class="w-16 h-16 rounded-2xl bg-red-100 text-red-600 flex items-center justify-center text-3xl">

                    <i class="fa-solid fa-triangle-exclamation"></i>

                </div>

            </div>

        </div>

    </div>

    <!-- LOGS -->

    <div class="glass rounded-3xl overflow-hidden">

        <div class="p-6 border-b border-slate-200 dark:border-slate-700 flex items-center justify-between">

            <h2 class="text-2xl font-black">

                Historique système

            </h2>

            <div class="text-sm text-slate-500">

                <?= $totalLogs ?> événement(s)

            </div>

        </div>

        <div class="max-h-[700px] overflow-y-auto scrollbar p-6 space-y-4">

            <?php if($logs): ?>

                <?php foreach($logs as $log): ?>

                    <?php

                    $class = 'log-info';

                    if (
                        stripos($log, 'SUCCESS') !== false
                    ) {

                        $class = 'log-success';
                    }

                    if (
                        stripos($log, 'ERROR') !== false
                    ) {

                        $class = 'log-error';
                    }

                    ?>

                    <div class="glass rounded-2xl p-5 <?= $class ?>">

                        <div class="flex items-start gap-4">

                            <!-- ICON -->

                            <div class="w-14 h-14 rounded-2xl flex items-center justify-center text-2xl bg-slate-100 dark:bg-slate-800">

                                <?php if(stripos($log, 'SUCCESS') !== false): ?>

                                    <i class="fa-solid fa-circle-check text-green-600"></i>

                                <?php elseif(stripos($log, 'ERROR') !== false): ?>

                                    <i class="fa-solid fa-circle-xmark text-red-600"></i>

                                <?php else: ?>

                                    <i class="fa-solid fa-circle-info text-blue-600"></i>

                                <?php endif; ?>

                            </div>

                            <!-- CONTENT -->

                            <div class="flex-1">

                                <div class="font-mono text-sm break-all">

                                    <?= e($log) ?>

                                </div>

                            </div>

                        </div>

                    </div>

                <?php endforeach; ?>

            <?php else: ?>

                <div class="py-24 text-center">

                    <div class="w-28 h-28 mx-auto rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-5xl text-slate-400">

                        <i class="fa-solid fa-clock"></i>

                    </div>

                    <h2 class="text-3xl font-black mt-6">

                        Aucun log trouvé

                    </h2>

                    <p class="text-slate-500 mt-3">

                        Les événements de sauvegarde apparaîtront ici

                    </p>

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