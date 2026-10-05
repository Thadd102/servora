<?php

require_once "../includes/admin_auth.php";
require_once "../config/database.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    die("Method not allowed.");
}

// -------------------------
// CSRF CHECK
// -------------------------
if (
    !isset($_POST["csrf_token"]) ||
    !isset($_SESSION["csrf_token"]) ||
    !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
) {
    die("Invalid security token.");
}

// -------------------------
// VALIDATE REQUEST ID
// -------------------------
$requestId = filter_input(INPUT_POST, "request_id", FILTER_VALIDATE_INT);

if (!$requestId || $requestId <= 0) {
    die("Invalid request.");
}

$adminId = currentUserId();

try {

    $pdo->beginTransaction();

    // -------------------------
    // LOCK REQUEST
    // -------------------------
    $stmt = $pdo->prepare("
        SELECT
            sr.id,
            sr.request_code,
            sr.user_id,
            sr.amount,
            sr.status,
            u.full_name,
            u.email
        FROM service_requests sr
        INNER JOIN users u ON u.id = sr.user_id
        WHERE sr.id = ?
        FOR UPDATE
    ");

    $stmt->execute([$requestId]);

    $request = $stmt->fetch();

    if (!$request) {
        throw new Exception("Request not found.");
    }

    $amount = (float) $request["amount"];

    if ($amount <= 0) {
        throw new Exception("Invalid refund amount.");
    }

    // -------------------------
    // PREVENT DOUBLE REFUND
    // -------------------------
    if ($request["status"] === "cancelled") {
        throw new Exception("This request has already been cancelled/refunded.");
    }

    // Completed requests should not be refunded through this action.
    if ($request["status"] === "completed") {
        throw new Exception("A completed request cannot be refunded here.");
    }

    // A result is already available, so don't refund through this action.
    if ($request["status"] === "ready_for_download") {
        throw new Exception("This request already has a result ready for download.");
    }

    // Only failed/incomplete processing stages can be refunded.
    $refundableStatuses = [
        "submitted",
        "payment_confirmed",
        "processing",
        "under_review",
        "awaiting_information"
    ];

    if (!in_array($request["status"], $refundableStatuses, true)) {
        throw new Exception("This request cannot be refunded at its current status.");
    }

    // -------------------------
    // LOCK CLIENT WALLET
    // -------------------------
    $walletStmt = $pdo->prepare("
        SELECT id, balance
        FROM wallets
        WHERE user_id = ?
        FOR UPDATE
    ");

    $walletStmt->execute([
        $request["user_id"]
    ]);

    $wallet = $walletStmt->fetch();

    if (!$wallet) {
        throw new Exception("Client wallet not found.");
    }

    $balanceBefore = (float) $wallet["balance"];
    $balanceAfter = $balanceBefore + $amount;

    // -------------------------
    // UPDATE WALLET
    // -------------------------
    $updateWallet = $pdo->prepare("
        UPDATE wallets
        SET balance = ?, updated_at = NOW()
        WHERE id = ?
    ");

    $updateWallet->execute([
        $balanceAfter,
        $wallet["id"]
    ]);

    // -------------------------
    // UNIQUE REFUND REFERENCE
    // -------------------------
    $reference = "REFUND-" .
        $requestId .
        "-" .
        strtoupper(bin2hex(random_bytes(8)));

    // -------------------------
    // RECORD WALLET TRANSACTION
    // -------------------------
    $transactionStmt = $pdo->prepare("
        INSERT INTO wallet_transactions
        (
            user_id,
            type,
            amount,
            balance_before,
            balance_after,
            reference,
            description,
            status,
            created_at
        )
        VALUES
        (
            ?,
            'refund',
            ?,
            ?,
            ?,
            ?,
            ?,
            'successful',
            NOW()
        )
    ");

    $transactionStmt->execute([
        $request["user_id"],
        $amount,
        $balanceBefore,
        $balanceAfter,
        $reference,
        "Refund for request " . $request["request_code"]
    ]);

    // -------------------------
    // CANCEL REQUEST
    // -------------------------
    $updateRequest = $pdo->prepare("
        UPDATE service_requests
        SET
            status = 'cancelled',
            assigned_admin_id = ?,
            updated_at = NOW()
        WHERE id = ?
    ");

    $updateRequest->execute([
        $adminId,
        $requestId
    ]);

    // -------------------------
    // STATUS HISTORY
    // -------------------------
    $historyMessage =
        "Request cancelled. ₦" .
        number_format($amount, 2) .
        " has been refunded to the client's wallet.";

    $historyStmt = $pdo->prepare("
        INSERT INTO request_status_history
        (
            request_id,
            status,
            message,
            changed_by,
            created_at
        )
        VALUES
        (
            ?,
            'cancelled',
            ?,
            ?,
            NOW()
        )
    ");

    $historyStmt->execute([
        $requestId,
        $historyMessage,
        $adminId
    ]);

    // -------------------------
    // CLIENT NOTIFICATION
    // -------------------------
    $notificationStmt = $pdo->prepare("
        INSERT INTO notifications
        (
            user_id,
            request_id,
            title,
            message,
            is_read,
            created_at
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            0,
            NOW()
        )
    ");

    $notificationStmt->execute([
        $request["user_id"],
        $requestId,
        "Request Cancelled and Refunded",
        "Your request " .
        $request["request_code"] .
        " could not be completed. ₦" .
        number_format($amount, 2) .
        " has been refunded to your wallet."
    ]);

    // -------------------------
    // ACTIVITY LOG
    // -------------------------
    $activityStmt = $pdo->prepare("
        INSERT INTO activity_logs
        (
            user_id,
            request_id,
            action,
            description,
            created_at
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            NOW()
        )
    ");

    $activityStmt->execute([
        $adminId,
        $requestId,
        "refund_request",
        "Refunded ₦" .
        number_format($amount, 2) .
        " for request " .
        $request["request_code"]
    ]);

    // -------------------------
    // COMMIT EVERYTHING
    // -------------------------
    $pdo->commit();

    header(
        "Location: request_details.php?id=" .
        $requestId .
        "&refund=success"
    );

    exit;

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    die(
        "Refund failed: " .
        htmlspecialchars($e->getMessage())
    );
}