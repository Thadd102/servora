<?php

require_once "../config/database.php";
require_once "../includes/client_auth.php";

$userId = currentUserId();

$stmt = $pdo->prepare("SELECT balance FROM wallets WHERE user_id = ? LIMIT 1");
$stmt->execute([$userId]);
$walletBalance = (float)($stmt->fetchColumn() ?: 0.00);

$fullName = trim((string)($_SESSION["full_name"] ?? "Client"));
$profileInitial = strtoupper(substr($fullName, 0, 1) ?: "C");

$stmt = $pdo->prepare("
    SELECT
        sr.id,
        sr.request_code,
        sr.amount,
        sr.status,
        sr.submitted_at,
        sr.updated_at,
        s.name AS service_name
    FROM service_requests sr
    INNER JOIN services s
        ON s.id = sr.service_id
    WHERE sr.user_id = ?
    ORDER BY sr.submitted_at DESC
");

$stmt->execute([$userId]);

$requests = $stmt->fetchAll();

function statusLabel(string $status): string
{
    return ucwords(str_replace("_", " ", $status));
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>My Requests | Servora</title>

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

<style>
    body {
        font-family: Inter, ui-sans-serif, system-ui, -apple-system,
            BlinkMacSystemFont, "Segoe UI", sans-serif;
    }

    .hide-scrollbar::-webkit-scrollbar {
        display: none;
    }

    .hide-scrollbar {
        -ms-overflow-style: none;
        scrollbar-width: none;
    }
</style>
</head>

<body class="bg-slate-50 text-slate-900 min-h-screen">

<!-- Desktop Navigation Header -->
<header class="hidden md:block sticky top-0 z-40 border-b border-slate-200/80 bg-white/95 backdrop-blur">
    <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6">
        <a href="dashboard.php" class="flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-servora-700 text-lg font-black text-white shadow-sm">S</div>
            <div>
                <div class="text-lg font-bold tracking-tight text-slate-900 leading-tight">Servora</div>
                <div class="text-[10px] font-medium text-slate-400 uppercase tracking-wider">Service Requests</div>
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
        </div>
    </div>
</header>

<main class="mx-auto w-full max-w-5xl px-4 py-6 pb-28 md:pb-8 sm:px-6">

    <!-- Hero Card -->
    <section class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-servora-800 via-servora-700 to-servora-500 p-6 sm:p-8 text-white shadow-xl">
        <div class="flex items-center justify-between">
            <a href="dashboard.php" class="text-sm font-semibold text-white/70 hover:text-white transition">
                ← Dashboard
            </a>
            <a href="services.php" class="inline-flex items-center gap-1.5 rounded-xl bg-white/20 hover:bg-white/30 backdrop-blur px-3.5 py-1.5 text-xs font-bold text-white transition">
                + New Request
            </a>
        </div>
        <div class="mt-6">
            <p class="text-sm font-semibold text-white/70">
                Servora Service Tracking
            </p>
            <h1 class="mt-1 text-3xl font-black">
                My Requests
            </h1>
            <p class="mt-2 max-w-xl text-sm text-white/70">
                Track your service requests and monitor progress in real-time.
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

    <div class="mt-6">


    <!-- =====================================================
         REQUESTS
    ====================================================== -->

    <?php if (empty($requests)): ?>

        <!-- Empty State -->

        <div class="bg-white rounded-3xl border border-slate-200
                    shadow-sm overflow-hidden">

            <div class="px-6 py-14 sm:py-20 text-center">

                <!-- Icon -->
                <div class="w-20 h-20 mx-auto rounded-3xl
                            bg-servora-50
                            flex items-center justify-center
                            text-servora-700 mb-6">

                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="w-9 h-9"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="1.5"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5
                               a2 2 0 0 1 2-2h6l5 5v11a2 2 0 0 1-2 2Z"
                        />

                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M13 3v5h5"
                        />
                    </svg>

                </div>


                <h2 class="text-xl font-extrabold text-slate-900">
                    No requests yet
                </h2>

                <p class="text-sm text-slate-500 mt-2 max-w-sm mx-auto">
                    You have not submitted any service requests yet.
                    Start by choosing a service below.
                </p>


                <a
                    href="services.php"
                    class="inline-flex items-center justify-center gap-2
                           mt-7 px-6 py-3
                           rounded-xl bg-servora-700
                           hover:bg-servora-800
                           text-white text-sm font-bold
                           transition shadow-lg shadow-servora-700/20"
                >

                    Browse Services

                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="w-4 h-4"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="2"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M5 12h14m-6-6 6 6-6 6"
                        />
                    </svg>

                </a>

            </div>

        </div>


    <?php else: ?>


        <!-- Request Count -->

        <div class="flex items-center justify-between mb-4">

            <p class="text-sm text-slate-500">

                <span class="font-bold text-slate-900">
                    <?= count($requests) ?>
                </span>

                <?= count($requests) === 1 ? "request" : "requests" ?>

            </p>

        </div>


        <!-- Request List -->

        <div class="space-y-4">

            <?php foreach ($requests as $request): ?>

                <article
                    class="group bg-white rounded-2xl sm:rounded-3xl
                           border border-slate-200
                           shadow-sm hover:shadow-md
                           hover:border-servora-200
                           transition duration-200 overflow-hidden"
                >

                    <div class="p-5 sm:p-6">

                        <!-- Top -->
                        <div class="flex items-start justify-between gap-4">

                            <div class="min-w-0">

                                <!-- Service icon -->
                                <div class="flex items-center gap-3">

                                    <div class="shrink-0 w-11 h-11
                                                rounded-xl bg-servora-50
                                                text-servora-700
                                                flex items-center justify-center">

                                        <svg
                                            xmlns="http://www.w3.org/2000/svg"
                                            class="w-5 h-5"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            stroke="currentColor"
                                            stroke-width="1.8"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                d="M9 12h6m-6 4h6m2 5H7
                                                   a2 2 0 0 1-2-2V5
                                                   a2 2 0 0 1 2-2h6l5 5v11
                                                   a2 2 0 0 1-2 2Z"
                                            />

                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                d="M13 3v5h5"
                                            />
                                        </svg>

                                    </div>


                                    <div class="min-w-0">

                                        <h2 class="font-extrabold text-base sm:text-lg
                                                   text-slate-900 truncate">
                                            <?= htmlspecialchars($request["service_name"]) ?>
                                        </h2>

                                        <p class="text-xs text-slate-400 mt-0.5">
                                            Request ID:
                                            <span class="font-semibold text-slate-500">
                                                <?= htmlspecialchars($request["request_code"]) ?>
                                            </span>
                                        </p>

                                    </div>

                                </div>

                            </div>


                            <!-- Status -->
                            <div class="shrink-0">

                                <?php
                                    $status = $request["status"];

                                    $statusClasses = match ($status) {

                                        'submitted' =>
                                            'bg-blue-50 text-blue-700 border-blue-100',

                                        'payment_confirmed' =>
                                            'bg-indigo-50 text-indigo-700 border-indigo-100',

                                        'processing' =>
                                            'bg-amber-50 text-amber-700 border-amber-100',

                                        'under_review' =>
                                            'bg-orange-50 text-orange-700 border-orange-100',

                                        'awaiting_information' =>
                                            'bg-purple-50 text-purple-700 border-purple-100',

                                        'ready_for_download' =>
                                            'bg-emerald-50 text-emerald-700 border-emerald-100',

                                        'completed' =>
                                            'bg-green-50 text-green-700 border-green-100',

                                        'cancelled' =>
                                            'bg-red-50 text-red-700 border-red-100',

                                        default =>
                                            'bg-slate-100 text-slate-600 border-slate-200'
                                    };
                                ?>

                                <span
                                    class="inline-flex items-center gap-1.5
                                           px-2.5 py-1.5
                                           rounded-full border
                                           text-[11px] sm:text-xs
                                           font-bold whitespace-nowrap
                                           <?= $statusClasses ?>"
                                >

                                    <span class="w-1.5 h-1.5 rounded-full bg-current"></span>

                                    <?= htmlspecialchars(statusLabel($request["status"])) ?>

                                </span>

                            </div>

                        </div>


                        <!-- Divider -->

                        <div class="border-t border-slate-100 my-5"></div>


                        <!-- Details -->

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">

                            <!-- Amount -->

                            <div class="bg-slate-50 rounded-xl p-3.5">

                                <p class="text-[11px] uppercase tracking-wide
                                          font-bold text-slate-400">
                                    Amount
                                </p>

                                <p class="text-base font-extrabold
                                          text-slate-900 mt-1">
                                    ₦<?= number_format((float) $request["amount"], 2) ?>
                                </p>

                            </div>


                            <!-- Submitted -->

                            <div class="bg-slate-50 rounded-xl p-3.5">

                                <p class="text-[11px] uppercase tracking-wide
                                          font-bold text-slate-400">
                                    Submitted
                                </p>

                                <p class="text-sm font-bold text-slate-700 mt-1">
                                    <?= htmlspecialchars($request["submitted_at"]) ?>
                                </p>

                            </div>


                            <!-- Updated -->

                            <div class="bg-slate-50 rounded-xl p-3.5">

                                <p class="text-[11px] uppercase tracking-wide
                                          font-bold text-slate-400">
                                    Last Updated
                                </p>

                                <p class="text-sm font-bold text-slate-700 mt-1">
                                    <?= htmlspecialchars($request["updated_at"]) ?>
                                </p>

                            </div>

                        </div>


                        <!-- Bottom -->

                        <div class="mt-5 flex flex-col sm:flex-row
                                    sm:items-center sm:justify-between gap-3">

                            <div class="flex items-center gap-2 text-xs text-slate-400">

                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    class="w-4 h-4"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M12 6v6l4 2"
                                    />

                                    <circle
                                        cx="12"
                                        cy="12"
                                        r="9"
                                    />
                                </svg>

                                Request tracking available

                            </div>


                            <a
                                href="request_tracker.php?id=<?= (int) $request["id"] ?>"
                                class="w-full sm:w-auto
                                       inline-flex items-center justify-center
                                       gap-2 px-5 py-3
                                       rounded-xl
                                       bg-servora-700
                                       hover:bg-servora-800
                                       text-white text-sm font-bold
                                       transition
                                       shadow-sm
                                       group-hover:shadow-md"
                            >

                                View Request

                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    class="w-4 h-4 transition-transform
                                           group-hover:translate-x-0.5"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                    stroke-width="2"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M5 12h14m-6-6 6 6-6 6"
                                    />
                                </svg>

                            </a>

                        </div>

                    </div>

                </article>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

    </div>

</main>


<?php require_once __DIR__ . "/../includes/client_bottom_nav.php"; ?>
</body>
</html>