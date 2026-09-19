<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/client-tracking.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$user = currentUser();
if (!$user || ($user['role'] ?? '') !== 'client') {
    http_response_code(403);
    echo json_encode(['error' => 'Connexion client requise.']);
    exit;
}
$bufferLevel = ob_get_level();
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $id = filter_var($_POST['notification_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            http_response_code(400);
            echo json_encode(['error' => 'Notification invalide.']);
            exit;
        }
        $stmt = $pdo->prepare('UPDATE notifications_clients SET lu=1 WHERE id=? AND utilisateur_id=?');
        $stmt->execute([$id, (int)$user['id']]);
    } elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        exit;
    }
    $data = clientTrackingData($pdo, (int)$user['id']);
    if (isset($_GET['orders'])) {
        ob_start();
        renderClientOrders($pdo, (int)$user['id']);
        $data['orders_html'] = ob_get_clean();
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    while (ob_get_level() > $bufferLevel) ob_end_clean();
    error_log('Suivi client : ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Le suivi est temporairement indisponible.']);
}
