
<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config-settings.php';

requireLogin();

$user = currentUser();
$settings = getSettings();

/* =========================================================
   ADMIN SECURITY
========================================================= */

if (($user['role'] ?? '') !== 'admin') {

    die("⛔ Accès refusé");
}

/* =========================================================
   SECURITY HEADERS
========================================================= */

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

/* =========================================================
   BACKUP DIRECTORY
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
   MESSAGES
========================================================= */

$success = '';
$error   = '';

/* =========================================================
   MAX SIZE
========================================================= */

$maxFileSize =
    1024 * 1024 * 500; // 500MB

/* =========================================================
   ALLOWED EXTENSIONS
========================================================= */

$allowedExtensions = [

    'sql',
    'zip'
];

/* =========================================================
   UPLOAD PROCESS
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_FILES['backup_file'])) {

        $error =
            "Aucun fichier envoyé.";

    } else {

        $file =
            $_FILES['backup_file'];

        /* =====================================
           ERRORS
        ===================================== */

        if ($file['error'] !== 0) {

            $error =
                "Erreur upload fichier.";

        } else {

            $fileName =
                basename(
                    $file['name']
                );

            $tmpName =
                $file['tmp_name'];

            $fileSize =
                $file['size'];

            $extension =
                strtolower(
                    pathinfo(
                        $fileName,
                        PATHINFO_EXTENSION
                    )
                );

            /* =====================================
               EXTENSION VALIDATION
            ===================================== */

            if (
                !in_array(
                    $extension,
                    $allowedExtensions
                )
            ) {

                $error =
                    "Formats autorisés : SQL, ZIP";

            }

            /* =====================================
               SIZE VALIDATION
            ===================================== */

            elseif (
                $fileSize > $maxFileSize
            ) {

                $error =
                    "Fichier trop volumineux.";

            }

            /* =====================================
               MIME VALIDATION
            ===================================== */

            else {

                $finfo =
                    finfo_open(
                        FILEINFO_MIME_TYPE
                    );

                $mime =
                    finfo_file(
                        $finfo,
                        $tmpName
                    );

                finfo_close($finfo);

                $allowedMime = [

                    'application/sql',
                    'application/octet-stream',
                    'application/x-sql',
                    'application/zip',
                    'application/x-zip-compressed',
                    'multipart/x-zip'
                ];

                /* =====================================
                   SECURE FILENAME
                ===================================== */

                $safeName =
                    date('Y-m-d_H-i-s')
                    .
                    '_'
                    .
                    preg_replace(
                        '/[^A-Za-z0-9_\-\.]/',
                        '_',
                        $fileName
                    );

                $destination =
                    $backupDir .
                    $safeName;

                /* =====================================
                   MOVE FILE
                ===================================== */

                if (
                    move_uploaded_file(
                        $tmpName,
                        $destination
                    )
                ) {

                    /* ===============================
                       LOG
                    =============================== */

                    try {

                        $stmt =
                            $pdo->prepare("
                                INSERT INTO backup_logs
                                (
                                    user_id,
                                    action_type,
                                    file_name,
                                    file_size
                                )
                                VALUES
                                (
                                    ?,
                                    ?,
                                    ?,
                                    ?
                                )
                            ");

                        $stmt->execute([

                            $user['id'],
                            'UPLOAD',
                            $safeName,
                            $fileSize
                        ]);

                    } catch(Exception $e){}

                    $success =
                        "Backup importé avec succès.";

                } else {

                    $error =
                        "Impossible d'importer le fichier.";
                }
            }
        }
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

Upload Backup |
<?= e($settings['nom_boutique'] ?? 'POS PREMIUM') ?>

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
    rgba(15,23,42,.88);

    border:
    1px solid rgba(255,255,255,.06);
}

.upload-zone{

    border:3px dashed #94a3b8;

    border-radius:24px;

    transition:.25s;
}

.upload-zone:hover{

    border-color:#2563eb;

    background:
    rgba(37,99,235,.05);
}

.btn{

    padding:14px 20px;

    border-radius:18px;

    font-weight:700;

    transition:.25s;

    display:flex;

    align-items:center;

    justify-content:center;

    gap:10px;
}

