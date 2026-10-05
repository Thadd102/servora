<?php

require_once "../config/database.php";
require_once "../includes/admin_auth.php";
require_once "../includes/VTPassDataClient.php";

$message = "";
$error = "";
$syncResults = null;

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION["csrf_token"];

$vtpassClient = new VTPassDataClient();
$balanceResult = $vtpassClient->getBalance();
$isConfigured = !empty($vtpassClient->getBaseUrl());
$vtpassBalance = null;
if (($balanceResult['ok'] ?? false) === true) {
    $rawBal = $balanceResult['data']['contents']['balance'] ?? ($balanceResult['data']['balance'] ?? null);
    if ($rawBal !== null && is_numeric($rawBal)) {
        $vtpassBalance = (float)$rawBal;
    }
}

// Current VTPass plans count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM data_plans WHERE provider_id = 8");
$stmt->execute();
$currentVtpassCount = (int)$stmt->fetchColumn();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (empty($_POST["csrf_token"]) || !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])) {
        $error = "Invalid security token. Please refresh and try again.";
    } else {
        $defaultProfit = (float)($_POST["default_profit"] ?? 50.00);
        if ($defaultProfit < 0) {
            $defaultProfit = 50.00;
        }

        $serviceMap = [
            'mtn' => [
                'serviceId' => 'mtn-data',
                'network_id' => 1,
                'name' => 'MTN'
            ],
            'airtel' => [
                'serviceId' => 'airtel-data',
                'network_id' => 2,
                'name' => 'Airtel'
            ],
            'glo' => [
                'serviceId' => 'glo-data',
                'network_id' => 3,
                'name' => 'Glo'
            ],
            't2' => [
                'serviceId' => 'etisalat-data',
                'network_id' => 4,
                'name' => 'T2 / 9mobile'
            ]
        ];

        $totalInserted = 0;
        $totalUpdated = 0;
        $networkSummary = [];

        try {
            foreach ($serviceMap as $netKey => $net) {
                $res = $vtpassClient->getVariations($net['serviceId']);
                $variations = $res['data']['content']['varations'] ?? ($res['data']['content']['variations'] ?? []);

                $netInserted = 0;
                $netUpdated = 0;

                foreach ($variations as $var) {
                    $code = trim((string)($var['variation_code'] ?? ''));
                    $rawName = trim((string)($var['name'] ?? ''));
                    $costPrice = (float)($var['variation_amount'] ?? 0);

                    if ($code === '' || $costPrice <= 0) {
                        continue;
                    }

                    // Extract data amount (e.g. 500MB, 1.5GB, 10GB)
                    $dataAmount = '';
                    if (preg_match('/(\d+(?:\.\d+)?\s*(?:MB|GB|TB))/i', $rawName, $m)) {
                        $dataAmount = strtoupper(str_replace(' ', '', $m[1]));
                    } else {
                        $dataAmount = $rawName;
                    }

                    // Extract validity (e.g. 24 hrs, 30 days, 1 day)
                    $validity = '';
                    if (preg_match('/(\d+\s*(?:day|days|hrs|hour|hours|month|months|week|weeks|yr|year))/i', $rawName, $vm)) {
                        $validity = ucwords(trim($vm[1]));
                    }

                    // Clean display name
                    $cleanName = $net['name'] . ' ' . $dataAmount . ($validity ? ' (' . $validity . ')' : '');

                    // Check if plan already exists for VTPass
                    $stmtCheck = $pdo->prepare("
                        SELECT id, cost_price, selling_price
                        FROM data_plans
                        WHERE provider_id = 8
                          AND provider_plan_code = ?
                        LIMIT 1
                    ");
                    $stmtCheck->execute([$code]);
                    $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

                    if ($existing) {
                        // Plan exists: update cost price. If selling price is below cost, adjust it.
                        $existingSelling = (float)$existing['selling_price'];
                        $newSelling = ($existingSelling < $costPrice) ? ($costPrice + $defaultProfit) : $existingSelling;

                        $stmtUp = $pdo->prepare("
                            UPDATE data_plans
                            SET cost_price = ?,
                                selling_price = ?,
                                name = ?,
                                data_amount = ?,
                                validity = ?
                            WHERE id = ?
                        ");
                        $stmtUp->execute([
                            $costPrice,
                            $newSelling,
                            $cleanName,
                            $dataAmount,
                            $validity ?: null,
                            $existing['id']
                        ]);
                        $netUpdated++;
                        $totalUpdated++;
                    } else {
                        // New plan: insert with default profit markup
                        $sellingPrice = $costPrice + $defaultProfit;

                        $stmtIn = $pdo->prepare("
                            INSERT INTO data_plans (
                                network_id,
                                provider_id,
                                provider_plan_code,
                                name,
                                data_amount,
                                validity,
                                cost_price,
                                selling_price,
                                status
                            )
                            VALUES (?, 8, ?, ?, ?, ?, ?, ?, 'active')
                        ");
                        $stmtIn->execute([
                            $net['network_id'],
                            $code,
                            $cleanName,
                            $dataAmount,
                            $validity ?: null,
                            $costPrice,
                            $sellingPrice
                        ]);
                        $netInserted++;
                        $totalInserted++;
                    }
                }

                $networkSummary[$net['name']] = [
                    'variations' => count($variations),
                    'inserted'   => $netInserted,
                    'updated'    => $netUpdated
                ];
            }

            $message = "Synchronization successful! Added {$totalInserted} new plans, updated {$totalUpdated} existing plans with ₦" . number_format($defaultProfit, 2) . " markup.";
            $syncResults = $networkSummary;

            // Refresh count
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM data_plans WHERE provider_id = 8");
            $stmt->execute();
            $currentVtpassCount = (int)$stmt->fetchColumn();

        } catch (Throwable $e) {
            error_log("VTPass Sync error: " . $e->getMessage());
            $error = "Synchronization failed: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sync VTPass Data Bundles | Subnext Admin</title>
    <!-- Precompiled Production Stylesheet -->
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">

<main class="mx-auto w-full max-w-4xl px-4 py-6 sm:px-6 lg:px-8">

    <!-- HEADER -->
    <section class="rounded-3xl bg-gradient-to-br from-purple-900 via-indigo-800 to-servora-600 p-6 text-white shadow-xl sm:p-8">
        <a href="data_plans.php" class="mb-4 inline-flex items-center text-sm font-semibold text-white/70 hover:text-white transition">
            ← Back to Data Plans
        </a>
        <div class="flex items-center gap-3">
            <span class="inline-flex items-center rounded-full bg-purple-400/20 px-3 py-1 text-xs font-bold text-purple-200 ring-1 ring-inset ring-purple-300/30">
                Supplier Integration
            </span>
            <span class="inline-flex items-center rounded-full bg-emerald-400/20 px-3 py-1 text-xs font-bold text-emerald-200 ring-1 ring-inset ring-emerald-300/30">
                VTPass Provider ID: 8
            </span>
        </div>
        <h1 class="mt-3 text-3xl font-black">
            Sync VTPass Data Bundles
        </h1>
        <p class="mt-2 max-w-2xl text-sm text-purple-100/80">
            Automatically fetch official VTPass variation codes for MTN, Airtel, Glo, and 9mobile/T2.
            Subnext will calculate customer selling prices using your configurable profit markup (default: ₦50.00).
        </p>
    </section>

    <!-- STATUS CARDS -->
    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">VTPass Status</p>
            <p class="mt-1 flex items-center gap-2 text-lg font-bold text-slate-800">
                <span class="h-2.5 w-2.5 rounded-full <?= ($balanceResult['ok'] ?? false) ? 'bg-emerald-500' : 'bg-amber-500' ?>"></span>
                <?= ($balanceResult['ok'] ?? false) ? 'Connected & Live' : 'Pending Check' ?>
            </p>
            <p class="mt-1 text-xs text-slate-500">Target: <?= htmlspecialchars($vtpassClient->getBaseUrl()) ?></p>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Supplier Wallet</p>
            <p class="mt-1 text-lg font-bold text-purple-700">
                <?= $vtpassBalance !== null ? '₦' . number_format($vtpassBalance, 2) : 'Unavailable' ?>
            </p>
            <p class="mt-1 text-xs text-slate-500">Live VTPass account balance</p>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Active VTPass Plans</p>
            <p class="mt-1 text-lg font-bold text-slate-800"><?= $currentVtpassCount ?> Plans</p>
            <p class="mt-1 text-xs text-slate-500">Currently in Subnext catalog</p>
        </div>
    </div>

    <!-- FEEDBACK -->
    <?php if ($message !== ""): ?>
        <div class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-sm font-semibold text-emerald-800">
            <div class="flex items-center gap-2">
                <svg class="h-5 w-5 text-emerald-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <div class="mt-6 rounded-2xl border border-red-200 bg-red-50 p-5 text-sm font-semibold text-red-800">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($syncResults): ?>
        <div class="mt-6 rounded-2xl border border-purple-200 bg-purple-50/50 p-5">
            <h3 class="text-sm font-black uppercase tracking-wider text-purple-900">Sync Breakdown by Network</h3>
            <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <?php foreach ($syncResults as $netName => $stats): ?>
                    <div class="rounded-xl border border-purple-100 bg-white p-3 text-center shadow-xs">
                        <p class="text-xs font-bold text-slate-500"><?= htmlspecialchars($netName) ?></p>
                        <p class="mt-1 text-lg font-black text-purple-700"><?= $stats['variations'] ?> plans</p>
                        <p class="text-xs text-slate-400">+<?= $stats['inserted'] ?> new, <?= $stats['updated'] ?> updated</p>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="mt-4 flex justify-end">
                <a href="data_plans.php?provider=vtpass" class="rounded-xl bg-purple-700 px-4 py-2 text-xs font-bold text-white hover:bg-purple-800 transition">
                    View VTPass Plans in Inventory →
                </a>
            </div>
        </div>
    <?php endif; ?>

    <!-- SYNC FORM -->
    <section class="mt-6 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
        <h2 class="text-xl font-black text-slate-900">Run Synchronization</h2>
        <p class="mt-1 text-sm text-slate-500">
            This tool queries the official VTPass API for real variation codes across all 4 networks and imports or updates them in Subnext.
        </p>

        <form method="POST" class="mt-6 space-y-6">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <div class="max-w-md">
                <label class="block text-sm font-bold text-slate-800">
                    Default Profit Markup per Plan (₦)
                </label>
                <p class="text-xs text-slate-500 mt-0.5">
                    Selling Price will be set to: <span class="font-bold text-slate-700">Cost Price + Markup</span>.
                    You can adjust any individual plan's price anytime in Data Plans.
                </p>
                <div class="relative mt-2">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-4 font-bold text-slate-400">₦</span>
                    <input
                        type="number"
                        step="0.01"
                        min="0"
                        name="default_profit"
                        value="50.00"
                        required
                        class="w-full rounded-xl border border-slate-300 py-3 pl-8 pr-4 font-bold text-slate-900 outline-none focus:border-purple-600 focus:ring-1 focus:ring-purple-600"
                    >
                </div>
            </div>

            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-xs text-amber-800">
                <p class="font-bold">Safe Multi-Provider Architecture:</p>
                <p class="mt-0.5">
                    Syncing VTPass will <strong>NEVER</strong> overwrite or affect your existing CheapDataHub plans.
                    All VTPass plans are saved under Provider ID 8 with their distinct variation codes.
                </p>
            </div>

            <div class="flex items-center gap-4 pt-2">
                <button
                    type="submit"
                    class="rounded-xl bg-gradient-to-r from-purple-700 to-indigo-700 px-6 py-3.5 text-sm font-bold text-white shadow-md hover:from-purple-800 hover:to-indigo-800 transition"
                >
                    Sync All VTPass Data Bundles
                </button>
                <a href="data_plans.php" class="text-sm font-semibold text-slate-500 hover:text-slate-800 transition">
                    Cancel
                </a>
            </div>
        </form>
    </section>

</main>

</body>
</html>
