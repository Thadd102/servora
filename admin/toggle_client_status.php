<?php

// ============================================================
// TOGGLE CLIENT STATUS
// Admin can activate or deactivate a client account.
// ============================================================

require_once "../config/database.php";
require_once "../includes/admin_auth.php";

// ------------------------------------------------------------
// ONLY POST REQUESTS ARE ALLOWED
// ------------------------------------------------------------

if ($_SERVER["REQUEST_METHOD"] !== "POST") {

    http_response_code(405);
    die("Method not allowed.");
}

// ------------------------------------------------------------
// GET CLIENT ID
// ------------------------------------------------------------

$clientId = filter_input(
    INPUT_POST,
    "client_id",
    FILTER_VALIDATE_INT
);

if (!$clientId) {

    die("Invalid client ID.");
}

// ------------------------------------------------------------
// CSRF PROTECTION
// ------------------------------------------------------------

if (
    !isset($_POST["csrf_token"]) ||
    !hash_equals(
        $_SESSION["csrf_token"] ?? "",
        $_POST["csrf_token"]
    )
) {

    die("Invalid security token.");
}

// ------------------------------------------------------------
// GENERATE CSRF TOKEN IF NEEDED
// ------------------------------------------------------------

if (!isset($_SESSION["csrf_token"])) {

    $_SESSION["csrf_token"] = bin2hex(
        random_bytes(32)
    );
}

// ------------------------------------------------------------
// START DATABASE TRANSACTION
// ------------------------------------------------------------

try {

    $pdo->beginTransaction();

    // --------------------------------------------------------
    // LOCK CLIENT RECORD
    // --------------------------------------------------------

    $stmt = $pdo->prepare("
        SELECT
            id,
            full_name,
            email,
            role,
            status

        FROM users

        WHERE id = ?

        LIMIT 1

        FOR UPDATE
    ");

    $stmt->execute([$clientId]);

    $client = $stmt->fetch();

    // --------------------------------------------------------
    // CHECK CLIENT
    // --------------------------------------------------------

    if (!$client) {

        throw new Exception("Client not found.");
    }

    // Only clients can be changed here.
    // This prevents an admin from accidentally
    // deactivating another admin account.

    if ($client["role"] !== "client") {

        throw new Exception(
            "Only client accounts can be changed here."
        );
    }

    // --------------------------------------------------------
    // DETERMINE NEW STATUS
    // --------------------------------------------------------

    if ($client["status"] === "active") {

        // Active client becomes suspended.
        $newStatus = "suspended";

        $action = "deactivate_client";

        $description =
            "Client account deactivated: "
            . $client["full_name"];

    } else {

        // Suspended/inactive client becomes active.
        $newStatus = "active";

        $action = "activate_client";

        $description =
            "Client account activated: "
            . $client["full_name"];
    }

    // --------------------------------------------------------
    // UPDATE CLIENT STATUS
    // --------------------------------------------------------

    $updateStmt = $pdo->prepare("
        UPDATE users

        SET
            status = ?,
            updated_at = NOW()

        WHERE id = ?
    ");

    $updateStmt->execute([
        $newStatus,
        $clientId
    ]);

    // --------------------------------------------------------
    // RECORD ADMIN ACTION
    // --------------------------------------------------------

    $logStmt = $pdo->prepare("
        INSERT INTO activity_logs
        (
            user_id,
            action,
            description,
            created_at
        )

        VALUES
        (
            ?,
            ?,
            ?,
            NOW()
        )
    ");

    $logStmt->execute([
        currentUserId(),
        $action,
        $description
    ]);

    // --------------------------------------------------------
    // COMMIT
    // --------------------------------------------------------

    $pdo->commit();

    // --------------------------------------------------------
    // RETURN TO CLIENTS PAGE
    // --------------------------------------------------------

    header(
        "Location: clients.php?status=updated"
    );

    exit;

} catch (Throwable $e) {

    // --------------------------------------------------------
    // ROLLBACK IF SOMETHING FAILS
    // --------------------------------------------------------

    if ($pdo->inTransaction()) {

        $pdo->rollBack();
    }

    die(
        "Unable to update client status. "
        . htmlspecialchars($e->getMessage())
    );
}