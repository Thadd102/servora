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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Security Code | Subnext</title>
    <meta name="description" content="Enter your Subnext verification OTP to proceed.">

    <!-- Precompiled Production Stylesheet -->
    <link rel="stylesheet" href="assets/css/style.css">

</head>

<body class="min-h-screen bg-[#F6F7FB] flex items-center justify-center p-4 sm:p-6 text-slate-900 antialiased">

    <div class="w-full max-w-md">

        <!-- CARD -->
        <div
            class="rounded-3xl border border-slate-200/80 bg-white p-6 sm:p-8 shadow-xl shadow-slate-200/50 text-center">

            <!-- LOGO & HEADER -->
            <div class="mb-6">

                <div
                    class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-[#3E37B7] text-white shadow-lg shadow-indigo-200 mb-3">
                    <span class="text-2xl font-black">S</span>
                </div>

                <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">
                    Verify Code
                </h1>

                <p class="text-xs text-slate-500 mt-1 max-w-xs mx-auto">
                    Enter the 6-digit verification code sent to your registered email address.
                </p>

            </div>

            <?php if ($error): ?>
                <div
                    class="mb-5 rounded-2xl border border-rose-200 bg-rose-50/90 p-3.5 text-xs font-semibold text-rose-800 flex items-start gap-2.5 text-left">
                    <span class="text-base shrink-0 leading-none">⚠️</span>
                    <span class="leading-relaxed"><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-4">

                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>">

                <div>
                    <label for="otp" class="sr-only">
                        6-Digit OTP
                    </label>

                    <input type="text" name="otp" id="otp" maxlength="6" inputmode="numeric" pattern="[0-9]{6}"
                        placeholder="000000" autocomplete="one-time-code" required autofocus class="w-full
                        h-16
                        rounded-2xl
                        border border-slate-200
                        bg-slate-50
                        px-4
                        text-center
                        font-mono
                        text-3xl
                        font-extrabold
                        tracking-[0.35em]
                        text-[#3E37B7]
                        placeholder-slate-300
                        outline-none
                        transition
                        focus:bg-white
                        focus:border-[#5146C7]
                        focus:ring-4
                        focus:ring-[#5146C7]/10">

                    <p class="mt-2 text-[11px] text-slate-400">Code expires in 10 minutes</p>
                </div>

                <button type="submit" class="w-full
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
                    flex items-center justify-center gap-2">
                    Verify Code →
                </button>

            </form>

            <div class="mt-6 pt-5 border-t border-slate-100 flex items-center justify-between text-xs">
                <a href="forgot_password.php" class="font-semibold text-[#5146C7] hover:text-[#312E81] transition">
                    Request New Code
                </a>
                <a href="login.php" class="font-medium text-slate-500 hover:text-slate-800 transition">
                    Back to Login
                </a>
            </div>

        </div>

        <!-- FOOTER -->
        <p class="text-center text-[11px] text-slate-400 mt-6">
            © <?= date('Y') ?> Subnext. Digital Services, Simplified.
        </p>

    </div>

    <script src="assets/js/loader.js" defer></script>
</body>

</html>