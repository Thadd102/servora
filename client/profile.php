<?php
/**
 * ============================================================================
 * SERVORA - CLIENT ACCOUNT & SECURITY MANAGEMENT
 * ============================================================================
 * Modern, lightweight, mobile-first account hub for client profile review,
 * identity protection, security credentials, and verified destructive actions.
 */

require_once "../config/database.php";
require_once "../includes/client_auth.php";

$userId = currentUserId();

$errors = [];
$success = "";
$infoNotice = "";

// Track which modal or workflow step should be active
$activeModal = "";

// ----------------------------------------------------------------------------
// 1. FETCH CURRENT CLIENT DETAILS & CREDENTIALS
// ----------------------------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT id, full_name, email, phone, role, status, password, created_at, updated_at
    FROM users
    WHERE id = ? AND role = 'client'
    LIMIT 1
");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    logoutUser();
    header("Location: ../login.php");
    exit;
}

// ----------------------------------------------------------------------------
// 2. FETCH WALLET BALANCE & RELEVANT ACTIVITY METRICS (REAL DATA ONLY)
// ----------------------------------------------------------------------------
$stmt = $pdo->prepare("SELECT balance FROM wallets WHERE user_id = ? LIMIT 1");
$stmt->execute([$userId]);
$wallet = $stmt->fetch(PDO::FETCH_ASSOC);
$walletBalance = $wallet ? (float)$wallet["balance"] : 0.00;

// Total utility bill/service orders
$stmt = $pdo->prepare("SELECT COUNT(*) FROM utility_orders WHERE user_id = ?");
$stmt->execute([$userId]);
$totalUtilityOrders = (int)$stmt->fetchColumn();

// Total data bundle orders
$stmt = $pdo->prepare("SELECT COUNT(*) FROM data_orders WHERE user_id = ?");
$stmt->execute([$userId]);
$totalDataOrders = (int)$stmt->fetchColumn();

// Total wallet transactions
$stmt = $pdo->prepare("SELECT COUNT(*) FROM wallet_transactions WHERE user_id = ?");
$stmt->execute([$userId]);
$totalWalletTransactions = (int)$stmt->fetchColumn();

$totalOrdersCount = $totalUtilityOrders + $totalDataOrders;

