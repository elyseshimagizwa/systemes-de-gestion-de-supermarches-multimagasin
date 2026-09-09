<?php
if (PHP_SAPI === 'cli') {
    ini_set('session.save_path', sys_get_temp_dir());
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/stock-alerts.php';

if (PHP_SAPI !== 'cli') {
    requireAdmin();
}

$result = sendLowStockSupplierAlerts($pdo, getSettings());
echo 'Alertes envoyees: ' . $result['sent'] . ', erreurs: ' . $result['failed'] . ', resolues: ' . $result['resolved'] . PHP_EOL;