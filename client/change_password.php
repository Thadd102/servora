<?php

require_once "../config/database.php";
require_once "../includes/client_auth.php";

$userId = currentUserId();

// Wallet balance & user initial for standard layout
$stmt = $pdo->prepare("SELECT balance FROM wallets WHERE user_id = ? LIMIT 1");
$stmt->execute([$userId]);
$walletBalance = (float)($stmt->fetchColumn() ?: 0.00);

$fullName = trim((string)($_SESSION["full_name"] ?? "Client"));
$profileInitial = strtoupper(substr($fullName, 0, 1) ?: "C");

$message = "";
$error = "";

/*
|--------------------------------------------------------------------------
| Handle Password Change
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    verifyCsrfToken();

    $currentPassword = $_POST["current_password"] ?? "";
    $newPassword = $_POST["new_password"] ?? "";
    $confirmPassword = $_POST["confirm_password"] ?? "";

    if ($currentPassword === "" || $newPassword === "" || $confirmPassword === "") {

        $error = "All fields are required.";

    } elseif (strlen($newPassword) < 8) {

        $error = "New password must be at least 8 characters.";

    } elseif ($newPassword !== $confirmPassword) {

        $error = "New passwords do not match.";

    } else {

        // Get current password
        $stmt = $pdo->prepare("
            SELECT password
            FROM users
            WHERE id = ?
            AND role = 'client'
            LIMIT 1
        ");

        $stmt->execute([$userId]);

        $user = $stmt->fetch();

        if (!$user || !password_verify($currentPassword, $user["password"])) {

            $error = "Current password is incorrect.";

        } else {

            // Hash the new password securely
            $newPasswordHash = password_hash(
                $newPassword,
                PASSWORD_DEFAULT
            );

            $update = $pdo->prepare("
                UPDATE users
                SET password = ?, updated_at = NOW()
                WHERE id = ?
            ");

            $update->execute([
                $newPasswordHash,
                $userId
            ]);

            $message = "Password changed successfully.";
        }
    }
}

?>


<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Change Password | Subnext</title>

    <!-- Precompiled Production Stylesheet -->
    <link rel="stylesheet" href="../assets/css/style.css">

</head>


<body class="min-h-screen bg-slate-50 text-slate-900">

<!-- Desktop Navigation Header -->
<header class="hidden md:block sticky top-0 z-40 border-b border-slate-200/80 bg-white/95 backdrop-blur">
    <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6">
        <a href="dashboard.php" class="flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-servora-700 text-lg font-black text-white shadow-sm">S</div>
            <div>
                <div class="text-lg font-bold tracking-tight text-slate-900 leading-tight">Subnext</div>
                <div class="text-[10px] font-medium text-slate-400 uppercase tracking-wider">Account Security</div>
            </div>
        </a>

        <!-- Desktop Navigation Links -->
        <nav class="flex items-center gap-1">
            <a href="dashboard.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">Dashboard</a>
            <a href="orders.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">My Orders</a>
            <a href="wallet.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">Wallet</a>
            <a href="profile.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">Profile</a>
        </nav>

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

<main class="mx-auto w-full max-w-5xl px-4 py-6 pb-28 md:pb-8 sm:px-6">

    <!-- Hero Card -->
    <section class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-servora-800 via-servora-700 to-servora-500 p-6 sm:p-8 text-white shadow-xl">
        <a href="profile.php" class="text-sm font-semibold text-white/70 hover:text-white transition">
            ← Profile
        </a>
        <div class="mt-6">
            <p class="text-sm font-semibold text-white/70">
                Subnext Security
            </p>
            <h1 class="mt-1 text-3xl font-black">
                Change Password
            </h1>
            <p class="mt-2 max-w-xl text-sm text-white/70">
                Update your account password to keep your Subnext account protected.
            </p>
        </div>
    </section>

    <!-- Wallet Balance Card -->
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
            <a href="fund_wallet.php" class="inline-flex items-center justify-center rounded-xl bg-servora-50 px-4 py-2 text-xs font-bold text-servora-700 transition hover:bg-servora-100">
                Fund Wallet
            </a>
        </div>
    </section>

    <div class="mx-auto max-w-2xl mt-6">
        <!-- Card -->
        <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">


                <!-- Messages -->

                <?php if ($message): ?>

                    <div class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">

                        <p class="font-semibold">
                            <?= htmlspecialchars($message) ?>
                        </p>

                    </div>

                <?php endif; ?>


                <?php if ($error): ?>

                    <div class="mb-5 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">

                        <p class="font-semibold">
                            <?= htmlspecialchars($error) ?>
                        </p>

                    </div>

                <?php endif; ?>


                <!-- Form -->
                <form method="POST" class="space-y-5">
<?= csrfField() ?>


                    <!-- Current Password -->
                    <div>

                        <label
                            for="current_password"
                            class="mb-2 block text-sm font-semibold text-slate-700"
                        >
                            Current Password
                        </label>

                        <input
                            type="password"
                            name="current_password"
                            id="current_password"
                            required
                            autocomplete="current-password"
                            placeholder="Enter your current password"
                            class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3.5 text-sm outline-none transition placeholder:text-slate-400 focus:border-servora-500 focus:bg-white focus:ring-4 focus:ring-servora-100"
                        >

                    </div>


                    <!-- New Password -->
                    <div>

                        <label
                            for="new_password"
                            class="mb-2 block text-sm font-semibold text-slate-700"
                        >
                            New Password
                        </label>

                        <input
                            type="password"
                            name="new_password"
                            id="new_password"
                            minlength="8"
                            required
                            autocomplete="new-password"
                            placeholder="Enter your new password"
                            class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3.5 text-sm outline-none transition placeholder:text-slate-400 focus:border-servora-500 focus:bg-white focus:ring-4 focus:ring-servora-100"
                        >

                        <p class="mt-2 text-xs text-slate-400">
                            Minimum 8 characters.
                        </p>

                    </div>


                    <!-- Confirm Password -->
                    <div>

                        <label
                            for="confirm_password"
                            class="mb-2 block text-sm font-semibold text-slate-700"
                        >
                            Confirm New Password
                        </label>

                        <input
                            type="password"
                            name="confirm_password"
                            id="confirm_password"
                            minlength="8"
                            required
                            autocomplete="new-password"
                            placeholder="Confirm your new password"
                            class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3.5 text-sm outline-none transition placeholder:text-slate-400 focus:border-servora-500 focus:bg-white focus:ring-4 focus:ring-servora-100"
                        >

                    </div>


                    <!-- Security note -->
                    <div class="rounded-2xl bg-servora-50 px-4 py-3.5">

                        <p class="text-xs leading-5 text-servora-800">
                            Never share your password with anyone. Keep your Subnext account credentials private.
                        </p>

                    </div>


                    <!-- Button -->
                    <button
                        type="submit"
                        class="w-full rounded-2xl bg-servora-700 px-5 py-3.5 text-sm font-bold text-white shadow-lg shadow-servora-700/20 transition hover:bg-servora-800 focus:outline-none focus:ring-4 focus:ring-servora-200 active:scale-[0.99]"
                    >
                        Change Password
                    </button>

                </form>


                <!-- Back -->
                <div class="mt-6 border-t border-slate-100 pt-5">

                    <a
                        href="dashboard.php"
                        class="flex items-center justify-center text-sm font-semibold text-servora-700 hover:text-servora-800"
                    >
                        ← Back to Dashboard
                    </a>

                </div>

            </div>

        </div>

    </main>


<?php require_once __DIR__ . "/../includes/client_bottom_nav.php"; ?>
</body>

</html>