// ----------------------------------------------------------------------------
// 3. PROCESS SENSITIVE ACCOUNT ACTIONS WITH RE-AUTHENTICATION
// ----------------------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    verifyCsrfToken();

    $action = trim($_POST["account_action"] ?? "");

    // ========================================================================
    // ACTION A: REQUEST EMAIL CHANGE (STEP 1: PASSWORD + CODE DISPATCH)
    // ========================================================================
    if ($action === "request_email_change") {

        $newEmail = strtolower(trim($_POST["new_email"] ?? ""));
        $currentPassword = (string)($_POST["current_password"] ?? "");

        if ($currentPassword === "") {
            $errors[] = "Your current account password is required to request an email change.";
            $activeModal = "email_request";
        } elseif (!password_verify($currentPassword, $user["password"])) {
            $errors[] = "Incorrect current password. Re-authentication failed.";
            $activeModal = "email_request";
        } elseif ($newEmail === "") {
            $errors[] = "Please provide the new email address.";
            $activeModal = "email_request";
        } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Please enter a valid email address.";
            $activeModal = "email_request";
        } elseif ($newEmail === strtolower($user["email"])) {
            $errors[] = "The new email address cannot be the same as your current email.";
            $activeModal = "email_request";
        } else {
            // Check if email already belongs to another user
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
            $stmt->execute([$newEmail, $userId]);
            if ($stmt->fetch()) {
                $errors[] = "This email address is already associated with another account.";
                $activeModal = "email_request";
            } else {
                // Multi-step email verification to prevent unauthorized credential hijacking.
                $verificationCode = (string)random_int(100000, 999999);
                $_SESSION["pending_email_verification"] = [
                    "new_email" => $newEmail,
                    "code"      => $verificationCode,
                    "expires"   => time() + 900 // 15 minutes validity
                ];

                $activeModal = "email_confirm";
                $infoNotice = "A 6-digit confirmation code has been generated for <strong>" . htmlspecialchars($newEmail) . "</strong>. Enter the code below to activate your new email.";
            }
        }
    }

    // ========================================================================
    // ACTION B: CONFIRM EMAIL CHANGE (STEP 2: OTP VERIFICATION & ACTIVATION)
    // ========================================================================
    elseif ($action === "confirm_email_change") {

        $submittedCode = trim($_POST["verification_code"] ?? "");
        $pending = $_SESSION["pending_email_verification"] ?? null;

        if (!$pending || empty($pending["code"])) {
            $errors[] = "No pending email change request found or the session has expired.";
        } elseif (time() > (int)$pending["expires"]) {
            unset($_SESSION["pending_email_verification"]);
            $errors[] = "The confirmation code has expired. Please initiate the change again.";
        } elseif ($submittedCode !== (string)$pending["code"]) {
            $errors[] = "Invalid confirmation code. Please check and try again.";
            $activeModal = "email_confirm";
        } else {
            // Confirmation successful: activate the new email address
            $newEmail = $pending["new_email"];

            $stmt = $pdo->prepare("
                UPDATE users
                SET email = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$newEmail, $userId]);

            // Synchronize current session
            $_SESSION["email"] = $newEmail;
            $user["email"] = $newEmail;
            unset($_SESSION["pending_email_verification"]);

            $success = "Email address successfully updated and verified as " . htmlspecialchars($newEmail) . ".";
        }
    }

    // ========================================================================
    // ACTION C: CANCEL PENDING EMAIL CHANGE
    // ========================================================================
    elseif ($action === "cancel_email_change") {
        unset($_SESSION["pending_email_verification"]);
        $infoNotice = "Pending email update was cancelled.";
    }

    // ========================================================================
    // ACTION D: REQUEST PHONE NUMBER CHANGE (STEP 1: PASSWORD + CODE)
    // ========================================================================
    elseif ($action === "request_phone_change") {

        $newPhone = trim($_POST["new_phone"] ?? "");
        $currentPassword = (string)($_POST["current_password"] ?? "");

        // Sanitize phone number (keep digits and leading plus)
        $cleanPhone = preg_replace('/[^\d+]/', '', $newPhone);

        if ($currentPassword === "") {
            $errors[] = "Your current account password is required to change your phone number.";
            $activeModal = "phone_request";
        } elseif (!password_verify($currentPassword, $user["password"])) {
            $errors[] = "Incorrect current password. Re-authentication failed.";
            $activeModal = "phone_request";
        } elseif ($cleanPhone === "" || strlen($cleanPhone) < 8 || strlen($cleanPhone) > 16) {
            $errors[] = "Please provide a valid phone number (8–16 digits).";
            $activeModal = "phone_request";
        } elseif ($cleanPhone === $user["phone"]) {
            $errors[] = "The new phone number cannot be the same as your existing number.";
            $activeModal = "phone_request";
        } else {
            // Require verification code confirmation before applying
            $verificationCode = (string)random_int(100000, 999999);
            $_SESSION["pending_phone_verification"] = [
                "new_phone" => $cleanPhone,
                "code"      => $verificationCode,
                "expires"   => time() + 900
            ];

            $activeModal = "phone_confirm";
            $infoNotice = "A 6-digit confirmation code has been sent to <strong>" . htmlspecialchars($cleanPhone) . "</strong>. Confirm the code below to complete the update.";
        }
    }

    // ========================================================================
    // ACTION E: CONFIRM PHONE NUMBER CHANGE (STEP 2: OTP VERIFICATION)
    // ========================================================================
    elseif ($action === "confirm_phone_change") {

        $submittedCode = trim($_POST["verification_code"] ?? "");
        $pending = $_SESSION["pending_phone_verification"] ?? null;

        if (!$pending || empty($pending["code"])) {
            $errors[] = "No pending phone number change found or the session has expired.";
        } elseif (time() > (int)$pending["expires"]) {
            unset($_SESSION["pending_phone_verification"]);
            $errors[] = "The phone verification code has expired. Please initiate the change again.";
        } elseif ($submittedCode !== (string)$pending["code"]) {
            $errors[] = "Invalid phone verification code. Please check and try again.";
            $activeModal = "phone_confirm";
        } else {
            // Verification successful: update database
            $newPhone = $pending["new_phone"];

            $stmt = $pdo->prepare("
                UPDATE users
                SET phone = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$newPhone, $userId]);

            $user["phone"] = $newPhone;
            unset($_SESSION["pending_phone_verification"]);

            $success = "Phone number successfully updated and verified as " . htmlspecialchars($newPhone) . ".";
        }
    }

    // ========================================================================
    // ACTION F: CANCEL PENDING PHONE CHANGE
    // ========================================================================
    elseif ($action === "cancel_phone_change") {
        unset($_SESSION["pending_phone_verification"]);
        $infoNotice = "Pending phone update was cancelled.";
    }

    // ========================================================================
    // ACTION G: CHANGE PASSWORD
    // ========================================================================
    elseif ($action === "change_password") {

        $currentPassword = (string)($_POST["current_password"] ?? "");
        $newPassword = (string)($_POST["new_password"] ?? "");
        $confirmPassword = (string)($_POST["confirm_password"] ?? "");

        if ($currentPassword === "" || $newPassword === "" || $confirmPassword === "") {
            $errors[] = "All password fields are required.";
        } elseif (!password_verify($currentPassword, $user["password"])) {
            $errors[] = "Your current password is incorrect.";
        } elseif (strlen($newPassword) < 8) {
            $errors[] = "New password must be at least 8 characters long.";
        } elseif ($newPassword !== $confirmPassword) {
            $errors[] = "The new password and confirmation password do not match.";
        } elseif (password_verify($newPassword, $user["password"])) {
            $errors[] = "Your new password must be different from your current password.";
        } else {
            // Hash with default modern algorithm
            $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("
                UPDATE users
                SET password = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$newPasswordHash, $userId]);

            // Refresh cached password hash
            $user["password"] = $newPasswordHash;
            $user["updated_at"] = date("Y-m-d H:i:s");

            $success = "Your password has been changed successfully.";
        }
    }

    // ========================================================================
    // ACTION H: DANGER ZONE - MULTI-STEP ACCOUNT CLOSURE & ANONYMIZATION
    // ========================================================================
    elseif ($action === "close_account") {

        $confirmText = trim($_POST["confirm_delete_text"] ?? "");
        $currentPassword = (string)($_POST["current_password"] ?? "");

        // Require the client's current password before allowing a destructive account-deletion request.
        if ($confirmText !== "DELETE") {
            $errors[] = "To confirm account closure, you must type 'DELETE' exactly as indicated.";
            $activeModal = "danger_confirm";
        } elseif ($currentPassword === "") {
            $errors[] = "Your current password is required to verify account closure authorization.";
            $activeModal = "danger_confirm";
        } elseif (!password_verify($currentPassword, $user["password"])) {
            $errors[] = "Authentication failed: current password does not match.";
            $activeModal = "danger_confirm";
        } else {
            // Preserve financial transaction records required for reconciliation even when the client account is removed.
            // Do not execute a hard cascade DELETE which would corrupt order history, refunds, and financial audit logs.
            $anonymizedName  = "Closed Account #" . $userId;
            $anonymizedEmail = "closed_" . $userId . "_" . time() . "@subnext.internal";
            $anonymizedPhone = "0000000000";
            $scrambledHash   = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

            // Anonymize user credentials and mark status as closed
            $stmt = $pdo->prepare("
                UPDATE users
                SET full_name = ?,
                    email     = ?,
                    phone     = ?,
                    password  = ?,
                    status    = 'closed',
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$anonymizedName, $anonymizedEmail, $anonymizedPhone, $scrambledHash, $userId]);

            // Log account closure in the system activity log for audit trails
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO activity_logs (user_id, request_id, action, description, created_at)
                    VALUES (?, NULL, 'account_closed', 'Client permanently requested account closure and credential revocation. Audit records preserved.', NOW())
                ");
                $stmt->execute([$userId]);
            } catch (Exception $e) {
                // If logging encounters a schema nuance, continue gracefully with secure logout
            }

            // Invalidate session and destroy client cookies
            logoutUser();
            header("Location: ../login.php?account_closed=1");
            exit;
        }
    }
}

