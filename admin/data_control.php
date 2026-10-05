<?php

require_once "../config/database.php";
require_once "../includes/admin_auth.php";

$adminName = $_SESSION["full_name"] ?? "Admin";


/*
|--------------------------------------------------------------------------
| DATA ORDER STATISTICS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM data_orders
");
$totalOrders = (int) $stmt->fetchColumn();


$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM data_orders
    WHERE status = 'pending'
");
$pendingOrders = (int) $stmt->fetchColumn();


$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM data_orders
    WHERE status = 'processing'
");
$processingOrders = (int) $stmt->fetchColumn();


$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM data_orders
    WHERE status = 'successful'
");
$successfulOrders = (int) $stmt->fetchColumn();


$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM data_orders
    WHERE status = 'failed'
");
$failedOrders = (int) $stmt->fetchColumn();


$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM data_orders
    WHERE status = 'refunded'
");
$refundedOrders = (int) $stmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| DATA PLAN STATISTICS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM data_plans
");
$totalPlans = (int) $stmt->fetchColumn();


$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM data_plans
    WHERE status = 'active'
");
$activePlans = (int) $stmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| NETWORK STATISTICS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM networks
");
$totalNetworks = (int) $stmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| PROVIDER STATISTICS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM api_providers
");
$totalProviders = (int) $stmt->fetchColumn();


$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM api_providers
    WHERE status = 'active'
");
$activeProviders = (int) $stmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| SALES AND PROFIT
|--------------------------------------------------------------------------
|
| Only successful orders count as completed sales/profit.
|
*/

