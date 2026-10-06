<?php

require_once "config/database.php";

require_once __DIR__ . "/includes/auth.php";

$error = "";

// Make sure reset process has started
if (empty($_SESSION["password_reset_user_id"])) {
    header("Location: forgot_password.php");
    exit;
}

// Create CSRF token
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$userId = (int) $_SESSION["password_reset_user_id"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // CSRF protection
    if (
        empty($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        $error = "Invalid request. Please try again.";

    } else {

        $otp = trim($_POST["otp"] ?? "");

        if (!preg_match('/^\d{6}$/', $otp)) {

            $error = "Enter the 6-digit OTP.";

        } else {

            // Get latest unused OTP
            $stmt = $pdo->prepare("
                SELECT *
                FROM password_resets
                WHERE user_id = ?
                AND used = 0
                ORDER BY id DESC
                LIMIT 1
            ");

            $stmt->execute([$userId]);

            $reset = $stmt->fetch();

            if (!$reset) {

                $error = "OTP is invalid or has already been used.";

            } elseif (strtotime($reset["expires_at"]) < time()) {

                $error = "OTP has expired. Please request a new one.";

            } elseif ((int) $reset["attempts"] >= 5) {

                $error = "Too many attempts. Please request a new OTP.";

            } else {

                // Count this attempt
                $attempt = $pdo->prepare("
                    UPDATE password_resets
                    SET attempts = attempts + 1
                    WHERE id = ?
                ");

                $attempt->execute([
                    $reset["id"]
                ]);

                if (!password_verify($otp, $reset["otp_hash"])) {

                    $remaining = 4 - (int) $reset["attempts"];

                    if ($remaining < 0) {
                        $remaining = 0;
                    }

                    $error =
                        "Incorrect OTP. Attempts remaining: "
                        . $remaining;

                } else {

                    // OTP is correct
                    $_SESSION["password_reset_verified"] = true;
                    $_SESSION["password_reset_id"] =
                        (int) $reset["id"];

                    // Regenerate session ID for security
                    session_regenerate_id(true);

                    header("Location: reset_password.php");
                    exit;
                }
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

    <title>Verify OTP | Subnext</title>

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
            text-align: center;
        }

        .logo {
            font-size: 28px;
            font-weight: bold;
            color: #3E37B7;
        }

        p {
            color: #666;
        }

        input {
            width: 100%;
            padding: 14px;
            text-align: center;
            font-size: 22px;
            letter-spacing: 6px;
            border: 1px solid #ddd;
            border-radius: 8px;
        }

        button {
            width: 100%;
            margin-top: 18px;
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

        a {
            display: block;
            margin-top: 18px;
            color: #3E37B7;
            text-decoration: none;
        }

    </style>

</head>

<body>

<div class="card">

    <div class="logo">Subnext</div>
    <p style="font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; margin-top: 2px; margin-bottom: 14px;">Digital Services, Simplified.</p>

    <p>
        Enter the 6-digit verification code sent to your email.
    </p>

    <?php if ($error): ?>

        <div class="error">
            <?= htmlspecialchars($error) ?>
        </div>

    <?php endif; ?>

    <form method="POST">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>"
        >

        <input
            type="text"
            name="otp"
            maxlength="6"
            inputmode="numeric"
            pattern="[0-9]{6}"
            placeholder="000000"
            autocomplete="one-time-code"
            required
        >

        <button type="submit">
            Verify Code
        </button>

    </form>

    <a href="forgot_password.php">
        Request New Code
    </a>

    <p style="margin-top: 24px; font-size: 11px; color: #94a3b8; text-align: center;">© <?= date('Y') ?> Subnext. All rights reserved.</p>

</div>

<script src="assets/js/loader.js" defer></script>
</body>

</html>