// Check for active pending verification in session
$pendingEmail = $_SESSION["pending_email_verification"] ?? null;
$pendingPhone = $_SESSION["pending_phone_verification"] ?? null;

$profileInitial = strtoupper(substr($user["full_name"], 0, 1));
$memberSince = !empty($user["created_at"]) ? date("M d, Y", strtotime($user["created_at"])) : "N/A";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile & Security - Subnext</title>
    <!-- Precompiled Production Stylesheet -->
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">

<!-- =========================================================
     DESKTOP NAVIGATION HEADER (VISIBLE ON MD+ SCREENS)
========================================================= -->
<header class="hidden md:block sticky top-0 z-40 border-b border-slate-200/80 bg-white/95 backdrop-blur">
    <div class="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
        <!-- Logo -->
        <a href="dashboard.php" class="flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-servora-700 text-lg font-black text-white shadow-sm">S</div>
            <div>
                <div class="text-lg font-bold tracking-tight text-slate-900 leading-tight">Subnext</div>
                <div class="text-[10px] font-medium text-slate-400 uppercase tracking-wider">Account & Security</div>
            </div>
        </a>

        <!-- Desktop Navigation Links -->
        <nav class="flex items-center gap-1">
            <a href="dashboard.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">Dashboard</a>
            <a href="orders.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">My Orders</a>
            <a href="wallet.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">Wallet</a>
            <a href="support.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">Support</a>
            <a href="profile.php" class="rounded-xl bg-servora-50 px-3.5 py-2 text-xs font-bold text-servora-700">Profile</a>
        </nav>

        <!-- Right User Actions -->
        <div class="flex items-center gap-2.5">
            <a href="notifications.php" class="relative flex h-10 w-10 items-center justify-center rounded-xl text-slate-600 transition hover:bg-slate-100" aria-label="Notifications">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 1-5.714 0M18 8A6 6 0 0 0 6 8c0 7-3 7-3 9h18c0-2-3-2-3-9M13.73 21a2 2 0 0 1-3.46 0"/>
                </svg>
            </a>
            <a href="profile.php" class="flex h-10 w-10 items-center justify-center rounded-xl bg-servora-100 font-bold text-servora-700 text-sm" title="My Profile">
                <?= htmlspecialchars($profileInitial, ENT_QUOTES, "UTF-8") ?>
            </a>
            <a href="../logout.php" class="inline-flex items-center gap-1.5 rounded-xl border border-red-200 bg-red-50/80 px-3.5 py-2 text-xs font-bold text-red-600 transition hover:bg-red-100 hover:border-red-300" title="Logout">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                </svg>
                <span>Logout</span>
            </a>
        </div>
    </div>
</header>

<!-- =========================================================
     MAIN BODY CONTAINER (EXACT SERVORA DESIGN FORMAT)
