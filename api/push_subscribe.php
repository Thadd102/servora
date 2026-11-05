<?php
/**
 * API: Push Notification Subscription
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
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

$subscription = $input['subscription'] ?? $input;
if (empty($subscription['endpoint'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid push subscription payload']);
    exit;
}

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

$service = new PushNotificationService($pdo);
$res = $service->saveSubscription($userId, $subscription, $userAgent);

echo json_encode($res);

