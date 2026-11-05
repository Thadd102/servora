<?php
/**
 * API: Push Configuration
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/PushNotificationService.php';

$service = new PushNotificationService($pdo);
$pubKey = $service->getVapidPublicKey();

echo json_encode([
    'ok' => true,
    'publicKey' => $pubKey,
    'appName' => 'Subnext',
]);

