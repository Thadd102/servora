<?php
/**
 * Subnext Root Announcements Route Bridge
 * Ensures both /announcements.php and /admin/announcements.php work seamlessly online.
 */

require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/includes/auth.php";

if (isLoggedIn()) {
    $role = currentUserRole();
    if (in_array($role, ['admin', 'super_admin'], true)) {
        header("Location: admin/announcements.php" . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
        exit;
    }
    // Clients are routed to client notification center
    header("Location: client/notifications.php");
    exit;
}

// Guest users routed to login with return parameter
$redirectUrl = 'admin/announcements.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
header("Location: login.php?redirect=" . urlencode($redirectUrl));
exit;

