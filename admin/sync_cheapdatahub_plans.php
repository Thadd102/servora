<?php

require_once "../config/database.php";
require_once "../includes/admin_auth.php";
require_once "../includes/CheapDataHubClient.php";
require_once "../includes/CheapDataHubSyncService.php";

$message = "";
$error = "";
$syncResults = null;

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION["csrf_token"];

$apiKey = trim((string)(getenv('CHEAPDATAHUB_API_KEY') ?: ($_ENV['CHEAPDATAHUB_API_KEY'] ?? '')));
$baseUrl = rtrim((string)(getenv('CHEAPDATAHUB_BASE_URL') ?: ($_ENV['CHEAPDATAHUB_BASE_URL'] ?? 'https://www.cheapdatahub.ng/api/v1/resellers')), '/');

$cdhClient = new CheapDataHubClient($apiKey, $baseUrl);
$balanceResult = $cdhClient->getBalance();
$isConfigured = !empty($apiKey);
$cdhBalance = null;

if (($balanceResult['ok'] ?? false) === true) {
    $balData = $balanceResult['data']['data'] ?? $balanceResult['data'];
    $rawBal = $balData['balance'] ?? ($balData['wallet_balance'] ?? null);
    if ($rawBal !== null && is_numeric($rawBal)) {
        $cdhBalance = (float)$rawBal;
    }
}

// Current stats
$stmt = $pdo->prepare("SELECT COUNT(*) FROM data_plans WHERE provider_id = 1 AND status = 'active'");
$stmt->execute();
$activeDataCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM service_products WHERE provider = 'cheapdatahub' AND status = 'active'");
$stmt->execute();
$activeProductsCount = (int)$stmt->fetchColumn();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (empty($_POST["csrf_token"]) || !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])) {
        $error = "Invalid security token. Please refresh and try again.";
    } else {
        $dataMargin = (float)($_POST["data_margin"] ?? 40.00);
        if ($dataMargin < 0) {
            $dataMargin = 40.00;
        }

        $cableMargin = (float)($_POST["cable_margin"] ?? 100.00);
        if ($cableMargin < 0) {
            $cableMargin = 100.00;
        }

        try {
            $syncService = new CheapDataHubSyncService($pdo);
            $syncResults = $syncService->syncAllServices($dataMargin, $cableMargin);

            $message = "CheapDataHub services synchronized successfully! Data plans, Cable packages, and Electricity Discos are up to date.";

            // Refresh counts
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM data_plans WHERE provider_id = 1 AND status = 'active'");
            $stmt->execute();
            $activeDataCount = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM service_products WHERE provider = 'cheapdatahub' AND status = 'active'");
            $stmt->execute();
            $activeProductsCount = (int)$stmt->fetchColumn();

        } catch (Throwable $e) {
            $error = "Synchronization failed: " . $e->getMessage();
            error_log("CheapDataHub Sync error: " . $e->getMessage());
        }
    }
}