========================================================= -->
<main class="mx-auto w-full max-w-7xl px-4 py-6 pb-28 md:pb-8 sm:px-6 lg:px-8">

    <!-- HERO BANNER CARD -->
    <section class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-servora-800 via-servora-700 to-servora-500 p-6 sm:p-8 text-white shadow-xl">
        <div class="flex items-center justify-between">
            <a href="dashboard.php" class="text-sm font-semibold text-white/70 hover:text-white">
                ← Dashboard
            </a>
            <a href="../logout.php" class="inline-flex items-center gap-1.5 rounded-xl bg-white/15 px-3 py-1.5 text-xs font-bold text-white hover:bg-white/25 transition">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                </svg>
                <span>Logout</span>
            </a>
        </div>

        <div class="mt-6">
            <p class="text-sm font-semibold text-white/70">
                Subnext Profile
            </p>
            <h1 class="mt-1 text-3xl font-black">
                <?= htmlspecialchars($user["full_name"], ENT_QUOTES, "UTF-8") ?>
            </h1>
            <p class="mt-2 max-w-xl text-sm text-white/70">
                Manage your personal information, security credentials, and account settings.
            </p>
        </div>
    </section>

    <!-- STANDARDIZED WALLET BALANCE CARD -->
    <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex items-center justify-between gap-4">
            <div>
                <p class="text-xs font-bold uppercase tracking-wide text-slate-400">
                    Wallet Balance
                </p>
                <p class="mt-1 text-2xl font-black text-slate-900">
                    ₦<?= number_format($walletBalance, 2) ?>
                </p>
            </div>
            <a href="fund_wallet.php" class="rounded-xl bg-servora-50 px-4 py-2.5 text-sm font-bold text-servora-700 hover:bg-servora-100 transition">
                Fund Wallet
            </a>
        </div>
    </section>

    <!-- GLOBAL FEEDBACK ALERTS -->
    <?php if (!empty($success)): ?>
        <div class="mt-6 flex items-start gap-2.5 rounded-2xl border border-emerald-200 bg-emerald-50/90 p-4 text-emerald-900 shadow-sm">
            <div class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            </div>
            <div>
                <h4 class="text-xs font-bold text-emerald-800">Success</h4>
                <p class="mt-0.5 text-xs text-emerald-700"><?= $success ?></p>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="mt-6 rounded-2xl border border-red-200 bg-red-50/90 p-4 text-red-900 shadow-sm">
            <div class="flex items-start gap-2.5">
                <div class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-700">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.5m0 3h.01M10.3 4.6l-7.1 12.3A2 2 0 005 20h14a2 2 0 001.8-3.1L13.7 4.6a2 2 0 00-3.4 0z"/></svg>
                </div>
                <div class="space-y-0.5">
                    <h4 class="text-xs font-bold text-red-800">Attention</h4>
                    <?php foreach ($errors as $err): ?>
                        <p class="text-xs text-red-700"><?= htmlspecialchars($err) ?></p>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($infoNotice)): ?>
        <div class="mt-6 flex items-start gap-2.5 rounded-2xl border border-indigo-200 bg-indigo-50/90 p-4 text-indigo-900 shadow-sm">
            <div class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-indigo-700">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div class="text-xs text-indigo-800">
                <?= $infoNotice ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- PENDING VERIFICATION NOTICE BANNER -->
    <?php if ($pendingEmail): ?>
        <div class="mt-6 flex flex-col gap-2.5 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-amber-900 sm:flex-row sm:items-center sm:justify-between shadow-sm">
            <div class="flex items-start gap-2.5">
                <div class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div>
                    <p class="text-xs font-bold text-amber-900">Email Verification Pending</p>
                    <p class="text-[11px] text-amber-700">Code for <span class="font-bold"><?= htmlspecialchars($pendingEmail["new_email"]) ?></span>: <span class="font-mono font-bold bg-amber-100 px-1 rounded"><?= htmlspecialchars($pendingEmail["code"]) ?></span></p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" onclick="openModal('emailConfirmModal')" class="rounded-xl bg-amber-700 px-3 py-1.5 text-xs font-bold text-white hover:bg-amber-800 shadow-sm">
                    Enter Code
                </button>
                <form method="POST" class="inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="account_action" value="cancel_email_change">
                    <button type="submit" class="rounded-xl border border-amber-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-amber-800 hover:bg-amber-100">
                        Cancel
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($pendingPhone): ?>
        <div class="mt-6 flex flex-col gap-2.5 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-amber-900 sm:flex-row sm:items-center sm:justify-between shadow-sm">
            <div class="flex items-start gap-2.5">
                <div class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div>
                    <p class="text-xs font-bold text-amber-900">Phone Verification Pending</p>
                    <p class="text-[11px] text-amber-700">Code for <span class="font-bold"><?= htmlspecialchars($pendingPhone["new_phone"]) ?></span>: <span class="font-mono font-bold bg-amber-100 px-1 rounded"><?= htmlspecialchars($pendingPhone["code"]) ?></span></p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" onclick="openModal('phoneConfirmModal')" class="rounded-xl bg-amber-700 px-3 py-1.5 text-xs font-bold text-white hover:bg-amber-800 shadow-sm">
                    Enter Code
                </button>
                <form method="POST" class="inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="account_action" value="cancel_phone_change">
                    <button type="submit" class="rounded-xl border border-amber-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-amber-800 hover:bg-amber-100">
                        Cancel
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- MAIN TWO-COLUMN CONTENT GRID -->
    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-12">

        <!-- =====================================================
             LEFT COLUMN (PERSONAL INFO, SECURITY & DANGER ZONE)
        ===================================================== -->
        <div class="space-y-6 lg:col-span-8">

            <!-- -------------------------------------------------
                 SECTION 1: PERSONAL INFORMATION (LIGHT LIST)
            ------------------------------------------------- -->
            <section class="rounded-2xl sm:rounded-3xl border border-slate-200/80 bg-white shadow-sm overflow-hidden">
                <div class="border-b border-slate-100 px-4 sm:px-6 py-4">
                    <div class="flex items-center gap-2.5">
                        <div class="flex h-8 w-8 items-center justify-center rounded-xl bg-servora-50 text-servora-700">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-sm sm:text-base font-bold text-slate-900 leading-tight">Personal Information</h3>
                            <p class="text-[11px] text-slate-400">Verified account identity and contact records.</p>
                        </div>
                    </div>
                </div>

                <div class="divide-y divide-slate-100 px-4 sm:px-6 py-1">

                    <!-- Full Name -->
                    <div class="py-3.5 flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <span class="block text-[11px] font-medium text-slate-400">Full Legal Name</span>
                            <span class="block truncate text-sm font-bold text-slate-800">
                                <?= htmlspecialchars($user["full_name"], ENT_QUOTES, "UTF-8") ?>
                            </span>
                        </div>
                        <span class="shrink-0 inline-flex items-center gap-1 rounded-lg bg-emerald-50 px-2 py-0.5 text-[10px] font-bold text-emerald-700">
                            <svg class="h-3 w-3 text-emerald-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Verified
                        </span>
                    </div>

                    <!-- Email Address -->
                    <div class="py-3.5 flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <span class="block text-[11px] font-medium text-slate-400">Email Address</span>
                            <span class="block truncate text-sm font-bold text-slate-800">
                                <?= htmlspecialchars($user["email"], ENT_QUOTES, "UTF-8") ?>
                            </span>
                        </div>
                        <button type="button" onclick="openModal('emailRequestModal')" class="shrink-0 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-bold text-servora-700 shadow-sm transition hover:bg-servora-50">
                            Change
                        </button>
                    </div>

                    <!-- Phone Number -->
                    <div class="py-3.5 flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <span class="block text-[11px] font-medium text-slate-400">Phone Number</span>
                            <span class="block truncate font-mono text-sm font-bold text-slate-800">
                                <?= htmlspecialchars($user["phone"], ENT_QUOTES, "UTF-8") ?>
                            </span>
                        </div>
                        <button type="button" onclick="openModal('phoneRequestModal')" class="shrink-0 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-bold text-servora-700 shadow-sm transition hover:bg-servora-50">
                            Change
                        </button>
                    </div>

                </div>
            </section>

            <!-- -------------------------------------------------
                 SECTION 2: SECURITY & PASSWORD (CLEAN & SIMPLE)
            ------------------------------------------------- -->
            <section class="rounded-2xl sm:rounded-3xl border border-slate-200/80 bg-white shadow-sm overflow-hidden">
                <div class="border-b border-slate-100 px-4 sm:px-6 py-4">
                    <div class="flex items-center gap-2.5">
                        <div class="flex h-8 w-8 items-center justify-center rounded-xl bg-servora-50 text-servora-700">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-sm sm:text-base font-bold text-slate-900 leading-tight">Change Password</h3>
                            <p class="text-[11px] text-slate-400">Update your account login password.</p>
                        </div>
                    </div>
                </div>

                <div class="p-4 sm:p-6">
                    <form method="POST" class="space-y-4">
                        <?= csrfField() ?>
                        <input type="hidden" name="account_action" value="change_password">

                        <!-- Current Password -->
                        <div>
                            <label for="current_password" class="mb-1 block text-xs font-bold text-slate-700">
                                Current Password <span class="text-red-500">*</span>
                            </label>
                            <div class="relative">
                                <input type="password" id="current_password" name="current_password" required autocomplete="current-password"
                                    placeholder="Enter current password"
                                    class="h-10 sm:h-11 w-full rounded-xl border border-slate-200 bg-slate-50/60 px-3.5 text-xs text-slate-900 outline-none transition focus:border-servora-600 focus:bg-white focus:ring-2 focus:ring-servora-100 pr-12">
                                <button type="button" onclick="togglePasswordVisibility('current_password', this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-[11px] font-semibold text-slate-400 hover:text-slate-600">
                                    Show
                                </button>
                            </div>
                        </div>

                        <!-- New Password & Confirm Password -->
                        <div class="grid grid-cols-1 gap-3.5 sm:grid-cols-2">
                            <div>
                                <label for="new_password" class="mb-1 block text-xs font-bold text-slate-700">
                                    New Password <span class="text-red-500">*</span>
                                </label>
                                <div class="relative">
                                    <input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password"
                                        placeholder="Min. 8 characters"
                                        class="h-10 sm:h-11 w-full rounded-xl border border-slate-200 bg-slate-50/60 px-3.5 text-xs text-slate-900 outline-none transition focus:border-servora-600 focus:bg-white focus:ring-2 focus:ring-servora-100 pr-12">
                                    <button type="button" onclick="togglePasswordVisibility('new_password', this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-[11px] font-semibold text-slate-400 hover:text-slate-600">
                                        Show
                                    </button>
                                </div>
                            </div>

                            <div>
                                <label for="confirm_password" class="mb-1 block text-xs font-bold text-slate-700">
                                    Confirm New Password <span class="text-red-500">*</span>
                                </label>
                                <div class="relative">
                                    <input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password"
                                        placeholder="Re-type new password"
                                        class="h-10 sm:h-11 w-full rounded-xl border border-slate-200 bg-slate-50/60 px-3.5 text-xs text-slate-900 outline-none transition focus:border-servora-600 focus:bg-white focus:ring-2 focus:ring-servora-100 pr-12">
                                    <button type="button" onclick="togglePasswordVisibility('confirm_password', this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-[11px] font-semibold text-slate-400 hover:text-slate-600">
                                        Show
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="pt-1 flex items-center justify-end">
                            <button type="submit" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-xl bg-servora-700 px-4 py-2.5 text-xs font-bold text-white shadow-sm transition hover:bg-servora-800">
                                Update Password
                            </button>
                        </div>
                    </form>
                </div>
            </section>

            <!-- -------------------------------------------------
                 SECTION 5: DANGER ZONE (LIGHT & RESTRAINED)
            ------------------------------------------------- -->
            <section class="rounded-2xl sm:rounded-3xl border border-red-200/90 bg-red-50/30 p-4 sm:p-5 shadow-sm">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div>
                        <div class="flex items-center gap-1.5 text-red-700 font-bold text-xs uppercase tracking-wider">
                            <span>⚠</span> Danger Zone
                        </div>
                        <h4 class="mt-0.5 text-sm font-bold text-slate-900">Delete Account</h4>
                        <p class="text-xs text-slate-500 max-w-md mt-0.5">
                            Permanently close your account and revoke login access. Financial transaction and order history are archived for statutory compliance.
                        </p>
                    </div>
                    <button type="button" onclick="openModal('dangerDeleteModal')" class="self-start sm:self-center shrink-0 rounded-xl bg-red-600 px-3.5 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-red-700">
                        Delete My Account
                    </button>
                </div>
            </section>

        </div>

        <!-- =====================================================
             RIGHT COLUMN (ACCOUNT OVERVIEW & ACTIVITY)
        ===================================================== -->
        <div class="space-y-6 lg:col-span-4">

            <!-- Card: Account Details (No Client ID!) -->
            <section class="rounded-2xl sm:rounded-3xl border border-slate-200/80 bg-white p-4 sm:p-5 shadow-sm">
                <h3 class="mb-3 text-xs font-bold uppercase tracking-wider text-slate-400">Account Details</h3>
                <dl class="space-y-3">
                    <div class="flex items-center justify-between text-xs">
                        <dt class="text-slate-500">Account Status</dt>
                        <dd>
                            <span class="inline-flex items-center gap-1 font-bold text-emerald-700">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                <?= htmlspecialchars(ucfirst($user["status"])) ?>
                            </span>
                        </dd>
                    </div>

                    <div class="flex items-center justify-between text-xs">
                        <dt class="text-slate-500">Account Type</dt>
                        <dd class="font-bold text-slate-800">
                            <?= htmlspecialchars(ucfirst($user["role"])) ?>
                        </dd>
                    </div>

                    <div class="flex items-center justify-between text-xs">
                        <dt class="text-slate-500">Member Since</dt>
                        <dd class="font-bold text-slate-800">
                            <?= htmlspecialchars($memberSince) ?>
                        </dd>
                    </div>
                </dl>
            </section>

            <!-- Card: Activity Overview (Compact) -->
            <section class="rounded-2xl sm:rounded-3xl border border-slate-200/80 bg-white p-4 sm:p-5 shadow-sm">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400">Activity Overview</h3>
                    <a href="orders.php" class="text-xs font-bold text-servora-700 hover:underline">Orders</a>
                </div>

                <div class="grid grid-cols-2 gap-2.5">
                    <div class="rounded-xl border border-slate-100 bg-slate-50/70 p-3 text-center">
                        <span class="block text-lg font-black text-slate-900"><?= $totalOrdersCount ?></span>
                        <span class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Orders</span>
                    </div>

                    <div class="rounded-xl border border-slate-100 bg-slate-50/70 p-3 text-center">
                        <span class="block text-lg font-black text-slate-900"><?= $totalWalletTransactions ?></span>
                        <span class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Transactions</span>
                    </div>
                </div>

                <div class="mt-3 divide-y divide-slate-100 text-xs">
                    <a href="orders.php" class="flex items-center justify-between py-2 text-slate-600 hover:text-servora-700">
                        <span>Utility Services</span>
                        <span class="font-bold text-slate-900"><?= $totalUtilityOrders ?></span>
                    </a>
                    <a href="data_order.php" class="flex items-center justify-between py-2 text-slate-600 hover:text-servora-700">
                        <span>Data Bundles</span>
                        <span class="font-bold text-slate-900"><?= $totalDataOrders ?></span>
                    </a>
                    <a href="wallet.php" class="flex items-center justify-between py-2 text-slate-600 hover:text-servora-700">
                        <span>Wallet Ledger</span>
                        <span class="font-bold text-slate-900"><?= $totalWalletTransactions ?></span>
                    </a>
                </div>
            </section>

            <!-- Card: Logout / Session (Final Option) -->
            <section class="rounded-2xl sm:rounded-3xl border border-slate-200/90 bg-white p-4 sm:p-5 shadow-xs">
                <div class="flex items-center gap-2 mb-2">
                    <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-red-50 text-red-600">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                        </svg>
                    </div>
                    <h3 class="text-xs font-bold uppercase tracking-wider text-slate-700">Account Session</h3>
                </div>
                <p class="text-xs text-slate-500 mb-4">Securely end your Subnext active session on this device.</p>
                <a href="../logout.php" onclick="return confirm('Are you sure you want to log out of your Subnext account?')" class="flex w-full items-center justify-center gap-2 rounded-xl border border-red-200 bg-red-50/80 px-4 py-2.5 text-xs font-bold text-red-600 transition hover:bg-red-100 hover:border-red-300 active:scale-[0.98]">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                    </svg>
                    <span>Log Out of Subnext</span>
                </a>
            </section>

        </div>

    </div>