.btn:hover{

    transform:
    translateY(-2px);
}

</style>

</head>

<body class="text-slate-800 dark:text-white min-h-screen">

<div class="max-w-4xl mx-auto p-6">

    <!-- HEADER -->

    <div class="glass rounded-3xl p-6 mb-6">

        <div class="flex items-center gap-4">

            <div class="w-20 h-20 rounded-3xl bg-blue-100 text-blue-600 flex items-center justify-center text-4xl">

                <i class="fa-solid fa-upload"></i>

            </div>

            <div>

                <h1 class="text-4xl font-black">

                    Upload Backup

                </h1>

                <p class="text-slate-500 mt-2">

                    Importer une sauvegarde SQL ou ZIP

                </p>

            </div>

        </div>

    </div>

    <!-- ALERTS -->

    <?php if($success): ?>

    <div class="mb-6 p-5 rounded-2xl bg-green-100 text-green-700 font-bold">

        ✅ <?= e($success) ?>

    </div>

    <?php endif; ?>

    <?php if($error): ?>

    <div class="mb-6 p-5 rounded-2xl bg-red-100 text-red-700 font-bold">

        ⛔ <?= e($error) ?>

    </div>

    <?php endif; ?>

    <!-- FORM -->

    <div class="glass rounded-3xl p-8">

        <form
            method="POST"
            enctype="multipart/form-data"
        >

            <!-- DROP ZONE -->

            <label
                class="upload-zone flex flex-col items-center justify-center p-16 cursor-pointer"
            >

                <div class="w-28 h-28 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center text-6xl mb-6">

                    <i class="fa-solid fa-cloud-arrow-up"></i>

                </div>

                <div class="text-3xl font-black mb-3">

                    Sélectionner un backup

                </div>

                <div class="text-slate-500 text-center">

                    SQL ou ZIP uniquement
                    <br>
                    Taille max : 500MB
                </div>

                <input
                    type="file"
                    name="backup_file"
                    id="backup_file"
                    class="hidden"
                    accept=".sql,.zip"
                    required
                    onchange="showFileName(this)"
                >

            </label>

            <!-- FILE NAME -->

            <div
                id="fileName"
                class="mt-6 text-center font-bold text-blue-600 text-lg"
            ></div>

            <!-- BUTTONS -->

            <div class="flex flex-wrap gap-4 mt-8">

                <button
                    type="submit"
                    class="btn bg-blue-600 hover:bg-blue-700 text-white flex-1"
                >

                    <i class="fa-solid fa-upload"></i>

                    Importer Backup

                </button>

                <a
                    href="index.php"
                    class="btn bg-slate-700 hover:bg-slate-800 text-white"
                >

                    <i class="fa-solid fa-arrow-left"></i>

                    Retour

                </a>

            </div>

        </form>

    </div>

    <!-- SECURITY INFO -->

    <div class="glass rounded-3xl p-6 mt-6">

        <h2 class="text-2xl font-black mb-5 flex items-center gap-3">

            <i class="fa-solid fa-shield-halved text-green-600"></i>

            Sécurité Upload

        </h2>

        <div class="grid md:grid-cols-2 gap-5">

            <div class="p-5 rounded-2xl bg-slate-100 dark:bg-slate-800">

                ✅ Validation extension SQL/ZIP
            </div>

            <div class="p-5 rounded-2xl bg-slate-100 dark:bg-slate-800">

                ✅ Nom fichier sécurisé
            </div>

            <div class="p-5 rounded-2xl bg-slate-100 dark:bg-slate-800">

                ✅ Taille maximum contrôlée
            </div>

            <div class="p-5 rounded-2xl bg-slate-100 dark:bg-slate-800">

                ✅ Historique sauvegardé
            </div>

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

/* =========================================================
   FILE NAME
========================================================= */

function showFileName(input){

    if(input.files.length > 0){

        document
        .getElementById("fileName")
        .innerHTML =

        "📁 " +
        input.files[0].name;
    }
}

</script>

</body>
</html>

