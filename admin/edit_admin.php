<?php

require_once "../config/database.php";
require_once "../includes/admin_auth.php";

if (($_SESSION['role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    die("Access denied. Only the Super Admin can manage admins.");
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* =========================
   CSRF TOKEN
========================= */

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/* =========================
   GET ADMIN ID
========================= */

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    die("Invalid admin ID.");
}

/* =========================
   FETCH ADMIN
========================= */

$stmt = $pdo->prepare("
    SELECT id, full_name, email, phone, role, status
    FROM users
    WHERE id = ?
      AND role IN ('admin', 'super_admin')
    LIMIT 1
");

$stmt->execute([$id]);

$admin = $stmt->fetch();

if (!$admin) {
    die("Admin not found.");
}

$errors = [];

/* =========================
   FORM SUBMISSION
========================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        empty($_POST['csrf_token']) ||
        !hash_equals($csrfToken, $_POST['csrf_token'])
    ) {
        $errors[] = "Invalid security token. Please refresh the page.";
    }

    $fullName = trim($_POST['full_name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $role     = $_POST['role'] ?? '';
    $status   = $_POST['status'] ?? '';

    /* =========================
       VALIDATION
    ========================= */

    if ($fullName === '' || strlen($fullName) < 2) {
        $errors[] = "Please enter a valid full name.";
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }

    if (!in_array($role, ['admin', 'super_admin'], true)) {
        $errors[] = "Invalid admin role.";
    }

    if (!in_array($status, ['active', 'suspended', 'inactive'], true)) {
        $errors[] = "Invalid account status.";
    }

    /* =========================
       CHECK EMAIL
    ========================= */

    if (empty($errors)) {

        $stmt = $pdo->prepare("
            SELECT id
            FROM users
            WHERE email = ?
              AND id != ?
            LIMIT 1
        ");

        $stmt->execute([$email, $id]);

        if ($stmt->fetch()) {
            $errors[] = "Another account already uses this email.";
        }
    }

    /* =========================
       PROTECT CURRENT USER
    ========================= */

    if (empty($errors) && $id === (int) $_SESSION['user_id']) {

        if ($status !== 'active') {
            $errors[] = "You cannot suspend or deactivate your own account.";
        }

        if ($role !== 'super_admin') {
            $errors[] = "You cannot remove your own Super Admin role.";
        }
    }

    /* =========================
       PROTECT LAST ACTIVE
       SUPER ADMIN
    ========================= */

    if (
        empty($errors) &&
        $admin['role'] === 'super_admin'
    ) {

        $changingRole = $role !== 'super_admin';
        $changingStatus = $status !== 'active';

        if ($changingRole || $changingStatus) {

            $stmt = $pdo->query("
                SELECT COUNT(*)
                FROM users
                WHERE role = 'super_admin'
                  AND status = 'active'
                  AND id != " . (int) $id
            );

            $activeSuperAdmins = (int) $stmt->fetchColumn();

            if ($activeSuperAdmins < 1) {
                $errors[] =
                    "You cannot remove or deactivate the last active Super Admin.";
            }
        }
    }

    /* =========================
       UPDATE
    ========================= */

    if (empty($errors)) {

        try {

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE users
                SET
                    full_name = ?,
                    email = ?,
                    phone = ?,
                    role = ?,
                    status = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
                  AND role IN ('admin', 'super_admin')
            ");

            $stmt->execute([
                $fullName,
                $email,
                $phone,
                $role,
                $status,
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
                'admin_updated',
                "Updated admin account ID #{$id}."
            ]);

            $pdo->commit();

            header("Location: admins.php?updated=1");
            exit;

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] =
                "Unable to update admin account. Please try again.";
        }
    }

    /* Keep submitted values on error */

    $admin['full_name'] = $fullName;
    $admin['email']     = $email;
    $admin['phone']     = $phone;
    $admin['role']      = $role;
    $admin['status']    = $status;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Edit Admin - Servora</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #f4f6fb;
    color: #222;
}

.container {
    width: 100%;
    max-width: 700px;
    margin: 40px auto;
    padding: 20px;
}

.card {
    background: #fff;
    border-radius: 12px;
    padding: 25px;
    box-shadow: 0 4px 18px rgba(0,0,0,0.08);
}

h1 {
    margin-top: 0;
    color: #3E37B7;
}

label {
    display: block;
    margin-top: 15px;
    margin-bottom: 6px;
    font-weight: bold;
}

input,
select {
    width: 100%;
    padding: 12px;
    border: 1px solid #ccc;
    border-radius: 7px;
    font-size: 15px;
}

button,
.back-button {
    display: inline-block;
    margin-top: 20px;
    padding: 12px 18px;
    border: none;
    border-radius: 7px;
    background: #3E37B7;
    color: #fff;
    cursor: pointer;
    text-decoration: none;
}

button:hover,
.back-button:hover {
    opacity: 0.9;
}

.back-button {
    background: #555;
    margin-left: 8px;
}

.errors {
    background: #ffe5e5;
    color: #a00000;
    padding: 12px;
    border-radius: 7px;
    margin-bottom: 15px;
}

.errors div {
    margin-bottom: 5px;
}

</style>

</head>

<body>

<div class="container">

    <div class="card">

        <h1>Edit Admin</h1>

        <?php if (!empty($errors)): ?>

            <div class="errors">

                <?php foreach ($errors as $error): ?>

                    <div>
                        <?= htmlspecialchars($error) ?>
                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

        <form method="POST">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($csrfToken) ?>"
            >

            <label>Full Name</label>

            <input
                type="text"
                name="full_name"
                value="<?= htmlspecialchars($admin['full_name']) ?>"
                required
            >

            <label>Email</label>

            <input
                type="email"
                name="email"
                value="<?= htmlspecialchars($admin['email']) ?>"
                required
            >

            <label>Phone</label>

            <input
                type="text"
                name="phone"
                value="<?= htmlspecialchars($admin['phone'] ?? '') ?>"
            >

            <label>Role</label>

            <select name="role" required>

                <option
                    value="admin"
                    <?= $admin['role'] === 'admin' ? 'selected' : '' ?>
                >
                    Admin
                </option>

                <option
                    value="super_admin"
                    <?= $admin['role'] === 'super_admin' ? 'selected' : '' ?>
                >
                    Super Admin
                </option>

            </select>

            <label>Status</label>

            <select name="status" required>

                <option
                    value="active"
                    <?= $admin['status'] === 'active' ? 'selected' : '' ?>
                >
                    Active
                </option>

                <option
                    value="suspended"
                    <?= $admin['status'] === 'suspended' ? 'selected' : '' ?>
                >
                    Suspended
                </option>

                <option
                    value="inactive"
                    <?= $admin['status'] === 'inactive' ? 'selected' : '' ?>
                >
                    Inactive
                </option>

            </select>

            <button type="submit">
                Save Changes
            </button>

            <a href="admins.php" class="back-button">
                Cancel
            </a>

        </form>

    </div>

</div>

</body>
</html>