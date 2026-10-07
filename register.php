<?php

require_once "config/database.php";
require_once "includes/auth.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ------------------------------------------------------------
// CHECK LIVE MAINTENANCE MODE STATUS
// ------------------------------------------------------------
$settingStmt = $pdo->prepare("
    SELECT setting_value
    FROM app_settings
    WHERE setting_key = 'client_login_enabled'
    LIMIT 1
");
$settingStmt->execute();
$clientLoginSetting = $settingStmt->fetchColumn();
$isMaintenanceActive = ($clientLoginSetting !== false && (string) $clientLoginSetting === "0");

// ------------------------------------------------------------
// REDIRECT ALREADY LOGGED IN USERS
// ------------------------------------------------------------
if (isLoggedIn()) {
    $currentRole = currentUserRole();
    if ($currentRole === "client") {
        if ($isMaintenanceActive) {
            logoutUser();
        } else {
            header("Location: client/dashboard.php");
            exit;
        }
    } elseif (in_array($currentRole, ["admin", "super_admin"], true)) {
        header("Location: admin/dashboard.php");
        exit;
    }
}

/* =========================
   ANTI-BOT MATH CAPTCHA
========================= */

function generateRegistrationCaptcha(): array
{
    $operations = ['+', '-', '×'];
    $op = $operations[random_int(0, 2)];

    if ($op === '+') {
        $n1 = random_int(2, 14);
        $n2 = random_int(1, 9);
        $ans = $n1 + $n2;
    } elseif ($op === '-') {
        $n1 = random_int(5, 18);
        $n2 = random_int(1, $n1 - 1);
        $ans = $n1 - $n2;
    } else { // multiplication
        $n1 = random_int(2, 6);
        $n2 = random_int(2, 5);
        $ans = $n1 * $n2;
    }

    return [
        'question' => "Solve this: {$n1} {$op} {$n2} = ?",
        'answer' => $ans,
        'created_at' => time()
    ];
}

/**
 * Validates that a phone number is a genuine, active telephone number.
 * - Supports genuine 11-digit Nigerian mobile numbers (MTN, Airtel, Glo, 9mobile).
 * - Normalizes +234 / 234 / local 0 formats.
 * - Rejects dummy/fake numbers (e.g. 0990, 08012345678, repeating digits, invalid prefixes).
 * - Also supports valid international numbers (E.164 standard: + followed by 8-15 digits).
 */
function validateRealPhoneNumber(string $phone): array {
    $raw = trim($phone);
    if ($raw === '') {
        return [
            'valid' => false,
            'phone' => '',
            'error' => 'Phone number is required.'
        ];
    }

    // Strip formatting spaces, hyphens, brackets, and dots
    $clean = preg_replace('/[\s\-\(\)\.]/', '', $raw);

    // Normalize Nigerian phone numbers with country code (+234 or 234)
    if (str_starts_with($clean, '+234')) {
        $clean = '0' . substr($clean, 4);
    } elseif (str_starts_with($clean, '234') && strlen($clean) === 13) {
        $clean = '0' . substr($clean, 3);
    }

    // 1. Check for standard Nigerian Mobile Number (11 digits)
    if (preg_match('/^0\d{10}$/', $clean)) {
        // Officially assigned Nigerian telecom mobile prefixes
        $validPrefixes = [
            // MTN Nigeria
            '0703', '0704', '0706', '0707', '0803', '0806', '0810', '0813', '0814', '0816', '0903', '0906', '0913', '0916', '0702',
            // Airtel Nigeria
            '0701', '0708', '0802', '0808', '0812', '0901', '0902', '0904', '0907', '0911', '0912',
            // Globacom (Glo)
            '0705', '0805', '0807', '0811', '0815', '0905', '0915',
            // 9mobile
            '0809', '0817', '0818', '0908', '0909',
            // Other licensed national operators
            '0804', '0819'
        ];

        $prefix = substr($clean, 0, 4);
        if (!in_array($prefix, $validPrefixes, true)) {
            return [
                'valid' => false,
                'phone' => '',
                'error' => 'Please enter a valid phone number. The prefix (' . htmlspecialchars($prefix) . ') is not recognized.'
            ];
        }

        // Reject obvious dummy/placeholder sequences
        $dummyNumbers = [
            '08012345678', '08087654321', '08000000000', '07000000000',
            '09000000000', '08100000000', '09100000000', '08099999999'
        ];
        if (in_array($clean, $dummyNumbers, true)) {
            return [
                'valid' => false,
                'phone' => '',
                'error' => 'Please enter a real, active phone number.'
            ];
        }

        // Reject repeating suffix digits (e.g. 08030000000, 08031111111, 09039999999)
        $suffix = substr($clean, 4);
        if (preg_match('/^(.)\1{6,}$/', $suffix)) {
            return [
                'valid' => false,
                'phone' => '',
                'error' => 'Please enter a real, active phone number.'
            ];
        }

        return [
            'valid' => true,
            'phone' => $clean,
            'error' => ''
        ];
    }

    // 2. Check for valid International Phone Number (E.164: + followed by 8 to 15 digits)
    if (preg_match('/^\+[1-9]\d{8,14}$/', $clean)) {
        // Reject repeating dummy digits like +111111111111
        if (preg_match('/^\+[1-9](.)\1{7,}$/', $clean)) {
            return [
                'valid' => false,
                'phone' => '',
                'error' => 'Please enter a real international phone number.'
            ];
        }

        return [
            'valid' => true,
            'phone' => $clean,
            'error' => ''
        ];
    }

    // Invalid length or format
    return [
        'valid' => false,
        'phone' => '',
        'error' => 'Please enter a valid 11-digit phone number (e.g. 08031234567) or international number with country code.'
    ];
}

// Interactive AJAX refresh request
if (isset($_GET['refresh_captcha'])) {
    $c = generateRegistrationCaptcha();
    $_SESSION['reg_captcha_answer'] = $c['answer'];
    $_SESSION['reg_captcha_question'] = $c['question'];
    $_SESSION['reg_captcha_time'] = $c['created_at'];

    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success',
        'question' => $c['question']
    ]);
    exit;
}

