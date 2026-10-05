<?php

require_once "config/database.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . "/vendor/autoload.php";

session_start();

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
                    $mail->Host = "smtp.gmail.com";
                    $mail->SMTPAuth = true;

                    // Keep your working Gmail details here
                    $mail->Username = getenv("SMTP_USERNAME") ?: "";
                    $mail->Password = getenv("SMTP_PASSWORD") ?: "";

                    $mail->SMTPSecure =
                        PHPMailer::ENCRYPTION_STARTTLS;

                    $mail->Port = 587;

                    $mail->setFrom(
                        $mail->Username,
                        "Servora"
                    );

                    $mail->addAddress(
                        $user["email"],
                        $user["full_name"]
                    );

                    $mail->isHTML(true);

                    $mail->Subject =
                        "Servora Password Reset OTP";

                    $mail->Body = "
                        <div style='font-family:Arial,sans-serif;line-height:1.6'>

                            <h2>Servora</h2>

                            <p>
                                Hello " .
                                htmlspecialchars($user["full_name"]) .
                                ",
                            </p>

                            <p>
                                Your password reset OTP is:
                            </p>

                            <h1 style='letter-spacing:6px'>
                                {$otp}
                            </h1>

                            <p>
                                This OTP expires in
                                <strong>10 minutes</strong>.
                            </p>

                            <p>
                                If you did not request this,
                                simply ignore this email.
                            </p>

                            <p>
                                Regards,<br>
                                <strong>Servora</strong>
                            </p>

                        </div>
                    ";

                    $mail->AltBody =
                        "Your Servora password reset OTP is "
                        . $otp
                        . ". It expires in 10 minutes.";

                    $mail->send();

                    // Go directly to OTP page
                    header("Location: verify_otp.php");
                    exit;

                } catch (Exception $e) {

                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    $error =
                        "Unable to send OTP. Please try again.";

                } catch (Throwable $e) {

                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

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

    <title>Forgot Password | Servora</title>

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

    <div class="logo">Servora</div>

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
            Send OTP
        </button>

    </form>

    <a href="login.php" class="back">
        Back to Login
    </a>

</div>

</body>

</html>