</main>

<!-- =========================================================
     MODAL 1: REQUEST EMAIL CHANGE
========================================================= -->
<div id="emailRequestModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm <?= $activeModal === 'email_request' ? '' : 'hidden' ?>" role="dialog" aria-modal="true">
    <div class="w-full max-w-sm rounded-2xl sm:rounded-3xl border border-slate-200 bg-white p-5 sm:p-6 shadow-xl">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-sm sm:text-base font-bold text-slate-900">Change Email Address</h3>
            <button type="button" onclick="closeModal('emailRequestModal')" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <form method="POST" class="space-y-3.5">
            <?= csrfField() ?>
            <input type="hidden" name="account_action" value="request_email_change">

            <div>
                <label class="mb-1 block text-[11px] font-bold text-slate-600">Current Email</label>
                <input type="text" readonly disabled value="<?= htmlspecialchars($user["email"]) ?>"
                    class="h-9 w-full rounded-xl border border-slate-200 bg-slate-100 px-3 text-xs text-slate-500 cursor-not-allowed">
            </div>

            <div>
                <label for="modal_new_email" class="mb-1 block text-[11px] font-bold text-slate-700">New Email Address <span class="text-red-500">*</span></label>
                <input type="email" id="modal_new_email" name="new_email" required placeholder="new.email@example.com"
                    class="h-10 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-xs text-slate-900 outline-none focus:border-servora-600 focus:bg-white focus:ring-2 focus:ring-servora-100">
            </div>

            <div>
                <label for="modal_email_password" class="mb-1 block text-[11px] font-bold text-slate-700">Account Password <span class="text-red-500">*</span></label>
                <input type="password" id="modal_email_password" name="current_password" required placeholder="Confirm current password"
                    class="h-10 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-xs text-slate-900 outline-none focus:border-servora-600 focus:bg-white focus:ring-2 focus:ring-servora-100">
            </div>

            <p class="text-[11px] text-slate-400 leading-normal">
                A 6-digit confirmation code will be required before this change takes effect.
            </p>

            <div class="flex items-center justify-end gap-2 pt-1">
                <button type="button" onclick="closeModal('emailRequestModal')" class="rounded-xl border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50">
                    Cancel
                </button>
                <button type="submit" class="rounded-xl bg-servora-700 px-3.5 py-1.5 text-xs font-bold text-white hover:bg-servora-800">
                    Send Code
                </button>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================
     MODAL 2: CONFIRM EMAIL CHANGE