$adminName = $_SESSION["full_name"] ?? "Admin";

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sync CheapDataHub Services | Subnext Admin</title>
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
                <a href="service_pricing.php" class="px-3 py-2 rounded-lg hover:bg-slate-100">Services & Pricing</a>
                <a href="utility_orders.php" class="px-3 py-2 rounded-lg hover:bg-slate-100">Utility Orders</a>
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
    <div class="rounded-3xl bg-gradient-to-br from-servora-900 via-servora-800 to-sky-900 p-6 sm:p-8 text-white shadow-xl">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/20 px-3 py-1 text-xs font-semibold text-emerald-300 border border-emerald-500/30">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span>
                    Primary VTU Supplier: CheapDataHub
                </span>
                <h1 class="mt-2 text-2xl sm:text-3xl font-black">Sync CheapDataHub Services</h1>
                <p class="mt-1 text-sm text-slate-300 max-w-2xl">
                    Automatically fetch official plans, variations, and provider costs for Airtime, Data Bundles, Cable TV, and Electricity Discos directly from CheapDataHub.
                </p>
            </div>
        </div>
    </div>

    <!-- Status Cards -->
    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <!-- CDH API Status -->
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
            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Reseller Wallet Balance</p>
            <p class="mt-1 text-2xl font-black text-slate-900">
                <?= $cdhBalance !== null ? '₦' . number_format($cdhBalance, 2) : 'Unavailable' ?>
            </p>
            <p class="mt-1 text-xs text-slate-500">Live CheapDataHub balance</p>
        </div>

        <!-- Active Catalog Count -->
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-xs">
            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Active Catalog</p>
            <p class="mt-1 text-2xl font-black text-servora-700">
                <?= $activeDataCount ?> Data Plans
            </p>
            <p class="mt-1 text-xs text-slate-500"><?= $activeProductsCount ?> Utility &amp; TV Services</p>
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
                <span class="text-slate-400 block">Data Plans:</span>
                <strong class="text-slate-800"><?= (int)($syncResults['data']['total'] ?? 0) ?> plans synced</strong>
            </div>
            <div class="bg-white/80 rounded-xl p-2.5 border border-emerald-100">
                <span class="text-slate-400 block">Cable TV:</span>
                <strong class="text-slate-800"><?= (int)($syncResults['cable']['total'] ?? 0) ?> packages synced</strong>
            </div>
            <div class="bg-white/80 rounded-xl p-2.5 border border-emerald-100">
                <span class="text-slate-400 block">Electricity:</span>
                <strong class="text-slate-800"><?= (int)($syncResults['electricity']['synced'] ?? 0) ?> Discos configured</strong>
            </div>
            <div class="bg-white/80 rounded-xl p-2.5 border border-emerald-100">
                <span class="text-slate-400 block">Exam PINs:</span>
                <strong class="text-slate-800"><?= (int)($syncResults['exam_pins']['synced'] ?? 0) ?> products synced</strong>
            </div>
        </div>
        <?php endif; ?>

        <div class="mt-4 flex gap-2">
            <a href="data_plans.php" class="rounded-xl bg-servora-700 px-4 py-2 text-xs font-bold text-white hover:bg-servora-800 transition">View Data Inventory →</a>
            <a href="service_pricing.php" class="rounded-xl bg-white px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 border border-slate-200 transition">View Pricing Control →</a>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
    <div class="mt-6 rounded-2xl bg-rose-50 border border-rose-200 p-4 text-rose-900 shadow-xs flex items-center gap-3">
        <svg class="h-6 w-6 text-rose-600 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
        <p class="text-sm font-semibold"><?= htmlspecialchars($error) ?></p>
    </div>
    <?php endif; ?>

    <!-- Synchronization Form -->
    <div class="mt-6 rounded-3xl border border-slate-200 bg-white p-6 sm:p-8 shadow-xs">
        <h2 class="text-lg font-bold text-slate-900">Synchronize Catalog &amp; Supplier Rates</h2>
        <p class="mt-1 text-xs text-slate-500">
            This operation connects to CheapDataHub's official API, imports all verified plans and pricing, and ensures your catalog is 100% up to date. Existing custom selling prices will be preserved unless set below supplier cost.
        </p>

        <form method="POST" class="mt-6 space-y-5" id="syncForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <!-- Data Default Markup -->
                <div>
                    <label for="data_margin" class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">
                        Default Data Margin (₦)
                    </label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-sm font-bold text-slate-400">₦</span>
                        <input type="number" step="1" min="0" max="500" name="data_margin" id="data_margin" value="40" required
                            class="w-full rounded-xl border border-slate-300 pl-8 pr-4 py-2.5 text-sm font-semibold text-slate-900 focus:border-servora-600 focus:outline-none focus:ring-2 focus:ring-servora-200">
                    </div>
                    <p class="mt-1 text-[11px] text-slate-400">Added to CheapDataHub cost price for new data plans.</p>
                </div>

                <!-- Cable Default Markup -->
                <div>
                    <label for="cable_margin" class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">
                        Default Cable TV Margin (₦)
                    </label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-sm font-bold text-slate-400">₦</span>
                        <input type="number" step="1" min="0" max="1000" name="cable_margin" id="cable_margin" value="100" required
                            class="w-full rounded-xl border border-slate-300 pl-8 pr-4 py-2.5 text-sm font-semibold text-slate-900 focus:border-servora-600 focus:outline-none focus:ring-2 focus:ring-servora-200">
                    </div>
                    <p class="mt-1 text-[11px] text-slate-400">Added to CheapDataHub cost price for TV packages.</p>
                </div>
            </div>

            <!-- Services Overview -->
            <div class="rounded-2xl bg-slate-50 border border-slate-200/80 p-4 text-xs space-y-2 text-slate-700">
                <div class="font-bold text-slate-900 text-sm mb-2">Services Included in Sync:</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    <div class="flex items-center gap-2">
                        <span class="text-emerald-600 font-bold">✓</span>
                        <span><strong>Data Bundles:</strong> MTN, Airtel, Glo (all 49 plans &amp; variations)</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-emerald-600 font-bold">✓</span>
                        <span><strong>Airtime:</strong> MTN, Airtel, Glo, 9mobile/T2</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-emerald-600 font-bold">✓</span>
                        <span><strong>Cable TV:</strong> DStv, GOtv, StarTimes (all 24 packages)</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-emerald-600 font-bold">✓</span>
                        <span><strong>Electricity:</strong> All 10 Nigerian Discos mapped with disco_id</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-emerald-600 font-bold">✓</span>
                        <span><strong>Exam PINs:</strong> WAEC, NECO, NABTEB</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-emerald-600 font-bold">✓</span>
                        <span><strong>VTpass Removal:</strong> Automatically deactivates old VTpass plans</span>
                    </div>
                </div>
            </div>

            <button type="submit" id="syncBtn" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-2xl bg-servora-700 px-6 py-3.5 text-sm font-bold text-white shadow-md hover:bg-servora-800 transition">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                <span>Synchronize All CheapDataHub Services Now</span>
            </button>
        </form>
    </div>
</main>

<script>
document.getElementById('syncForm').addEventListener('submit', function() {
    var btn = document.getElementById('syncBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="animate-spin inline-block mr-2">⏳</span> Synchronizing Services from CheapDataHub...';
});
</script>

</body>
</html>
