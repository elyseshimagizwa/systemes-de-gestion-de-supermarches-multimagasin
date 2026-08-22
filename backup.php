<?php
require_once 'config.php';
requireLogin();
requireRole('admin');

$filename = "backup_" . date("Y-m-d_H-i-s") . ".sql";
header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="$filename"');

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

foreach ($tables as $table) {
    $create = $pdo->query("SHOW CREATE TABLE $table")->fetch()[1];
    echo "\n\n-- TABLE $table\n";
    echo $create . ";\n\n";

    $rows = $pdo->query("SELECT * FROM $table");

    while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
        $values = array_map([$pdo,'quote'], array_values($row));
        echo "INSERT INTO $table VALUES (" . implode(',', $values) . ");\n";
    }
}
exit;