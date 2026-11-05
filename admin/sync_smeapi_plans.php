<?php

require_once "../config/database.php";
require_once "../includes/admin_auth.php";
require_once "../includes/SmeApiClient.php";
require_once "../includes/SmeApiSyncService.php";

$message = "";
$error = "";
$syncResults = null;

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION["csrf_token"];

// Load credentials
require_once "../config/env.php";
$apiKey = trim((string)(getenv('SMEAPI_API_KEY') ?: ($_ENV['SMEAPI_API_KEY'] ?? '')));
if ($apiKey === '') {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'smeapi_api_key' LIMIT 1");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        if ($val !== false) {
            $apiKey = trim((string)$val);
        }
    } catch (\Throwable) {}
}

$baseUrl = rtrim((string)(getenv('SMEAPI_BASE_URL') ?: ($_ENV['SMEAPI_BASE_URL'] ?? 'https://smeapi.com.ng')), '/');

$smeClient = new SmeApiClient($apiKey, $baseUrl);
$isConfigured = $smeClient->isConfigured();
$smeBalance = null;
$balanceError = null;

if ($isConfigured) {
    $balanceResult = $smeClient->getBalance();
    if (($balanceResult['ok'] ?? false) === true && $balanceResult['balance'] !== null) {
        $smeBalance = (float)$balanceResult['balance'];
    } else {
        $balanceError = $balanceResult['message'] ?? 'Unable to retrieve live balance';
    }
}

// Current stats
$stmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM data_plans dp
    INNER JOIN api_providers ap ON ap.id = dp.provider_id
    WHERE ap.slug = 'smeapi' AND dp.status = 'active'
");
$stmt->execute();
$activeSmeDataCount = (int)$stmt->fetchColumn();

