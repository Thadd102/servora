<?php

require_once "config/database.php";
require_once "includes/auth.php";

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

$message = "";
$isMaintenanceMessage = false;

if (isset($_GET["account_closed"])) {
    $message = "Your Servora account has been successfully closed and credentials deactivated. All audit and financial records have been archived.";
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    verifyCsrfToken();

    // Get login details
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    // Basic validation
    if ($email === "" || $password === "") {

        $message = "Please enter your email and password.";
        $isMaintenanceMessage = false;

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $message = "Please enter a valid email address.";
        $isMaintenanceMessage = false;

    } else {

        // Find the user
        $stmt = $pdo->prepare("
            SELECT
                id,
                full_name,
                email,
                password,
                role,
                status
            FROM users
            WHERE email = ?
            LIMIT 1
        ");

        $stmt->execute([$email]);

        $user = $stmt->fetch();

        if (!$user) {

            $message = "Invalid email or password.";
            $isMaintenanceMessage = false;

        } elseif ($user["status"] !== "active") {

            $message = "Your account is not active.";
            $isMaintenanceMessage = false;

        } elseif (!password_verify($password, $user["password"])) {

            $message = "Invalid email or password.";
            $isMaintenanceMessage = false;

        } else {

            // ------------------------------------------------------------
            // BLOCK CLIENT LOGIN WHILE GLOBAL CLIENT ACCESS IS LOCKED
            // Admin and super_admin accounts are not affected.
            // ------------------------------------------------------------
            if ($user["role"] === "client" && $isMaintenanceActive) {
                $message = "Servora is temporarily under maintenance. Client access is currently unavailable. Please try again later.";
                $isMaintenanceMessage = true;
            }

            if ($message === "") {

                // Prevent session fixation
                session_regenerate_id(true);

                // Store authenticated user information
                $_SESSION["user_id"] = (int) $user["id"];
                $_SESSION["full_name"] = $user["full_name"];
                $_SESSION["email"] = $user["email"];
                $_SESSION["role"] = $user["role"];

                // Redirect according to role
                if ($user["role"] === "client") {

                    header("Location: client/dashboard.php");
                    exit;

                } elseif (
                    $user["role"] === "admin" ||
                    $user["role"] === "super_admin"
                ) {

                    header("Location: admin/dashboard.php");
                    exit;

                } else {

                    // Unknown role
                    logoutUser();

                    $message = "Your account role is not recognized.";
                    $isMaintenanceMessage = false;
                }
            }
        }
    }
} else {
    // GET Request: Only show maintenance message if maintenance is genuinely ACTIVE
    if ($isMaintenanceActive) {
        $message = "Servora is currently under maintenance. Client access is temporarily paused. Admin login remains available.";
        $isMaintenanceMessage = true;
    } elseif (($_GET["timeout"] ?? "") === "1") {
        $message = "Your session expired due to inactivity. Please log in again.";
        $isMaintenanceMessage = false;
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

    <title>Login - Servora</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        servora: {
                            50: '#F5F3FF',
                            100: '#EDE9FE',
                            200: '#DDD6FE',
                            500: '#635BDB',
                            600: '#5146C7',
                            700: '#3E37B7',
                            800: '#312E81',
                            900: '#1E1B4B'
                        }
                    }
                }
            }
        }
    </script>

</head>

