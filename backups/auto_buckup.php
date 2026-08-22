<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config-settings.php';

/* =========================================================
   SECURITY
========================================================= */

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');

set_time_limit(0);

date_default_timezone_set('Africa/Bujumbura');

/* =========================================================
   SETTINGS
========================================================= */

$backupDir =
    __DIR__ .
    '/../../storage/backups/';

if (!is_dir($backupDir)) {

    mkdir(
        $backupDir,
        0777,
        true
    );
}

/* =========================================================
   DATABASE CONFIG
========================================================= */

$dbHost =
    DB_HOST;

$dbName =
    DB_NAME;

$dbUser =
    DB_USER;

$dbPass =
    DB_PASS;

/* =========================================================
   BACKUP FILE NAME
========================================================= */

$date =
    date('Y-m-d_H-i-s');

$sqlFileName =
    'auto_backup_' .
    $date .
    '.sql';

$sqlFilePath =
    $backupDir .
    $sqlFileName;

$zipFileName =
    'auto_backup_' .
    $date .
    '.zip';

$zipFilePath =
    $backupDir .
    $zipFileName;

/* =========================================================
   CREATE SQL BACKUP
========================================================= */

try {

    $tables = [];

    $stmt =
        $pdo->query("SHOW TABLES");

    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {

        $tables[] = $row[0];
    }

    $sqlScript = '';

    $sqlScript .=
        "-- =====================================\n";

    $sqlScript .=
        "-- AUTO BACKUP\n";

    $sqlScript .=
        "-- Date : " .
        date('Y-m-d H:i:s') .
        "\n";

    $sqlScript .=
        "-- =====================================\n\n";

    foreach ($tables as $table) {

        /* =====================================
           CREATE TABLE
        ===================================== */

        $stmtCreate =
            $pdo->query(
                "SHOW CREATE TABLE `$table`"
            );

        $createData =
            $stmtCreate->fetch(PDO::FETCH_ASSOC);

        $createSql =
            $createData['Create Table'] ?? '';

        $sqlScript .=
            "DROP TABLE IF EXISTS `$table`;\n";

        $sqlScript .=
            $createSql . ";\n\n";

        /* =====================================
           EXPORT DATA
        ===================================== */

        $stmtData =
            $pdo->query(
                "SELECT * FROM `$table`"
            );

        while (
            $row =
            $stmtData->fetch(PDO::FETCH_ASSOC)
        ) {

            $columns =
                array_keys($row);

            $columns =
                array_map(function($c){

                    return "`$c`";

                }, $columns);

            $values = [];

            foreach ($row as $value) {

                if ($value === null) {

                    $values[] = "NULL";

                } else {

                    $values[] =
                        $pdo->quote($value);
                }
            }

            $sqlScript .=
                "INSERT INTO `$table` (" .
                implode(',', $columns) .
                ") VALUES (" .
                implode(',', $values) .
                ");\n";
        }

        $sqlScript .= "\n\n";
    }

    /* =====================================
       SAVE SQL FILE
    ===================================== */

    file_put_contents(
        $sqlFilePath,
        $sqlScript
    );

    /* =====================================
       CREATE ZIP
    ===================================== */

    $zip = new ZipArchive();

    if (
        $zip->open(
            $zipFilePath,
            ZipArchive::CREATE
        ) === TRUE
    ) {

        $zip->addFile(
            $sqlFilePath,
            $sqlFileName
        );

        $zip->close();
    }

    /* =====================================
       DELETE SQL
    ===================================== */

    if (file_exists($sqlFilePath)) {

        unlink($sqlFilePath);
    }

    /* =====================================
       DELETE OLD BACKUPS
    ===================================== */

    $files =
        glob($backupDir . '*.zip');

    $maxFiles = 10;

    if (
        count($files) > $maxFiles
    ) {

        usort($files, function($a, $b){

            return filemtime($a)
                -
                filemtime($b);

        });

        $deleteCount =
            count($files) - $maxFiles;

        for (
            $i = 0;
            $i < $deleteCount;
            $i++
        ) {

            if (file_exists($files[$i])) {

                unlink($files[$i]);
            }
        }
    }

    /* =====================================
       SECURITY LOG
    ===================================== */

    try {

        $stmtLog =
            $pdo->prepare("
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

        $stmtLog->execute([

            'AUTO_BACKUP',

            'Sauvegarde automatique créée : ' .
            $zipFileName
        ]);

    } catch(Exception $e){

    }

    /* =====================================
       SUCCESS
    ===================================== */

    echo "✅ Backup automatique créé : " .
         $zipFileName;

} catch(Exception $e){

    echo "❌ Erreur backup : " .
         $e->getMessage();
}