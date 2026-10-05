<?php

require_once "../config/database.php";
require_once "../includes/admin_auth.php";

$message = "";
$error = "";

/*
|--------------------------------------------------------------------------
| CSRF Token
|--------------------------------------------------------------------------
*/
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION["csrf_token"];


/*
|--------------------------------------------------------------------------
| ADD DATA PLAN
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (
        empty($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        $error = "Invalid request. Please refresh the page and try again.";

    } else {

        $networkId = (int) ($_POST["network_id"] ?? 0);
        $categoryId = (int) ($_POST["category_id"] ?? 0);
        $providerId = (int) ($_POST["provider_id"] ?? 0);

        $name = trim($_POST["name"] ?? "");
        $dataAmount = trim($_POST["data_amount"] ?? "");
        $validity = trim($_POST["validity"] ?? "");
        $providerPlanCode = trim($_POST["provider_plan_code"] ?? "");

        $costPrice = (float) ($_POST["cost_price"] ?? 0);
        $sellingPrice = (float) ($_POST["selling_price"] ?? 0);

        $status = $_POST["status"] ?? "inactive";

        if (!in_array($status, ["active", "inactive"], true)) {
            $status = "inactive";
        }

        /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */

        if ($networkId <= 0) {
            $error = "Please select a network.";

        } elseif ($name === "") {
            $error = "Please enter the plan name.";

        } elseif ($dataAmount === "") {
            $error = "Please enter the data amount.";

        } elseif ($sellingPrice <= 0) {
            $error = "Selling price must be greater than zero.";

        } elseif ($costPrice < 0) {
            $error = "Cost price cannot be negative.";

        } elseif ($sellingPrice < $costPrice) {
            $error = "Selling price cannot be lower than the supplier cost price.";

        } else {

            /*
            |--------------------------------------------------------------------------
            | Confirm Network Exists
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT id
                FROM networks
                WHERE id = ?
                LIMIT 1
            ");

            $stmt->execute([$networkId]);

            if (!$stmt->fetch()) {
                $error = "The selected network does not exist.";

            } else {

                /*
                |--------------------------------------------------------------------------
                | Insert Plan
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    INSERT INTO data_plans (
                        network_id,
                        category_id,
                        provider_id,
                        provider_plan_code,
                        name,
                        data_amount,
                        validity,
                        cost_price,
                        selling_price,
                        status
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $networkId,
                    $categoryId > 0 ? $categoryId : null,
                    $providerId > 0 ? $providerId : null,
                    $providerPlanCode !== "" ? $providerPlanCode : null,
                    $name,
                    $dataAmount,
                    $validity !== "" ? $validity : null,
                    $costPrice,
                    $sellingPrice,
                    $status
                ]);

                $message = "Data plan added successfully.";
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| GET NETWORKS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT id, name
    FROM networks
    WHERE status = 'active'
    ORDER BY name ASC
");

$networks = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| GET CATEGORIES
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT id, name
    FROM data_categories
    WHERE status = 'active'
    ORDER BY name ASC
");

$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| GET ACTIVE API PROVIDERS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT id, name, slug
    FROM api_providers
    WHERE status = 'active'
    ORDER BY name ASC
");

$providers = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| INVENTORY COUNTS
|--------------------------------------------------------------------------
*/
$statsStmt = $pdo->query("
    SELECT
        COUNT(*) AS total_count,
        SUM(CASE WHEN provider_id = 1 THEN 1 ELSE 0 END) AS cdh_count,
        SUM(CASE WHEN provider_id = 8 THEN 1 ELSE 0 END) AS vtpass_count,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_count
    FROM data_plans
");
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [
    'total_count' => 0,
    'cdh_count' => 0,
    'vtpass_count' => 0,
    'active_count' => 0
];


/*
|--------------------------------------------------------------------------
| FILTERS & SEARCH
|--------------------------------------------------------------------------
*/

$filterProvider = trim($_GET["provider"] ?? "");
$filterNetwork = (int)($_GET["network"] ?? 0);
$filterStatus = trim($_GET["status"] ?? "");
$searchQuery = trim($_GET["search"] ?? "");

$where = ["1 = 1"];
$params = [];

if ($filterProvider === "vtpass") {
    $where[] = "(dp.provider_id = 8 OR ap.slug = 'vtpass')";
} elseif ($filterProvider === "cheapdatahub") {
    $where[] = "(dp.provider_id = 1 OR ap.slug = 'cheapdatahub')";
} elseif ($filterProvider === "none") {
    $where[] = "dp.provider_id IS NULL";
} elseif (is_numeric($filterProvider) && (int)$filterProvider > 0) {
    $where[] = "dp.provider_id = ?";
    $params[] = (int)$filterProvider;
}

if ($filterNetwork > 0) {
    $where[] = "dp.network_id = ?";
    $params[] = $filterNetwork;
}

if (in_array($filterStatus, ["active", "inactive"], true)) {
    $where[] = "dp.status = ?";
    $params[] = $filterStatus;
}

if ($searchQuery !== "") {
    $where[] = "(dp.name LIKE ? OR dp.data_amount LIKE ? OR dp.provider_plan_code LIKE ?)";
    $like = "%" . $searchQuery . "%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereClause = implode(" AND ", $where);


/*
|--------------------------------------------------------------------------
| GET DATA PLANS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        dp.id,
        dp.name,
        dp.data_amount,
        dp.validity,
        dp.cost_price,
        dp.selling_price,
        dp.status,
        dp.provider_plan_code,
        dp.provider_id,

        n.name AS network_name,
        n.slug AS network_slug,
        dc.name AS category_name,
        ap.name AS provider_name,
        ap.slug AS provider_slug

    FROM data_plans dp

    INNER JOIN networks n
        ON dp.network_id = n.id

    LEFT JOIN data_categories dc
        ON dp.category_id = dc.id

    LEFT JOIN api_providers ap
        ON dp.provider_id = ap.id

    WHERE {$whereClause}

    ORDER BY dp.id DESC
");

$stmt->execute($params);
$dataPlans = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Plans | Subnext Admin</title>
    <!-- Precompiled Production Stylesheet -->
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">

<main class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8">

    <!-- HEADER -->
    <section class="rounded-3xl bg-gradient-to-br from-servora-800 via-servora-700 to-servora-500 p-6 text-white shadow-xl sm:p-8">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <a href="dashboard.php" class="mb-3 inline-flex text-sm font-semibold text-white/70 hover:text-white transition">
                    ← Admin Dashboard
                </a>
                <p class="text-sm font-semibold text-white/70">
                    Subnext Administration
                </p>
                <h1 class="mt-1 text-3xl font-black">
                    Data Plans & Multi-Provider Pricing
                </h1>
                <p class="mt-2 max-w-2xl text-sm text-white/75">
                    Manage data bundles across independent providers (CheapDataHub & VTPass) with live markup and transparent profit control.
                </p>
            </div>
            <div class="flex flex-wrap sm:flex-col gap-2.5">
                <a
                    href="sync_vtpass_data.php"
                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-purple-500/30 px-4 py-3 text-xs font-bold text-white ring-1 ring-inset ring-purple-300/40 hover:bg-purple-500/50 transition shadow-sm"
                >
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    Sync VTPass Data Bundles
                </a>
                <a
                    href="data_orders.php"
                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-white/10 px-4 py-3 text-xs font-bold text-white ring-1 ring-inset ring-white/20 hover:bg-white/20 transition shadow-sm"
                >
                    View Data Orders →
                </a>
            </div>
        </div>

        <!-- STATS BAR -->
        <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4 border-t border-white/10 pt-5">
            <div>
                <p class="text-xs text-white/60">Total Plans</p>
                <p class="mt-0.5 text-xl font-bold"><?= (int)$stats['total_count'] ?></p>
            </div>
            <div>
                <p class="text-xs text-blue-200">CheapDataHub Plans</p>
                <p class="mt-0.5 text-xl font-bold text-blue-200"><?= (int)$stats['cdh_count'] ?></p>
            </div>
            <div>
                <p class="text-xs text-purple-200">VTPass Plans</p>
                <p class="mt-0.5 text-xl font-bold text-purple-200"><?= (int)$stats['vtpass_count'] ?></p>
            </div>
            <div>
                <p class="text-xs text-emerald-200">Active Plans</p>
                <p class="mt-0.5 text-xl font-bold text-emerald-200"><?= (int)$stats['active_count'] ?></p>
            </div>
        </div>
    </section>

    <!-- MESSAGES -->
    <?php if ($message !== ""): ?>
        <div class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <div class="mt-6 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-700">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <!-- ADD PLAN FORM -->
    <section class="mt-7 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
        <div>
            <p class="text-xs font-bold uppercase tracking-widest text-servora-600">
                New Plan
            </p>
            <h2 class="mt-1 text-xl font-black text-slate-900">
                Add Data Plan
            </h2>
            <p class="mt-1 text-sm text-slate-500">
                Select provider and cost. Selling price automatically updates based on your desired profit markup.
            </p>
        </div>

        <form method="POST" class="mt-6 grid gap-5 md:grid-cols-3">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <!-- NETWORK -->
            <div>
                <label class="mb-2 block text-sm font-bold text-slate-800">
                    Network
                </label>
                <select name="network_id" required class="w-full rounded-xl border border-slate-300 px-4 py-3 outline-none focus:border-servora-600">
                    <option value="">Select network</option>
                    <?php foreach ($networks as $network): ?>
                        <option value="<?= (int) $network["id"] ?>">
                            <?= htmlspecialchars($network["name"]) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- CATEGORY -->
            <div>
                <label class="mb-2 block text-sm font-bold text-slate-800">
                    Category (Optional)
                </label>
                <select name="category_id" class="w-full rounded-xl border border-slate-300 px-4 py-3 outline-none focus:border-servora-600">
                    <option value="">Select category</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= (int) $category["id"] ?>">
                            <?= htmlspecialchars($category["name"]) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- API PROVIDER -->
            <div>
                <label class="mb-2 block text-sm font-bold text-slate-800">
                    API Provider
                </label>
                <select id="provider_id_select" name="provider_id" class="w-full rounded-xl border border-slate-300 px-4 py-3 outline-none focus:border-servora-600">
                    <option value="">Connect later</option>
                    <?php foreach ($providers as $provider): ?>
                        <option value="<?= (int) $provider["id"] ?>" data-slug="<?= htmlspecialchars($provider["slug"] ?? "") ?>">
                            <?= htmlspecialchars($provider["name"]) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- PLAN NAME -->
            <div>
                <label class="mb-2 block text-sm font-bold text-slate-800">
                    Plan Name
                </label>
                <input
                    type="text"
                    name="name"
                    required
                    placeholder="Example: MTN 1.5GB Monthly"
                    class="w-full rounded-xl border border-slate-300 px-4 py-3 outline-none focus:border-servora-600"
                >
            </div>

            <!-- DATA AMOUNT -->
            <div>
                <label class="mb-2 block text-sm font-bold text-slate-800">
                    Data Amount
                </label>
                <input
                    type="text"
                    name="data_amount"
                    required
                    placeholder="Example: 1.5GB"
                    class="w-full rounded-xl border border-slate-300 px-4 py-3 outline-none focus:border-servora-600"
                >
            </div>

            <!-- VALIDITY -->
            <div>
                <label class="mb-2 block text-sm font-bold text-slate-800">
                    Validity
                </label>
                <input
                    type="text"
                    name="validity"
                    placeholder="Example: 30 Days"
                    class="w-full rounded-xl border border-slate-300 px-4 py-3 outline-none focus:border-servora-600"
                >
            </div>

            <!-- PROVIDER PLAN CODE -->
            <div>
                <label class="mb-2 block text-sm font-bold text-slate-800">
                    Provider Plan Code
                </label>
                <input
                    type="text"
                    name="provider_plan_code"
                    placeholder="e.g. 77 (CheapDataHub) or mtn-100mb-1000 (VTPass)"
                    class="w-full rounded-xl border border-slate-300 px-4 py-3 outline-none focus:border-servora-600"
                >
            </div>

            <!-- COST PRICE -->
            <div>
                <label class="mb-2 block text-sm font-bold text-slate-800">
                    Supplier Cost Price (₦)
                </label>
                <input
                    type="number"
                    id="add_cost_price"
                    name="cost_price"
                    min="0"
                    step="0.01"
                    value="0"
                    required
                    class="w-full rounded-xl border border-slate-300 px-4 py-3 outline-none focus:border-servora-600"
                >
            </div>

            <!-- PROFIT MARKUP (INTERACTIVE) -->
            <div>
                <label class="mb-2 flex items-center justify-between text-sm font-bold text-slate-800">
                    <span>Profit Markup (₦)</span>
                    <span class="text-xs font-semibold text-emerald-600">Auto-calculates</span>
                </label>
                <input
                    type="number"
                    id="add_profit_margin"
                    step="0.01"
                    value="50.00"
                    placeholder="50.00"
                    class="w-full rounded-xl border border-emerald-300 bg-emerald-50/30 px-4 py-3 font-bold text-emerald-700 outline-none focus:border-emerald-600"
                >
            </div>

            <!-- SELLING PRICE -->
            <div>
                <label class="mb-2 block text-sm font-bold text-slate-800">
                    Subnext Selling Price (₦)
                </label>
                <input
                    type="number"
                    id="add_selling_price"
                    name="selling_price"
                    min="0.01"
                    step="0.01"
                    required
                    placeholder="0.00"
                    class="w-full rounded-xl border border-slate-300 px-4 py-3 font-bold text-slate-900 outline-none focus:border-servora-600"
                >
            </div>

            <!-- STATUS -->
            <div>
                <label class="mb-2 block text-sm font-bold text-slate-800">
                    Status
                </label>
                <select name="status" class="w-full rounded-xl border border-slate-300 px-4 py-3 outline-none focus:border-servora-600">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>

            <!-- SUBMIT -->
            <div class="flex items-end">
                <button
                    type="submit"
                    class="w-full rounded-xl bg-servora-700 px-5 py-3.5 font-bold text-white transition hover:bg-servora-800"
                >
                    Add Data Plan
                </button>
            </div>
        </form>
    </section>

    <!-- INVENTORY SECTION WITH FILTERS -->
    <section class="mt-10">

        <!-- FILTER BAR -->
        <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <h2 class="text-xl font-black text-slate-900">
                        Data Plans Catalog
                    </h2>
                    <p class="text-xs text-slate-500">
                        Showing <?= count($dataPlans) ?> data plans based on your filter criteria.
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <a
                        href="sync_vtpass_data.php"
                        class="rounded-xl border border-purple-200 bg-purple-50 px-3.5 py-2 text-xs font-bold text-purple-700 hover:bg-purple-100 transition"
                    >
                        Sync VTPass
                    </a>
                    <a
                        href="data_plans.php"
                        class="rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2 text-xs font-bold text-slate-600 hover:bg-slate-100 transition"
                    >
                        Reset Filters
                    </a>
                </div>
            </div>

            <form method="GET" class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-4">
                <!-- PROVIDER FILTER -->
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">
                        Provider
                    </label>
                    <select name="provider" onchange="this.form.submit()" class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 text-sm font-semibold outline-none focus:border-servora-600">
                        <option value="">All Providers</option>
                        <option value="cheapdatahub" <?= $filterProvider === "cheapdatahub" ? "selected" : "" ?>>CheapDataHub</option>
                        <option value="vtpass" <?= $filterProvider === "vtpass" ? "selected" : "" ?>>VTpass</option>
                        <option value="none" <?= $filterProvider === "none" ? "selected" : "" ?>>Unconnected</option>
                    </select>
                </div>

                <!-- NETWORK FILTER -->
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">
                        Network
                    </label>
                    <select name="network" onchange="this.form.submit()" class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 text-sm font-semibold outline-none focus:border-servora-600">
                        <option value="0">All Networks</option>
                        <?php foreach ($networks as $net): ?>
                            <option value="<?= (int)$net['id'] ?>" <?= $filterNetwork === (int)$net['id'] ? "selected" : "" ?>>
                                <?= htmlspecialchars($net['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- STATUS FILTER -->
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">
                        Status
                    </label>
                    <select name="status" onchange="this.form.submit()" class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 text-sm font-semibold outline-none focus:border-servora-600">
                        <option value="">All Statuses</option>
                        <option value="active" <?= $filterStatus === "active" ? "selected" : "" ?>>Active</option>
                        <option value="inactive" <?= $filterStatus === "inactive" ? "selected" : "" ?>>Inactive</option>
                    </select>
                </div>

                <!-- SEARCH -->
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">
                        Search Plan
                    </label>
                    <div class="relative">
                        <input
                            type="text"
                            name="search"
                            value="<?= htmlspecialchars($searchQuery) ?>"
                            placeholder="Name, size or code..."
                            class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 pr-8 text-sm outline-none focus:border-servora-600"
                        >
                        <?php if ($searchQuery !== ""): ?>
                            <a href="data_plans.php" class="absolute right-2 top-2 text-xs text-slate-400 hover:text-slate-600">✕</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>

        <!-- TABLE -->
        <?php if (!$dataPlans): ?>
            <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-12 text-center shadow-xs">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <p class="mt-3 font-bold text-slate-800">No data plans match your filter.</p>
                <p class="mt-1 text-sm text-slate-500">Try adjusting your filters or search keywords above.</p>
                <div class="mt-4">
                    <a href="data_plans.php" class="rounded-xl bg-servora-600 px-4 py-2 text-xs font-bold text-white hover:bg-servora-700 transition">
                        Clear All Filters
                    </a>
                </div>
            </div>
        <?php else: ?>
            <div class="mt-6 overflow-x-auto rounded-3xl border border-slate-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-slate-100">
                    <thead class="bg-slate-50/80">
                        <tr class="text-left text-xs font-bold uppercase tracking-wider text-slate-500">
                            <th class="px-5 py-4">Network</th>
                            <th class="px-5 py-4">Plan Name & Details</th>
                            <th class="px-5 py-4">Provider</th>
                            <th class="px-5 py-4">Supplier Cost</th>
                            <th class="px-5 py-4">Profit Markup</th>
                            <th class="px-5 py-4">Selling Price</th>
                            <th class="px-5 py-4">Status</th>
                            <th class="px-5 py-4 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-sm">
                        <?php foreach ($dataPlans as $plan): ?>
                            <?php
                            $cost = (float) $plan["cost_price"];
                            $selling = (float) $plan["selling_price"];
                            $profit = $selling - $cost;
                            $providerSlug = strtolower(trim((string)($plan["provider_slug"] ?? "")));
                            $providerId = (int)($plan["provider_id"] ?? 0);
                            $isVtpass = ($providerSlug === 'vtpass' || $providerId === 8);
                            $isCdh = ($providerSlug === 'cheapdatahub' || $providerId === 1);
                            ?>
                            <tr class="hover:bg-slate-50/80 transition-colors">
                                <!-- NETWORK -->
                                <td class="px-5 py-4 font-black text-slate-900 whitespace-nowrap">
                                    <span class="inline-flex items-center gap-1.5">
                                        <span class="h-2 w-2 rounded-full <?= match(strtolower($plan['network_slug'] ?? '')) {
                                            'mtn' => 'bg-amber-400',
                                            'airtel' => 'bg-red-500',
                                            'glo' => 'bg-emerald-500',
                                            't2', '9mobile' => 'bg-lime-500',
                                            default => 'bg-slate-400'
                                        } ?>"></span>
                                        <?= htmlspecialchars($plan["network_name"]) ?>
                                    </span>
                                </td>

                                <!-- PLAN NAME -->
                                <td class="px-5 py-4">
                                    <p class="font-bold text-slate-900">
                                        <?= htmlspecialchars($plan["name"]) ?>
                                    </p>
                                    <div class="mt-0.5 flex items-center gap-2 text-xs text-slate-500">
                                        <span class="font-semibold text-slate-700"><?= htmlspecialchars($plan["data_amount"]) ?></span>
                                        <?php if (!empty($plan["validity"])): ?>
                                            <span>• <?= htmlspecialchars($plan["validity"]) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($plan["category_name"])): ?>
                                            <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] uppercase font-bold text-slate-600">
                                                <?= htmlspecialchars($plan["category_name"]) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($plan["provider_plan_code"])): ?>
                                        <p class="mt-1 font-mono text-[11px] text-slate-400">
                                            Code: <span class="text-slate-600 font-semibold"><?= htmlspecialchars($plan["provider_plan_code"]) ?></span>
                                        </p>
                                    <?php endif; ?>
                                </td>

                                <!-- PROVIDER BADGE -->
                                <td class="px-5 py-4 whitespace-nowrap">
                                    <?php if ($isVtpass): ?>
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-purple-50 px-2.5 py-1 text-xs font-bold text-purple-700 border border-purple-200">
                                            <span class="h-1.5 w-1.5 rounded-full bg-purple-600"></span>
                                            VTpass
                                        </span>
                                    <?php elseif ($isCdh): ?>
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-50 px-2.5 py-1 text-xs font-bold text-blue-700 border border-blue-200">
                                            <span class="h-1.5 w-1.5 rounded-full bg-blue-600"></span>
                                            CheapDataHub
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-500">
                                            <?= htmlspecialchars($plan["provider_name"] ?? "Not connected") ?>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- SUPPLIER COST -->
                                <td class="px-5 py-4 font-mono font-medium text-slate-600 whitespace-nowrap">
                                    ₦<?= number_format($cost, 2) ?>
                                </td>

                                <!-- PROFIT -->
                                <td class="px-5 py-4 font-mono font-bold text-emerald-600 whitespace-nowrap">
                                    +₦<?= number_format($profit, 2) ?>
                                </td>

                                <!-- SELLING PRICE -->
                                <td class="px-5 py-4 font-mono font-black text-slate-900 whitespace-nowrap">
                                    ₦<?= number_format($selling, 2) ?>
                                </td>

                                <!-- STATUS -->
                                <td class="px-5 py-4 whitespace-nowrap">
                                    <?php if ($plan["status"] === "active"): ?>
                                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-bold text-emerald-700 border border-emerald-200">
                                            Active
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-bold text-slate-500">
                                            Inactive
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- ACTION -->
                                <td class="px-5 py-4 text-right whitespace-nowrap">
                                    <a
                                        href="edit_data_plan.php?id=<?= (int) $plan["id"] ?>"
                                        class="inline-flex items-center rounded-lg bg-servora-50 px-3 py-1.5 text-xs font-bold text-servora-700 hover:bg-servora-100 transition"
                                    >
                                        Edit Price
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <footer class="py-8 text-center text-xs text-slate-400">
        Subnext Multi-Provider Data Infrastructure
    </footer>
</main>

<script>
    // Live profit markup and selling price synchronizer
    const costInput = document.getElementById('add_cost_price');
    const profitInput = document.getElementById('add_profit_margin');
    const sellingInput = document.getElementById('add_selling_price');
    const providerSelect = document.getElementById('provider_id_select');

    function updateFromProfit() {
        const cost = parseFloat(costInput.value) || 0;
        const profit = parseFloat(profitInput.value) || 0;
        const total = cost + profit;
        sellingInput.value = total > 0 ? total.toFixed(2) : '';
    }

    function updateFromSelling() {
        const cost = parseFloat(costInput.value) || 0;
        const selling = parseFloat(sellingInput.value) || 0;
        const profit = selling - cost;
        profitInput.value = profit >= 0 ? profit.toFixed(2) : '0.00';
    }

    if (costInput && profitInput && sellingInput) {
        costInput.addEventListener('input', updateFromProfit);
        profitInput.addEventListener('input', updateFromProfit);
        sellingInput.addEventListener('input', updateFromSelling);

        // Pre-fill VTPass default profit if selected
        if (providerSelect) {
            providerSelect.addEventListener('change', function() {
                const opt = providerSelect.options[providerSelect.selectedIndex];
                const slug = opt.getAttribute('data-slug');
                if (slug === 'vtpass' || providerSelect.value === '8') {
                    if (!profitInput.value || parseFloat(profitInput.value) === 0) {
                        profitInput.value = '50.00';
                        updateFromProfit();
                    }
                }
            });
        }
    }
</script>

</body>
</html>