/* =========================
   CSRF TOKEN
========================= */

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

$error = '';

/* =========================
   REGISTER
========================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* =========================
       MAINTENANCE & CSRF CHECK
    ========================= */

    if ($isMaintenanceActive) {
        $error = "Registration is temporarily paused while Subnext is in maintenance mode. Please try again later.";

    } elseif (
        empty($_POST['csrf_token']) ||
        empty($_SESSION['csrf_token']) ||
        !hash_equals(
            $_SESSION['csrf_token'],
            $_POST['csrf_token']
        )
    ) {
        $error = "Invalid security token.";

    } else {

        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        /* =========================
           CAPTCHA VERIFICATION
        ========================= */
        $captchaInput = trim($_POST['captcha_answer'] ?? '');
        $storedCaptcha = $_SESSION['reg_captcha_answer'] ?? null;
        $captchaTime = (int)($_SESSION['reg_captcha_time'] ?? 0);

        // Invalidate and rotate the question immediately to prevent reuse
        $freshCaptcha = generateRegistrationCaptcha();
        $_SESSION['reg_captcha_answer'] = $freshCaptcha['answer'];
        $_SESSION['reg_captcha_question'] = $freshCaptcha['question'];
        $_SESSION['reg_captcha_time'] = $freshCaptcha['created_at'];

        /* =========================
           DISCLAIMER / TERMS CHECK
        ========================= */

        $acceptedDisclaimer = isset($_POST['disclaimer'])
            && $_POST['disclaimer'] === '1';

        /* =========================
           PHONE VALIDATION
        ========================= */
        $phoneValidation = validateRealPhoneNumber($phone);

        if ($captchaInput === '' || !is_numeric($captchaInput) || $storedCaptcha === null || (int)$captchaInput !== (int)$storedCaptcha) {

            $error = "Incorrect answer to security question. Please solve the new question.";

        } elseif ($captchaTime > 0 && (time() - $captchaTime) > 900) {

            $error = "Security question expired. Please solve the new question.";

        } elseif (!$acceptedDisclaimer) {

            $error = "You must agree to the Terms & Conditions before creating an account.";

        } elseif ($fullName === '') {

            $error = "Full name is required.";

        } elseif (strlen($fullName) < 2) {

            $error = "Please enter your full name.";

        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

            $error = "Please enter a valid email address.";

        } elseif (!$phoneValidation['valid']) {

            $error = $phoneValidation['error'];

        } elseif ($password === '') {

            $error = "Password is required.";

        } elseif (strlen($password) < 8) {

            $error = "Password must be at least 8 characters.";

        } elseif ($password !== $confirmPassword) {

            $error = "Passwords do not match.";

        } else {

            // Use the sanitized and normalized phone number
            $phone = $phoneValidation['phone'];

            try {

                /* =========================
                   CHECK EMAIL
                ========================= */

                $stmt = $pdo->prepare("
                    SELECT id
                    FROM users
                    WHERE email = ?
                    LIMIT 1
                ");

                $stmt->execute([$email]);

                if ($stmt->fetch()) {

                    $error = "An account with this email already exists.";

                } else {

                    $pdo->beginTransaction();

                    /* =========================
                       CREATE USER
                    ========================= */

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
                        VALUES (?, ?, ?, ?, 'client', 'active')
                    ");

                    $stmt->execute([
                        $fullName,
                        $email,
                        $phone,
                        $hashedPassword
                    ]);

                    $userId = (int) $pdo->lastInsertId();

                    /* =========================
                       CREATE WALLET
                    ========================= */

                    $stmt = $pdo->prepare("
                        INSERT INTO wallets
                        (
                            user_id,
                            balance
                        )
                        VALUES (?, 0.00)
                    ");

                    $stmt->execute([
                        $userId
                    ]);

                    $pdo->commit();

                    /* =========================
                       LOGIN USER
                    ========================= */

                    session_regenerate_id(true);

                    $_SESSION['user_id'] = $userId;
                    $_SESSION['full_name'] = $fullName;
                    $_SESSION['email'] = $email;
                    $_SESSION['role'] = 'client';

                    header("Location: client/dashboard.php");
                    exit;
                }

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $error = "Registration failed. Please try again.";
            }
        }
    }
}

// Ensure active CAPTCHA question for display
if (
    empty($_SESSION['reg_captcha_question']) ||
    !isset($_SESSION['reg_captcha_answer']) ||
    (time() - (int)($_SESSION['reg_captcha_time'] ?? 0) > 900)
) {
    $initCaptcha = generateRegistrationCaptcha();
    $_SESSION['reg_captcha_answer'] = $initCaptcha['answer'];
    $_SESSION['reg_captcha_question'] = $initCaptcha['question'];
    $_SESSION['reg_captcha_time'] = $initCaptcha['created_at'];
}
$captchaQuestion = $_SESSION['reg_captcha_question'];

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Create Account - Subnext</title>

    <meta name="description" content="Create a Subnext account to get fast, reliable data, airtime, bills payment, and simplified digital services.">

    <!-- Precompiled Production Stylesheet -->
    <link rel="stylesheet" href="assets/css/style.css">

</head>

<body class="min-h-screen bg-[#F6F7FB]">

    <main class="min-h-screen flex items-center justify-center px-4 py-8">

        <div class="w-full max-w-lg">

            <!-- LOGO -->
            <div class="text-center mb-7">

                <div
                    class="inline-flex items-center justify-center
                    w-14 h-14
                    rounded-2xl
                    bg-[#3E37B7]
                    text-white
                    shadow-lg shadow-indigo-200"
                >
                    <span class="text-2xl font-bold">
                        S
                    </span>
                </div>

                <h1
                    class="mt-3
                    text-2xl
                    font-bold
                    tracking-tight
                    text-gray-900"
                >
                    Subnext
                </h1>

                <p class="mt-1 text-sm text-gray-500">
                    Digital Services, Simplified.
                </p>

            </div>


            <!-- MAIN CARD -->
            <div
                class="bg-white
                rounded-3xl
                border border-gray-100
                shadow-xl shadow-gray-200/50
                p-5
                sm:p-8"
            >

                <!-- HEADER -->
                <div class="mb-7">

                    <div
                        class="inline-flex items-center
                        px-3 py-1
                        rounded-full
                        bg-[#F5F3FF]
                        text-[#5146C7]
                        text-xs
                        font-bold
                        tracking-wide"
                    >
                        GET STARTED
                    </div>

                    <h2
                        class="mt-3
                        text-2xl
                        sm:text-3xl
                        font-bold
                        text-gray-900"
                    >
                        Create your account
                    </h2>

                    <p
                        class="mt-2
                        text-sm
                        leading-relaxed
                        text-gray-500"
                    >
                        Create your Subnext account and start accessing
                        available services.
                    </p>

                </div>

                <!-- MAINTENANCE NOTICE -->
                <?php if ($isMaintenanceActive): ?>
                    <div
                        class="mb-6
                        rounded-2xl
                        border border-amber-200
                        bg-amber-50
                        p-4
                        text-amber-900"
                    >
                        <div class="flex items-start gap-3">
                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-lg">
                                🔧
                            </div>
                            <div>
                                <p class="text-sm font-bold">
                                    Subnext is Under Maintenance
                                </p>
                                <p class="mt-1 text-xs text-amber-800 leading-relaxed">
                                    New client account registrations are temporarily paused while scheduled system maintenance is underway. If you are an administrator, you can <a href="login.php" class="font-bold underline text-amber-950 hover:text-amber-800">log in here</a>.
                                </p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- ERROR -->
                <?php if ($error): ?>

                    <div
                        class="mb-6
                        flex items-start gap-3
                        rounded-xl
                        border border-red-100
                        bg-red-50
                        px-4 py-3
                        text-sm
                        text-red-700"
                    >

                        <svg
                            class="w-5 h-5 flex-shrink-0 mt-0.5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >

                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M12 9v3.5m0 3h.01M10.29 3.86l-7.5 13A2 2 0 004.52 20h14.96a2 2 0 001.73-3.14l-7.5-13a2 2 0 00-3.42 0z"
                            />

                        </svg>

                        <span>
                            <?= htmlspecialchars($error) ?>
                        </span>

                    </div>

                <?php endif; ?>


                <!-- FORM -->
                <form
                    method="POST"
                    class="space-y-5"
                >

                    <!-- CSRF -->
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars($csrfToken) ?>"
                    >


                    <!-- FULL NAME -->
                    <div>

                        <label
                            for="full_name"
                            class="block
                            text-sm
                            font-semibold
                            text-gray-700
                            mb-2"
                        >
                            Full Name
                        </label>

                        <input
                            type="text"
                            id="full_name"
                            name="full_name"
                            value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>"
                            placeholder="Enter your full name"
                            autocomplete="name"
                            required
                            class="w-full
                            h-14
                            rounded-xl
                            border border-gray-200
                            bg-gray-50
                            px-4
                            text-gray-900
                            placeholder-gray-400
                            outline-none
                            transition
                            focus:bg-white
                            focus:border-[#5146C7]
                            focus:ring-4
                            focus:ring-[#5146C7]/10"
                        >

                    </div>


                    <!-- EMAIL -->
                    <div>

                        <label
                            for="email"
                            class="block
                            text-sm
                            font-semibold
                            text-gray-700
                            mb-2"
                        >
                            Email Address
                        </label>

                        <input
                            type="email"
                            id="email"
                            name="email"
                            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                            placeholder="you@example.com"
                            autocomplete="email"
                            required
                            class="w-full
                            h-14
                            rounded-xl
                            border border-gray-200
                            bg-gray-50
                            px-4
                            text-gray-900
                            placeholder-gray-400
                            outline-none
                            transition
                            focus:bg-white
                            focus:border-[#5146C7]
                            focus:ring-4
                            focus:ring-[#5146C7]/10"
                        >

                    </div>


                    <!-- PHONE -->
                    <div>

                        <label
                            for="phone"
                            class="block
                            text-sm
                            font-semibold
                            text-gray-700
                            mb-2"
                        >
                            Phone Number
                        </label>

                        <input
                            type="tel"
                            id="phone"
                            name="phone"
                            value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
                            placeholder="e.g. 08031234567 or +234..."
                            autocomplete="tel"
                            required
                            maxlength="17"
                            class="w-full
                            h-14
                            rounded-xl
                            border border-gray-200
                            bg-gray-50
                            px-4
                            text-gray-900
                            placeholder-gray-400
                            outline-none
                            transition
                            focus:bg-white
                            focus:border-[#5146C7]
                            focus:ring-4
                            focus:ring-[#5146C7]/10"
                        >

                        <p class="mt-1.5 text-xs text-gray-500">
                            Enter an active 11-digit Nigerian mobile number (e.g. 0803 123 4567) or international number.
                        </p>

                    </div>


                    <!-- PASSWORD -->
                    <div>

                        <label
                            for="password"
                            class="block
                            text-sm
                            font-semibold
                            text-gray-700
                            mb-2"
                        >
                            Password
                        </label>

                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Create a strong password"
                            autocomplete="new-password"
                            required
                            class="w-full
                            h-14
                            rounded-xl
                            border border-gray-200
                            bg-gray-50
                            px-4
                            text-gray-900
                            placeholder-gray-400
                            outline-none
                            transition
                            focus:bg-white
                            focus:border-[#5146C7]
                            focus:ring-4
                            focus:ring-[#5146C7]/10"
                        >

                    </div>


                    <!-- CONFIRM PASSWORD -->
                    <div>

                        <label
                            for="confirm_password"
                            class="block
                            text-sm
                            font-semibold
                            text-gray-700
                            mb-2"
                        >
                            Confirm Password
                        </label>

                        <input
                            type="password"
                            id="confirm_password"
                            name="confirm_password"
                            placeholder="Re-enter your password"
                            autocomplete="new-password"
                            required
                            class="w-full
                            h-14
                            rounded-xl
                            border border-gray-200
                            bg-gray-50
                            px-4
                            text-gray-900
                            placeholder-gray-400
                            outline-none
                            transition
                            focus:bg-white
                            focus:border-[#5146C7]
                            focus:ring-4
                            focus:ring-[#5146C7]/10"
                        >

                    </div>


                    <!-- ANTI-BOT MATH CAPTCHA -->
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <label
                                for="captcha_answer"
                                class="block text-sm font-semibold text-gray-700"
                            >
                                Security Question <span class="text-rose-500">*</span>
                            </label>
                            <button
                                type="button"
                                id="refreshCaptchaBtn"
                                class="inline-flex items-center gap-1.5 text-xs font-semibold text-[#5146C7] hover:text-[#3E37B7] transition cursor-pointer select-none"
                                title="Generate a different question"
                            >
                                <svg id="refreshCaptchaIcon" class="w-3.5 h-3.5 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                </svg>
                                <span>Change question</span>
                            </button>
                        </div>

                        <div class="rounded-xl border border-indigo-100 bg-indigo-50/70 p-3 mb-2 flex items-center justify-between">
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-[#3E37B7] text-xs font-black text-white shadow-xs">?</span>
                                <span id="captchaQuestionText" class="font-bold text-slate-800 text-sm tracking-wide">
                                    <?= htmlspecialchars($captchaQuestion, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-indigo-700 bg-white/90 px-2 py-0.5 rounded-md border border-indigo-100/80">Anti-Bot</span>
                        </div>

                        <input
                            type="number"
                            id="captcha_answer"
                            name="captcha_answer"
                            placeholder="Enter your math answer (e.g. 5)"
                            required
                            autocomplete="off"
                            class="w-full
                            h-14
                            rounded-xl
                            border border-gray-200
                            bg-gray-50
                            px-4
                            text-gray-900
                            placeholder-gray-400
                            outline-none
                            transition
                            focus:bg-white
                            focus:border-[#5146C7]
                            focus:ring-4
                            focus:ring-[#5146C7]/10"
                        >
                    </div>


                    <!-- SIMPLIFIED TERMS & CONDITIONS -->
                    <div class="rounded-xl border border-slate-200 bg-slate-50/70 p-3.5 sm:p-4">
                        <label class="flex items-start gap-3 cursor-pointer select-none">
                            <input
                                type="checkbox"
                                name="disclaimer"
                                value="1"
                                <?= isset($_POST['disclaimer']) ? 'checked' : '' ?>
                                required
                                class="mt-0.5 w-4 h-4 flex-shrink-0 accent-[#3E37B7] rounded cursor-pointer"
                            >
                            <span class="text-xs sm:text-sm text-slate-600 leading-relaxed">
                                I agree to the <span class="font-semibold text-slate-800">Terms of Service</span> and acknowledge that my details will only be used securely for processing requested services.
                            </span>
                        </label>
                    </div>


                    <!-- SUBMIT -->
                    <button
                        type="submit"
                        class="w-full
                        h-14
                        rounded-xl
                        bg-[#3E37B7]
                        text-white
                        font-semibold
                        text-base
                        shadow-lg
                        shadow-indigo-200
                        transition-all
                        duration-200
                        hover:bg-[#312E81]
                        hover:shadow-xl
                        active:scale-[0.98]"
                    >
                        Create Account
                    </button>

                </form>


                <!-- LOGIN LINK -->
                <div
                    class="mt-7
                    pt-6
                    border-t border-gray-100
                    text-center"
                >

                    <p class="text-sm text-gray-500">

                        Already have an account?

                        <a
                            href="login.php"
                            class="font-semibold
                            text-[#3E37B7]
                            hover:text-[#312E81]"
                        >
                            Login
                        </a>

                    </p>

                </div>

            </div>


            <!-- FOOTER -->
            <p
                class="text-center
                text-xs
                text-gray-500
                mt-6"
            >
                © <?= date('Y') ?> Subnext. All rights reserved.
            </p>

        </div>

    </main>

    <script src="assets/js/loader.js" defer></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const refreshBtn = document.getElementById('refreshCaptchaBtn');
        const refreshIcon = document.getElementById('refreshCaptchaIcon');
        const questionText = document.getElementById('captchaQuestionText');
        const answerInput = document.getElementById('captcha_answer');

        if (refreshBtn) {
            refreshBtn.addEventListener('click', function () {
                if (refreshIcon) refreshIcon.classList.add('animate-spin');
                refreshBtn.disabled = true;

                fetch('register.php?refresh_captcha=1')
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (data && data.question && questionText) {
                            questionText.textContent = data.question;
                            if (answerInput) {
                                answerInput.value = '';
                                answerInput.focus();
                            }
                        }
                    })
                    .catch(function (err) {
                        console.error('Failed to refresh security question', err);
                    })
                    .finally(function () {
                        if (refreshIcon) refreshIcon.classList.remove('animate-spin');
                        refreshBtn.disabled = false;
                    });
            });
        }

        // Real-time phone sanitizer: allow digits, plus, spaces, and hyphens only
        const phoneInput = document.getElementById('phone');
        if (phoneInput) {
            phoneInput.addEventListener('input', function () {
                const cleaned = this.value.replace(/[^\d+\s\-]/g, '');
                if (cleaned !== this.value) {
                    this.value = cleaned;
                }
            });
        }
    });
    </script>
</body>

</html>
