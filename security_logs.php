<?php

require_once 'config.php';

requireLogin();

/* =========================================================
   USER
========================================================= */

$user = currentUser();

$user_id =
    $user['id'];

$isAdmin =
    ($user['role'] ?? '') === 'admin';

/* =========================================================
   FILTERS
========================================================= */

$search =
    trim($_GET['search'] ?? '');

$type =
    trim($_GET['type'] ?? '');

$date =
    trim($_GET['date'] ?? '');

/* =========================================================
   PAGINATION
========================================================= */

$page =
    max(1, (int)($_GET['page'] ?? 1));

$limit = 20;

$offset =
    ($page - 1) * $limit;

/* =========================================================
   QUERY LOGS
========================================================= */

$sql = "
SELECT *
FROM securite_logs
WHERE 1=1
";

$params = [];

/* =========================================================
   SEARCH
========================================================= */

if($search !== ''){

    $sql .= "
    AND
    (
        type LIKE ?
        OR message LIKE ?
        OR ip LIKE ?
    )
    ";

    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

/* =========================================================
   TYPE
========================================================= */

if($type !== ''){

    $sql .= "
    AND type = ?
    ";

    $params[] = $type;
}

/* =========================================================
   DATE
========================================================= */

if($date !== ''){

    $sql .= "
    AND DATE(created_at)=?
    ";

    $params[] = $date;
}

/* =========================================================
   TOTAL
========================================================= */

$countSql = "
SELECT COUNT(*) FROM (
    $sql
) x
";

$countStmt =
    $pdo->prepare($countSql);

$countStmt->execute($params);

$totalLogs =
    $countStmt->fetchColumn();

$totalPages =
    ceil($totalLogs / $limit);

/* =========================================================
   ORDER + LIMIT
========================================================= */

$sql .= "
ORDER BY id DESC
LIMIT $limit OFFSET $offset
";

$stmt =
    $pdo->prepare($sql);

$stmt->execute($params);

$logs =
    $stmt->fetchAll();

/* =========================================================
   LOGIN ATTEMPTS
========================================================= */

$stmtLogin = $pdo->prepare("
    SELECT *
    FROM securite_login
    ORDER BY id DESC
    LIMIT 50
");

$stmtLogin->execute();

$loginAttempts =
    $stmtLogin->fetchAll();

/* =========================================================
   STATS
========================================================= */

$todayLogs = 0;
$dangerLogs = 0;
$totalTentatives = 0;

try{

    $todayLogs =
        $pdo->query("
            SELECT COUNT(*)
            FROM securite_logs
            WHERE DATE(created_at)=CURDATE()
        ")->fetchColumn();

}catch(Exception $e){}

try{

    $dangerLogs =
        $pdo->query("
            SELECT COUNT(*)
            FROM securite_logs
            WHERE type LIKE '%ERROR%'
            OR type LIKE '%DANGER%'
            OR type LIKE '%FAILED%'
        ")->fetchColumn();

}catch(Exception $e){}

try{

    $totalTentatives =
        $pdo->query("
            SELECT COUNT(*)
            FROM securite_login
        ")->fetchColumn();

}catch(Exception $e){}

/* =========================================================
   HEADER
========================================================= */

include 'includes/header.php';

include 'includes/sidebar.php';

?>

<div class="p-4 md:p-6">

    <!-- TITLE -->

    <div class="flex flex-col md:flex-row justify-between gap-4 mb-6">

        <div>

            <h1 class="text-3xl font-black text-gray-800 dark:text-white">

                🔐 Security Logs

            </h1>

            <p class="text-gray-500 mt-2">

                Surveillance complète des activités de sécurité

            </p>

        </div>

        <div class="bg-red-100 text-red-700 px-5 py-4 rounded-2xl font-bold shadow">

            🚨
            <?= $dangerLogs ?>
            alertes détectées

        </div>

    </div>

    <!-- STATS -->

    <div class="grid md:grid-cols-3 gap-5 mb-6">

        <div class="bg-white dark:bg-slate-900 rounded-3xl shadow border p-5">

            <div class="text-sm text-gray-500">

                Logs aujourd'hui

            </div>

            <div class="text-4xl font-black mt-3 text-blue-600">

                <?= $todayLogs ?>

            </div>

        </div>

        <div class="bg-white dark:bg-slate-900 rounded-3xl shadow border p-5">

            <div class="text-sm text-gray-500">

                Tentatives login

            </div>

            <div class="text-4xl font-black mt-3 text-yellow-500">

                <?= $totalTentatives ?>

            </div>

        </div>

        <div class="bg-white dark:bg-slate-900 rounded-3xl shadow border p-5">

            <div class="text-sm text-gray-500">

                Activités critiques

            </div>

            <div class="text-4xl font-black mt-3 text-red-600">

                <?= $dangerLogs ?>

            </div>

        </div>

    </div>

    <!-- FILTERS -->

    <div class="bg-white dark:bg-slate-900 rounded-3xl shadow border p-5 mb-6">

        <form method="GET" class="grid md:grid-cols-4 gap-4">

            <!-- SEARCH -->

            <div>

                <input
                    type="text"
                    name="search"
                    value="<?= e($search) ?>"
                    placeholder="Recherche..."
                    class="w-full border dark:border-slate-700 dark:bg-slate-800 p-4 rounded-2xl"
                >

            </div>

            <!-- TYPE -->

            <div>

                <select
                    name="type"
                    class="w-full border dark:border-slate-700 dark:bg-slate-800 p-4 rounded-2xl"
                >

                    <option value="">
                        Tous types
                    </option>

                    <option
                        value="LOGIN"
                        <?= $type=='LOGIN' ? 'selected' : '' ?>
                    >
                        LOGIN
                    </option>

                    <option
                        value="LOGOUT"
                        <?= $type=='LOGOUT' ? 'selected' : '' ?>
                    >
                        LOGOUT
                    </option>

                    <option
                        value="FAILED_LOGIN"
                        <?= $type=='FAILED_LOGIN' ? 'selected' : '' ?>
                    >
                        FAILED LOGIN
                    </option>

                </select>

            </div>

            <!-- DATE -->

            <div>

                <input
                    type="date"
                    name="date"
                    value="<?= e($date) ?>"
                    class="w-full border dark:border-slate-700 dark:bg-slate-800 p-4 rounded-2xl"
                >

            </div>

            <!-- BTN -->

            <div class="flex gap-3">

                <button
                    class="bg-black text-white px-6 py-4 rounded-2xl font-bold w-full"
                >

                    🔎 Filtrer

                </button>

                <a
                    href="security_logs.php"
                    class="bg-gray-200 dark:bg-slate-700 px-5 py-4 rounded-2xl font-bold"
                >

                    ↺

                </a>

            </div>

        </form>

    </div>

    <!-- LOGS TABLE -->

    <div class="bg-white dark:bg-slate-900 rounded-3xl shadow border overflow-hidden mb-8">

        <div class="p-5 border-b dark:border-slate-800">

            <h2 class="text-2xl font-bold">

                📜 Logs sécurité

            </h2>

        </div>

        <div class="overflow-x-auto">

            <table class="w-full">

                <thead class="bg-gray-50 dark:bg-slate-800">

                    <tr>

                        <th class="p-4 text-left">
                            Type
                        </th>

                        <th class="p-4 text-left">
                            Message
                        </th>

                        <th class="p-4 text-left">
                            IP
                        </th>

                        <th class="p-4 text-left">
                            Navigateur
                        </th>

                        <th class="p-4 text-left">
                            Date
                        </th>

                    </tr>

                </thead>

                <tbody>

                    <?php if($logs): ?>

                        <?php foreach($logs as $log): ?>

                        <?php

                        $badge =
                            'bg-blue-100 text-blue-700';

                        if(
                            str_contains(
                                strtoupper($log['type']),
                                'FAILED'
                            )
                            ||
                            str_contains(
                                strtoupper($log['type']),
                                'ERROR'
                            )
                        ){

                            $badge =
                                'bg-red-100 text-red-700';
                        }

                        ?>

                        <tr class="border-t dark:border-slate-800 hover:bg-gray-50 dark:hover:bg-slate-800 transition">

                            <!-- TYPE -->

                            <td class="p-4">

                                <span class="px-3 py-1 rounded-full text-xs font-bold <?= $badge ?>">

                                    <?= e($log['type']) ?>

                                </span>

                            </td>

                            <!-- MESSAGE -->

                            <td class="p-4">

                                <?= e($log['message']) ?>

                            </td>

                            <!-- IP -->

                            <td class="p-4 text-sm font-semibold">

                                <?= e($log['ip']) ?>

                            </td>

                            <!-- USER AGENT -->

                            <td class="p-4 text-xs max-w-xs">

                                <div class="truncate">

                                    <?= e($log['user_agent']) ?>

                                </div>

                            </td>

                            <!-- DATE -->

                            <td class="p-4 text-sm">

                                <?= date(
                                    'd/m/Y H:i',
                                    strtotime($log['created_at'])
                                ) ?>

                            </td>

                        </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>

                            <td colspan="5" class="p-10 text-center text-gray-500">

                                🔒 Aucun log trouvé

                            </td>

                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

    <!-- LOGIN ATTEMPTS -->

    <div class="bg-white dark:bg-slate-900 rounded-3xl shadow border overflow-hidden">

        <div class="p-5 border-b dark:border-slate-800">

            <h2 class="text-2xl font-bold text-red-600">

                🚨 Tentatives de connexion

            </h2>

        </div>

        <div class="overflow-x-auto">

            <table class="w-full">

                <thead class="bg-red-50 dark:bg-slate-800">

                    <tr>

                        <th class="p-4 text-left">
                            Email
                        </th>

                        <th class="p-4 text-left">
                            IP
                        </th>

                        <th class="p-4 text-left">
                            Tentatives
                        </th>

                        <th class="p-4 text-left">
                            Bloqué jusqu'à
                        </th>

                        <th class="p-4 text-left">
                            Date
                        </th>

                    </tr>

                </thead>

                <tbody>

                    <?php if($loginAttempts): ?>

                        <?php foreach($loginAttempts as $attempt): ?>

                        <tr class="border-t dark:border-slate-800 hover:bg-gray-50 dark:hover:bg-slate-800 transition">

                            <td class="p-4 font-semibold">

                                <?= e($attempt['email']) ?>

                            </td>

                            <td class="p-4">

                                <?= e($attempt['ip']) ?>

                            </td>

                            <td class="p-4">

                                <span class="bg-red-100 text-red-700 px-3 py-1 rounded-full font-bold">

                                    <?= e($attempt['tentatives']) ?>

                                </span>

                            </td>

                            <td class="p-4">

                                <?= e($attempt['bloque_jusqu'] ?? '-') ?>

                            </td>

                            <td class="p-4 text-sm">

                                <?= date(
                                    'd/m/Y H:i',
                                    strtotime($attempt['created_at'])
                                ) ?>

                            </td>

                        </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>

                            <td colspan="5" class="p-10 text-center text-gray-500">

                                ✅ Aucune tentative suspecte

                            </td>

                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

    <!-- PAGINATION -->

    <?php if($totalPages > 1): ?>

    <div class="flex justify-center gap-2 mt-6 flex-wrap">

        <?php for($i=1; $i <= $totalPages; $i++): ?>

            <a
                href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&type=<?= urlencode($type) ?>&date=<?= urlencode($date) ?>"
                class="px-5 py-3 rounded-2xl font-bold shadow
                <?= $page == $i
                    ? 'bg-blue-600 text-white'
                    : 'bg-white dark:bg-slate-900 border dark:border-slate-700'
                ?>"
            >

                <?= $i ?>

            </a>

        <?php endfor; ?>

    </div>

    <?php endif; ?>

</div>

<?php include 'includes/footer.php'; ?>