<body class="min-h-screen bg-[#F6F7FB]">

    <div class="min-h-screen flex flex-col lg:flex-row">

        <!-- LEFT BRAND SECTION -->
        <div
            class="relative hidden lg:flex lg:w-1/2
            bg-gradient-to-br from-[#3E37B7] via-[#5146C7] to-[#312E81]
            overflow-hidden"
        >

            <!-- Decorative circles -->
            <div
                class="absolute -top-24 -left-24
                w-72 h-72 rounded-full
                bg-white/10"
            ></div>

            <div
                class="absolute -bottom-32 -right-20
                w-96 h-96 rounded-full
                bg-white/10"
            ></div>

            <div
                class="relative z-10
                flex flex-col justify-center
                px-16 xl:px-24
                text-white"
            >

                <div class="mb-10">

                    <div class="flex items-center gap-3">

                        <div
                            class="w-12 h-12 rounded-2xl
                            bg-white/15 backdrop-blur-sm
                            flex items-center justify-center"
                        >

                            <span class="text-2xl font-bold">
                                S
                            </span>

                        </div>

                        <span class="text-3xl font-bold tracking-tight">
                            Servora
                        </span>

                    </div>

                </div>

                <h1
                    class="text-5xl xl:text-6xl
                    font-bold leading-tight
                    max-w-xl"
                >
                    Your services,
                    <span class="text-purple-200">
                        simplified.
                    </span>
                </h1>

                <p
                    class="mt-6
                    text-lg text-purple-100
                    leading-relaxed
                    max-w-lg"
                >
                    Access the services you need, track your requests,
                    manage your wallet and receive your results — all
                    from one place.
                </p>

                <div class="mt-10 flex items-center gap-4">

                    <div
                        class="flex items-center
                        justify-center
                        w-11 h-11
                        rounded-xl
                        bg-white/10"
                    >
                        ✓
                    </div>

                    <div>
                        <p class="font-semibold">
                            Simple & Secure
                        </p>

                        <p class="text-sm text-purple-200">
                            Everything organized in one place.
                        </p>
                    </div>

                </div>

            </div>

        </div>



        <!-- LOGIN SECTION -->
        <div
            class="flex-1
            flex items-center justify-center
            px-5 py-10
            sm:px-8"
        >

            <div class="w-full max-w-md">

                <!-- MOBILE LOGO -->
                <div class="lg:hidden text-center mb-8">

                    <div
                        class="inline-flex items-center
                        justify-center
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
                        text-gray-900"
                    >
                        Servora
                    </h1>

                    <p class="text-sm text-gray-500 mt-1">
                        Your Services, Simplified.
                    </p>

                </div>



                <!-- LOGIN CARD -->
                <div
                    class="bg-white
                    rounded-3xl
                    border border-gray-100
                    shadow-xl shadow-gray-200/50
                    p-6
                    sm:p-8"
                >

                    <!-- HEADER -->
                    <div class="mb-8">

                        <p
                            class="text-sm
                            font-semibold
                            text-[#5146C7]
                            mb-2"
                        >
                            WELCOME BACK
                        </p>

                        <h2
                            class="text-2xl sm:text-3xl
                            font-bold
                            text-gray-900"
                        >
                            Sign in to Servora
                        </h2>

                        <p
                            class="mt-2
                            text-sm
                            text-gray-500"
                        >
                            Enter your details to continue.
                        </p>

                    </div>



                    <!-- MESSAGE -->
                    <?php if ($message !== ""): ?>

                        <?php if ($isMaintenanceMessage): ?>

                            <div
                                class="mb-6
                                rounded-2xl
                                bg-amber-50
                                border border-amber-200
                                px-4 py-4
                                text-amber-800"
                            >

                                <div class="flex items-start gap-3">

                                    <div
                                        class="flex h-9 w-9 shrink-0 items-center justify-center
                                        rounded-xl bg-amber-100 text-lg"
                                    >
                                        🔧
                                    </div>

                                    <div>

                                        <p class="text-sm font-bold">
                                            Servora is under maintenance
                                        </p>

                                        <p class="mt-1 text-sm leading-5">
                                            Client access is currently unavailable.
                                            Please try again later.
                                        </p>

                                    </div>

                                </div>

                            </div>

                        <?php else: ?>

                            <div
                                class="mb-6
                                rounded-xl
                                bg-red-50
                                border border-red-100
                                px-4 py-3
                                text-sm
                                text-red-700"
                            >

                                <?= htmlspecialchars($message) ?>

                            </div>

                        <?php endif; ?>

                    <?php endif; ?>



                    <!-- FORM -->
                    <form
                        method="POST"
                        action="login.php"
                        class="space-y-5"
                    >
<?= csrfField() ?>

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
                                Email address
                            </label>

                            <div class="relative">

                                <div
                                    class="absolute
                                    inset-y-0 left-0
                                    pl-4
                                    flex items-center
                                    pointer-events-none"
                                >

                                    <svg
                                        class="w-5 h-5 text-gray-400"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >

                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="1.8"
                                            d="M3 8l9 6 9-6M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"
                                        />

                                    </svg>

                                </div>

                                <input
                                    type="email"
                                    id="email"
                                    name="email"
                                    required
                                    autocomplete="email"
                                    value="<?= htmlspecialchars($_POST["email"] ?? "") ?>"
                                    placeholder="you@example.com"
                                    class="w-full
                                    h-14
                                    rounded-xl
                                    border border-gray-200
                                    bg-gray-50
                                    pl-12 pr-4
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

                        </div>



                        <!-- PASSWORD -->
                        <div>

                            <div
                                class="flex items-center
                                justify-between
                                mb-2"
                            >

                                <label
                                    for="password"
                                    class="block
                                    text-sm
                                    font-semibold
                                    text-gray-700"
                                >
                                    Password
                                </label>

                                <a
                                    href="forgot_password.php"
                                    class="text-sm
                                    font-semibold
                                    text-[#5146C7]
                                    hover:text-[#3E37B7]"
                                >
                                    Forgot password?
                                </a>

                            </div>

                            <div class="relative">

                                <div
                                    class="absolute
                                    inset-y-0 left-0
                                    pl-4
                                    flex items-center
                                    pointer-events-none"
                                >

                                    <svg
                                        class="w-5 h-5 text-gray-400"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >

                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="1.8"
                                            d="M15 11V7a3 3 0 00-6 0v4m-2 0h10a2 2 0 012 2v6a2 2 0 01-2 2H7a2 2 0 01-2-2v-6a2 2 0 012-2z"
                                        />

                                    </svg>

                                </div>

                                <input
                                    type="password"
                                    id="password"
                                    name="password"
                                    required
                                    autocomplete="current-password"
                                    placeholder="Enter your password"
                                    class="w-full
                                    h-14
                                    rounded-xl
                                    border border-gray-200
                                    bg-gray-50
                                    pl-12 pr-4
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

                        </div>



                        <!-- LOGIN BUTTON -->
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
                            Sign In
                        </button>

                    </form>



                    <!-- REGISTER -->
                    <div
                        class="mt-7
                        pt-6
                        border-t border-gray-100
                        text-center"
                    >

                        <p class="text-sm text-gray-500">

                            Don't have an account?

                            <a
                                href="register.php"
                                class="font-semibold
                                text-[#3E37B7]
                                hover:text-[#312E81]"
                            >
                                Create Account
                            </a>

                        </p>

                    </div>

                </div>



                <!-- FOOTER -->
                <p
                    class="text-center
                    text-xs
                    text-gray-400
                    mt-6"
                >
                    © <?= date('Y') ?> Servora. All rights reserved.
                </p>

            </div>

        </div>

    </div>

</body>

</html>
