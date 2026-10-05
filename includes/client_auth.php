<?php

require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/../config/database.php";

// Only clients can access this page
requireRole(['client']);

// ------------------------------------------------------------
// GLOBAL CLIENT ACCESS / MAINTENANCE MODE
// When client_login_enabled = 0, every client session is ended
// on the next authenticated request. Admins are not affected.
// ------------------------------------------------------------

$stmt = $pdo->prepare("
    SELECT setting_value
    FROM app_settings
    WHERE setting_key = 'client_login_enabled'
    LIMIT 1
");
$stmt->execute();

$clientLoginEnabled = $stmt->fetchColumn();

// Fail closed if the setting exists and is OFF (0).
// If the row is missing, normal client access remains enabled.
if ($clientLoginEnabled !== false && (string) $clientLoginEnabled === "0") {

    logoutUser();

    // Handle AJAX or JSON requests gracefully
    if (
        (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    ) {
        http_response_code(503);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error',
            'message' => 'Subnext is temporarily under maintenance. Client access is paused.',
            'maintenance' => true
        ]);
        exit;
    }

    header("Location: ../login.php?error=maintenance");
    exit;
}
