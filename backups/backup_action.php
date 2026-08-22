<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config-settings.php';

requireLogin();

$user = currentUser();

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

$backupDir =
    realpath(__DIR__ . '/../../storage/backups/');

if (!$backupDir) {

    die("Dossier backup introuvable.");
}

/* =========================================================
   ACTION + FILE
========================================================= */

$action =
    $_GET['action'] ?? '';

$file =
    $_GET['file'] ?? '';

/* =========================================================
   VALIDATE FILE
========================================================= */

if (empty($file)) {

    die("Fichier manquant.");
}

/* =========================================================
   PROTECTION
========================================================= */

$file =
    basename($file);

$filePath =
    $backupDir . DIRECTORY_SEPARATOR . $file;

if (!file_exists($filePath)) {

    die("Fichier introuvable.");
}

/* =========================================================
   VALID EXTENSIONS
========================================================= */

$allowedExtensions = [

    'zip',
    'sql'
];

$extension =
    strtolower(
        pathinfo(
            $filePath,
            PATHINFO_EXTENSION
        )
    );

if (!in_array($extension, $allowedExtensions)) {

    die("Extension non autorisée.");
}

/* =========================================================
   LOG FUNCTION
========================================================= */

function addSecurityLog($pdo, $type, $message)
{
    try {

        $stmt = $pdo->prepare("
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

        $stmt->execute([
            $type,
            $message
        ]);

    } catch(Exception $e){

    }
}

/* =========================================================
   DOWNLOAD
========================================================= */

if ($action === 'download') {

    addSecurityLog(
        $pdo,
        'BACKUP_DOWNLOAD',
        'Téléchargement backup : ' . $file
    );

    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');

    header(
        'Content-Disposition: attachment; filename="' .
        basename($filePath) .
        '"'
    );

    header('Expires: 0');

    header('Cache-Control: must-revalidate');

    header('Pragma: public');

    header(
        'Content-Length: ' .
        filesize($filePath)
    );

    readfile($filePath);

    exit;
}

/* =========================================================
   DELETE
========================================================= */

if ($action === 'delete') {

    if (unlink($filePath)) {

        addSecurityLog(
            $pdo,
            'BACKUP_DELETE',
            'Suppression backup : ' . $file
        );

        header(
            "Location: index.php?success=Backup supprimé"
        );

        exit;

    } else {

        header(
            "Location: index.php?error=Erreur suppression"
        );

        exit;
    }
}

/* =========================================================
   RESTORE
========================================================= */

if ($action === 'restore') {

    $tempSqlFile = '';

    try {

        /* =====================================
           EXTRACT ZIP
        ===================================== */

        if ($extension === 'zip') {

            $zip = new ZipArchive();

            if ($zip->open($filePath) === TRUE) {

                $extractDir =
                    $backupDir .
                    '/temp_restore_' .
                    time();

                if (!is_dir($extractDir)) {

                    mkdir(
                        $extractDir,
                        0777,
                        true
                    );
                }

                $zip->extractTo($extractDir);

                $zip->close();

                $sqlFiles =
                    glob($extractDir . '/*.sql');

                if (!$sqlFiles) {

                    die("Aucun fichier SQL trouvé.");
                }

                $tempSqlFile =
                    $sqlFiles[0];

            } else {

                die("Impossible d'ouvrir ZIP.");
            }

        } else {

            $tempSqlFile = $filePath;
        }

        /* =====================================
           READ SQL
        ===================================== */

        $sqlContent =
            file_get_contents(
                $tempSqlFile
            );

        if (!$sqlContent) {

            die("SQL vide.");
        }

        /* =====================================
           DISABLE FK
        ===================================== */

        $pdo->exec("SET FOREIGN_KEY_CHECKS=0");

        /* =====================================
           SPLIT SQL
        ===================================== */

        $queries =
            explode(";\n", $sqlContent);

        foreach ($queries as $query) {

            $query = trim($query);

            if (!empty($query)) {

                $pdo->exec($query);
            }
        }

        /* =====================================
           ENABLE FK
        ===================================== */

        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");

        /* =====================================
           DELETE TEMP
        ===================================== */

        if (
            !empty($extractDir)
            &&
            is_dir($extractDir)
        ) {

            foreach (
                glob($extractDir . '/*')
                as $f
            ) {

                unlink($f);
            }

            rmdir($extractDir);
        }

        /* =====================================
           LOG
        ===================================== */

        addSecurityLog(
            $pdo,
            'BACKUP_RESTORE',
            'Restauration backup : ' . $file
        );

        header(
            "Location: index.php?success=Backup restauré avec succès"
        );

        exit;

    } catch(Exception $e){

        header(
            "Location: index.php?error=" .
            urlencode($e->getMessage())
        );

        exit;
    }
}

/* =========================================================
   INVALID ACTION
========================================================= */

die("Action invalide.");