// Network counts for SMEAPI
$stmt = $pdo->prepare("
    SELECT n.name, COUNT(dp.id) as count
    FROM data_plans dp
    INNER JOIN api_providers ap ON ap.id = dp.provider_id
    INNER JOIN networks n ON n.id = dp.network_id
    WHERE ap.slug = 'smeapi' AND dp.status = 'active'
    GROUP BY n.id, n.name
");
$stmt->execute();
$networkBreakdown = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (empty($_POST["csrf_token"]) || !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])) {
        $error = "Invalid security token. Please refresh and try again.";
    } else {
        $dataMargin = (float)($_POST["data_margin"] ?? 35.00);
        if ($dataMargin < 0) {
            $dataMargin = 35.00;
        }

        try {
            $syncService = new SmeApiSyncService($pdo);
            $syncResults = $syncService->syncDataPlans($dataMargin);

            $message = "SMEAPI data plans synchronized successfully! MTN, Airtel, Glo, and 9mobile plans are now up to date.";

            // Refresh counts
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM data_plans dp
                INNER JOIN api_providers ap ON ap.id = dp.provider_id
                WHERE ap.slug = 'smeapi' AND dp.status = 'active'
            ");
            $stmt->execute();
            $activeSmeDataCount = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare("
                SELECT n.name, COUNT(dp.id) as count
                FROM data_plans dp
                INNER JOIN api_providers ap ON ap.id = dp.provider_id
                INNER JOIN networks n ON n.id = dp.network_id
                WHERE ap.slug = 'smeapi' AND dp.status = 'active'
                GROUP BY n.id, n.name
            ");
            $stmt->execute();
            $networkBreakdown = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        } catch (Throwable $e) {
            $error = "Synchronization failed: " . $e->getMessage();
            error_log("SMEAPI Sync error: " . $e->getMessage());
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sync SMEAPI Data Plans | Subnext Admin</title>
    <!-- Precompiled Production Stylesheet -->
    <link rel="stylesheet" href="../assets/css/style.css">
    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/images/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="../assets/images/favicon-16x16.png">
    <link rel="shortcut icon" href="../favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="../assets/images/apple-touch-icon.png">
</head>
<body class="bg-slate-50 text-slate-900 min-h-screen">

<!-- Admin Navigation Bar -->
<header class="sticky top-0 z-40 border-b border-slate-200 bg-white/95 backdrop-blur">
    <div class="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
        <div class="flex items-center gap-4">
            <a href="dashboard.php" class="flex items-center gap-2">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-servora-700 text-lg font-black text-white">S</div>
                <div>
                    <span class="font-bold text-slate-900 block leading-tight">Subnext</span>
                    <span class="text-[10px] uppercase font-bold text-servora-700">Admin Panel</span>
                </div>
            </a>
            <nav class="hidden md:flex items-center gap-1 ml-6 text-xs font-semibold text-slate-600">
                <a href="dashboard.php" class="px-3 py-2 rounded-lg hover:bg-slate-100">Dashboard</a>
                <a href="data_plans.php" class="px-3 py-2 rounded-lg hover:bg-slate-100">Data Inventory</a>
                <a href="data_control.php" class="px-3 py-2 rounded-lg hover:bg-slate-100">Data Control</a>
                <a href="data_orders.php" class="px-3 py-2 rounded-lg hover:bg-slate-100">Data Orders</a>
            </nav>
        </div>
        <div class="flex items-center gap-3">
            <a href="data_plans.php" class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-100">← Back to Data Plans</a>
            <a href="../logout.php" class="rounded-xl border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-bold text-red-600 hover:bg-red-100">Logout</a>
        </div>
    </div>
</header>

<main class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">

    <!-- Header Banner -->
    <div class="rounded-3xl bg-gradient-to-br from-indigo-950 via-slate-900 to-indigo-900 p-6 sm:p-8 text-white shadow-xl">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-indigo-500/20 px-3 py-1 text-xs font-semibold text-indigo-300 border border-indigo-500/30">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span>
                    Specialized Supplier: SMEAPI (Data Subscriptions Only)
                </span>
                <h1 class="mt-2 text-2xl sm:text-3xl font-black">Sync SMEAPI Data Plans</h1>
                <p class="mt-1 text-sm text-slate-300 max-w-2xl">
                    Synchronize official mobile data plans and provider wholesale costs for MTN, Airtel, Glo, and 9mobile directly from SMEAPI.
                </p>
            </div>
        </div>
    </div>

    <!-- Status Cards -->
    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <!-- API Connection Status -->
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-xs">
            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Provider Connection</p>
            <div class="mt-2 flex items-center gap-2">
                <span class="inline-block h-2.5 w-2.5 rounded-full <?= $isConfigured ? 'bg-emerald-500' : 'bg-rose-500' ?>"></span>
                <span class="font-bold text-slate-800 text-sm"><?= $isConfigured ? 'Connected & Ready' : 'API Key Missing' ?></span>
            </div>
            <p class="mt-1 text-xs text-slate-500 font-mono truncate"><?= htmlspecialchars($baseUrl) ?></p>
        </div>

        <!-- Wallet Balance -->
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-xs">
            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">SMEAPI Live Balance</p>
            <p class="mt-1 text-2xl font-black text-slate-900">
                <?= $smeBalance !== null ? '₦' . number_format($smeBalance, 2) : ($isConfigured ? ($balanceError ?: 'Pending Check') : 'Not Configured') ?>
            </p>
            <p class="mt-1 text-xs text-slate-500">Live balance from /api/user/</p>
        </div>

        <!-- Active Catalog Count -->
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-xs">
            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Active SMEAPI Plans</p>
            <p class="mt-1 text-2xl font-black text-servora-700">
                <?= $activeSmeDataCount ?> Plans
            </p>
            <p class="mt-1 text-xs text-slate-500">
                MTN: <?= $networkBreakdown['MTN'] ?? 0 ?> | Airtel: <?= $networkBreakdown['Airtel'] ?? 0 ?> | Glo: <?= $networkBreakdown['Glo'] ?? 0 ?>
            </p>
        </div>
    </div>

    <!-- Alerts -->
    <?php if (!empty($message)): ?>
    <div class="mt-6 rounded-2xl bg-emerald-50 border border-emerald-200 p-5 text-emerald-900 shadow-xs">
        <div class="flex items-center gap-3">
            <svg class="h-6 w-6 text-emerald-600 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            <div>
                <h3 class="font-bold text-sm">Sync Complete</h3>
                <p class="text-xs text-emerald-700 mt-0.5"><?= htmlspecialchars($message) ?></p>
            </div>
        </div>

        <?php if (!empty($syncResults)): ?>
        <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs">
            <div class="bg-white/80 rounded-xl p-2.5 border border-emerald-100">
                <span class="text-slate-400 block">Total Fetched:</span>
                <strong class="text-slate-800"><?= (int)($syncResults['total_plans_fetched'] ?? 0) ?> plans</strong>
            </div>
            <div class="bg-white/80 rounded-xl p-2.5 border border-emerald-100">
                <span class="text-slate-400 block">New Inserted:</span>
                <strong class="text-slate-800"><?= (int)($syncResults['inserted'] ?? 0) ?> plans</strong>
            </div>
            <div class="bg-white/80 rounded-xl p-2.5 border border-emerald-100">
                <span class="text-slate-400 block">Existing Updated:</span>
                <strong class="text-slate-800"><?= (int)($syncResults['updated'] ?? 0) ?> plans</strong>
            </div>
            <div class="bg-white/80 rounded-xl p-2.5 border border-emerald-100">
                <span class="text-slate-400 block">MTN Plans:</span>
                <strong class="text-slate-800"><?= (int)($syncResults['by_network']['MTN'] ?? 0) ?></strong>
            </div>
        </div>
        <?php endif; ?>

        <div class="mt-4 flex gap-2">
            <a href="data_plans.php" class="rounded-xl bg-servora-700 px-4 py-2 text-xs font-bold text-white hover:bg-servora-800 transition">View Data Inventory →</a>
            <a href="dashboard.php" class="rounded-xl bg-white px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 border border-slate-200 transition">Return to Dashboard →</a>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
    <div class="mt-6 rounded-2xl bg-rose-50 border border-rose-200 p-4 text-rose-900 shadow-xs flex items-center gap-3">
        <svg class="h-6 w-6 text-rose-600 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
        <p class="text-sm font-semibold"><?= htmlspecialchars($error) ?></p>
    </div>
    <?php endif; ?>

    <!-- Scope Constraint Notice -->
    <div class="mt-6 rounded-2xl bg-blue-50 border border-blue-200 p-4 text-blue-900 shadow-xs text-xs">
        <p class="font-bold flex items-center gap-1.5">
            <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Strict Integration Scope: Data Subscriptions Only
        </p>
        <p class="mt-1 text-blue-700">
            SMEAPI is strictly integrated for mobile data subscriptions. Other Subnext services (Airtime, Electricity Tokens, Cable TV Bouquets, Exam PINs, and Foreign Virtual Numbers) remain completely untouched and powered by their respective dedicated providers.
        </p>
    </div>

    <!-- Synchronization Form -->
    <div class="mt-6 rounded-3xl border border-slate-200 bg-white p-6 sm:p-8 shadow-xs">
        <h2 class="text-lg font-bold text-slate-900">Configure &amp; Execute Plan Sync</h2>
        <p class="text-xs text-slate-500 mt-1">Set your default markup margin. Selling prices will automatically be calculated as <code>Cost Price + Margin</code>.</p>

        <form method="POST" action="sync_smeapi_plans.php" class="mt-6 space-y-6">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                <!-- Data Profit Margin -->
                <div>
                    <label for="data_margin" class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                        Default Data Plan Margin (₦)
                    </label>
                    <p class="mt-1 text-xs text-slate-500">Amount added to each SMEAPI data plan wholesale cost.</p>
                    <div class="relative mt-2">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-sm font-bold text-slate-400">₦</span>
                        <input
                            type="number"
                            step="0.01"
                            min="0"
                            id="data_margin"
                            name="data_margin"
                            value="35.00"
                            required
                            class="block w-full rounded-xl border border-slate-200 pl-8 pr-4 py-2.5 text-sm font-semibold text-slate-900 focus:border-servora-500 focus:outline-none focus:ring-2 focus:ring-servora-200"
                        >
                    </div>
                </div>

                <!-- API Key Notice -->
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                        SMEAPI API Key Configuration
                    </label>
                    <p class="mt-1 text-xs text-slate-500">Stored securely in environment variables (<code>.env</code>) or database.</p>
                    <div class="mt-2 p-2.5 rounded-xl border border-slate-200 bg-slate-50 flex items-center justify-between text-xs">
                        <span class="font-mono text-slate-600">
                            <?= $isConfigured ? '••••••••••••••••' . substr($apiKey, -4) : 'Not configured' ?>
                        </span>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold <?= $isConfigured ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800' ?>">
                            <?= $isConfigured ? 'Active' : 'Missing' ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Submit Button -->
            <div class="pt-4 border-t border-slate-100 flex flex-wrap items-center justify-between gap-4">
                <a href="data_control.php" class="text-xs font-bold text-slate-500 hover:text-slate-800">
                    ← Return to Data Control Hub
                </a>
                <button
                    type="submit"
                    class="inline-flex items-center gap-2 rounded-xl bg-servora-700 px-6 py-3 text-sm font-bold text-white shadow-md shadow-servora-700/25 transition hover:bg-servora-800 active:scale-95"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    <span>Execute SMEAPI Plan Synchronization</span>
                </button>
            </div>
        </form>
    </div>

</main>

</body>
</html>

