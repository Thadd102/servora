<?php

require_once "config/database.php";

session_start();

$error = "";
$success = "";

// User must pass OTP verification first
if (
    empty($_SESSION["password_reset_user_id"]) ||
    empty($_SESSION["password_reset_verified"]) ||
    empty($_SESSION["password_reset_id"])
) {
    header("Location: forgot_password.php");
    exit;
}

// Create CSRF token
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$userId = (int) $_SESSION["password_reset_user_id"];
$resetId = (int) $_SESSION["password_reset_id"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // CSRF protection
    if (
        empty($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        $error = "Invalid request. Please try again.";

    } else {

        $password = $_POST["password"] ?? "";
        $confirmPassword = $_POST["confirm_password"] ?? "";

        if (strlen($password) < 8) {

            $error = "Password must be at least 8 characters.";

        } elseif ($password !== $confirmPassword) {

            $error = "Passwords do not match.";

        } else {

            try {

                $pdo->beginTransaction();

                // Lock reset record
                $stmt = $pdo->prepare("
                    SELECT *
                    FROM password_resets
                    WHERE id = ?
                    AND user_id = ?
                    AND used = 0
                    LIMIT 1
                    FOR UPDATE
                ");

                $stmt->execute([
                    $resetId,
                    $userId
                ]);

                $reset = $stmt->fetch();

                if (!$reset) {

                    throw new Exception(
                        "This password reset session is no longer valid."
                    );
                }

                // Check expiry again
                if (
                    strtotime($reset["expires_at"]) < time()
                ) {

                    throw new Exception(
                        "Your OTP has expired."
                    );
                }

                // Hash new password
                $passwordHash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                // Update password
                $update = $pdo->prepare("
                    UPDATE users
                    SET password = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");

                $update->execute([
                    $passwordHash,
                    $userId
                ]);

                // Mark OTP as used
                $markUsed = $pdo->prepare("
                    UPDATE password_resets
                    SET used = 1
                    WHERE id = ?
                ");

                $markUsed->execute([
                    $resetId
                ]);

                $pdo->commit();

                // Destroy reset session data
                unset(
                    $_SESSION["password_reset_user_id"],
                    $_SESSION["password_reset_verified"],
                    $_SESSION["password_reset_id"]
                );

                // New CSRF token
                $_SESSION["csrf_token"] =
                    bin2hex(random_bytes(32));

                $success =
                    "Password changed successfully.";

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $error = $e->getMessage();
            }
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

    <title>Reset Password | Subnext</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: Arial, sans-serif;
            background: #f5f7fb;
            padding: 20px;
        }

        .card {
            width: 100%;
            max-width: 420px;
            background: white;
            padding: 30px;
            border-radius: 14px;
            box-shadow: 0 8px 30px rgba(0,0,0,.08);
        }

        .logo {
            text-align: center;
            font-size: 28px;
            font-weight: bold;
            color: #3E37B7;
        }

        .subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 25px;
        }

        label {
            display: block;
            margin-top: 15px;
            margin-bottom: 7px;
            font-weight: bold;
        }

        input {
            width: 100%;
            padding: 13px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 15px;
        }

        button {
            width: 100%;
            margin-top: 20px;
            padding: 13px;
            border: 0;
            border-radius: 8px;
            background: #3E37B7;
            color: white;
            font-weight: bold;
            cursor: pointer;
        }

        .error {
            background: #ffe9e9;
            color: #b00020;
            padding: 10px;
            border-radius: 7px;
            margin-bottom: 15px;
        }

        .success {
            background: #e8f8ee;
            color: #16753a;
            padding: 12px;
            border-radius: 7px;
            margin-bottom: 15px;
            text-align: center;
        }

        .login {
            display: block;
            text-align: center;
            margin-top: 20px;
            color: #3E37B7;
            text-decoration: none;
        }

    </style>

</head>

<body>

<div class="card">

    <div class="logo">Subnext</div>
    <p style="font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; margin-top: 2px; margin-bottom: 14px;">Digital Services, Simplified.</p>

    <p class="subtitle">
        Create a new password
    </p>

    <?php if ($error): ?>

        <div class="error">
            <?= htmlspecialchars($error) ?>
        </div>

    <?php endif; ?>

    <?php if ($success): ?>

        <div class="success">
            <?= htmlspecialchars($success) ?>
        </div>

        <a href="login.php" class="login">
            Continue to Login
        </a>

    <?php else: ?>

        <form method="POST">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>"
            >

            <label for="password">
                New Password
            </label>

            <input
                type="password"
                name="password"
                id="password"
                minlength="8"
                required
            >

            <label for="confirm_password">
                Confirm Password
            </label>

            <input
                type="password"
                name="confirm_password"
                id="confirm_password"
                minlength="8"
                required
            >

            <button type="submit">
                Reset Password
            </button>

        </form>

    <?php endif; ?>

    <p style="margin-top: 24px; font-size: 11px; color: #94a3b8; text-align: center;">© <?= date('Y') ?> Subnext. All rights reserved.</p>

</div>

</body>

</html>