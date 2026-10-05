<?php

require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/includes/auth.php";

// Check maintenance mode
$stmt = $pdo->prepare("
    SELECT setting_value
    FROM app_settings
    WHERE setting_key = 'client_login_enabled'
    LIMIT 1
");
$stmt->execute();
$setting = $stmt->fetchColumn();
$isMaintenanceActive = ($setting !== false && (string)$setting === "0");

if (isLoggedIn()) {
    $role = currentUserRole();
    if ($role === 'client') {
        if ($isMaintenanceActive) {
            logoutUser();
            header("Location: login.php?error=maintenance");
            exit;
        }
        header("Location: client/dashboard.php");
        exit;
    } elseif (in_array($role, ['admin', 'super_admin'], true)) {
        header("Location: admin/dashboard.php");
        exit;
    }
}

// Guest visitor
header("Location: login.php" . ($isMaintenanceActive ? "?error=maintenance" : ""));
exit;