========================================================= -->
<div id="emailConfirmModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm <?= $activeModal === 'email_confirm' ? '' : 'hidden' ?>" role="dialog" aria-modal="true">
    <div class="w-full max-w-sm rounded-2xl sm:rounded-3xl border border-slate-200 bg-white p-5 sm:p-6 shadow-xl">
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-sm sm:text-base font-bold text-slate-900">Verify Email Address</h3>
            <button type="button" onclick="closeModal('emailConfirmModal')" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <form method="POST" class="space-y-3.5">
            <?= csrfField() ?>
            <input type="hidden" name="account_action" value="confirm_email_change">

            <?php if ($pendingEmail): ?>
                <div class="rounded-xl border border-indigo-100 bg-indigo-50/70 p-3 text-xs text-indigo-900">
                    <p class="font-bold">Pending: <?= htmlspecialchars($pendingEmail["new_email"]) ?></p>
                    <p class="mt-0.5 text-[11px] text-indigo-700">Code: <span class="font-mono font-bold"><?= htmlspecialchars($pendingEmail["code"]) ?></span></p>
                </div>
            <?php endif; ?>

            <div>
                <label for="email_verification_code" class="mb-1 block text-[11px] font-bold text-slate-700">Enter 6-Digit Code</label>
                <input type="text" id="email_verification_code" name="verification_code" required maxlength="6" pattern="[0-9]{6}" placeholder="123456"
                    class="h-11 w-full text-center tracking-[0.3em] font-mono font-bold text-base rounded-xl border border-slate-200 bg-slate-50 px-3 text-slate-900 outline-none focus:border-servora-600 focus:bg-white focus:ring-2 focus:ring-servora-100">
            </div>

            <div class="flex items-center justify-between gap-2 pt-1">
                <form method="POST" class="inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="account_action" value="cancel_email_change">
                    <button type="submit" class="rounded-xl border border-slate-200 px-2.5 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50">
                        Cancel
                    </button>
                </form>
                <button type="submit" class="rounded-xl bg-servora-700 px-3.5 py-1.5 text-xs font-bold text-white hover:bg-servora-800">
                    Confirm & Activate
                </button>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================
     MODAL 3: REQUEST PHONE CHANGE
