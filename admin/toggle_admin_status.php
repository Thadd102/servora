<?php

require_once "../config/database.php";
require_once "../includes/admin_auth.php";

if (($_SESSION['role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    die("Access denied. Only the Super Admin can manage admins.");
}

/* =========================
   POST ONLY
========================= */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die("Method not allowed.");
}

/* =========================
   CSRF
========================= */

if (
    empty($_POST['csrf_token']) ||
    empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
) {
    http_response_code(403);
    die("Invalid security token.");
}

/* =========================
   ADMIN ID
========================= */

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    die("Invalid admin ID.");
}

/* =========================
   PREVENT SELF ACTION
========================= */

if ($id === (int) $_SESSION['user_id']) {
    die("You cannot suspend or deactivate your own account.");
}

try {

    $pdo->beginTransaction();

    /* =========================
       LOCK ADMIN
    ========================= */

    $stmt = $pdo->prepare("
        SELECT id, full_name, role, status
        FROM users
        WHERE id = ?
          AND role IN ('admin', 'super_admin')
        FOR UPDATE
    ");

    $stmt->execute([$id]);

    $admin = $stmt->fetch();

    if (!$admin) {
        throw new Exception("Admin not found.");
    }

    /* =========================
       DETERMINE NEW STATUS
    ========================= */

    if ($admin['status'] === 'active') {

        /* Prevent disabling the last active Super Admin */

        if ($admin['role'] === 'super_admin') {

            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM users
                WHERE role = 'super_admin'
                  AND status = 'active'
                  AND id != ?
            ");

            $stmt->execute([$id]);

            $otherActiveSuperAdmins = (int) $stmt->fetchColumn();

            if ($otherActiveSuperAdmins < 1) {
                throw new Exception(
                    "You cannot suspend the last active Super Admin."
                );
            }
        }

        $newStatus = 'suspended';

        $action = 'admin_suspended';

        $message = "Admin {$admin['full_name']} was suspended.";

    } else {

        $newStatus = 'active';

        $action = 'admin_activated';

        $message = "Admin {$admin['full_name']} was activated.";
    }

    /* =========================
       UPDATE STATUS
    ========================= */

    $stmt = $pdo->prepare("
        UPDATE users
        SET status = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");

    $stmt->execute([
        $newStatus,
        $id
    ]);

    /* =========================
       ACTIVITY LOG
    ========================= */

    $stmt = $pdo->prepare("
        INSERT INTO activity_logs
        (
            user_id,
            action,
            description
        )
        VALUES (?, ?, ?)
    ");

    $stmt->execute([
        $_SESSION['user_id'],
        $action,
        $message
    ]);

    $pdo->commit();

    header("Location: admins.php?status_updated=1");
    exit;

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    die(
        htmlspecialchars(
            $e->getMessage(),
            ENT_QUOTES,
            'UTF-8'
        )
    );
}