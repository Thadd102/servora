<?php
/**
 * API: Dismiss Announcement
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/AnnouncementService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$announcementId = isset($input['announcement_id']) ? (int)$input['announcement_id'] : 0;
if ($announcementId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid announcement ID']);
    exit;
}

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$guestToken = null;
if ($userId === null && !empty($_COOKIE['sn_guest_id'])) {
    $guestToken = preg_replace('/[^a-zA-Z0-9]/', '', (string)$_COOKIE['sn_guest_id']);
}

$service = new AnnouncementService($pdo);
$ok = $service->dismiss($announcementId, $userId, $guestToken);

echo json_encode(['ok' => $ok]);