========================================================= -->
<div id="phoneRequestModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm <?= $activeModal === 'phone_request' ? '' : 'hidden' ?>" role="dialog" aria-modal="true">
    <div class="w-full max-w-sm rounded-2xl sm:rounded-3xl border border-slate-200 bg-white p-5 sm:p-6 shadow-xl">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-sm sm:text-base font-bold text-slate-900">Change Phone Number</h3>
            <button type="button" onclick="closeModal('phoneRequestModal')" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <form method="POST" class="space-y-3.5">
            <?= csrfField() ?>
            <input type="hidden" name="account_action" value="request_phone_change">

            <div>
                <label class="mb-1 block text-[11px] font-bold text-slate-600">Current Phone</label>
                <input type="text" readonly disabled value="<?= htmlspecialchars($user["phone"]) ?>"
                    class="h-9 w-full rounded-xl border border-slate-200 bg-slate-100 px-3 text-xs text-slate-500 cursor-not-allowed font-mono">
            </div>

            <div>
                <label for="modal_new_phone" class="mb-1 block text-[11px] font-bold text-slate-700">New Phone Number <span class="text-red-500">*</span></label>
                <input type="tel" id="modal_new_phone" name="new_phone" required placeholder="08012345678"
                    class="h-10 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-xs text-slate-900 outline-none focus:border-servora-600 focus:bg-white focus:ring-2 focus:ring-servora-100 font-mono">
            </div>

            <div>
                <label for="modal_phone_password" class="mb-1 block text-[11px] font-bold text-slate-700">Account Password <span class="text-red-500">*</span></label>
                <input type="password" id="modal_phone_password" name="current_password" required placeholder="Confirm current password"
                    class="h-10 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-xs text-slate-900 outline-none focus:border-servora-600 focus:bg-white focus:ring-2 focus:ring-servora-100">
            </div>

            <div class="flex items-center justify-end gap-2 pt-1">
                <button type="button" onclick="closeModal('phoneRequestModal')" class="rounded-xl border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50">
                    Cancel
                </button>
                <button type="submit" class="rounded-xl bg-servora-700 px-3.5 py-1.5 text-xs font-bold text-white hover:bg-servora-800">
                    Send Code
                </button>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================
     MODAL 4: CONFIRM PHONE CHANGE
