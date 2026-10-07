<?php
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/client_auth.php";

$userId = currentUserId();

/*
|--------------------------------------------------------------------------
| GET WALLET BALANCE
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("SELECT balance FROM wallets WHERE user_id = ? LIMIT 1");
$stmt->execute([$userId]);
$wallet = $stmt->fetch(PDO::FETCH_ASSOC);
$balance = $wallet ? (float)$wallet["balance"] : 0.00;

/*
|--------------------------------------------------------------------------
| CLIENT NAME
|--------------------------------------------------------------------------
*/
$fullName = trim((string)($_SESSION["full_name"] ?? "Client"));
$profileInitial = strtoupper(substr($fullName, 0, 1));

/*
|--------------------------------------------------------------------------
| ACTIVE SERVICES FROM DATABASE
|--------------------------------------------------------------------------
*/
$stmt = $pdo->query("SELECT id, name, slug, description, icon, route, status FROM services WHERE status = 'active' ORDER BY sort_order ASC");
$activeServices = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| RECENT TRANSACTIONS (LATEST 5)
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT id, type, amount, balance_after, reference, description, status, created_at
    FROM wallet_transactions
    WHERE user_id = ?
    ORDER BY id DESC
    LIMIT 5
");
$stmt->execute([$userId]);
$recentTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Subnext</title>
    <meta name="description" content="Subnext Client Dashboard: Manage your wallet balance, recent transactions, data subscriptions, and instant digital services.">
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
                <div class="text-[10px] font-medium text-slate-400 uppercase tracking-wider">Digital Services, Simplified.</div>
            </div>
        </a>

        <!-- Desktop Navigation Links -->
        <nav class="flex items-center gap-1">
            <a href="dashboard.php" class="rounded-xl bg-servora-50 px-3.5 py-2 text-xs font-bold text-servora-700">Dashboard</a>
            <a href="orders.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">My Orders</a>
            <a href="wallet.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">Wallet</a>
            <a href="support.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">Support</a>
            <a href="profile.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">Profile</a>
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
<main class="mx-auto w-full max-w-7xl px-3.5 py-3.5 pb-24 md:py-6 md:pb-8 sm:px-6 lg:px-8">

    <!-- 1. WELCOME HERO SECTION -->
    <section class="relative overflow-hidden rounded-2xl sm:rounded-3xl bg-gradient-to-br from-servora-800 via-servora-700 to-servora-600 p-4 sm:p-7 text-white shadow-md sm:shadow-lg">
        <div class="pointer-events-none absolute -right-6 -bottom-6 h-36 w-36 rounded-full bg-white/5 blur-2xl"></div>
        <div class="pointer-events-none absolute right-12 top-0 h-24 w-24 rounded-full bg-servora-400/10 blur-xl"></div>

        <div class="relative z-10 flex items-center justify-between gap-3">
            <div class="min-w-0">
                <span class="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-2.5 py-0.5 text-[11px] font-medium text-white/90 backdrop-blur-xs">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                    <span>Welcome back</span>
                </span>
                <h1 class="mt-1.5 text-lg sm:text-2xl font-bold tracking-tight text-white truncate">
                    <?= htmlspecialchars($fullName, ENT_QUOTES, "UTF-8") ?>
                </h1>
                <p class="mt-0.5 text-xs text-white/75 truncate sm:whitespace-normal">
                    Digital Services, Simplified.
                </p>
            </div>

            <!-- Profile Avatar Quick Link -->
            <a href="profile.php" class="flex h-11 w-11 sm:h-12 sm:w-12 shrink-0 items-center justify-center rounded-2xl bg-white/15 backdrop-blur-sm border border-white/20 font-bold text-white text-base shadow-inner transition hover:bg-white/25 active:scale-95" aria-label="Profile">
                <?= htmlspecialchars($profileInitial, ENT_QUOTES, "UTF-8") ?>
            </a>
        </div>
    </section>

    <!-- 2. WALLET BALANCE CARD -->
    <section class="mt-3 sm:mt-5 rounded-2xl sm:rounded-3xl border border-slate-200/80 bg-white p-4 sm:p-6 shadow-xs">
        <div class="flex items-center justify-between gap-3">
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                    Wallet Balance
                </p>
                <p class="mt-0.5 text-xl sm:text-2xl font-extrabold tracking-tight text-slate-900 font-mono">
                    ₦<?= number_format($balance, 2) ?>
                </p>
            </div>

            <div class="flex items-center gap-2 shrink-0">
                <a href="fund_wallet.php" class="inline-flex items-center gap-1.5 rounded-xl bg-servora-700 px-3.5 py-2 text-xs font-bold text-white shadow-xs transition hover:bg-servora-800 active:scale-95">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    <span>Fund Wallet</span>
                </a>
            </div>
        </div>
    </section>

    <!-- 3. AVAILABLE SERVICES SECTION -->
    <section class="mt-3 sm:mt-5 rounded-2xl sm:rounded-3xl border border-slate-200/80 bg-white p-4 sm:p-6 shadow-xs">
        <div class="flex items-center justify-between gap-2">
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-wider text-servora-600">
                    Available Services
                </p>
                <h2 class="mt-0.5 text-sm sm:text-base font-bold text-slate-900 leading-snug">
                    Quick Service Launcher
                </h2>
            </div>
            <a href="services.php" class="text-xs font-semibold text-servora-700 hover:underline">All Services →</a>
        </div>

        <div class="mt-3 sm:mt-4 grid grid-cols-2 gap-2.5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 sm:gap-3.5">

            <!-- 1. Foreign Numbers (5SIM) -->
            <a href="foreign_numbers.php" class="group flex flex-col justify-between rounded-xl sm:rounded-2xl border border-slate-200/90 bg-white p-3.5 sm:p-4 shadow-xs transition hover:-translate-y-0.5 hover:border-emerald-300 hover:shadow-md active:scale-[0.98]">
                <div>
                    <div class="flex items-center justify-between mb-2.5">
                        <div class="flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <circle cx="12" cy="12" r="9"/>
                                <path stroke-linecap="round" d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/>
                            </svg>
                        </div>
                        <span class="rounded-md bg-emerald-50 px-2 py-0.5 text-[10px] font-bold text-emerald-700">OTP</span>
                    </div>
                    <h3 class="text-xs sm:text-sm font-bold text-slate-900 group-hover:text-emerald-700 leading-tight">Foreign Numbers</h3>
                    <p class="mt-1 text-[11px] text-slate-400 hidden sm:block">Virtual SMS numbers for WhatsApp & Telegram.</p>
                </div>
                <div class="mt-2.5 flex items-center gap-1 text-[11px] sm:text-xs font-semibold text-emerald-700">
                    <span>Open</span>
                    <svg class="h-3 w-3 transition group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </div>
            </a>

            <!-- 2. Data Bundles (CheapDataHub) -->
            <a href="buy_data.php" class="group flex flex-col justify-between rounded-xl sm:rounded-2xl border border-slate-200/90 bg-white p-3.5 sm:p-4 shadow-xs transition hover:-translate-y-0.5 hover:border-sky-300 hover:shadow-md active:scale-[0.98]">
                <div>
                    <div class="flex items-center justify-between mb-2.5">
                        <div class="flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-xl bg-sky-50 text-sky-600">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" d="M5 16.5a10 10 0 0 1 14 0M8 19a6 6 0 0 1 8 0M11 21.5a2 2 0 0 1 2 0M3 13.5a14 14 0 0 1 18 0"/>
                            </svg>
                        </div>
                        <span class="rounded-md bg-sky-50 px-2 py-0.5 text-[10px] font-bold text-sky-700">SME</span>
                    </div>
                    <h3 class="text-xs sm:text-sm font-bold text-slate-900 group-hover:text-sky-700 leading-tight">Data Bundles</h3>
                    <p class="mt-1 text-[11px] text-slate-400 hidden sm:block">Cheap mobile data for all Nigerian networks.</p>
                </div>
                <div class="mt-2.5 flex items-center gap-1 text-[11px] sm:text-xs font-semibold text-sky-700">
                    <span>Open</span>
                    <svg class="h-3 w-3 transition group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </div>
            </a>

            <!-- 3. Airtime Top-up -->
            <a href="airtime.php" class="group flex flex-col justify-between rounded-xl sm:rounded-2xl border border-slate-200/90 bg-white p-3.5 sm:p-4 shadow-xs transition hover:-translate-y-0.5 hover:border-amber-300 hover:shadow-md active:scale-[0.98]">
                <div>
                    <div class="flex items-center justify-between mb-2.5">
                        <div class="flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-xl bg-amber-50 text-amber-600">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                            </svg>
                        </div>
                        <span class="rounded-md bg-amber-50 px-2 py-0.5 text-[10px] font-bold text-amber-800">VTU</span>
                    </div>
                    <h3 class="text-xs sm:text-sm font-bold text-slate-900 group-hover:text-amber-700 leading-tight">Airtime Top-up</h3>
                    <p class="mt-1 text-[11px] text-slate-400 hidden sm:block">Instant recharge at discounted rates.</p>
                </div>
                <div class="mt-2.5 flex items-center gap-1 text-[11px] sm:text-xs font-semibold text-amber-700">
                    <span>Open</span>
                    <svg class="h-3 w-3 transition group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </div>
            </a>

            <!-- 4. Electricity Bills -->
            <a href="electricity.php" class="group flex flex-col justify-between rounded-xl sm:rounded-2xl border border-slate-200/90 bg-white p-3.5 sm:p-4 shadow-xs transition hover:-translate-y-0.5 hover:border-orange-300 hover:shadow-md active:scale-[0.98]">
                <div>
                    <div class="flex items-center justify-between mb-2.5">
                        <div class="flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-xl bg-orange-50 text-orange-600">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                            </svg>
                        </div>
                        <span class="rounded-md bg-orange-50 px-2 py-0.5 text-[10px] font-bold text-orange-800">Tokens</span>
                    </div>
                    <h3 class="text-xs sm:text-sm font-bold text-slate-900 group-hover:text-orange-700 leading-tight">Electricity Bills</h3>
                    <p class="mt-1 text-[11px] text-slate-400 hidden sm:block">Prepaid meter tokens & postpaid bills.</p>
                </div>
                <div class="mt-2.5 flex items-center gap-1 text-[11px] sm:text-xs font-semibold text-orange-700">
                    <span>Open</span>
                    <svg class="h-3 w-3 transition group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </div>
            </a>

            <!-- 5. Cable TV -->
            <a href="cable.php" class="group flex flex-col justify-between rounded-xl sm:rounded-2xl border border-slate-200/90 bg-white p-3.5 sm:p-4 shadow-xs transition hover:-translate-y-0.5 hover:border-indigo-300 hover:shadow-md active:scale-[0.98]">
                <div>
                    <div class="flex items-center justify-between mb-2.5">
                        <div class="flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-xl bg-indigo-50 text-servora-600">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <rect x="2" y="7" width="20" height="15" rx="2" ry="2"/>
                                <polyline points="17 2 12 7 7 2"/>
                            </svg>
                        </div>
                        <span class="rounded-md bg-indigo-50 px-2 py-0.5 text-[10px] font-bold text-servora-800">DStv</span>
                    </div>
                    <h3 class="text-xs sm:text-sm font-bold text-slate-900 group-hover:text-servora-700 leading-tight">Cable TV</h3>
                    <p class="mt-1 text-[11px] text-slate-400 hidden sm:block">DStv, GOtv & StarTimes renewal.</p>
                </div>
                <div class="mt-2.5 flex items-center gap-1 text-[11px] sm:text-xs font-semibold text-servora-700">
                    <span>Open</span>
                    <svg class="h-3 w-3 transition group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </div>
            </a>

            <!-- 6. Bulk SMS -->
            <a href="bulk_sms.php" class="group flex flex-col justify-between rounded-xl sm:rounded-2xl border border-slate-200/90 bg-white p-3.5 sm:p-4 shadow-xs transition hover:-translate-y-0.5 hover:border-violet-300 hover:shadow-md active:scale-[0.98]">
                <div>
                    <div class="flex items-center justify-between mb-2.5">
                        <div class="flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-xl bg-violet-50 text-violet-600">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/>
                            </svg>
                        </div>
                        <span class="rounded-md bg-violet-50 px-2 py-0.5 text-[10px] font-bold text-violet-800">SMS</span>
                    </div>
                    <h3 class="text-xs sm:text-sm font-bold text-slate-900 group-hover:text-violet-700 leading-tight">Bulk SMS</h3>
                    <p class="mt-1 text-[11px] text-slate-400 hidden sm:block">Customized Sender ID messages.</p>
                </div>
                <div class="mt-2.5 flex items-center gap-1 text-[11px] sm:text-xs font-semibold text-violet-700">
                    <span>Open</span>
                    <svg class="h-3 w-3 transition group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </div>
            </a>

            <!-- 7. Exam PINs -->
            <a href="exam_pins.php" class="group flex flex-col justify-between rounded-xl sm:rounded-2xl border border-slate-200/90 bg-white p-3.5 sm:p-4 shadow-xs transition hover:-translate-y-0.5 hover:border-purple-300 hover:shadow-md active:scale-[0.98]">
                <div>
                    <div class="flex items-center justify-between mb-2.5">
                        <div class="flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-xl bg-purple-50 text-purple-600">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path d="M12 14l9-5-9-5-9 5 9 5z"/>
                                <path d="M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z"/>
                            </svg>
                        </div>
                        <span class="rounded-md bg-purple-50 px-2 py-0.5 text-[10px] font-bold text-purple-800">WAEC</span>
                    </div>
                    <h3 class="text-xs sm:text-sm font-bold text-slate-900 group-hover:text-purple-700 leading-tight">Exam PINs</h3>
                    <p class="mt-1 text-[11px] text-slate-400 hidden sm:block">WAEC, NECO & JAMB tokens.</p>
                </div>
                <div class="mt-2.5 flex items-center gap-1 text-[11px] sm:text-xs font-semibold text-purple-700">
                    <span>Open</span>
                    <svg class="h-3 w-3 transition group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </div>
            </a>

            <!-- 8. Support Center -->
            <a href="support.php" class="group flex flex-col justify-between rounded-xl sm:rounded-2xl border border-slate-200/90 bg-white p-3.5 sm:p-4 shadow-xs transition hover:-translate-y-0.5 hover:border-servora-300 hover:shadow-md active:scale-[0.98]">
                <div>
                    <div class="flex items-center justify-between mb-2.5">
                        <div class="flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-xl bg-servora-50 text-servora-700">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a.75.75 0 01-.876-.876c.15-.71.39-1.393.708-2.023C3.805 16.592 3 14.414 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" />
                            </svg>
                        </div>
                        <span class="rounded-md bg-servora-50 px-2 py-0.5 text-[10px] font-bold text-servora-800">Help</span>
                    </div>
                    <h3 class="text-xs sm:text-sm font-bold text-slate-900 group-hover:text-servora-700 leading-tight">Support Center</h3>
                    <p class="mt-1 text-[11px] text-slate-400 hidden sm:block">Tickets, replies & quick assistance.</p>
                </div>
                <div class="mt-2.5 flex items-center gap-1 text-[11px] sm:text-xs font-semibold text-servora-700">
                    <span>Open</span>
                    <svg class="h-3 w-3 transition group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </div>
            </a>

        </div>
    </section>

    <!-- 4. RECENT ACTIVITY SECTION -->
    <section class="mt-3 sm:mt-5 rounded-2xl sm:rounded-3xl border border-slate-200/80 bg-white p-4 sm:p-6 shadow-xs">
        <div class="flex items-center justify-between gap-2">
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-wider text-servora-600">
                    Transaction History
                </p>
                <h2 class="mt-0.5 text-sm sm:text-base font-bold text-slate-900 leading-snug">
                    Recent Activity
                </h2>
            </div>
            <a href="wallet.php" class="text-xs font-semibold text-servora-700 hover:underline">View All →</a>
        </div>

        <div class="mt-3 sm:mt-4 overflow-hidden rounded-xl sm:rounded-2xl border border-slate-100 bg-white">
            <?php if (empty($recentTransactions)): ?>
            <div class="p-6 text-center text-xs text-slate-400">
                No recent transactions recorded yet.
            </div>
            <?php else: ?>
            <div class="divide-y divide-slate-100">
                <?php foreach ($recentTransactions as $tx):
                    $isCredit = ($tx['type'] === 'credit' || $tx['type'] === 'refund');
                ?>
                <div class="flex items-center justify-between p-3 sm:p-3.5 transition hover:bg-slate-50/70">
                    <div class="flex items-center gap-2.5 sm:gap-3 min-w-0">
                        <div class="flex h-8 w-8 sm:h-9 sm:w-9 shrink-0 items-center justify-center rounded-xl <?= $isCredit ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-600' ?>">
                            <?php if ($isCredit): ?>
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
                            <?php else: ?>
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 10l7-7m0 0l7 7m-7-7v18"/></svg>
                            <?php endif; ?>
                        </div>
                        <div class="min-w-0">
                            <p class="truncate text-xs font-semibold text-slate-900"><?= htmlspecialchars($tx['description'] ?? 'Wallet Activity') ?></p>
                            <span class="block truncate text-[10px] text-slate-400 font-mono"><?= date('M d, h:i A', strtotime($tx['created_at'])) ?></span>
                        </div>
                    </div>
                    <div class="text-right shrink-0 pl-2">
                        <span class="font-mono text-xs font-bold <?= $isCredit ? 'text-emerald-600' : 'text-slate-900' ?>">
                            <?= $isCredit ? '+' : '-' ?>₦<?= number_format((float)$tx['amount'], 2) ?>
                        </span>
                        <span class="block text-[9px] capitalize font-bold <?= $tx['status'] === 'successful' ? 'text-emerald-500' : 'text-amber-500' ?>">
                            <?= htmlspecialchars($tx['status']) ?>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </section>

</main>

<!-- =========================================================
     STANDARDIZED MOBILE BOTTOM NAVIGATION
========================================================= -->
<?php require_once __DIR__ . "/../includes/client_bottom_nav.php"; ?>

</body>
</html>