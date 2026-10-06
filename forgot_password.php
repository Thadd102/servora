<?php

require_once "config/database.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . "/vendor/autoload.php";
require_once __DIR__ . "/includes/auth.php";

$error = "";

// Create CSRF token
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Check CSRF token
    if (
        empty($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        $error = "Invalid request. Please try again.";
    } else {

        $email = trim($_POST["email"] ?? "");

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

            $error = "Please enter a valid email address.";

        } else {

            $stmt = $pdo->prepare("
                SELECT id, full_name, email
                FROM users
                WHERE email = ?
                LIMIT 1
            ");

            $stmt->execute([$email]);

            $user = $stmt->fetch();

            if (!$user) {

                $error = "If that email is registered, an OTP will be sent.";

            } else {

                $otp = (string) random_int(100000, 999999);

                $otpHash = password_hash(
                    $otp,
                    PASSWORD_DEFAULT
                );

                $expiresAt = date(
                    "Y-m-d H:i:s",
                    time() + 600
                );

                try {

                    $pdo->beginTransaction();

                    // Invalidate previous OTPs
                    $invalidate = $pdo->prepare("
                        UPDATE password_resets
                        SET used = 1
                        WHERE user_id = ?
                        AND used = 0
                    ");

                    $invalidate->execute([
                        $user["id"]
                    ]);

                    // Save new OTP
                    $insert = $pdo->prepare("
                        INSERT INTO password_resets
                        (
                            user_id,
                            otp_hash,
                            expires_at,
                            attempts,
                            used,
                            created_at
                        )
                        VALUES (?, ?, ?, 0, 0, NOW())
                    ");

                    $insert->execute([
                        $user["id"],
                        $otpHash,
                        $expiresAt
                    ]);

                    $pdo->commit();

                    $_SESSION["password_reset_user_id"] =
                        (int) $user["id"];

                    // Send OTP email
                    $mail = new PHPMailer(true);

                    $mail->isSMTP();
                    $mail->Host = getenv("SMTP_HOST") ?: ($_ENV["SMTP_HOST"] ?? "smtp.gmail.com");
                    $mail->SMTPAuth = true;

                    $mail->Username = getenv("SMTP_USERNAME") ?: ($_ENV["SMTP_USERNAME"] ?? "");
                    $mail->Password = getenv("SMTP_PASSWORD") ?: ($_ENV["SMTP_PASSWORD"] ?? "");

                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port = (int) (getenv("SMTP_PORT") ?: ($_ENV["SMTP_PORT"] ?? 587));

                    $fromName = getenv("SMTP_FROM_NAME") ?: ($_ENV["SMTP_FROM_NAME"] ?? "Subnext");
                    $fromEmail = getenv("SMTP_FROM_EMAIL") ?: ($_ENV["SMTP_FROM_EMAIL"] ?? ($mail->Username ?: "support@subnext.com.ng"));

                    $mail->setFrom($fromEmail, $fromName);

                    $mail->addAddress(
                        $user["email"],
                        $user["full_name"]
                    );

                    $mail->isHTML(true);

                    $mail->Subject = "Your Subnext Verification Code";

                    $mail->Body = "
                        <div style='max-width:540px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,Helvetica,Arial,sans-serif;line-height:1.6;color:#1e293b;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;padding:32px 28px;background:#ffffff;'>

                            <div style='text-align:center;margin-bottom:24px;'>
                                <div style='display:inline-block;width:44px;height:44px;line-height:44px;background:#3E37B7;color:#ffffff;font-size:22px;font-weight:900;border-radius:12px;'>S</div>
                                <h1 style='margin:12px 0 2px 0;font-size:24px;font-weight:800;color:#0f172a;letter-spacing:-0.02em;'>Subnext</h1>
                                <p style='margin:0;font-size:12px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;'>Digital Services, Simplified.</p>
                            </div>

                            <p style='font-size:15px;margin:0 0 16px 0;'>
                                Hello <strong>" . htmlspecialchars($user["full_name"], ENT_QUOTES, "UTF-8") . "</strong>,
                            </p>

                            <p style='font-size:14px;color:#475569;margin:0 0 20px 0;'>
                                We received a request to verify your identity and reset your Subnext account password. Please use the verification code below:
                            </p>

                            <div style='background-color:#F5F3FF;border:2px dashed #635BDB;border-radius:14px;padding:22px;text-align:center;margin:24px 0;'>
                                <span style='font-size:11px;font-weight:700;color:#635BDB;text-transform:uppercase;letter-spacing:0.1em;display:block;margin-bottom:6px;'>Your One-Time Passcode</span>
                                <span style='font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,\"Liberation Mono\",\"Courier New\",monospace;font-size:36px;font-weight:900;letter-spacing:10px;color:#3E37B7;display:inline-block;padding-left:10px;'>{$otp}</span>
                            </div>

                            <p style='font-size:13px;color:#475569;margin:0 0 14px 0;'>
                                ⏱️ This verification code is valid for <strong>10 minutes</strong>.
                            </p>

                            <p style='font-size:12px;color:#64748b;background:#f8fafc;border-left:3px solid #cbd5e1;padding:10px 14px;margin:0 0 24px 0;border-radius:4px;'>
                                🔒 <strong>Security Notice:</strong> Never share this verification code with anyone. Subnext representatives will never ask for your code.
                            </p>

                            <p style='font-size:13px;color:#64748b;margin:0 0 28px 0;'>
                                If you did not make this request, you can safely ignore this email — your account remains completely secure.
                            </p>

                            <p style='font-size:13px;color:#334155;margin:0;'>
                                Warm regards,<br>
                                <strong>Team Subnext</strong>
                            </p>

                            <div style='margin-top:32px;padding-top:20px;border-top:1px solid #f1f5f9;text-align:center;font-size:11px;color:#94a3b8;'>
                                <p style='margin:0 0 4px 0;font-weight:600;'>Subnext • Digital Services, Simplified.</p>
                                <p style='margin:0 0 4px 0;'>https://subnext.com.ng • support@subnext.com.ng</p>
                                <p style='margin:0;'>© " . date('Y') . " Subnext. All rights reserved.</p>
                            </div>

                        </div>
                    ";

                    $mail->AltBody =
                        "Hello " . $user["full_name"] . ",\n\n"
                        . "Your Subnext verification code is: " . $otp . "\n\n"
                        . "This code expires in 10 minutes. Never share this code with anyone.\n"
                        . "If you did not request this, please ignore this email.\n\n"
                        . "Subnext - Digital Services, Simplified.\n"
                        . "https://subnext.com.ng • support@subnext.com.ng";

                    $mail->send();

                    // Go directly to OTP page
                    header("Location: verify_otp.php");
                    exit;

                } catch (Exception $e) {

                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    error_log("OTP mail delivery failed: " . $e->getMessage());

                    $error =
                        "Unable to send verification OTP. Please try again or contact support@subnext.com.ng.";

                } catch (Throwable $e) {

                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    error_log("Password reset OTP error: " . $e->getMessage());

                    $error =
                        "Something went wrong. Please try again.";
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

    <title>Forgot Password | Subnext</title>

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
            background: #fff;
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
            margin-bottom: 8px;
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

        .back {
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
        Reset your password
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

        <label for="email">
            Email Address
        </label>

        <input
            type="email"
            name="email"
            id="email"
            placeholder="Enter your email"
            required
        >

        <button type="submit">
            Send Verification Code
        </button>

    </form>

    <a href="login.php" class="back">
        Back to Login
    </a>

    <p style="margin-top: 24px; font-size: 11px; color: #94a3b8; text-align: center;">© <?= date('Y') ?> Subnext. All rights reserved.</p>

</div>

<script src="assets/js/loader.js" defer></script>
</body>

</html>