$stmt = $pdo->query("
    SELECT
        COALESCE(SUM(selling_price), 0) AS total_sales,
        COALESCE(SUM(cost_price), 0) AS total_cost,
        COALESCE(SUM(profit), 0) AS total_profit
    FROM data_orders
    WHERE status = 'successful'
");

$financialStats = $stmt->fetch(PDO::FETCH_ASSOC);

$totalSales = (float) ($financialStats["total_sales"] ?? 0);
$totalCost = (float) ($financialStats["total_cost"] ?? 0);
$totalProfit = (float) ($financialStats["total_profit"] ?? 0);


/*
|--------------------------------------------------------------------------
| RECENT DATA ORDERS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT
        do.id,
        do.order_reference,
        do.phone_number,
        do.selling_price,
        do.status,
        do.created_at,

        u.full_name,

        dp.name AS plan_name,
        dp.data_amount,

        n.name AS network_name

    FROM data_orders do

    INNER JOIN users u
        ON u.id = do.user_id

    INNER JOIN data_plans dp
        ON dp.id = do.plan_id

    INNER JOIN networks n
        ON n.id = dp.network_id

    ORDER BY do.id DESC

    LIMIT 5
");

$recentOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Data Control | Subnext Admin</title>

    <!-- Precompiled Production Stylesheet -->
    <link rel="stylesheet" href="../assets/css/style.css">

</head>


<body class="min-h-screen bg-slate-50 text-slate-900">

<main class="mx-auto w-full max-w-7xl px-4 py-5 sm:px-6 sm:py-8 lg:px-8">


    <!-- HEADER -->

    <section
        class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-servora-800 via-servora-700 to-servora-500 p-6 text-white shadow-xl sm:p-8"
    >

        <div class="relative z-10">

            <div class="flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">

                <div>

                    <a
                        href="dashboard.php"
                        class="mb-4 inline-flex items-center text-sm font-semibold text-white/75 transition hover:text-white"
                    >
                        ← Admin Dashboard
                    </a>

                    <p class="text-sm font-semibold text-white/70">
                        Subnext Administration
                    </p>

                    <h1 class="mt-1 text-3xl font-black tracking-tight sm:text-4xl">
                        Data Control Center
                    </h1>

                    <p class="mt-3 max-w-2xl text-sm leading-6 text-white/75 sm:text-base">
                        Manage data plans, networks, suppliers, orders and
                        monitor the performance of your data vending service.
                    </p>

                </div>


                <div
                    class="rounded-2xl border border-white/15 bg-white/10 px-5 py-4 backdrop-blur"
                >

                    <p class="text-xs text-white/60">
                        Signed in as
                    </p>

                    <p class="mt-1 font-bold">
                        <?= htmlspecialchars($adminName) ?>
                    </p>

                </div>

            </div>

        </div>


        <div
            class="absolute -right-16 -top-20 h-56 w-56 rounded-full bg-white/10"
        ></div>

        <div
            class="absolute -bottom-24 right-20 h-64 w-64 rounded-full bg-white/5"
        ></div>

    </section>



    <!-- ORDER STATISTICS -->

    <section class="mt-7">

        <div class="mb-4">

            <p class="text-xs font-bold uppercase tracking-widest text-servora-600">
                Overview
            </p>

            <h2 class="mt-1 text-xl font-black">
                Data vending performance
            </h2>

        </div>


        <div class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6">


            <!-- Total -->

            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">

                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">
                    Total Orders
                </p>

                <p class="mt-2 text-2xl font-black">
                    <?= $totalOrders ?>
                </p>

            </div>


            <!-- Pending -->

            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">

                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">
                    Pending
                </p>

                <p class="mt-2 text-2xl font-black text-amber-600">
                    <?= $pendingOrders ?>
                </p>

            </div>


            <!-- Processing -->

            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">

                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">
                    Processing
                </p>

                <p class="mt-2 text-2xl font-black text-blue-600">
                    <?= $processingOrders ?>
                </p>

            </div>


            <!-- Successful -->

            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">

                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">
                    Successful
                </p>

                <p class="mt-2 text-2xl font-black text-emerald-600">
                    <?= $successfulOrders ?>
                </p>

            </div>


            <!-- Failed -->

            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">

                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">
                    Failed
                </p>

                <p class="mt-2 text-2xl font-black text-red-600">
                    <?= $failedOrders ?>
                </p>

            </div>


            <!-- Refunded -->

            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">

                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">
                    Refunded
                </p>

                <p class="mt-2 text-2xl font-black text-purple-600">
                    <?= $refundedOrders ?>
                </p>

            </div>

        </div>

    </section>



    <!-- FINANCIAL OVERVIEW -->

    <section class="mt-7">

        <div class="grid gap-4 md:grid-cols-3">


            <div
                class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
            >

                <p class="text-sm font-semibold text-slate-500">
                    Successful Data Sales
                </p>

                <p class="mt-2 text-2xl font-black">
                    ₦<?= number_format($totalSales, 2) ?>
                </p>

            </div>


            <div
                class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
            >

                <p class="text-sm font-semibold text-slate-500">
                    Supplier Cost
                </p>

                <p class="mt-2 text-2xl font-black">
                    ₦<?= number_format($totalCost, 2) ?>
                </p>

            </div>


            <div
                class="rounded-2xl border border-servora-200 bg-servora-50 p-5 shadow-sm"
            >

                <p class="text-sm font-semibold text-servora-700">
                    Data Profit
                </p>

                <p class="mt-2 text-2xl font-black text-servora-800">
                    ₦<?= number_format($totalProfit, 2) ?>
                </p>

            </div>

        </div>

    </section>



    <!-- MANAGEMENT -->

    <section class="mt-8">

        <div class="mb-4">

            <p class="text-xs font-bold uppercase tracking-widest text-servora-600">
                Data Management
            </p>

            <h2 class="mt-1 text-xl font-black sm:text-2xl">
                Manage data services
            </h2>

            <p class="mt-1 text-sm text-slate-500">
                Everything related to Subnext data vending is available here.
            </p>

        </div>


        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">


            <!-- DATA PLANS -->

            <a
                href="data_plans.php"
                class="group rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-servora-200 hover:shadow-md"
            >

                <div class="flex items-start justify-between">

                    <div
                        class="flex h-11 w-11 items-center justify-center rounded-xl bg-blue-50 text-blue-600"
                    >
                        📶
                    </div>

                    <span class="text-slate-300 group-hover:text-servora-600">
                        →
                    </span>

                </div>

                <h3 class="mt-5 font-bold">
                    Data Plans
                </h3>

                <p class="mt-1 text-sm leading-6 text-slate-500">
                    Add plans, change selling prices, supplier costs and
                    activate or deactivate plans.
                </p>

                <p class="mt-4 text-xs font-bold text-servora-600">
                    <?= $activePlans ?> active of <?= $totalPlans ?> plans
                </p>

            </a>



            <!-- DATA ORDERS -->

            <a
                href="data_orders.php"
                class="group rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-servora-200 hover:shadow-md"
            >

                <div class="flex items-start justify-between">

                    <div
                        class="flex h-11 w-11 items-center justify-center rounded-xl bg-purple-50 text-purple-600"
                    >
                        🧾
                    </div>

                    <span class="text-slate-300 group-hover:text-servora-600">
                        →
                    </span>

                </div>

                <h3 class="mt-5 font-bold">
                    Data Orders
                </h3>

                <p class="mt-1 text-sm leading-6 text-slate-500">
                    Monitor customer purchases, supplier responses,
                    failures and refunds.
                </p>

                <p class="mt-4 text-xs font-bold text-servora-600">
                    <?= $totalOrders ?> total orders
                </p>

            </a>



            <!-- NETWORKS -->

            <a
                href="data_networks.php"
                class="group rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-servora-200 hover:shadow-md"
            >

                <div class="flex items-start justify-between">

                    <div
                        class="flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600"
                    >
                        📡
                    </div>

                    <span class="text-slate-300 group-hover:text-servora-600">
                        →
                    </span>

                </div>

                <h3 class="mt-5 font-bold">
                    Networks
                </h3>

                <p class="mt-1 text-sm leading-6 text-slate-500">
                    Manage Airtel, MTN, Glo, T2 and other supported
                    networks.
                </p>

                <p class="mt-4 text-xs font-bold text-servora-600">
                    <?= $totalNetworks ?> networks
                </p>

            </a>



            <!-- PROVIDERS -->

            <a
                href="data_providers.php"
                class="group rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-servora-200 hover:shadow-md"
            >

                <div class="flex items-start justify-between">

                    <div
                        class="flex h-11 w-11 items-center justify-center rounded-xl bg-amber-50 text-amber-600"
                    >
                        🔌
                    </div>

                    <span class="text-slate-300 group-hover:text-servora-600">
                        →
                    </span>

                </div>

                <h3 class="mt-5 font-bold">
                    API Providers
                </h3>

                <p class="mt-1 text-sm leading-6 text-slate-500">
                    Manage CheapDataHub and future data suppliers.
                </p>

                <p class="mt-4 text-xs font-bold text-servora-600">
                    <?= $activeProviders ?> active of <?= $totalProviders ?>
                </p>

            </a>



            <!-- CATEGORIES -->

            <a
                href="data_categories.php"
                class="group rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-servora-200 hover:shadow-md"
            >

                <div class="flex items-start justify-between">

                    <div
                        class="flex h-11 w-11 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600"
                    >
                        🗂️
                    </div>

                    <span class="text-slate-300 group-hover:text-servora-600">
                        →
                    </span>

                </div>

                <h3 class="mt-5 font-bold">
                    Data Categories
                </h3>

                <p class="mt-1 text-sm leading-6 text-slate-500">
                    Organize plans into Monthly, SME, Router,
                    Everyday and other categories.
                </p>

            </a>



            <!-- PROVIDER TRANSACTIONS -->

            <a
                href="provider_transactions.php"
                class="group rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-servora-200 hover:shadow-md"
            >

                <div class="flex items-start justify-between">

                    <div
                        class="flex h-11 w-11 items-center justify-center rounded-xl bg-rose-50 text-rose-600"
                    >
                        🔄
                    </div>

                    <span class="text-slate-300 group-hover:text-servora-600">
                        →
                    </span>

                </div>

                <h3 class="mt-5 font-bold">
                    Provider Transactions
                </h3>

                <p class="mt-1 text-sm leading-6 text-slate-500">
                    Inspect supplier requests, HTTP responses,
                    references and transaction states.
                </p>

            </a>

        </div>

    </section>



    <!-- RECENT ORDERS -->

    <section class="mt-8">

        <div class="mb-4 flex items-end justify-between gap-4">

            <div>

                <p class="text-xs font-bold uppercase tracking-widest text-servora-600">
                    Recent Activity
                </p>

                <h2 class="mt-1 text-xl font-black">
                    Latest data orders
                </h2>

            </div>


            <a
                href="data_orders.php"
                class="text-sm font-bold text-servora-600 hover:text-servora-800"
            >
                View all →
            </a>

        </div>


        <div
            class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
        >

            <?php if (!$recentOrders): ?>

                <div class="p-8 text-center">

                    <p class="font-bold text-slate-700">
                        No data orders yet
                    </p>

                    <p class="mt-1 text-sm text-slate-500">
                        Customer data purchases will appear here.
                    </p>

                </div>

            <?php else: ?>

                <div class="overflow-x-auto">

                    <table class="min-w-full text-left text-sm">

                        <thead class="bg-slate-50">

                            <tr>

                                <th class="px-5 py-4 font-bold">
                                    Order
                                </th>

                                <th class="px-5 py-4 font-bold">
                                    Client
                                </th>

                                <th class="px-5 py-4 font-bold">
                                    Data
                                </th>

                                <th class="px-5 py-4 font-bold">
                                    Amount
                                </th>

                                <th class="px-5 py-4 font-bold">
                                    Status
                                </th>

                                <th class="px-5 py-4 font-bold">
                                    Date
                                </th>

                            </tr>

                        </thead>


                        <tbody class="divide-y divide-slate-100">

                        <?php foreach ($recentOrders as $order): ?>

                            <?php

                            $status = $order["status"];

                            $statusClass = match ($status) {

                                "successful" =>
                                    "bg-emerald-50 text-emerald-700",

                                "failed" =>
                                    "bg-red-50 text-red-700",

                                "refunded" =>
                                    "bg-purple-50 text-purple-700",

                                "processing" =>
                                    "bg-blue-50 text-blue-700",

                                default =>
                                    "bg-amber-50 text-amber-700"
                            };

                            ?>

                            <tr>

                                <td class="px-5 py-4">

                                    <p class="font-bold">
                                        <?= htmlspecialchars($order["order_reference"]) ?>
                                    </p>

                                    <p class="mt-1 text-xs text-slate-400">
                                        <?= htmlspecialchars($order["phone_number"]) ?>
                                    </p>

                                </td>


                                <td class="px-5 py-4 font-semibold">

                                    <?= htmlspecialchars($order["full_name"]) ?>

                                </td>


                                <td class="px-5 py-4">

                                    <p class="font-semibold">
                                        <?= htmlspecialchars($order["network_name"]) ?>
                                        —
                                        <?= htmlspecialchars($order["data_amount"]) ?>
                                    </p>

                                    <p class="mt-1 text-xs text-slate-400">
                                        <?= htmlspecialchars($order["plan_name"]) ?>
                                    </p>

                                </td>


                                <td class="px-5 py-4 font-bold">

                                    ₦<?= number_format((float) $order["selling_price"], 2) ?>

                                </td>


                                <td class="px-5 py-4">

                                    <span
                                        class="inline-flex rounded-full px-3 py-1 text-xs font-bold <?= $statusClass ?>"
                                    >
                                        <?= htmlspecialchars(ucfirst($status)) ?>
                                    </span>

                                </td>


                                <td class="px-5 py-4 text-slate-500">

                                    <?= date(
                                        "d M Y, h:i A",
                                        strtotime($order["created_at"])
                                    ) ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        </div>

    </section>



    <!-- BACK -->

    <section class="mt-8 border-t border-slate-200 pt-6">

        <a
            href="dashboard.php"
            class="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50"
        >
            ← Back to Admin Dashboard
        </a>

    </section>


    <footer class="py-8 text-center text-xs text-slate-400">
        Subnext Data Control Center
    </footer>

</main>

</body>

</html>