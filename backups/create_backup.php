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

/* =========================================================
   BACKUP DIRECTORY
========================================================= */

$backupDir = __DIR__ . '/../../storage/backups/';

if (!is_dir($backupDir)) {

    mkdir($backupDir, 0777, true);
}

/* =========================================================
   DATABASE CONFIG
========================================================= */

$dbHost = $host;
$dbName = $dbname;
$dbUser = $username;
$dbPass = $password;

/* =========================================================
   FILE NAME
========================================================= */

$date = date('Y-m-d_H-i-s');

$sqlFileName =
    'backup_' . $date . '.sql';

$zipFileName =
    'backup_' . $date . '.zip';

$sqlFile =
    $backupDir . $sqlFileName;

$zipFile =
    $backupDir . $zipFileName;

/* =========================================================
   CREATE SQL BACKUP
========================================================= */

$message = '';
$success = false;

try {

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $tables = [];

    $stmtTables = $pdo->query("SHOW TABLES");

    while ($row = $stmtTables->fetch(PDO::FETCH_NUM)) {

        $tables[] = $row[0];
    }

    $sqlScript = '';

    $sqlScript .= "-- =====================================\n";
    $sqlScript .= "-- POS PREMIUM BACKUP\n";
    $sqlScript .= "-- Date : " . date('Y-m-d H:i:s') . "\n";
    $sqlScript .= "-- =====================================\n\n";

    foreach ($tables as $table) {

        /* =====================================
           TABLE STRUCTURE
        ===================================== */

        $stmtCreate = $pdo->query(
            "SHOW CREATE TABLE `$table`"
        );

        $createRow = $stmtCreate->fetch(PDO::FETCH_ASSOC);

        $sqlScript .= "\n\n";
        $sqlScript .= "-- TABLE : $table\n\n";

        $sqlScript .= "DROP TABLE IF EXISTS `$table`;\n";

        $sqlScript .= $createRow['Create Table'] . ";\n\n";

        /* =====================================
           TABLE DATA
        ===================================== */

        $stmtData = $pdo->query(
            "SELECT * FROM `$table`"
        );

        while ($row = $stmtData->fetch(PDO::FETCH_ASSOC)) {

            $columns = array_keys($row);

            $columns = array_map(function($col){

                return "`$col`";

            }, $columns);

            $columnsList =
                implode(',', $columns);

            $values = array_values($row);

            $escapedValues = array_map(function($value) use ($pdo){

                if ($value === null) {

                    return "NULL";
                }

                return $pdo->quote($value);

            }, $values);

            $valuesList =
                implode(',', $escapedValues);

            $sqlScript .=
                "INSERT INTO `$table` ($columnsList) VALUES ($valuesList);\n";
        }

        $sqlScript .= "\n";
    }

    /* =====================================
       SAVE SQL FILE
    ===================================== */

    file_put_contents(
        $sqlFile,
        $sqlScript
    );

    /* =====================================
       CREATE ZIP
    ===================================== */

    $zip = new ZipArchive();

    if (
        $zip->open(
            $zipFile,
            ZipArchive::CREATE
        ) === TRUE
    ) {

        $zip->addFile(
            $sqlFile,
            $sqlFileName
        );

        $zip->close();
    }

    /* =====================================
       DELETE SQL FILE
    ===================================== */

    if (file_exists($sqlFile)) {

        unlink($sqlFile);
    }

    /* =====================================
       LOG SECURITY
    ===================================== */

    try {

        $log = $pdo->prepare("
            INSERT INTO securite_logs
            (
                type,
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

        $log->execute([

            'BACKUP',

            'Création backup : ' . $zipFileName
        ]);

    } catch(Exception $e){

    }

    $success = true;

    $message =
        "✅ Backup créé avec succès.";

} catch(Exception $e){

    $success = false;

    $message =
        "❌ Erreur : " . $e->getMessage();
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

Création Backup

</title>

<link rel="stylesheet" href="../assets/tailwind.css">

<link
rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
onerror="this.onerror=null;this.href='../assets/vendor/fontawesome.min.css';"
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

.glass{

    background:rgba(255,255,255,.88);

    backdrop-filter:blur(16px);

    border:1px solid rgba(255,255,255,.25);

    box-shadow:
    0 10px 30px rgba(0,0,0,.08);
}

.loader{

    width:90px;
    height:90px;

    border-radius:999px;

    border:8px solid #dbeafe;

    border-top-color:#2563eb;

    animation:spin 1s linear infinite;
}

@keyframes spin{

    to{

        transform:rotate(360deg);
    }
}

</style>

</head>

<body class="min-h-screen flex items-center justify-center p-6 text-slate-800">

<div class="glass rounded-3xl p-10 w-full max-w-2xl text-center">

    <!-- ICON -->

    <div class="flex justify-center mb-6">

        <?php if($success): ?>

            <div class="w-28 h-28 rounded-full bg-green-100 text-green-600 flex items-center justify-center text-5xl shadow-xl">

                <i class="fa-solid fa-circle-check"></i>

            </div>

        <?php else: ?>

            <div class="w-28 h-28 rounded-full bg-red-100 text-red-600 flex items-center justify-center text-5xl shadow-xl">

                <i class="fa-solid fa-circle-xmark"></i>

            </div>

        <?php endif; ?>

    </div>

    <!-- TITLE -->

    <h1 class="text-4xl font-black mb-4">

        Création Backup

    </h1>

    <!-- MESSAGE -->

    <div class="text-xl font-semibold mb-8">

        <?= e($message) ?>

    </div>

    <!-- INFO -->

    <?php if($success): ?>

    <div class="bg-slate-100 rounded-2xl p-6 text-left mb-8">

        <div class="mb-4 flex items-center gap-3">

            <i class="fa-solid fa-file-zipper text-blue-600 text-2xl"></i>

            <div>

                <div class="font-bold">

                    Fichier ZIP

                </div>

                <div class="text-slate-500">

                    <?= e($zipFileName) ?>

                </div>

            </div>

        </div>

        <div class="mb-4 flex items-center gap-3">

            <i class="fa-solid fa-calendar text-purple-600 text-2xl"></i>

            <div>

                <div class="font-bold">

                    Date

                </div>

                <div class="text-slate-500">

                    <?= date('d/m/Y H:i:s') ?>

                </div>

            </div>

        </div>

        <div class="flex items-center gap-3">

            <i class="fa-solid fa-hard-drive text-green-600 text-2xl"></i>

            <div>

                <div class="font-bold">

                    Taille

                </div>

                <div class="text-slate-500">

                    <?= filesize($zipFile) > 0 ? round(filesize($zipFile)/1024/1024,2) . ' MB' : '0 MB' ?>

                </div>

            </div>

        </div>

    </div>

    <?php endif; ?>

    <!-- ACTIONS -->

    <div class="flex flex-wrap justify-center gap-4">

        <!-- BACK -->

        <a
            href="index.php"
            class="px-6 py-4 rounded-2xl bg-slate-900 text-white font-bold hover:scale-105 transition"
        >

            <i class="fa-solid fa-arrow-left mr-2"></i>

            Retour

        </a>

        <?php if($success): ?>

        <!-- DOWNLOAD -->

        <a
            href="download_backup.php?file=<?= urlencode($zipFileName) ?>"
            class="px-6 py-4 rounded-2xl bg-blue-600 text-white font-bold hover:scale-105 transition"
        >

            <i class="fa-solid fa-download mr-2"></i>

            Télécharger

        </a>

        <!-- CREATE AGAIN -->

        <a
            href="create_backup.php"
            class="px-6 py-4 rounded-2xl bg-green-600 text-white font-bold hover:scale-105 transition"
        >

            <i class="fa-solid fa-rotate mr-2"></i>

            Nouveau Backup

        </a>

        <?php endif; ?>

    </div>

</div>

</body>
</html>