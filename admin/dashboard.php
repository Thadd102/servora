<?php

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/admin_auth.php";

$adminName = $_SESSION["full_name"] ?? "Admin";

// Global client access / maintenance status
$settingStmt = $pdo->query("
    SELECT setting_value
    FROM app_settings
    WHERE setting_key = 'client_login_enabled'
    LIMIT 1
");
$clientLoginSetting = $settingStmt ? $settingStmt->fetchColumn() : false;
$clientLoginEnabled = ($clientLoginSetting === false || (string)$clientLoginSetting === "1");

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

/*
|--------------------------------------------------------------------------
| GENERAL STATISTICS
|--------------------------------------------------------------------------
*/

// Count clients
$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM users
    WHERE role = 'client'
");
$totalClients = (int) $stmt->fetchColumn();

// Count active services
$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM services
    WHERE status = 'active'
");
$activeServices = (int) $stmt->fetchColumn();

// Count total service requests
$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM service_requests
");
$totalRequests = (int) $stmt->fetchColumn();

// Count requests currently processing
$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM service_requests
    WHERE status IN (
        'submitted',
        'payment_confirmed',
        'processing',
        'under_review'
    )
");
$processingRequests = (int) $stmt->fetchColumn();

/*
|--------------------------------------------------------------------------
| DATA VENDING STATISTICS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM data_orders
");
$totalDataOrders = (int) $stmt->fetchColumn();

$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM data_orders
    WHERE status IN ('pending', 'processing')
");
$pendingDataOrders = (int) $stmt->fetchColumn();

$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM data_orders
    WHERE status = 'successful'
");
$successfulDataOrders = (int) $stmt->fetchColumn();

$stmt = $pdo->query("
    SELECT
        COALESCE(SUM(selling_price), 0) AS total_sales,
        COALESCE(SUM(profit), 0) AS total_profit
    FROM data_orders
    WHERE status = 'successful'
");

$dataStats = $stmt->fetch(PDO::FETCH_ASSOC);

$totalDataSales =
    (float) ($dataStats["total_sales"] ?? 0);

$totalDataProfit =
    (float) ($dataStats["total_profit"] ?? 0);

/*
|--------------------------------------------------------------------------
| FOREIGN NUMBER STATISTICS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT
        COUNT(*) AS total_orders,

        COALESCE(
            SUM(
                CASE
                    WHEN status IN (
                        'processing',
                        'waiting_sms',
                        'unknown'
                    )
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS active_orders,

        COALESCE(
            SUM(
                CASE
                    WHEN status = 'completed'
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS completed_orders,

        COALESCE(
            SUM(
                CASE
                    WHEN status = 'completed'
                    THEN selling_price
                    ELSE 0
                END
            ),
            0
        ) AS total_sales,

        COALESCE(
            SUM(
                CASE
                    WHEN status = 'completed'
                    THEN selling_price - provider_cost
                    ELSE 0
                END
            ),
            0
        ) AS total_profit

    FROM virtual_number_orders
");

$foreignStats =
    $stmt->fetch(PDO::FETCH_ASSOC);

$totalForeignOrders =
    (int) ($foreignStats["total_orders"] ?? 0);

$activeForeignOrders =
    (int) ($foreignStats["active_orders"] ?? 0);

$completedForeignOrders =
    (int) ($foreignStats["completed_orders"] ?? 0);

$totalForeignSales =
    (float) ($foreignStats["total_sales"] ?? 0);

$totalForeignProfit =
    (float) ($foreignStats["total_profit"] ?? 0);

/*
|--------------------------------------------------------------------------
| UTILITY ORDERS STATISTICS (Airtime, Electricity, Cable, SMS, Exam PINs)
|--------------------------------------------------------------------------
*/
$stmt = $pdo->query("
    SELECT
        COUNT(*) AS total_orders,
        COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_orders,
        COALESCE(SUM(CASE WHEN status = 'successful' THEN 1 ELSE 0 END), 0) AS completed_orders,
        COALESCE(SUM(CASE WHEN status = 'successful' THEN selling_price ELSE 0 END), 0) AS total_sales,
        COALESCE(SUM(CASE WHEN status = 'successful' THEN profit ELSE 0 END), 0) AS total_profit
    FROM utility_orders
");
$utilityStats = $stmt->fetch(PDO::FETCH_ASSOC);
$totalUtilityOrders = (int)($utilityStats["total_orders"] ?? 0);
$pendingUtilityOrders = (int)($utilityStats["pending_orders"] ?? 0);
$completedUtilityOrders = (int)($utilityStats["completed_orders"] ?? 0);
$totalUtilitySales = (float)($utilityStats["total_sales"] ?? 0);
$totalUtilityProfit = (float)($utilityStats["total_profit"] ?? 0);

/*
|--------------------------------------------------------------------------
| UPSTREAM PROVIDER BALANCES
|--------------------------------------------------------------------------
|
| Provider credentials must never be exposed to the browser.
| Each provider is isolated: a failure to connect to one provider
| will not prevent the other provider balances from loading.
|
*/
require_once __DIR__ . "/../includes/ProviderBalanceService.php";
$balanceService = new ProviderBalanceService();
$providerBalances = $balanceService->getAllBalances();
?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Admin Dashboard | Servora</title>

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

<body class="min-h-screen bg-slate-50 text-slate-900">

<main
    class="mx-auto w-full max-w-7xl
    px-4 py-5 sm:px-6 sm:py-8 lg:px-8"
>

    <!-- =====================================================
         HEADER
    ====================================================== -->

    <section
        class="relative overflow-hidden
        rounded-3xl bg-gradient-to-br
        from-servora-800 via-servora-700
        to-servora-500 p-6 text-white
        shadow-xl sm:p-8"
    >

        <div class="relative z-10">

            <div
                class="mb-5 flex items-center
                justify-between"
            >

                <div class="flex items-center gap-3">

                    <div
                        class="flex h-11 w-11
                        items-center justify-center
                        rounded-2xl bg-white/15
                        text-lg font-black backdrop-blur"
                    >
                        S
                    </div>

                    <div>

                        <p
                            class="text-sm font-semibold
                            text-white/70"
                        >
                            Servora
                        </p>

                        <p
                            class="text-xs text-white/50"
                        >
                            Administration
                        </p>

                    </div>

                </div>

                <div
                    class="rounded-full border
                    border-white/15 bg-white/10
                    px-3 py-1.5 text-xs
                    font-semibold backdrop-blur"
                >
                    Admin Panel
                </div>

            </div>

            <div class="max-w-2xl">

                <p
                    class="mb-2 text-sm
                    font-medium text-white/70"
                >
                    Welcome back
                </p>

                <h1
                    class="text-2xl font-black
                    tracking-tight sm:text-4xl"
                >
                    <?= htmlspecialchars(
                        $adminName,
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>
                </h1>

                <p
                    class="mt-3 max-w-xl
                    text-sm leading-6
                    text-white/75 sm:text-base"
                >
                    Manage your services, clients,
                    orders and platform operations
                    from one place.
                </p>

            </div>

        </div>

        <div
            class="absolute -right-16 -top-20
            h-56 w-56 rounded-full bg-white/10"
        ></div>

        <div
            class="absolute -bottom-24 right-20
            h-64 w-64 rounded-full bg-white/5"
        ></div>

    </section>

    <!-- =====================================================
         MAINTENANCE MODE & SYSTEM STATUS
    ====================================================== -->

    <?php if (($_GET["maintenance"] ?? "") === "locked"): ?>
        <div class="mt-5 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm font-bold text-red-700 flex items-center justify-between">
            <span>🔒 All client access has been locked. System is now in Maintenance Mode.</span>
            <a href="dashboard.php" class="text-xs text-red-500 hover:text-red-700 font-semibold underline">Dismiss</a>
        </div>
    <?php elseif (($_GET["maintenance"] ?? "") === "unlocked"): ?>
        <div class="mt-5 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-bold text-emerald-700 flex items-center justify-between">
            <span>✓ Client access has been restored. System is Live.</span>
            <a href="dashboard.php" class="text-xs text-emerald-500 hover:text-emerald-700 font-semibold underline">Dismiss</a>
        </div>
    <?php elseif (($_GET["maintenance"] ?? "") === "security"): ?>
        <div class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm font-bold text-amber-700">
            Security check failed. Please try again.
        </div>
    <?php endif; ?>

    <section class="mt-5 rounded-2xl border <?= $clientLoginEnabled ? 'border-emerald-200 bg-emerald-50/80' : 'border-amber-300 bg-amber-50' ?> p-4 sm:p-5 shadow-sm transition">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl <?= $clientLoginEnabled ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700' ?> text-lg font-black">
                    <?= $clientLoginEnabled ? '✓' : '🔒' ?>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-black <?= $clientLoginEnabled ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800 animate-pulse' ?>">
                            <?= $clientLoginEnabled ? 'SYSTEM LIVE' : 'MAINTENANCE MODE ACTIVE' ?>
                        </span>
                        <h2 class="text-sm font-black <?= $clientLoginEnabled ? 'text-emerald-950' : 'text-red-950' ?>">
                            Client Access: <?= $clientLoginEnabled ? 'Enabled' : 'Locked' ?>
                        </h2>
                    </div>
                    <p class="mt-0.5 text-xs <?= $clientLoginEnabled ? 'text-emerald-800' : 'text-red-800' ?>">
                        <?= $clientLoginEnabled
                            ? 'Clients can log in, fund wallets, order utilities and use foreign numbers normally.'
                            : 'Client access is disabled. Existing client sessions will be ended on their next action.'
                        ?>
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <form
                    method="POST"
                    action="toggle_client_access.php"
                    onsubmit="return confirm('<?= $clientLoginEnabled
                        ? "Lock Servora for all clients? Existing client sessions will be ended on their next request."
                        : "Allow all active clients to access Servora again?"
                    ?>');"
                >
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="<?= $clientLoginEnabled ? 'lock' : 'unlock' ?>">
                    <input type="hidden" name="redirect_to" value="dashboard.php">

                    <?php if ($clientLoginEnabled): ?>
                        <button
                            type="submit"
                            class="inline-flex min-h-[42px] items-center justify-center rounded-xl bg-red-600 px-4 text-xs font-black text-white transition hover:bg-red-700 shadow-sm"
                        >
                            🔒 Lock All Clients
                        </button>
                    <?php else: ?>
                        <button
                            type="submit"
                            class="inline-flex min-h-[42px] items-center justify-center rounded-xl bg-emerald-600 px-4 text-xs font-black text-white transition hover:bg-emerald-700 shadow-sm"
                        >
                            ✓ Allow All Clients
                        </button>
                    <?php endif; ?>
                </form>

                <a
                    href="clients.php"
                    class="inline-flex min-h-[42px] items-center justify-center rounded-xl border border-slate-200 bg-white px-3 text-xs font-bold text-slate-700 hover:bg-slate-50 transition shadow-sm"
                >
                    Manage Clients →
                </a>
            </div>
        </div>
    </section>

    <!-- =====================================================
         UPSTREAM PROVIDER BALANCES
    ====================================================== -->

    <section class="mt-6 rounded-3xl border border-slate-200 bg-white p-5 sm:p-6 shadow-sm">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between border-b border-slate-100 pb-4">
            <div>
                <div class="flex items-center gap-2">
                    <span class="flex h-3 w-3 rounded-full bg-emerald-500 animate-pulse"></span>
                    <h2 class="text-base sm:text-lg font-black tracking-tight text-slate-900">
                        Upstream Provider Balances
                    </h2>
                </div>
                <p class="text-xs text-slate-500 mt-0.5">
                    Live operational account balances across all external APIs. Credentials stay strictly server-side.
                </p>
            </div>

            <div class="flex items-center gap-3">
                <span id="balancesLastChecked" class="text-xs font-medium text-slate-400">
                    Checked: <?= date('h:i A') ?>
                </span>

                <button
                    type="button"
                    id="btnRefreshBalances"
                    onclick="refreshProviderBalances()"
                    class="inline-flex min-h-[38px] items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-1.5 text-xs font-bold text-slate-700 transition hover:bg-slate-100 active:scale-95 shadow-xs"
                >
                    <svg id="refreshSpinner" class="h-3.5 w-3.5 text-slate-500 transition-transform duration-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                    <span>Refresh Balances</span>
                </button>
            </div>
        </div>

        <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <?php
            $providerIcons = [
                'cheapdatahub' => '<path stroke-linecap="round" stroke-linejoin="round" d="M8.288 15.038a5.25 5.25 0 017.424 0M5.106 11.856c3.807-3.808 9.98-3.808 13.788 0M1.924 8.674c5.565-5.565 14.587-5.565 20.152 0M12.53 18.22l-.53.53-.53-.53a.75.75 0 011.06 0z" />',
                'fivesim' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418" />',
                'vtpass' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />',
                'termii' => '<path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a.75.75 0 01-.974-.94 6.012 6.012 0 00.91-3.27C4.167 15.347 3 13.766 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" />'
            ];

            foreach ($providerBalances as $pKey => $p):
                $isConn = ($p['status'] === 'connected');
                $isErr = ($p['status'] === 'error');
                $isUnconf = ($p['status'] === 'unconfigured');

                $badgeClass = $isConn
                    ? (str_contains($p['environment'] ?? '', 'Sandbox') ? 'bg-purple-100 text-purple-800' : 'bg-emerald-100 text-emerald-800')
                    : ($isErr ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-800');

                $badgeLabel = $isConn ? ($p['environment'] ?? 'Live') : ($isErr ? 'Error' : 'Unconfigured');
            ?>
                <div
                    id="card-provider-<?= htmlspecialchars($pKey, ENT_QUOTES, 'UTF-8') ?>"
                    class="relative flex flex-col justify-between rounded-2xl border border-slate-200 bg-slate-50/70 p-4 transition-all hover:bg-slate-50 hover:shadow-md"
                >
                    <div>
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex items-center gap-2.5">
                                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white border border-slate-200 text-slate-700 shadow-xs">
                                    <svg class="h-5 w-5 text-servora-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                        <?= $providerIcons[$pKey] ?? '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />' ?>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-sm font-black text-slate-900 leading-tight">
                                        <?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?>
                                    </h3>
                                    <p class="text-[11px] font-medium text-slate-500">
                                        <?= htmlspecialchars($p['service'], ENT_QUOTES, 'UTF-8') ?>
                                    </p>
                                </div>
                            </div>

                            <span
                                id="badge-<?= htmlspecialchars($pKey, ENT_QUOTES, 'UTF-8') ?>"
                                class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-black uppercase tracking-wider <?= $badgeClass ?>"
                            >
                                <?= htmlspecialchars($badgeLabel, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>

                        <div class="mt-4">
                            <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">
                                Available Balance
                            </p>
                            <p
                                id="balance-<?= htmlspecialchars($pKey, ENT_QUOTES, 'UTF-8') ?>"
                                class="mt-0.5 text-xl sm:text-2xl font-black text-slate-900 tracking-tight transition-opacity"
                            >
                                <?= htmlspecialchars($p['formatted'], ENT_QUOTES, 'UTF-8') ?>
                            </p>
                        </div>
                    </div>

                    <div class="mt-3 border-t border-slate-200/70 pt-2.5">
                        <p
                            id="msg-<?= htmlspecialchars($pKey, ENT_QUOTES, 'UTF-8') ?>"
                            class="text-[11px] text-slate-500 truncate"
                            title="<?= htmlspecialchars($p['extra_info'] ?? $p['message'], ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <?= htmlspecialchars($p['extra_info'] ?? $p['message'], ENT_QUOTES, 'UTF-8') ?>
                        </p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- =====================================================
         GENERAL STATISTICS
    ====================================================== -->

    <section
        class="mt-6 grid grid-cols-2
        gap-3 sm:gap-5 lg:grid-cols-4"
    >

        <!-- CLIENTS -->

        <div
            class="rounded-2xl border
            border-slate-200 bg-white
            p-4 shadow-sm sm:p-5"
        >

            <div class="mb-4">

                <div
                    class="flex h-10 w-10
                    items-center justify-center
                    rounded-xl bg-servora-50
                    text-servora-700"
                >

                    <svg
                        class="h-5 w-5"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="1.8"
                            d="M17 20h5v-2a4 4 0 00-4-4h-1
                            M9 20H4v-2a4 4 0 014-4h1
                            m4-8a4 4 0 110 8 4 4 0 000-8
                            zm6 4a3 3 0 100-6"
                        />
                    </svg>

                </div>

            </div>

            <p
                class="text-xs font-semibold
                uppercase tracking-wide
                text-slate-400"
            >
                Total Clients
            </p>

            <p
                class="mt-1 text-2xl
                font-black text-slate-900
                sm:text-3xl"
            >
                <?= $totalClients ?>
            </p>

        </div>

        <!-- ACTIVE SERVICES -->

        <div
            class="rounded-2xl border
            border-slate-200 bg-white
            p-4 shadow-sm sm:p-5"
        >

            <div class="mb-4">

                <div
                    class="flex h-10 w-10
                    items-center justify-center
                    rounded-xl bg-emerald-50
                    text-emerald-600"
                >

                    <svg
                        class="h-5 w-5"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="1.8"
                            d="M20 7l-8-4-8 4
                            m16 0l-8 4m8-4v10l-8 4
                            m0-10L4 7m8 4v10
                            M4 7v10l8 4"
                        />
                    </svg>

                </div>

            </div>

            <p
                class="text-xs font-semibold
                uppercase tracking-wide
                text-slate-400"
            >
                Active Services
            </p>

            <p
                class="mt-1 text-2xl
                font-black text-slate-900
                sm:text-3xl"
            >
                <?= $activeServices ?>
            </p>

        </div>

        <!-- REQUESTS -->

        <div
            class="rounded-2xl border
            border-slate-200 bg-white
            p-4 shadow-sm sm:p-5"
        >

            <div class="mb-4">

                <div
                    class="flex h-10 w-10
                    items-center justify-center
                    rounded-xl bg-blue-50
                    text-blue-600"
                >

                    <svg
                        class="h-5 w-5"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="1.8"
                            d="M9 12h6m-6 4h6
                            m2 5H7a2 2 0 01-2-2V5
                            a2 2 0 012-2h7l5 5v11
                            a2 2 0 01-2 2z"
                        />
                    </svg>

                </div>

            </div>

            <p
                class="text-xs font-semibold
                uppercase tracking-wide
                text-slate-400"
            >
                Total Requests
            </p>

            <p
                class="mt-1 text-2xl
                font-black text-slate-900
                sm:text-3xl"
            >
                <?= $totalRequests ?>
            </p>

        </div>

        <!-- PROCESSING -->

        <div
            class="rounded-2xl border
            border-slate-200 bg-white
            p-4 shadow-sm sm:p-5"
        >

            <div class="mb-4">

                <div
                    class="flex h-10 w-10
                    items-center justify-center
                    rounded-xl bg-amber-50
                    text-amber-600"
                >

                    <svg
                        class="h-5 w-5"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="1.8"
                            d="M12 8v4l3 2
                            m6-2a9 9 0 11-18 0
                            9 9 0 0118 0z"
                        />
                    </svg>

                </div>

            </div>

            <p
                class="text-xs font-semibold
                uppercase tracking-wide
                text-slate-400"
            >
                Processing
            </p>

            <p
                class="mt-1 text-2xl
                font-black text-slate-900
                sm:text-3xl"
            >
                <?= $processingRequests ?>
            </p>

        </div>

    </section>

    <!-- =====================================================
         DATA VENDING STATISTICS
    ====================================================== -->

    <section class="mt-8">

        <div class="mb-4">

            <p
                class="text-xs font-bold
                uppercase tracking-widest
                text-servora-600"
            >
                Data Vending
            </p>

            <h2
                class="mt-1 text-xl
                font-black text-slate-900
                sm:text-2xl"
            >
                Data business overview
            </h2>

            <p
                class="mt-1 text-sm
                text-slate-500"
            >
                Monitor data purchases,
                sales and profit.
            </p>

        </div>

        <div
            class="grid grid-cols-2
            gap-3 sm:gap-5 lg:grid-cols-5"
        >

            <div
                class="rounded-2xl border
                border-slate-200 bg-white
                p-4 shadow-sm sm:p-5"
            >

                <p
                    class="text-xs font-semibold
                    uppercase tracking-wide
                    text-slate-400"
                >
                    Data Orders
                </p>

                <p
                    class="mt-2 text-2xl
                    font-black text-slate-900"
                >
                    <?= $totalDataOrders ?>
                </p>

            </div>

            <div
                class="rounded-2xl border
                border-slate-200 bg-white
                p-4 shadow-sm sm:p-5"
            >

                <p
                    class="text-xs font-semibold
                    uppercase tracking-wide
                    text-slate-400"
                >
                    Pending
                </p>

                <p
                    class="mt-2 text-2xl
                    font-black text-amber-600"
                >
                    <?= $pendingDataOrders ?>
                </p>

            </div>

            <div
                class="rounded-2xl border
                border-slate-200 bg-white
                p-4 shadow-sm sm:p-5"
            >

                <p
                    class="text-xs font-semibold
                    uppercase tracking-wide
                    text-slate-400"
                >
                    Successful
                </p>

                <p
                    class="mt-2 text-2xl
                    font-black text-emerald-600"
                >
                    <?= $successfulDataOrders ?>
                </p>

            </div>

            <div
                class="rounded-2xl border
                border-slate-200 bg-white
                p-4 shadow-sm sm:p-5"
            >

                <p
                    class="text-xs font-semibold
                    uppercase tracking-wide
                    text-slate-400"
                >
                    Data Sales
                </p>

                <p
                    class="mt-2 text-xl
                    font-black text-slate-900"
                >
                    ₦<?= number_format(
                        $totalDataSales,
                        2
                    ) ?>
                </p>

            </div>

            <div
                class="rounded-2xl border
                border-slate-200 bg-white
                p-4 shadow-sm sm:p-5"
            >

                <p
                    class="text-xs font-semibold
                    uppercase tracking-wide
                    text-slate-400"
                >
                    Data Profit
                </p>

                <p
                    class="mt-2 text-xl
                    font-black text-servora-700"
                >
                    ₦<?= number_format(
                        $totalDataProfit,
                        2
                    ) ?>
                </p>

            </div>

        </div>

    </section>

    <!-- =====================================================
         FOREIGN NUMBER STATISTICS
    ====================================================== -->

    <section class="mt-8">

        <div
            class="mb-4 flex flex-wrap
            items-end justify-between gap-3"
        >

            <div>

                <p
                    class="text-xs font-bold
                    uppercase tracking-widest
                    text-servora-600"
                >
                    Foreign Numbers
                </p>

                <h2
                    class="mt-1 text-xl
                    font-black text-slate-900
                    sm:text-2xl"
                >
                    Verification number overview
                </h2>

                <p
                    class="mt-1 text-sm
                    text-slate-500"
                >
                    Monitor foreign-number orders,
                    completed sales and profit.
                </p>

            </div>

            <a
                href="foreign_number_orders.php"
                class="rounded-xl bg-servora-700
                px-4 py-2.5 text-xs
                font-bold text-white
                transition hover:bg-servora-800"
            >
                View Orders
            </a>

        </div>

        <div
            class="grid grid-cols-2
            gap-3 sm:gap-5 lg:grid-cols-5"
        >

            <div
                class="rounded-2xl border
                border-slate-200 bg-white
                p-4 shadow-sm sm:p-5"
            >

                <p
                    class="text-xs font-semibold
                    uppercase tracking-wide
                    text-slate-400"
                >
                    Orders
                </p>

                <p
                    class="mt-2 text-2xl
                    font-black text-slate-900"
                >
                    <?= $totalForeignOrders ?>
                </p>

            </div>

            <div
                class="rounded-2xl border
                border-slate-200 bg-white
                p-4 shadow-sm sm:p-5"
            >

                <p
                    class="text-xs font-semibold
                    uppercase tracking-wide
                    text-slate-400"
                >
                    Active / Waiting
                </p>

                <p
                    class="mt-2 text-2xl
                    font-black text-amber-600"
                >
                    <?= $activeForeignOrders ?>
                </p>

            </div>

            <div
                class="rounded-2xl border
                border-slate-200 bg-white
                p-4 shadow-sm sm:p-5"
            >

                <p
                    class="text-xs font-semibold
                    uppercase tracking-wide
                    text-slate-400"
                >
                    Completed
                </p>

                <p
                    class="mt-2 text-2xl
                    font-black text-emerald-600"
                >
                    <?= $completedForeignOrders ?>
                </p>

            </div>

            <div
                class="rounded-2xl border
                border-slate-200 bg-white
                p-4 shadow-sm sm:p-5"
            >

                <p
                    class="text-xs font-semibold
                    uppercase tracking-wide
                    text-slate-400"
                >
                    Sales
                </p>

                <p
                    class="mt-2 text-xl
                    font-black text-slate-900"
                >
                    ₦<?= number_format(
                        $totalForeignSales,
                        2
                    ) ?>
                </p>

            </div>

            <div
                class="rounded-2xl border
                border-slate-200 bg-white
                p-4 shadow-sm sm:p-5"
            >

                <p
                    class="text-xs font-semibold
                    uppercase tracking-wide
                    text-slate-400"
                >
                    Profit
                </p>

                <p
                    class="mt-2 text-xl
                    font-black text-servora-700"
                >
                    ₦<?= number_format(
                        $totalForeignProfit,
                        2
                    ) ?>
                </p>

            </div>

        </div>

    </section>

    <!-- =====================================================
         UTILITY & BILLS STATISTICS (Airtime, Power, Cable, SMS, Exam PINs)
    ====================================================== -->

    <section class="mt-8">

        <div
            class="mb-4 flex flex-wrap
            items-end justify-between gap-3"
        >

            <div>

                <p
                    class="text-xs font-bold
                    uppercase tracking-widest
                    text-servora-600"
                >
                    Utilities & Bills
                </p>

                <h2
                    class="mt-1 text-xl
                    font-black text-slate-900
                    sm:text-2xl"
                >
                    Airtime, Power, Cable, SMS & Exam PINs
                </h2>

                <p
                    class="mt-1 text-sm
                    text-slate-500"
                >
                    Monitor utility vending orders, completed sales and profit.
                </p>

            </div>

            <div class="flex items-center gap-2">
                <a
                    href="service_pricing.php"
                    class="rounded-xl border border-slate-200 bg-white
                    px-4 py-2.5 text-xs
                    font-bold text-slate-700
                    transition hover:bg-slate-50"
                >
                    Pricing & Margins
                </a>
                <a
                    href="utility_orders.php"
                    class="rounded-xl bg-servora-700
                    px-4 py-2.5 text-xs
                    font-bold text-white
                    transition hover:bg-servora-800"
                >
                    View Orders
                </a>
            </div>

        </div>

        <div
            class="grid grid-cols-2
            gap-3 sm:gap-5 lg:grid-cols-5"
        >

            <div
                class="rounded-2xl border
                border-slate-200 bg-white
                p-4 shadow-sm sm:p-5"
            >

                <p
                    class="text-xs font-semibold
                    uppercase tracking-wide
                    text-slate-400"
                >
                    Total Orders
                </p>

                <p
                    class="mt-2 text-2xl
                    font-black text-slate-900"
                >
                    <?= $totalUtilityOrders ?>
                </p>

            </div>

            <div
                class="rounded-2xl border
                border-slate-200 bg-white
                p-4 shadow-sm sm:p-5"
            >

                <p
                    class="text-xs font-semibold
                    uppercase tracking-wide
                    text-slate-400"
                >
                    Pending
                </p>

                <p
                    class="mt-2 text-2xl
                    font-black text-amber-600"
                >
                    <?= $pendingUtilityOrders ?>
                </p>

            </div>

            <div
                class="rounded-2xl border
                border-slate-200 bg-white
                p-4 shadow-sm sm:p-5"
            >

                <p
                    class="text-xs font-semibold
                    uppercase tracking-wide
                    text-slate-400"
                >
                    Successful
                </p>

                <p
                    class="mt-2 text-2xl
                    font-black text-emerald-600"
                >
                    <?= $completedUtilityOrders ?>
                </p>

            </div>

            <div
                class="rounded-2xl border
                border-slate-200 bg-white
                p-4 shadow-sm sm:p-5"
            >

                <p
                    class="text-xs font-semibold
                    uppercase tracking-wide
                    text-slate-400"
                >
                    Total Sales
                </p>

                <p
                    class="mt-2 text-xl
                    font-black text-slate-900"
                >
                    ₦<?= number_format($totalUtilitySales, 2) ?>
                </p>

            </div>

            <div
                class="rounded-2xl border
                border-slate-200 bg-white
                p-4 shadow-sm sm:p-5"
            >

                <p
                    class="text-xs font-semibold
                    uppercase tracking-wide
                    text-slate-400"
                >
                    Profit
                </p>

                <p
                    class="mt-2 text-xl
                    font-black text-servora-700"
                >
                    ₦<?= number_format($totalUtilityProfit, 2) ?>
                </p>

            </div>

        </div>

    </section>

    <!-- =====================================================
         MANAGEMENT
    ====================================================== -->

    <section class="mt-8">

        <div class="mb-4">

            <p
                class="text-xs font-bold
                uppercase tracking-widest
                text-servora-600"
            >
                Management
            </p>

            <h2
                class="mt-1 text-xl
                font-black text-slate-900
                sm:text-2xl"
            >
                Admin tools
            </h2>

            <p
                class="mt-1 text-sm
                text-slate-500"
            >
                Manage the different parts
                of your Servora platform.
            </p>

        </div>

        <div
            class="grid gap-4
            sm:grid-cols-2 lg:grid-cols-3"
        >

            <!-- SERVICES -->

            <a
                href="services.php"
                class="group rounded-2xl border
                border-slate-200 bg-white
                p-5 shadow-sm transition
                hover:-translate-y-0.5
                hover:border-servora-200
                hover:shadow-md"
            >

                <div
                    class="flex items-start
                    justify-between"
                >

                    <div
                        class="flex h-11 w-11
                        items-center justify-center
                        rounded-xl bg-servora-50
                        text-servora-700"
                    >
                        <svg
                            class="h-5 w-5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="1.8"
                                d="M20 7l-8-4-8 4
                                m16 0l-8 4m8-4v10l-8 4
                                m0-10L4 7m8 4v10
                                M4 7v10l8 4"
                            />
                        </svg>
                    </div>

                    <span
                        class="text-slate-300
                        transition
                        group-hover:text-servora-600"
                    >
                        →
                    </span>

                </div>

                <h3
                    class="mt-5 font-bold
                    text-slate-900"
                >
                    Services
                </h3>

                <p
                    class="mt-1 text-sm
                    leading-6 text-slate-500"
                >
                    Add, edit, activate or
                    deactivate services and
                    manage prices.
                </p>

            </a>

            <!-- SERVICE REQUESTS -->

            <a
                href="requests.php"
                class="group rounded-2xl border
                border-slate-200 bg-white
                p-5 shadow-sm transition
                hover:-translate-y-0.5
                hover:border-blue-200
                hover:shadow-md"
            >

                <div
                    class="flex items-start
                    justify-between"
                >

                    <div
                        class="flex h-11 w-11
                        items-center justify-center
                        rounded-xl bg-blue-50
                        text-blue-600"
                    >
                        <svg
                            class="h-5 w-5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="1.8"
                                d="M9 12h6m-6 4h6
                                m2 5H7a2 2 0 01-2-2V5
                                a2 2 0 012-2h7l5 5v11
                                a2 2 0 01-2 2z"
                            />
                        </svg>
                    </div>

                    <span
                        class="text-slate-300
                        transition
                        group-hover:text-blue-600"
                    >
                        →
                    </span>

                </div>

                <h3
                    class="mt-5 font-bold
                    text-slate-900"
                >
                    Service Requests
                </h3>

                <p
                    class="mt-1 text-sm
                    leading-6 text-slate-500"
                >
                    View and manage client
                    service requests.
                </p>

            </a>

            <!-- CLIENTS -->

            <a
                href="clients.php"
                class="group rounded-2xl border
                border-slate-200 bg-white
                p-5 shadow-sm transition
                hover:-translate-y-0.5
                hover:border-emerald-200
                hover:shadow-md"
            >

                <div
                    class="flex items-start
                    justify-between"
                >

                    <div
                        class="flex h-11 w-11
                        items-center justify-center
                        rounded-xl bg-emerald-50
                        text-emerald-600"
                    >
                        <svg
                            class="h-5 w-5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="1.8"
                                d="M17 20h5v-2a4 4 0 00-4-4h-1
                                M9 20H4v-2a4 4 0 014-4h1
                                m4-8a4 4 0 110 8 4 4 0 000-8
                                zm6 4a3 3 0 100-6"
                            />
                        </svg>
                    </div>

                    <span
                        class="text-slate-300
                        transition
                        group-hover:text-emerald-600"
                    >
                        →
                    </span>

                </div>

                <h3
                    class="mt-5 font-bold
                    text-slate-900"
                >
                    Clients
                </h3>

                <p
                    class="mt-1 text-sm
                    leading-6 text-slate-500"
                >
                    View registered clients
                    and manage their accounts.
                </p>

            </a>

            <!-- TRANSACTIONS -->

            <a
                href="transactions.php"
                class="group rounded-2xl border
                border-slate-200 bg-white
                p-5 shadow-sm transition
                hover:-translate-y-0.5
                hover:border-violet-200
                hover:shadow-md"
            >

                <div
                    class="flex items-start
                    justify-between"
                >

                    <div
                        class="flex h-11 w-11
                        items-center justify-center
                        rounded-xl bg-violet-50
                        text-violet-600"
                    >
                        <svg
                            class="h-5 w-5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="1.8"
                                d="M12 8c-3.314 0-6 1.343-6 3
                                s2.686 3 6 3 6-1.343 6-3
                                -2.686-3-6-3zm0 0V5m0 9v5
                                m-6-8v5c0 1.657 2.686 3 6 3
                                s6-1.343 6-3v-5"
                            />
                        </svg>
                    </div>

                    <span
                        class="text-slate-300
                        transition
                        group-hover:text-violet-600"
                    >
                        →
                    </span>

                </div>

                <h3
                    class="mt-5 font-bold
                    text-slate-900"
                >
                    Transactions
                </h3>

                <p
                    class="mt-1 text-sm
                    leading-6 text-slate-500"
                >
                    View wallet funding,
                    service payments and refunds.
                </p>

            </a>

            <!-- REFUNDS -->

            <a
                href="refunds.php"
                class="group rounded-2xl border
                border-slate-200 bg-white
                p-5 shadow-sm transition
                hover:-translate-y-0.5
                hover:border-rose-200
                hover:shadow-md"
            >

                <div
                    class="flex items-start
                    justify-between"
                >

                    <div
                        class="flex h-11 w-11
                        items-center justify-center
                        rounded-xl bg-rose-50
                        text-rose-600"
                    >
                        <svg
                            class="h-5 w-5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="1.8"
                                d="M9 14l-4-4 4-4
                                m-4 4h10a5 5 0 015 5v1"
                            />
                        </svg>
                    </div>

                    <span
                        class="text-slate-300
                        transition
                        group-hover:text-rose-600"
                    >
                        →
                    </span>

                </div>

                <h3
                    class="mt-5 font-bold
                    text-slate-900"
                >
                    Refunds
                </h3>

                <p
                    class="mt-1 text-sm
                    leading-6 text-slate-500"
                >
                    Process eligible refunds
                    and review refund history.
                </p>

            </a>

            <!-- ACTIVITY LOGS -->

            <a
                href="activity_logs.php"
                class="group rounded-2xl border
                border-slate-200 bg-white
                p-5 shadow-sm transition
                hover:-translate-y-0.5
                hover:border-amber-200
                hover:shadow-md"
            >

                <div
                    class="flex items-start
                    justify-between"
                >

                    <div
                        class="flex h-11 w-11
                        items-center justify-center
                        rounded-xl bg-amber-50
                        text-amber-600"
                    >
                        <svg
                            class="h-5 w-5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="1.8"
                                d="M9 5H7a2 2 0 00-2 2v12
                                a2 2 0 002 2h10a2 2 0 002-2V7
                                a2 2 0 00-2-2h-2
                                M9 5a3 3 0 006 0M9 5h6
                                m-6 5h6m-6 4h4"
                            />
                        </svg>
                    </div>

                    <span
                        class="text-slate-300
                        transition
                        group-hover:text-amber-600"
                    >
                        →
                    </span>

                </div>

                <h3
                    class="mt-5 font-bold
                    text-slate-900"
                >
                    Activity Logs
                </h3>

                <p
                    class="mt-1 text-sm
                    leading-6 text-slate-500"
                >
                    Monitor important
                    administrative actions.
                </p>

            </a>

            <!-- ADMIN MANAGEMENT -->

            <a
                href="admins.php"
                class="group rounded-2xl border
                border-slate-200 bg-white
                p-5 shadow-sm transition
                hover:-translate-y-0.5
                hover:border-indigo-200
                hover:shadow-md"
            >

                <div
                    class="flex items-start
                    justify-between"
                >

                    <div
                        class="flex h-11 w-11
                        items-center justify-center
                        rounded-xl bg-indigo-50
                        text-indigo-600"
                    >
                        <svg
                            class="h-5 w-5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="1.8"
                                d="M16 21v-2a4 4 0 00-4-4H6
                                a4 4 0 00-4 4v2
                                m7-10a4 4 0 100-8
                                4 4 0 000 8
                                zm7-1v6m3-3h-6"
                            />
                        </svg>
                    </div>

                    <span
                        class="text-slate-300
                        transition
                        group-hover:text-indigo-600"
                    >
                        →
                    </span>

                </div>

                <h3
                    class="mt-5 font-bold
                    text-slate-900"
                >
                    Admin Management
                </h3>

                <p
                    class="mt-1 text-sm
                    leading-6 text-slate-500"
                >
                    Manage administrators
                    and their access.
                </p>

            </a>

            <!-- ADMIN PROFILE -->

            <a
                href="admin_profile.php"
                class="group rounded-2xl border
                border-slate-200 bg-white
                p-5 shadow-sm transition
                hover:-translate-y-0.5
                hover:border-slate-300
                hover:shadow-md"
            >

                <div
                    class="flex items-start
                    justify-between"
                >

                    <div
                        class="flex h-11 w-11
                        items-center justify-center
                        rounded-xl bg-slate-100
                        text-slate-700"
                    >
                        <svg
                            class="h-5 w-5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="1.8"
                                d="M15 19a6 6 0 00-12 0
                                m6-8a4 4 0 100-8
                                4 4 0 000 8
                                zm6-1v6m3-3h-6"
                            />
                        </svg>
                    </div>

                    <span
                        class="text-slate-300
                        transition
                        group-hover:text-slate-700"
                    >
                        →
                    </span>

                </div>

                <h3
                    class="mt-5 font-bold
                    text-slate-900"
                >
                    Admin Profile
                </h3>

                <p
                    class="mt-1 text-sm
                    leading-6 text-slate-500"
                >
                    View and update your
                    administrator profile.
                </p>

            </a>

            <!-- FUND CLIENT -->

            <a
                href="fund_client.php"
                class="group rounded-2xl border
                border-slate-200 bg-white
                p-5 shadow-sm transition
                hover:-translate-y-0.5
                hover:border-emerald-200
                hover:shadow-md"
            >

                <div
                    class="flex items-start
                    justify-between"
                >

                    <div
                        class="flex h-11 w-11
                        items-center justify-center
                        rounded-xl bg-emerald-50
                        text-xl"
                    >
                        💰
                    </div>

                    <span
                        class="text-slate-300
                        transition
                        group-hover:text-emerald-600"
                    >
                        →
                    </span>

                </div>

                <h3
                    class="mt-5 font-bold
                    text-slate-900"
                >
                    Fund Client
                </h3>

                <p
                    class="mt-1 text-sm
                    leading-6 text-slate-500"
                >
                    Manually add money
                    to a client's wallet.
                </p>

            </a>

            <!-- DATA CONTROL -->

            <a
                href="data_control.php"
                class="group rounded-2xl border
                border-slate-200 bg-white
                p-5 shadow-sm transition
                hover:-translate-y-0.5
                hover:border-blue-200
                hover:shadow-md"
            >

                <div
                    class="flex items-start
                    justify-between"
                >

                    <div
                        class="flex h-11 w-11
                        items-center justify-center
                        rounded-xl bg-blue-50
                        text-blue-600"
                    >
                        <svg
                            class="h-5 w-5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="1.8"
                                d="M12 18h.01
                                M8.5 14.5a5 5 0 017 0
                                M5 11a10 10 0 0114 0
                                M2 7.5a15 15 0 0120 0"
                            />
                        </svg>
                    </div>

                    <span
                        class="text-slate-300
                        transition
                        group-hover:text-blue-600"
                    >
                        →
                    </span>

                </div>

                <h3
                    class="mt-5 font-bold
                    text-slate-900"
                >
                    Admin Data Control
                </h3>

                <p
                    class="mt-1 text-sm
                    leading-6 text-slate-500"
                >
                    Manage networks, data plans,
                    suppliers and customer
                    data orders.
                </p>

            </a>

            <!-- SERVICE & PRICING MANAGEMENT -->

            <a
                href="service_pricing.php"
                class="group rounded-2xl border
                border-slate-200 bg-white
                p-5 shadow-sm transition
                hover:-translate-y-0.5
                hover:border-servora-200
                hover:shadow-md"
            >

                <div
                    class="flex items-start
                    justify-between"
                >

                    <div
                        class="flex h-11 w-11
                        items-center justify-center
                        rounded-xl bg-servora-50
                        text-servora-700"
                    >
                        <svg
                            class="h-5 w-5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="1.8"
                                d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                            />
                        </svg>
                    </div>

                    <span
                        class="text-slate-300
                        transition
                        group-hover:text-servora-600"
                    >
                        →
                    </span>

                </div>

                <h3
                    class="mt-5 font-bold
                    text-slate-900"
                >
                    Services & Pricing Control
                </h3>

                <p
                    class="mt-1 text-sm
                    leading-6 text-slate-500"
                >
                    Manage service toggles, supplier
                    costs, markups and selling prices
                    without touching code.
                </p>

            </a>

            <!-- UTILITY & BILLS ORDERS -->

            <a
                href="utility_orders.php"
                class="group rounded-2xl border
                border-slate-200 bg-white
                p-5 shadow-sm transition
                hover:-translate-y-0.5
                hover:border-cyan-200
                hover:shadow-md"
            >

                <div
                    class="flex items-start
                    justify-between"
                >

                    <div
                        class="flex h-11 w-11
                        items-center justify-center
                        rounded-xl bg-cyan-50
                        text-cyan-700"
                    >
                        <svg
                            class="h-5 w-5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="1.8"
                                d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"
                            />
                        </svg>
                    </div>

                    <span
                        class="text-slate-300
                        transition
                        group-hover:text-cyan-700"
                    >
                        →
                    </span>

                </div>

                <h3
                    class="mt-5 font-bold
                    text-slate-900"
                >
                    Utility & Bills Orders
                </h3>

                <p
                    class="mt-1 text-sm
                    leading-6 text-slate-500"
                >
                    Monitor Airtime, Electricity tokens,
                    Cable TV, Bulk SMS, and Exam PINs
                    fulfillment and refunds.
                </p>

            </a>

            <!-- FOREIGN NUMBER ORDERS -->

            <a
                href="foreign_number_orders.php"
                class="group rounded-2xl border
                border-slate-200 bg-white
                p-5 shadow-sm transition
                hover:-translate-y-0.5
                hover:border-servora-200
                hover:shadow-md"
            >

                <div
                    class="flex items-start
                    justify-between"
                >

                    <div
                        class="flex h-11 w-11
                        items-center justify-center
                        rounded-xl bg-servora-50
                        text-servora-700"
                    >

                        <svg
                            class="h-5 w-5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="1.8"
                                d="M8 7h8
                                M8 11h8
                                M8 15h5
                                M6 3h12a2 2 0 012 2v14
                                a2 2 0 01-2 2H6
                                a2 2 0 01-2-2V5
                                a2 2 0 012-2z"
                            />
                        </svg>

                    </div>

                    <span
                        class="text-slate-300
                        transition
                        group-hover:text-servora-600"
                    >
                        →
                    </span>

                </div>

                <div
                    class="mt-5 flex
                    items-center gap-2"
                >

                    <h3
                        class="font-bold
                        text-slate-900"
                    >
                        Foreign Number Orders
                    </h3>

                    <?php if (
                        $activeForeignOrders > 0
                    ): ?>

                        <span
                            class="rounded-full
                            bg-amber-50 px-2 py-0.5
                            text-[10px] font-black
                            text-amber-700"
                        >
                            <?= $activeForeignOrders ?>
                        </span>

                    <?php endif; ?>

                </div>

                <p
                    class="mt-1 text-sm
                    leading-6 text-slate-500"
                >
                    Monitor verification-number
                    orders, OTP statuses,
                    customers and provider
                    transactions.
                </p>

            </a>

            <!-- FOREIGN NUMBER SETTINGS -->

            <a
                href="foreign_number_settings.php"
                class="group rounded-2xl border
                border-slate-200 bg-white
                p-5 shadow-sm transition
                hover:-translate-y-0.5
                hover:border-violet-200
                hover:shadow-md"
            >

                <div
                    class="flex items-start
                    justify-between"
                >

                    <div
                        class="flex h-11 w-11
                        items-center justify-center
                        rounded-xl bg-violet-50
                        text-violet-600"
                    >

                        <svg
                            class="h-5 w-5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="1.8"
                                d="M12 15.5a3.5 3.5 0 100-7
                                3.5 3.5 0 000 7z
                                M19.4 15a1.7 1.7 0 00.34 1.88
                                l.06.06-2.12 2.12-.06-.06
                                a1.7 1.7 0 00-1.88-.34
                                1.7 1.7 0 00-1.04 1.56V20.3
                                h-3v-.08a1.7 1.7 0 00-1.04-1.56
                                1.7 1.7 0 00-1.88.34l-.06.06
                                -2.12-2.12.06-.06A1.7 1.7 0 007
                                15a1.7 1.7 0 00-1.56-1.04H5.3
                                v-3h.14A1.7 1.7 0 007 9
                                a1.7 1.7 0 00-.34-1.88l-.06-.06
                                2.12-2.12.06.06A1.7 1.7 0 0010.66
                                5.34 1.7 1.7 0 0011.7 3.78V3.7
                                h3v.08a1.7 1.7 0 001.04 1.56
                                1.7 1.7 0 001.88-.34l.06-.06
                                2.12 2.12-.06.06A1.7 1.7 0 0019.4
                                9a1.7 1.7 0 001.56 1.04h.04v3h-.04
                                A1.7 1.7 0 0019.4 15z"
                            />
                        </svg>

                    </div>

                    <span
                        class="text-slate-300
                        transition
                        group-hover:text-violet-600"
                    >
                        →
                    </span>

                </div>

                <h3
                    class="mt-5 font-bold
                    text-slate-900"
                >
                    Foreign Number Settings
                </h3>

                <p
                    class="mt-1 text-sm
                    leading-6 text-slate-500"
                >
                    Configure profit percentage,
                    minimum profit and module
                    availability.
                </p>

            </a>

        </div>

    </section>

    <!-- =====================================================
         LOGOUT
    ====================================================== -->

    <section
        class="mt-8 border-t
        border-slate-200 pt-6"
    >

        <a
            href="../logout.php"
            class="inline-flex w-full
            items-center justify-center
            rounded-2xl border border-red-200
            bg-white px-5 py-3.5
            text-sm font-bold text-red-600
            transition hover:bg-red-50
            sm:w-auto"
        >
            Logout
        </a>

    </section>

    <footer
        class="py-8 text-center
        text-xs text-slate-400"
    >
        Servora Administration
    </footer>

</main>

<script>
/**
 * Asynchronously refresh upstream provider balances via secure AJAX
 * Provider credentials are NEVER exposed to the frontend browser.
 */
async function refreshProviderBalances() {
    const btn = document.getElementById('btnRefreshBalances');
    const spinner = document.getElementById('refreshSpinner');
    const timeSpan = document.getElementById('balancesLastChecked');

    if (!btn || !spinner) return;

    btn.disabled = true;
    spinner.classList.add('animate-spin');

    const balanceEls = document.querySelectorAll('[id^="balance-"]');
    balanceEls.forEach(el => el.classList.add('opacity-40'));

    try {
        const response = await fetch('api_provider_balances.php', {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }

        const data = await response.json();
        if (data.ok && data.balances) {
            if (timeSpan && data.human_time) {
                timeSpan.textContent = 'Checked: ' + data.human_time;
            }

            for (const [key, p] of Object.entries(data.balances)) {
                const balEl = document.getElementById('balance-' + key);
                const badgeEl = document.getElementById('badge-' + key);
                const msgEl = document.getElementById('msg-' + key);

                if (balEl) {
                    balEl.textContent = p.formatted || 'Unavailable';
                }

                if (badgeEl) {
                    badgeEl.className = 'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-black uppercase tracking-wider ';
                    if (p.status === 'connected') {
                        const isSandbox = (p.environment || '').includes('Sandbox');
                        badgeEl.className += isSandbox ? 'bg-purple-100 text-purple-800' : 'bg-emerald-100 text-emerald-800';
                        badgeEl.textContent = p.environment || 'Live';
                    } else if (p.status === 'error') {
                        badgeEl.className += 'bg-red-100 text-red-800';
                        badgeEl.textContent = 'Error';
                    } else {
                        badgeEl.className += 'bg-amber-100 text-amber-800';
                        badgeEl.textContent = 'Unconfigured';
                    }
                }

                if (msgEl) {
                    const text = p.extra_info || p.message || '';
                    msgEl.textContent = text;
                    msgEl.title = text;
                }
            }
        }
    } catch (err) {
        console.error('Failed to refresh balances:', err);
    } finally {
        balanceEls.forEach(el => el.classList.remove('opacity-40'));
        spinner.classList.remove('animate-spin');
        btn.disabled = false;
    }
}
</script>

</body>
</html>