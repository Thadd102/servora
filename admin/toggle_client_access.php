<?php

require_once "../config/database.php";
require_once "../includes/admin_auth.php";

$redirectTo = $_POST["redirect_to"] ?? "clients.php";
if (!in_array($redirectTo, ["clients.php", "dashboard.php"], true)) {
    $redirectTo = "clients.php";
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: {$redirectTo}");
    exit;
}

$csrfToken = $_POST["csrf_token"] ?? "";

if (
    empty($_SESSION["csrf_token"])
    ||
    !hash_equals($_SESSION["csrf_token"], $csrfToken)
) {
    header("Location: {$redirectTo}?maintenance=security");
    exit;
}

$action = $_POST["action"] ?? "";

if (!in_array($action, ["lock", "unlock"], true)) {
    header("Location: {$redirectTo}?maintenance=invalid");
    exit;
}

$newValue = $action === "lock" ? "0" : "1";

$stmt = $pdo->prepare("
    INSERT INTO app_settings (
        setting_key,
        setting_value,
        updated_at
    )
    VALUES (
        'client_login_enabled',
        ?,
        NOW()
    )
    ON DUPLICATE KEY UPDATE
        setting_value = VALUES(setting_value),
        updated_at = NOW()
");

$stmt->execute([$newValue]);

header(
    "Location: {$redirectTo}?maintenance="
    . ($newValue === "0" ? "locked" : "unlocked")
);
exit;
