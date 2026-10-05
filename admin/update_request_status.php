<?php

require_once "../config/database.php";
require_once "../includes/admin_auth.php";

// Only allow POST requests
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    die("Method not allowed.");
}

// --------------------------------------------------
// CSRF PROTECTION
// --------------------------------------------------

if (
    !isset($_POST["csrf_token"], $_SESSION["csrf_token"]) ||
    !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
) {
    die("Invalid request.");
}

// --------------------------------------------------
// GET AND VALIDATE REQUEST ID
// --------------------------------------------------

$requestId = filter_input(
    INPUT_POST,
    "request_id",
    FILTER_VALIDATE_INT
);

if (!$requestId || $requestId <= 0) {
    die("Invalid request ID.");
}

// --------------------------------------------------
// VALIDATE STATUS
// --------------------------------------------------

$allowedStatuses = [
    "submitted",
    "payment_confirmed",
    "processing",
    "under_review",
    "awaiting_information",
    "ready_for_download",
    "completed"
];

// Cancellation is intentionally excluded for now.
// Cancellation will be handled together with the refund system.

$status = $_POST["status"] ?? "";

if (!in_array($status, $allowedStatuses, true)) {
    die("Invalid status.");
}

// --------------------------------------------------
// VALIDATE MESSAGE
// --------------------------------------------------

$message = trim($_POST["message"] ?? "");

if (strlen($message) > 1000) {
    die("Message is too long.");
}

$adminId = currentUserId();

try {

    $pdo->beginTransaction();

    // --------------------------------------------------
    // LOCK THE REQUEST
    // --------------------------------------------------

    $stmt = $pdo->prepare("
        SELECT
            id,
            user_id,
            request_code,
            status
        FROM service_requests
        WHERE id = ?
        FOR UPDATE
    ");

    $stmt->execute([$requestId]);

    $request = $stmt->fetch();

    if (!$request) {
        throw new Exception("Request not found.");
    }

    $oldStatus = $request["status"];

    // --------------------------------------------------
    // IF STATUS IS ALREADY THE SAME
    // --------------------------------------------------

    if ($oldStatus === $status) {

        $pdo->commit();

        header(
            "Location: request_details.php?id=" .
            (int) $requestId
        );

        exit;
    }

    // --------------------------------------------------
    // UPDATE REQUEST
    // --------------------------------------------------

    if ($status === "completed") {

        $stmt = $pdo->prepare("
            UPDATE service_requests
            SET
                status = ?,
                assigned_admin_id = ?,
                updated_at = NOW(),
                completed_at = NOW()
            WHERE id = ?
        ");

        $stmt->execute([
            $status,
            $adminId,
            $requestId
        ]);

    } else {

        $stmt = $pdo->prepare("
            UPDATE service_requests
            SET
                status = ?,
                assigned_admin_id = ?,
                updated_at = NOW()
            WHERE id = ?
        ");

        $stmt->execute([
            $status,
            $adminId,
            $requestId
        ]);
    }

    // --------------------------------------------------
    // ADD STATUS HISTORY
    // --------------------------------------------------

    $stmt = $pdo->prepare("
        INSERT INTO request_status_history
        (
            request_id,
            status,
            message,
            changed_by
        )
        VALUES (?, ?, ?, ?)
    ");

    $stmt->execute([
        $requestId,
        $status,
        $message !== "" ? $message : null,
        $adminId
    ]);

    // --------------------------------------------------
    // CREATE CLIENT NOTIFICATION
    // --------------------------------------------------

    $statusTitle = ucwords(
        str_replace("_", " ", $status)
    );

    $notificationMessage =
        "Your request " .
        $request["request_code"] .
        " is now " .
        strtolower($statusTitle) .
        ".";

    if ($message !== "") {
        $notificationMessage .= " " . $message;
    }

    $stmt = $pdo->prepare("
        INSERT INTO notifications
        (
            user_id,
            request_id,
            title,
            message,
            is_read
        )
        VALUES (?, ?, ?, ?, 0)
    ");

    $stmt->execute([
        $request["user_id"],
        $requestId,
        "Request Status Updated",
        $notificationMessage
    ]);

    // --------------------------------------------------
    // ADMIN ACTIVITY LOG
    // --------------------------------------------------

    $description =
        "Changed request " .
        $request["request_code"] .
        " status from " .
        $oldStatus .
        " to " .
        $status . ".";

    $stmt = $pdo->prepare("
        INSERT INTO activity_logs
        (
            user_id,
            request_id,
            action,
            description
        )
        VALUES (?, ?, ?, ?)
    ");

    $stmt->execute([
        $adminId,
        $requestId,
        "request_status_update",
        $description
    ]);

    // --------------------------------------------------
    // COMMIT EVERYTHING
    // --------------------------------------------------

    $pdo->commit();

    header(
        "Location: request_details.php?id=" .
        (int) $requestId
    );

    exit;

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);

    die("Unable to update request status. Please try again.");
}