<?php

require_once "config/database.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . "/vendor/autoload.php";
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/includes/EmailService.php";

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

                    // Send OTP email using secure EmailService
                    $mailSent = EmailService::sendOtpEmail(
                        $user["email"],
                        $user["full_name"],
                        $otp,
                        "Password Reset"
                    );

                    if (!$mailSent) {
                        throw new RuntimeException("Mailer delivery returned false.");
                    }

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | Subnext</title>
    <meta name="description" content="Reset your Subnext account password securely.">

    <!-- Precompiled Production Stylesheet -->
    <link rel="stylesheet" href="assets/css/style.css">

</head>

<body class="min-h-screen bg-[#F6F7FB] flex items-center justify-center p-4 sm:p-6 text-slate-900 antialiased">

    <div class="w-full max-w-md">

        <!-- CARD -->
        <div class="rounded-3xl border border-slate-200/80 bg-white p-6 sm:p-8 shadow-xl shadow-slate-200/50">

            <!-- LOGO & HEADER -->
            <div class="text-center mb-6">

                <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-[#3E37B7] text-white shadow-lg shadow-indigo-200 mb-3">
                    <span class="text-2xl font-black">S</span>
                </div>

                <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">
                    Reset Password
                </h1>

                <p class="text-xs text-slate-500 mt-1 max-w-xs mx-auto">
                    Enter the email registered with your Subnext account and we'll send you a 6-digit verification code.
                </p>

            </div>

            <?php if ($error): ?>
                <div class="mb-5 rounded-2xl border border-rose-200 bg-rose-50/90 p-3.5 text-xs font-semibold text-rose-800 flex items-start gap-2.5">
                    <svg class="w-4 h-4 text-rose-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    <span class="leading-relaxed"><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-4">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>"
                >

                <div>
                    <label for="email" class="block text-xs sm:text-sm font-semibold text-slate-700 mb-1.5">
                        Email Address
                    </label>

                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 8l9 6 9-6M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <input
                            type="email"
                            name="email"
                            id="email"
                            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                            placeholder="you@example.com"
                            autocomplete="email"
                            required
                            class="w-full
                            h-14
                            rounded-xl
                            border border-gray-200
                            bg-gray-50
                            pl-12 pr-4
                            text-sm
                            text-slate-900
                            placeholder-slate-400
                            outline-none
                            transition
                            focus:bg-white
                            focus:border-[#5146C7]
                            focus:ring-4
                            focus:ring-[#5146C7]/10"
                        >
                    </div>
                </div>

                <button
                    type="submit"
                    class="w-full
                    h-14
                    rounded-xl
                    bg-[#3E37B7]
                    text-white
                    font-semibold
                    text-sm
                    shadow-lg
                    shadow-indigo-200
                    transition-all
                    duration-200
                    hover:bg-[#312E81]
                    hover:shadow-xl
                    active:scale-[0.98]
                    flex items-center justify-center gap-2"
                >
                    <span>Send Verification Code</span>
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                    </svg>
                </button>

            </form>

            <div class="mt-6 pt-5 border-t border-slate-100 text-center">
                <a
                    href="login.php"
                    class="inline-flex items-center gap-2 text-xs sm:text-sm font-semibold text-[#5146C7] hover:text-[#312E81] transition"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    <span>Back to Login</span>
                </a>
            </div>

        </div>

        <!-- FOOTER -->
        <p class="text-center text-[11px] text-slate-400 mt-6">
            &copy; <?= date('Y') ?> Subnext. Digital Services, Simplified.
        </p>

    </div>

    <script src="assets/js/loader.js" defer></script>
</body>

</html>