<?php
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/client_auth.php";
require_once __DIR__ . "/../includes/UtilityProvider.php";

header('Content-Type: application/json');

$userId = currentUserId();
$action = trim((string)($_GET['action'] ?? ''));

$provider = new UtilityProvider();

if ($action === 'validate_meter') {
    $disco = trim((string)($_GET['disco'] ?? ''));
    $meter = trim((string)($_GET['meter'] ?? ''));
    $type = trim((string)($_GET['type'] ?? 'prepaid'));

    if (empty($disco) || empty($meter)) {
        echo json_encode(['ok' => false, 'message' => 'Please provide disco and meter number.']);
        exit;
    }

    $res = $provider->validateMeter($disco, $meter, $type);
    echo json_encode($res);
    exit;
}

if ($action === 'validate_cable') {
    $cable = trim((string)($_GET['provider'] ?? ''));
    $iuc = trim((string)($_GET['iuc'] ?? ''));

    if (empty($cable) || empty($iuc)) {
        echo json_encode(['ok' => false, 'message' => 'Please provide cable provider and IUC number.']);
        exit;
    }

    $res = $provider->validateSmartCard($cable, $iuc);
    echo json_encode($res);
    exit;
}

echo json_encode(['ok' => false, 'message' => 'Invalid action']);
exit;