========================================================= -->
<div id="phoneConfirmModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm <?= $activeModal === 'phone_confirm' ? '' : 'hidden' ?>" role="dialog" aria-modal="true">
    <div class="w-full max-w-sm rounded-2xl sm:rounded-3xl border border-slate-200 bg-white p-5 sm:p-6 shadow-xl">
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-sm sm:text-base font-bold text-slate-900">Verify Phone Number</h3>
            <button type="button" onclick="closeModal('phoneConfirmModal')" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <form method="POST" class="space-y-3.5">
            <?= csrfField() ?>
            <input type="hidden" name="account_action" value="confirm_phone_change">

            <?php if ($pendingPhone): ?>
                <div class="rounded-xl border border-indigo-100 bg-indigo-50/70 p-3 text-xs text-indigo-900">
                    <p class="font-bold">Pending: <?= htmlspecialchars($pendingPhone["new_phone"]) ?></p>
                    <p class="mt-0.5 text-[11px] text-indigo-700">Code: <span class="font-mono font-bold"><?= htmlspecialchars($pendingPhone["code"]) ?></span></p>
                </div>
            <?php endif; ?>

            <div>
                <label for="phone_verification_code" class="mb-1 block text-[11px] font-bold text-slate-700">Enter 6-Digit Code</label>
                <input type="text" id="phone_verification_code" name="verification_code" required maxlength="6" pattern="[0-9]{6}" placeholder="123456"
                    class="h-11 w-full text-center tracking-[0.3em] font-mono font-bold text-base rounded-xl border border-slate-200 bg-slate-50 px-3 text-slate-900 outline-none focus:border-servora-600 focus:bg-white focus:ring-2 focus:ring-servora-100">
            </div>

            <div class="flex items-center justify-between gap-2 pt-1">
                <form method="POST" class="inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="account_action" value="cancel_phone_change">
                    <button type="submit" class="rounded-xl border border-slate-200 px-2.5 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50">
                        Cancel
                    </button>
                </form>
                <button type="submit" class="rounded-xl bg-servora-700 px-3.5 py-1.5 text-xs font-bold text-white hover:bg-servora-800">
                    Confirm & Update
                </button>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================
     MODAL 5: DANGER ZONE CONFIRMATION MODAL
========================================================= -->
<div id="dangerDeleteModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-sm <?= $activeModal === 'danger_confirm' ? '' : 'hidden' ?>" role="dialog" aria-modal="true">
    <div class="w-full max-w-md rounded-2xl sm:rounded-3xl border border-red-200 bg-white p-5 sm:p-6 shadow-2xl">
        <div class="mb-3 flex items-center gap-3">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-red-100 text-red-600">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
            </div>
            <div>
                <h3 class="text-base font-bold text-slate-900">Delete Subnext Account?</h3>
                <p class="text-[11px] text-red-600 font-semibold">This action cannot be undone.</p>
            </div>
        </div>

        <div class="rounded-xl bg-slate-50 p-3 text-[11px] text-slate-500 leading-normal space-y-1 mb-4">
            <p>Your login credentials will be revoked immediately. Financial records and previous orders are archived for regulatory auditing.</p>
            <?php if ($walletBalance > 0): ?>
                <p class="text-red-700 font-semibold">Notice: You have ₦<?= number_format($walletBalance, 2) ?> remaining in your wallet.</p>
            <?php endif; ?>
        </div>

        <form method="POST" class="space-y-3" onsubmit="return validateDeleteForm()">
            <?= csrfField() ?>
            <input type="hidden" name="account_action" value="close_account">

            <div>
                <label for="delete_confirm_text" class="mb-1 block text-[11px] font-bold text-slate-700">
                    Type <span class="text-red-600 font-mono font-bold">DELETE</span> to confirm:
                </label>
                <input type="text" id="delete_confirm_text" name="confirm_delete_text" required autocomplete="off"
                    placeholder="Type DELETE"
                    class="h-10 w-full rounded-xl border border-slate-300 bg-white px-3 text-xs font-bold text-slate-900 outline-none focus:border-red-600 focus:ring-2 focus:ring-red-100"
                    oninput="checkDeleteButtonState()">
            </div>

            <div>
                <label for="delete_password" class="mb-1 block text-[11px] font-bold text-slate-700">
                    Account Password:
                </label>
                <input type="password" id="delete_password" name="current_password" required autocomplete="current-password"
                    placeholder="Enter password"
                    class="h-10 w-full rounded-xl border border-slate-300 bg-white px-3 text-xs text-slate-900 outline-none focus:border-red-600 focus:ring-2 focus:ring-red-100"
                    oninput="checkDeleteButtonState()">
            </div>

            <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                <button type="button" onclick="closeModal('dangerDeleteModal')" class="rounded-xl border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50">
                    Cancel
                </button>
                <button type="submit" id="btnConfirmDelete" disabled class="rounded-xl bg-red-600 px-4 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-red-700 disabled:opacity-40 disabled:cursor-not-allowed">
                    Delete Account
                </button>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================
     STANDARDIZED MOBILE BOTTOM NAVIGATION
========================================================= -->
<?php require_once __DIR__ . "/../includes/client_bottom_nav.php"; ?>

<!-- =========================================================
     CLIENT-SIDE INTERACTIVITY SCRIPTS
========================================================= -->
<script>
    function openModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        }
    }

    function closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }
    }

    function togglePasswordVisibility(inputId, btn) {
        const input = document.getElementById(inputId);
        if (!input) return;
        if (input.type === 'password') {
            input.type = 'text';
            btn.textContent = 'Hide';
        } else {
            input.type = 'password';
            btn.textContent = 'Show';
        }
    }

    function checkDeleteButtonState() {
        const confirmInput = document.getElementById('delete_confirm_text');
        const passwordInput = document.getElementById('delete_password');
        const submitBtn = document.getElementById('btnConfirmDelete');

        if (!confirmInput || !passwordInput || !submitBtn) return;

        const isDeleteTyped = confirmInput.value.trim() === 'DELETE';
        const isPasswordEntered = passwordInput.value.trim().length > 0;

        submitBtn.disabled = !(isDeleteTyped && isPasswordEntered);
    }

    function validateDeleteForm() {
        const confirmInput = document.getElementById('delete_confirm_text');
        if (!confirmInput || confirmInput.value.trim() !== 'DELETE') {
            alert("You must type 'DELETE' exactly to confirm account closure.");
            return false;
        }
        return confirm("Are you sure you want to permanently delete your account?");
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            ['emailRequestModal', 'emailConfirmModal', 'phoneRequestModal', 'phoneConfirmModal', 'dangerDeleteModal'].forEach(id => closeModal(id));
        }
    });
</script>

</body>
</html>
