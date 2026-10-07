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
           DISCLAIMER CHECK
        ========================= */

        $acceptedDisclaimer = isset($_POST['disclaimer'])
            && $_POST['disclaimer'] === '1';

        if (!$acceptedDisclaimer) {

            $error = "You must read and accept the disclaimer before creating an account.";

        } elseif ($fullName === '') {

            $error = "Full name is required.";

        } elseif (strlen($fullName) < 2) {

            $error = "Please enter your full name.";

        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

            $error = "Please enter a valid email address.";

        } elseif ($password === '') {

            $error = "Password is required.";

        } elseif (strlen($password) < 8) {

            $error = "Password must be at least 8 characters.";

        } elseif ($password !== $confirmPassword) {

            $error = "Passwords do not match.";

        } else {

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
                            placeholder="08012345678"
                            autocomplete="tel"
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


                    <!-- DISCLAIMER -->
                    <div
                        class="rounded-2xl
                        border border-[#DDD9FF]
                        bg-gradient-to-br
                        from-[#F8F7FF]
                        to-[#F3F1FF]
                        p-4
                        sm:p-5"
                    >

                        <div class="flex items-start gap-3">

                            <div
                                class="flex-shrink-0
                                w-9 h-9
                                rounded-xl
                                bg-[#3E37B7]
                                text-white
                                flex items-center justify-center"
                            >

                                <svg
                                    class="w-5 h-5"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >

                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M13 16h-1v-4h-1m1-4h.01M12 22a10 10 0 100-20 10 10 0 000 20z"
                                    />

                                </svg>

                            </div>

                            <div>

                                <h3
                                    class="font-bold
                                    text-[#3E37B7]"
                                >
                                    Important Notice
                                </h3>

                                <p
                                    class="mt-2
                                    text-xs
                                    sm:text-sm
                                    leading-relaxed
                                    text-gray-600"
                                >
                                    Subnext provides a platform for submitting
                                    service requests and receiving processed
                                    results. You are responsible for providing
                                    accurate information and documents.
                                </p>

                            </div>

                        </div>


                        <div
                            class="mt-4
                            pt-4
                            border-t
                            border-[#DDD9FF]"
                        >

                            <p
                                class="text-xs
                                sm:text-sm
                                leading-relaxed
                                text-gray-600"
                            >
                                Subnext will process your information only for
                                the purpose of handling your requested service.
                                You should not submit information that you are
                                not authorized to provide.
                            </p>

                            <p
                                class="mt-3
                                text-xs
                                sm:text-sm
                                leading-relaxed
                                text-gray-600"
                            >
                                Service processing times may vary depending on
                                the nature of the request and the relevant
                                processing requirements.
                            </p>

                            <p
                                class="mt-3
                                text-xs
                                sm:text-sm
                                leading-relaxed
                                text-gray-600"
                            >
                                By creating an account, you acknowledge that
                                you have read and understood this notice.
                            </p>

                        </div>


                        <!-- CHECKBOX -->
                        <label
                            class="mt-5
                            flex items-start gap-3
                            cursor-pointer
                            select-none"
                        >

                            <input
                                type="checkbox"
                                name="disclaimer"
                                value="1"
                                <?= isset($_POST['disclaimer']) ? 'checked' : '' ?>
                                class="mt-1
                                w-5 h-5
                                flex-shrink-0
                                accent-[#3E37B7]
                                cursor-pointer"
                            >

                            <span
                                class="text-xs
                                sm:text-sm
                                leading-relaxed
                                font-medium
                                text-gray-700"
                            >
                                I have read and understood the disclaimer
                                and agree to provide accurate information
                                when using Subnext.
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
                        okey
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
</body>

</html>
