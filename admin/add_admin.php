<?php

require_once "../config/database.php";
require_once "../includes/admin_auth.php";

if ($_SESSION['role'] !== 'super_admin') {
    http_response_code(403);
    die("Access denied.");
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

$errors = [];

$fullName = "";
$email = "";
$phone = "";
$role = "admin";
$status = "active";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($csrfToken, $_POST['csrf_token'])
    ) {
        $errors[] = "Invalid security token. Please refresh and try again.";
    }

    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $role = $_POST['role'] ?? 'admin';
    $status = $_POST['status'] ?? 'active';
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($fullName === '') {
        $errors[] = "Full name is required.";
    } elseif (strlen($fullName) < 3) {
        $errors[] = "Full name must be at least 3 characters.";
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Enter a valid email address.";
    }

    if (!in_array($role, ['admin', 'super_admin'], true)) {
        $errors[] = "Invalid administrator role.";
    }

    if (!in_array($status, ['active', 'suspended', 'inactive'], true)) {
        $errors[] = "Invalid account status.";
    }

    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters.";
    }

    if ($password !== $confirmPassword) {
        $errors[] = "Passwords do not match.";
    }

    if (empty($errors)) {

        $stmt = $pdo->prepare("
            SELECT id
            FROM users
            WHERE email = ?
            LIMIT 1
        ");

        $stmt->execute([$email]);

        if ($stmt->fetch()) {
            $errors[] = "An account with this email already exists.";
        }
    }

    if (empty($errors)) {

        try {

            $pdo->beginTransaction();

            $hashedPassword = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            $stmt = $pdo->prepare("
                INSERT INTO users
                (
                    full_name,
                    email,
                    phone,
                    password,
                    role,
                    status
                )
                VALUES
                (?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $fullName,
                $email,
                $phone !== '' ? $phone : null,
                $hashedPassword,
                $role,
                $status
            ]);

            $newAdminId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare("
                INSERT INTO activity_logs
                (
                    user_id,
                    action,
                    description
                )
                VALUES
                (?, ?, ?)
            ");

            $stmt->execute([
                $_SESSION['user_id'],
                'admin_created',
                "Created administrator account #{$newAdminId}."
            ]);

            $pdo->commit();

            header("Location: admins.php?created=1");
            exit;

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] = "Unable to create administrator. Please try again.";
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Add Administrator - Servora</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f5f6fa;
            color: #222;
        }

        .container {
            width: 95%;
            max-width: 700px;
            margin: 40px auto;
        }

        .card {
            background: white;
            padding: 30px;
            border-radius: 14px;
            box-shadow: 0 4px 18px rgba(0,0,0,0.07);
        }

        h1 {
            margin-top: 0;
            color: #3E37B7;
        }

        .subtitle {
            color: #666;
            margin-bottom: 25px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        label {
            display: block;
            margin-bottom: 7px;
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

        input:focus,
        select:focus {
            outline: none;
            border-color: #3E37B7;
        }

        .buttons {
            display: flex;
            gap: 10px;
            margin-top: 25px;
            flex-wrap: wrap;
        }

        button,
        .back {
            padding: 12px 18px;
            border-radius: 7px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
        }

        button {
            background: #3E37B7;
            color: white;
        }

        .back {
            background: #555;
            color: white;
        }

        .errors {
            background: #ffe4e4;
            color: #a40000;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .errors ul {
            margin: 0;
            padding-left: 20px;
        }

        .hint {
            font-size: 13px;
            color: #777;
            margin-top: 5px;
        }

        @media (max-width: 600px) {

            .container {
                width: 92%;
                margin: 25px auto;
            }

            .card {
                padding: 22px;
            }

        }

    </style>

</head>

<body>

<div class="container">

    <div class="card">

        <h1>Add Administrator</h1>

        <p class="subtitle">
            Create a new administrator account for Servora.
        </p>

        <?php if (!empty($errors)): ?>

            <div class="errors">

                <ul>

                    <?php foreach ($errors as $error): ?>

                        <li>
                            <?= htmlspecialchars($error) ?>
                        </li>

                    <?php endforeach; ?>

                </ul>

            </div>

        <?php endif; ?>


        <form method="POST">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($csrfToken) ?>"
            >


            <div class="form-group">

                <label for="full_name">
                    Full Name
                </label>

                <input
                    type="text"
                    id="full_name"
                    name="full_name"
                    value="<?= htmlspecialchars($fullName) ?>"
                    required
                >

            </div>


            <div class="form-group">

                <label for="email">
                    Email Address
                </label>

                <input
                    type="email"
                    id="email"
                    name="email"
                    value="<?= htmlspecialchars($email) ?>"
                    required
                >

            </div>


            <div class="form-group">

                <label for="phone">
                    Phone Number
                </label>

                <input
                    type="tel"
                    id="phone"
                    name="phone"
                    value="<?= htmlspecialchars($phone) ?>"
                >

            </div>


            <div class="form-group">

                <label for="role">
                    Role
                </label>

                <select id="role" name="role">

                    <option value="admin"
                        <?= $role === 'admin' ? 'selected' : '' ?>>
                        Admin
                    </option>

                    <option value="super_admin"
                        <?= $role === 'super_admin' ? 'selected' : '' ?>>
                        Super Admin
                    </option>

                </select>

                <div class="hint">
                    Super Admin has full administrative control.
                </div>

            </div>


            <div class="form-group">

                <label for="status">
                    Account Status
                </label>

                <select id="status" name="status">

                    <option value="active"
                        <?= $status === 'active' ? 'selected' : '' ?>>
                        Active
                    </option>

                    <option value="suspended"
                        <?= $status === 'suspended' ? 'selected' : '' ?>>
                        Suspended
                    </option>

                    <option value="inactive"
                        <?= $status === 'inactive' ? 'selected' : '' ?>>
                        Inactive
                    </option>

                </select>

            </div>


            <div class="form-group">

                <label for="password">
                    Password
                </label>

                <input
                    type="password"
                    id="password"
                    name="password"
                    minlength="8"
                    required
                >

                <div class="hint">
                    Minimum 8 characters.
                </div>

            </div>


            <div class="form-group">

                <label for="confirm_password">
                    Confirm Password
                </label>

                <input
                    type="password"
                    id="confirm_password"
                    name="confirm_password"
                    minlength="8"
                    required
                >

            </div>


            <div class="buttons">

                <button type="submit">
                    Create Administrator
                </button>

                <a
                    href="admins.php"
                    class="back"
                >
                    Cancel
                </a>

            </div>

        </form>

    </div>

</div>

</body>

</html>