<?php
/**
 * API: Push Notification Unsubscribe
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/PushNotificationService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$endpoint = trim((string)($input['endpoint'] ?? ''));
if ($endpoint === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Endpoint required']);
    exit;
}

$service = new PushNotificationService($pdo);
$ok = $service->unsubscribe($endpoint);

echo json_encode(['ok' => $ok]);

