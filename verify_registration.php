<?php

require_once "config/database.php";
require_once "includes/auth.php";
require_once "includes/EmailService.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect already logged in clients
if (isLoggedIn()) {
    $currentRole = currentUserRole();
    if ($currentRole === "client") {
        header("Location: client/dashboard.php");
        exit;
    } elseif (in_array($currentRole, ["admin", "super_admin"], true)) {
        header("Location: admin/dashboard.php");
        exit;
    }
}

// Verify pending registration session exists
if (empty($_SESSION['pending_reg_id'])) {
    header("Location: register.php");
    exit;
}

$pendingId = (int) $_SESSION['pending_reg_id'];

// Fetch the pending registration record
$stmt = $pdo->prepare("
    SELECT *
    FROM registration_verifications
    WHERE id = ? AND used = 0
    LIMIT 1
");
$stmt->execute([$pendingId]);
$pending = $stmt->fetch();

if (!$pending) {
    unset($_SESSION['pending_reg_id'], $_SESSION['pending_reg_email'], $_SESSION['pending_reg_name']);
    header("Location: register.php?error=expired");
    exit;
}

// Create CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$error = '';
$success = '';

// Calculate timings
$now = time();
$expiresAtTimestamp = strtotime($pending['expires_at']);
$cooldownUntilTimestamp = strtotime($pending['resend_cooldown_until']);
$secondsLeftUntilExpiry = max(0, $expiresAtTimestamp - $now);
$secondsLeftUntilResend = max(0, $cooldownUntilTimestamp - $now);

// Mask email for privacy (e.g. j***e@domain.com)
function maskEmailAddress(string $email): string
{
    $parts = explode('@', $email);
    if (count($parts) !== 2) {
        return $email;
    }
    $name = $parts[0];
    $domain = $parts[1];
    $len = strlen($name);
    if ($len <= 2) {
        $maskedName = substr($name, 0, 1) . '*';
    } else {
        $maskedName = substr($name, 0, 1) . str_repeat('*', max(3, $len - 2)) . substr($name, -1);
    }
    return $maskedName . '@' . $domain;
}

$maskedEmail = maskEmailAddress($pending['email']);

// Handle POST submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        empty($_POST['csrf_token']) ||
        !hash_equals($csrfToken, $_POST['csrf_token'])
    ) {
        $error = "Security token expired or invalid. Please try again.";
    } else {
        $action = $_POST['action'] ?? 'verify';

        if ($action === 'verify') {
            $otp = trim($_POST['otp'] ?? '');

            if (!preg_match('/^\d{6}$/', $otp)) {
                $error = "Please enter a valid 6-digit numeric verification code.";
            } elseif ((int)$pending['attempts'] >= 5) {
                $error = "Too many incorrect attempts (5/5). Please request a fresh code below.";
            } elseif (strtotime($pending['expires_at']) < time()) {
                $error = "This verification code has expired (valid for 5 minutes). Please click 'Resend Code' below.";
            } else {
                // Increment attempt counter in database
                $incStmt = $pdo->prepare("
                    UPDATE registration_verifications
                    SET attempts = attempts + 1
                    WHERE id = ?
                ");
                $incStmt->execute([$pendingId]);

                if (!password_verify($otp, $pending['otp_hash'])) {
                    $remainingAttempts = max(0, 4 - (int)$pending['attempts']);
                    $error = "Incorrect verification code. Attempts remaining: {$remainingAttempts}.";
                    
                    // Reload record
                    $stmt->execute([$pendingId]);
                    $pending = $stmt->fetch();
                } else {
                    // OTP is valid! Create the account
                    try {
                        // Check if email was created concurrently
                        $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                        $chk->execute([$pending['email']]);
                        if ($chk->fetch()) {
                            $error = "An account with this email address has already been registered. Please log in.";
                        } else {
                            $pdo->beginTransaction();

                            $userStmt = $pdo->prepare("
                                INSERT INTO users (full_name, email, phone, password, role, status)
                                VALUES (?, ?, ?, ?, 'client', 'active')
                            ");
                            $userStmt->execute([
                                $pending['full_name'],
                                $pending['email'],
                                $pending['phone'],
                                $pending['password_hash']
                            ]);
                            $newUserId = (int) $pdo->lastInsertId();

                            // Create wallet with 0.00 balance
                            $walletStmt = $pdo->prepare("
                                INSERT INTO wallets (user_id, balance)
                                VALUES (?, 0.00)
                            ");
                            $walletStmt->execute([$newUserId]);

                            // Mark verification as used
                            $usedStmt = $pdo->prepare("
                                UPDATE registration_verifications
                                SET used = 1
                                WHERE id = ?
                            ");
                            $usedStmt->execute([$pendingId]);

                            $pdo->commit();

                            // Invalidate pending session variables
                            unset($_SESSION['pending_reg_id'], $_SESSION['pending_reg_email'], $_SESSION['pending_reg_name']);

                            // Log in user and regenerate session
                            session_regenerate_id(true);
                            $_SESSION['user_id'] = $newUserId;
                            $_SESSION['full_name'] = $pending['full_name'];
                            $_SESSION['email'] = $pending['email'];
                            $_SESSION['role'] = 'client';

                            header("Location: client/dashboard.php?welcome=1");
                            exit;
                        }
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log("[RegisterOTP] Account creation error: " . $e->getMessage());
                        $error = "Account setup failed. Please try again or contact support.";
                    }
                }
            }
        } elseif ($action === 'resend') {
            if (time() < strtotime($pending['resend_cooldown_until'])) {
                $wait = strtotime($pending['resend_cooldown_until']) - time();
                $error = "Please wait {$wait} second(s) before requesting another code.";
            } else {
                $newOtp = (string) random_int(100000, 999999);
                $newOtpHash = password_hash($newOtp, PASSWORD_DEFAULT);

                $resendStmt = $pdo->prepare("
                    UPDATE registration_verifications
                    SET otp_hash = ?,
                        attempts = 0,
                        resend_cooldown_until = DATE_ADD(NOW(), INTERVAL 60 SECOND),
                        expires_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE)
                    WHERE id = ?
                ");
                $resendStmt->execute([$newOtpHash, $pendingId]);

                $mailSent = EmailService::sendOtpEmail(
                    $pending['email'],
                    $pending['full_name'],
                    $newOtp,
                    'Account Registration',
                    5
                );

                if ($mailSent) {
                    $success = "A new 6-digit verification code has been sent to your email.";
                } else {
                    $lastErr = EmailService::getLastError();
                    $error = "Could not send verification email: " . htmlspecialchars($lastErr ?: 'Mailer error', ENT_QUOTES, 'UTF-8');
                }

                // Refresh pending record
                $stmt->execute([$pendingId]);
                $pending = $stmt->fetch();
                $expiresAtTimestamp = strtotime($pending['expires_at']);
                $cooldownUntilTimestamp = strtotime($pending['resend_cooldown_until']);
                $secondsLeftUntilExpiry = max(0, $expiresAtTimestamp - time());
                $secondsLeftUntilResend = max(0, $cooldownUntilTimestamp - time());
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
    <title>Verify Your Email | Subnext</title>
    <meta name="description" content="Enter your Subnext 6-digit verification code to complete your registration.">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="icon" type="image/svg+xml" href="assets/images/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/images/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/images/favicon-16x16.png">
    <link rel="shortcut icon" href="favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/images/apple-touch-icon.png">
</head>
<body class="min-h-screen bg-[#F6F7FB] flex items-center justify-center p-4 sm:p-6 text-slate-900 antialiased">

    <div class="w-full max-w-md">

        <!-- BRAND LOGO -->
        <div class="mb-6 text-center">
            <a href="index.php" class="inline-flex items-center gap-2.5">
                <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-gradient-to-br from-[#3E37B7] to-[#635BDB] text-white font-black text-xl shadow-md shadow-servora-700/20">
                    S
                </div>
                <span class="text-2xl font-black tracking-tight text-slate-900">Subnext</span>
            </a>
        </div>

        <!-- MAIN VERIFICATION CARD -->
        <div class="rounded-3xl border border-slate-200/80 bg-white p-6 sm:p-8 shadow-xl shadow-slate-200/50 text-center">

            <!-- ICON -->
            <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-servora-50 text-servora-700">
                <svg class="h-7 w-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                </svg>
            </div>

            <h1 class="text-2xl font-bold tracking-tight text-slate-900">Verify Your Email</h1>
            <p class="mt-2 text-xs text-slate-500 sm:text-sm leading-relaxed">
                We sent a 6-digit verification code to<br>
                <strong class="font-semibold text-slate-900"><?= htmlspecialchars($maskedEmail, ENT_QUOTES, 'UTF-8') ?></strong>
            </p>

            <!-- NOTIFICATIONS -->
            <?php if ($success): ?>
                <div class="mt-4 rounded-2xl border border-emerald-200 bg-emerald-50 p-3.5 text-xs sm:text-sm font-semibold text-emerald-800 shadow-sm text-left flex items-start gap-2">
                    <svg class="h-5 w-5 text-emerald-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                    <span><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="mt-4 rounded-2xl border border-red-200 bg-red-50 p-3.5 text-xs sm:text-sm font-semibold text-red-800 shadow-sm text-left flex items-start gap-2">
                    <svg class="h-5 w-5 text-red-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            <?php endif; ?>

            <!-- VERIFY FORM -->
            <form method="POST" action="verify_registration.php" class="mt-6 space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="verify">

                <div>
                    <label for="otp" class="sr-only">6-Digit Verification Code</label>
                    <input
                        type="text"
                        name="otp"
                        id="otp"
                        maxlength="6"
                        inputmode="numeric"
                        pattern="[0-9]*"
                        autocomplete="one-time-code"
                        placeholder="••••••"
                        required
                        autofocus
                        class="w-full rounded-2xl border border-slate-300 bg-slate-50/50 py-3.5 text-center font-mono text-2xl font-bold tracking-[0.4em] text-slate-900 shadow-sm focus:border-servora-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-servora-100 transition"
                    >
                </div>

                <!-- 5-MINUTE EXPIRATION NOTICE -->
                <div class="flex items-center justify-center gap-1.5 text-xs text-slate-500">
                    <svg class="h-4 w-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span>Code expires in: <strong id="expiry-timer" class="font-mono text-slate-800">--:--</strong></span>
                </div>

                <button
                    type="submit"
                    class="w-full rounded-2xl bg-servora-700 hover:bg-servora-800 py-3.5 text-sm font-bold text-white shadow-lg shadow-servora-700/25 transition active:scale-95"
                >
                    Confirm & Activate Account
                </button>
            </form>

            <!-- RESEND FORM WITH COOLDOWN -->
            <div class="mt-6 border-t border-slate-100 pt-5">
                <form method="POST" action="verify_registration.php" id="resend-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="resend">

                    <p class="text-xs text-slate-500">Didn't receive the email? Check spam or resend.</p>
                    <button
                        type="submit"
                        id="resend-btn"
                        class="mt-2 text-xs font-bold text-servora-700 hover:text-servora-900 disabled:opacity-50 disabled:cursor-not-allowed transition"
                    >
                        <span id="resend-label">Resend Code</span>
                    </button>
                </form>
            </div>

            <!-- START OVER LINK -->
            <div class="mt-4 pt-3 border-t border-slate-100 text-center">
                <a href="register.php?reset=1" class="text-xs font-semibold text-slate-400 hover:text-slate-600 transition">
                    &larr; Wrong email? Start registration over
                </a>
            </div>

        </div>

        <p class="mt-6 text-center text-xs text-slate-400">
            © <?= date('Y') ?> Subnext. Digital Services, Simplified.
        </p>

    </div>

    <!-- FLOATING WHATSAPP SUPPORT ICON -->
    

    <!-- TIMERS JAVASCRIPT -->
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        // Expiration Countdown (5 minutes)
        var expirySeconds = <?= (int)$secondsLeftUntilExpiry ?>;
        var expiryEl = document.getElementById('expiry-timer');

        function updateExpiryDisplay() {
            if (expirySeconds <= 0) {
                expiryEl.textContent = 'Expired';
                expiryEl.classList.add('text-red-600');
                return;
            }
            var mins = Math.floor(expirySeconds / 60);
            var secs = expirySeconds % 60;
            expiryEl.textContent = (mins < 10 ? '0' : '') + mins + ':' + (secs < 10 ? '0' : '') + secs;
            expirySeconds--;
            setTimeout(updateExpiryDisplay, 1000);
        }
        updateExpiryDisplay();

        // Resend Cooldown Countdown (60 seconds)
        var resendSeconds = <?= (int)$secondsLeftUntilResend ?>;
        var resendBtn = document.getElementById('resend-btn');
        var resendLabel = document.getElementById('resend-label');

        function updateResendDisplay() {
            if (resendSeconds > 0) {
                resendBtn.disabled = true;
                resendLabel.textContent = 'Resend Code in ' + resendSeconds + 's';
                resendSeconds--;
                setTimeout(updateResendDisplay, 1000);
            } else {
                resendBtn.disabled = false;
                resendLabel.textContent = 'Resend Code';
            }
        }
        updateResendDisplay();
    });
    </script>

    <script src="assets/js/loader.js" defer></script>
</body>
</html>

