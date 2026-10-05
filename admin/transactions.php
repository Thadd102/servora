<?php

// ============================================================
// ADMIN TRANSACTIONS PAGE
// Shows all wallet financial transactions in a clear format.
// Admin can filter by type/status and search for a client.
// ============================================================

require_once "../config/database.php";
require_once "../includes/admin_auth.php";

// ------------------------------------------------------------
// FILTER VALUES
// ------------------------------------------------------------

$type = $_GET["type"] ?? "";

$status = $_GET["status"] ?? "";

$search = trim($_GET["search"] ?? "");

// ------------------------------------------------------------
// ALLOWED FILTER VALUES
// ------------------------------------------------------------

$allowedTypes = [
    "funding",
    "service_payment",
    "refund",
    "adjustment"
];

$allowedStatuses = [
    "pending",
    "successful",
    "failed",
    "reversed"
];

// Prevent invalid values from being used

if (!in_array($type, $allowedTypes, true)) {
    $type = "";
}

if (!in_array($status, $allowedStatuses, true)) {
    $status = "";
}

// ------------------------------------------------------------
// BUILD QUERY
// ------------------------------------------------------------

$sql = "
    SELECT
        wt.id,
        wt.user_id,
        wt.type,
        wt.amount,
        wt.balance_before,
        wt.balance_after,
        wt.reference,
        wt.description,
        wt.status,
        wt.created_at,

        u.full_name,
        u.email

    FROM wallet_transactions wt

    INNER JOIN users u
        ON u.id = wt.user_id

    WHERE 1 = 1
";

$params = [];

// ------------------------------------------------------------
// TYPE FILTER
// ------------------------------------------------------------

if ($type !== "") {

    $sql .= " AND wt.type = ?";

    $params[] = $type;
}

// ------------------------------------------------------------
// STATUS FILTER
// ------------------------------------------------------------

if ($status !== "") {

    $sql .= " AND wt.status = ?";

    $params[] = $status;
}

// ------------------------------------------------------------
// SEARCH FILTER
// ------------------------------------------------------------

if ($search !== "") {

    $sql .= "
        AND (
            u.full_name LIKE ?
            OR u.email LIKE ?
            OR wt.reference LIKE ?
            OR wt.description LIKE ?
        )
    ";

    $searchValue = "%" . $search . "%";

    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
}

// ------------------------------------------------------------
// ORDER
// ------------------------------------------------------------

$sql .= "
    ORDER BY wt.created_at DESC
";

// ------------------------------------------------------------
// EXECUTE QUERY
// ------------------------------------------------------------

$stmt = $pdo->prepare($sql);

$stmt->execute($params);

$transactions = $stmt->fetchAll();

// ------------------------------------------------------------
// FINANCIAL SUMMARY
// Only successful transactions are counted.
// ------------------------------------------------------------

$summaryStmt = $pdo->query("
    SELECT

        COALESCE(
            SUM(
                CASE
                    WHEN type = 'funding'
                    AND status = 'successful'
                    THEN amount
                    ELSE 0
                END
            ),
            0
        ) AS total_funding,

        COALESCE(
            SUM(
                CASE
                    WHEN type = 'service_payment'
                    AND status = 'successful'
                    THEN amount
                    ELSE 0
                END
            ),
            0
        ) AS total_service_payment,

        COALESCE(
            SUM(
                CASE
                    WHEN type = 'refund'
                    AND status = 'successful'
                    THEN amount
                    ELSE 0
                END
            ),
            0
        ) AS total_refund

    FROM wallet_transactions
");

$summary = $summaryStmt->fetch();

// ------------------------------------------------------------
// HELPER FUNCTIONS
// ------------------------------------------------------------

function transactionTypeLabel(string $type): string
{
    return match ($type) {

        "funding" => "Wallet Funding",

        "service_payment" => "Service Payment",

        "refund" => "Refund",

        "adjustment" => "Adjustment",

        default => ucfirst(str_replace("_", " ", $type))
    };
}


function statusLabel(string $status): string
{
    return ucfirst(str_replace("_", " ", $status));
}


function transactionTypeClass(string $type): string
{
    return match ($type) {

        "funding" => "bg-emerald-50 text-emerald-700 border-emerald-200",

        "service_payment" => "bg-amber-50 text-amber-700 border-amber-200",

        "refund" => "bg-indigo-50 text-indigo-700 border-indigo-200",

        "adjustment" => "bg-slate-100 text-slate-600 border-slate-200",

        default => "bg-slate-100 text-slate-600 border-slate-200"
    };
}


function transactionStatusClass(string $status): string
{
    return match ($status) {

        "successful" =>
            "bg-emerald-50 text-emerald-700 border-emerald-200",

        "pending" =>
            "bg-amber-50 text-amber-700 border-amber-200",

        "failed",
        "reversed" =>
            "bg-red-50 text-red-700 border-red-200",

        default =>
            "bg-slate-100 text-slate-600 border-slate-200"
    };
}


function transactionAmountClass(string $type): string
{
    return match ($type) {

        "funding",
        "refund",
        "adjustment" =>
            "text-emerald-600",

        "service_payment" =>
            "text-slate-800",

        default =>
            "text-slate-800"
    };
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

    <title>Transactions | Subnext Admin</title>


    <!-- Tailwind -->

    <!-- Precompiled Production Stylesheet -->
    <link rel="stylesheet" href="../assets/css/style.css">

</head>


<body class="bg-slate-50 text-slate-800">


<div class="min-h-screen">


    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8">


        <!-- =====================================================
             PAGE HEADER
        ====================================================== -->

        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between mb-7">


            <div>

                <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-servora-50 border border-servora-100 text-servora-700 text-xs font-semibold mb-3">

                    <span class="w-2 h-2 rounded-full bg-servora-600"></span>

                    Financial Management

                </div>


                <h1 class="text-2xl sm:text-3xl font-bold tracking-tight text-slate-900">

                    Financial Transactions

                </h1>


                <p class="text-sm text-slate-500 mt-1">

                    Monitor wallet funding, service payments and refunds.

                </p>

            </div>


            <a
                href="dashboard.php"
                class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl border border-slate-200 bg-white text-sm font-semibold text-slate-700 hover:bg-slate-50 transition"
            >

                <svg
                    class="w-4 h-4"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                >

                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M15 19l-7-7 7-7"
                    />

                </svg>

                Admin Dashboard

            </a>

        </div>


        <!-- =====================================================
             FINANCIAL SUMMARY
        ====================================================== -->

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">


            <!-- Wallet Funding -->

            <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">

                <div class="flex items-start justify-between gap-4">

                    <div>

                        <p class="text-sm font-medium text-slate-500">

                            Total Wallet Funding

                        </p>


                        <p class="text-2xl sm:text-3xl font-bold text-slate-900 mt-2">

                            ₦<?= number_format(
                                (float) $summary["total_funding"],
                                2
                            ) ?>

                        </p>


                        <p class="text-xs text-slate-400 mt-2">

                            Successful funding transactions

                        </p>

                    </div>


                    <div class="w-11 h-11 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">

                        <svg
                            class="w-5 h-5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >

                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="1.8"
                                d="M12 4v16m8-8H4"
                            />

                        </svg>

                    </div>

                </div>

            </div>


            <!-- Service Payments -->

            <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">

                <div class="flex items-start justify-between gap-4">

                    <div>

                        <p class="text-sm font-medium text-slate-500">

                            Total Service Payments

                        </p>


                        <p class="text-2xl sm:text-3xl font-bold text-slate-900 mt-2">

                            ₦<?= number_format(
                                (float) $summary["total_service_payment"],
                                2
                            ) ?>

                        </p>


                        <p class="text-xs text-slate-400 mt-2">

                            Successful service payments

                        </p>

                    </div>


                    <div class="w-11 h-11 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">

                        <svg
                            class="w-5 h-5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >

                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="1.8"
                                d="M12 1v22M17 5H9.5a3.5 3.5 0 000 7H14a3.5 3.5 0 010 7H6"
                            />

                        </svg>

                    </div>

                </div>

            </div>


            <!-- Refunds -->

            <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">

                <div class="flex items-start justify-between gap-4">

                    <div>

                        <p class="text-sm font-medium text-slate-500">

                            Total Refunds

                        </p>


                        <p class="text-2xl sm:text-3xl font-bold text-slate-900 mt-2">

                            ₦<?= number_format(
                                (float) $summary["total_refund"],
                                2
                            ) ?>

                        </p>


                        <p class="text-xs text-slate-400 mt-2">

                            Successful refunds issued

                        </p>

                    </div>


                    <div class="w-11 h-11 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">

                        <svg
                            class="w-5 h-5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >

                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="1.8"
                                d="M9 14l-3-3 3-3M6 11h8a4 4 0 014 4v1M15 10l3 3-3 3M18 13h-8a4 4 0 00-4-4V8"
                            />

                        </svg>

                    </div>

                </div>

            </div>

        </div>


        <!-- =====================================================
             FILTERS
        ====================================================== -->

        <section class="bg-white border border-slate-200 rounded-3xl shadow-sm p-5 sm:p-6 mb-6">


            <div class="mb-5">

                <h2 class="text-lg font-bold text-slate-900">

                    Transaction Filters

                </h2>


                <p class="text-sm text-slate-500 mt-1">

                    Narrow down transactions by type, status or client information.

                </p>

            </div>


            <form
                method="GET"
                class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4"
            >


                <!-- Type -->

                <div>

                    <label
                        for="type"
                        class="block text-sm font-semibold text-slate-700 mb-2"
                    >

                        Transaction Type

                    </label>


                    <select
                        id="type"
                        name="type"
                        class="w-full h-11 rounded-xl border border-slate-200 bg-white px-3.5 text-sm text-slate-700 outline-none focus:border-servora-500 focus:ring-4 focus:ring-servora-100 transition"
                    >

                        <option value="">
                            All Types
                        </option>


                        <?php foreach ($allowedTypes as $transactionType): ?>

                            <option
                                value="<?= htmlspecialchars($transactionType) ?>"
                                <?= $type === $transactionType ? "selected" : "" ?>
                            >

                                <?= htmlspecialchars(
                                    transactionTypeLabel($transactionType)
                                ) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <!-- Status -->

                <div>

                    <label
                        for="status"
                        class="block text-sm font-semibold text-slate-700 mb-2"
                    >

                        Status

                    </label>


                    <select
                        id="status"
                        name="status"
                        class="w-full h-11 rounded-xl border border-slate-200 bg-white px-3.5 text-sm text-slate-700 outline-none focus:border-servora-500 focus:ring-4 focus:ring-servora-100 transition"
                    >

                        <option value="">
                            All Statuses
                        </option>


                        <?php foreach ($allowedStatuses as $transactionStatus): ?>

                            <option
                                value="<?= htmlspecialchars($transactionStatus) ?>"
                                <?= $status === $transactionStatus ? "selected" : "" ?>
                            >

                                <?= htmlspecialchars(
                                    statusLabel($transactionStatus)
                                ) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <!-- Search -->

                <div>

                    <label
                        for="search"
                        class="block text-sm font-semibold text-slate-700 mb-2"
                    >

                        Search

                    </label>


                    <div class="relative">

                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">

                            <svg
                                class="w-4 h-4 text-slate-400"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >

                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M21 21l-4.3-4.3m2.3-5.2a7.5 7.5 0 11-15 0 7.5 7.5 0 0115 0z"
                                />

                            </svg>

                        </div>


                        <input
                            id="search"
                            type="text"
                            name="search"
                            placeholder="Client, email or reference..."
                            value="<?= htmlspecialchars($search) ?>"
                            class="w-full h-11 rounded-xl border border-slate-200 bg-white pl-10 pr-3.5 text-sm text-slate-700 placeholder:text-slate-400 outline-none focus:border-servora-500 focus:ring-4 focus:ring-servora-100 transition"
                        >

                    </div>

                </div>


                <!-- Buttons -->

                <div class="flex items-end gap-2">

                    <button
                        type="submit"
                        class="flex-1 h-11 inline-flex items-center justify-center gap-2 rounded-xl bg-servora-700 text-white text-sm font-semibold hover:bg-servora-800 transition shadow-sm"
                    >

                        <svg
                            class="w-4 h-4"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >

                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M3 4h18M6 8h12M10 12h4M11 16h2M12 20v-4"
                            />

                        </svg>

                        Apply Filters

                    </button>


                    <a
                        href="transactions.php"
                        class="h-11 px-4 inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white text-sm font-semibold text-slate-600 hover:bg-slate-50 transition"
                    >

                        Clear

                    </a>

                </div>

            </form>

        </section>


        <!-- =====================================================
             TRANSACTIONS
        ====================================================== -->

        <section class="bg-white border border-slate-200 rounded-3xl shadow-sm overflow-hidden">


            <!-- Section header -->

            <div class="px-5 sm:px-6 py-5 border-b border-slate-100">

                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">

                    <div>

                        <h2 class="text-lg font-bold text-slate-900">

                            Transaction History

                        </h2>


                        <p class="text-sm text-slate-500 mt-1">

                            <?= count($transactions) ?>
                            transaction<?= count($transactions) === 1 ? '' : 's' ?>
                            found.

                        </p>

                    </div>


                    <div class="text-xs font-medium text-slate-400">

                        Latest transactions first

                    </div>

                </div>

            </div>


            <?php if (empty($transactions)): ?>


                <!-- Empty state -->

                <div class="px-6 py-14 text-center">


                    <div class="w-16 h-16 mx-auto rounded-2xl bg-slate-100 flex items-center justify-center">

                        <svg
                            class="w-7 h-7 text-slate-400"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >

                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="1.8"
                                d="M3 7h18M5 7V5a2 2 0 012-2h10a2 2 0 012 2v2M5 7h14a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V9a2 2 0 012-2z"
                            />

                        </svg>

                    </div>


                    <h3 class="text-base font-semibold text-slate-800 mt-4">

                        No transactions found

                    </h3>


                    <p class="text-sm text-slate-500 mt-1 max-w-sm mx-auto">

                        Try changing your filters or search terms to find other transactions.

                    </p>


                    <a
                        href="transactions.php"
                        class="inline-flex items-center justify-center mt-5 px-4 py-2.5 rounded-xl bg-servora-700 text-white text-sm font-semibold hover:bg-servora-800 transition"
                    >

                        View All Transactions

                    </a>

                </div>


            <?php else: ?>


                <!-- =================================================
                     MOBILE TRANSACTION CARDS
                ================================================== -->

                <div class="lg:hidden divide-y divide-slate-100">


                    <?php foreach ($transactions as $transaction): ?>


                        <div class="p-5">


                            <!-- Top row -->

                            <div class="flex items-start justify-between gap-4">


                                <div class="min-w-0">


                                    <div class="flex items-center gap-2 flex-wrap">

                                        <span class="inline-flex px-2.5 py-1 rounded-full border text-xs font-semibold <?= transactionTypeClass($transaction["type"]) ?>">

                                            <?= htmlspecialchars(
                                                transactionTypeLabel(
                                                    $transaction["type"]
                                                )
                                            ) ?>

                                        </span>


                                        <span class="inline-flex px-2.5 py-1 rounded-full border text-xs font-semibold <?= transactionStatusClass($transaction["status"]) ?>">

                                            <?= htmlspecialchars(
                                                statusLabel(
                                                    $transaction["status"]
                                                )
                                            ) ?>

                                        </span>

                                    </div>


                                    <p class="font-semibold text-slate-900 mt-3">

                                        <?= htmlspecialchars(
                                            $transaction["full_name"]
                                        ) ?>

                                    </p>


                                    <p class="text-xs text-slate-400 mt-1 break-all">

                                        <?= htmlspecialchars(
                                            $transaction["email"]
                                        ) ?>

                                    </p>

                                </div>


                                <div class="text-right shrink-0">

                                    <p class="text-base font-bold <?= transactionAmountClass($transaction["type"]) ?>">

                                        ₦<?= number_format(
                                            (float) $transaction["amount"],
                                            2
                                        ) ?>

                                    </p>

                                    <p class="text-xs text-slate-400 mt-1">

                                        <?= htmlspecialchars(
                                            $transaction["created_at"]
                                        ) ?>

                                    </p>

                                </div>

                            </div>


                            <!-- Balance -->

                            <div class="grid grid-cols-2 gap-3 mt-4">


                                <div class="bg-slate-50 rounded-xl p-3">

                                    <p class="text-xs text-slate-400">

                                        Balance Before

                                    </p>


                                    <p class="text-sm font-semibold text-slate-700 mt-1">

                                        ₦<?= number_format(
                                            (float) $transaction["balance_before"],
                                            2
                                        ) ?>

                                    </p>

                                </div>


                                <div class="bg-slate-50 rounded-xl p-3">

                                    <p class="text-xs text-slate-400">

                                        Balance After

                                    </p>


                                    <p class="text-sm font-semibold text-slate-800 mt-1">

                                        ₦<?= number_format(
                                            (float) $transaction["balance_after"],
                                            2
                                        ) ?>

                                    </p>

                                </div>

                            </div>


                            <!-- Reference -->

                            <div class="mt-4">

                                <p class="text-xs font-medium text-slate-400">
                                    Reference
                                </p>


                                <p class="text-xs font-mono text-slate-600 break-all mt-1">

                                    <?= htmlspecialchars(
                                        $transaction["reference"] ?? "—"
                                    ) ?>

                                </p>

                            </div>


                            <!-- Description -->

                            <?php if (!empty($transaction["description"])): ?>

                                <div class="mt-3">

                                    <p class="text-xs font-medium text-slate-400">
                                        Description
                                    </p>


                                    <p class="text-sm text-slate-600 mt-1 leading-5">

                                        <?= htmlspecialchars(
                                            $transaction["description"]
                                        ) ?>

                                    </p>

                                </div>

                            <?php endif; ?>

                        </div>


                    <?php endforeach; ?>

                </div>


                <!-- =================================================
                     DESKTOP TABLE
                ================================================== -->

                <div class="hidden lg:block overflow-x-auto">


                    <table class="w-full">


                        <thead>

                        <tr class="bg-slate-50 border-b border-slate-100">


                            <th class="text-left px-6 py-4 text-xs font-semibold uppercase tracking-wide text-slate-400">

                                Client

                            </th>


                            <th class="text-left px-6 py-4 text-xs font-semibold uppercase tracking-wide text-slate-400">

                                Transaction

                            </th>


                            <th class="text-left px-6 py-4 text-xs font-semibold uppercase tracking-wide text-slate-400">

                                Amount

                            </th>


                            <th class="text-left px-6 py-4 text-xs font-semibold uppercase tracking-wide text-slate-400">

                                Before

                            </th>


                            <th class="text-left px-6 py-4 text-xs font-semibold uppercase tracking-wide text-slate-400">

                                After

                            </th>


                            <th class="text-left px-6 py-4 text-xs font-semibold uppercase tracking-wide text-slate-400">

                                Reference

                            </th>


                            <th class="text-left px-6 py-4 text-xs font-semibold uppercase tracking-wide text-slate-400">

                                Description

                            </th>


                            <th class="text-left px-6 py-4 text-xs font-semibold uppercase tracking-wide text-slate-400">

                                Status

                            </th>


                            <th class="text-left px-6 py-4 text-xs font-semibold uppercase tracking-wide text-slate-400">

                                Date

                            </th>


                        </tr>

                        </thead>


                        <tbody class="divide-y divide-slate-100">


                        <?php foreach ($transactions as $transaction): ?>


                            <tr class="hover:bg-slate-50/70 transition">


                                <!-- Client -->

                                <td class="px-6 py-4">


                                    <p class="text-sm font-semibold text-slate-800">

                                        <?= htmlspecialchars(
                                            $transaction["full_name"]
                                        ) ?>

                                    </p>


                                    <p class="text-xs text-slate-400 mt-1">

                                        <?= htmlspecialchars(
                                            $transaction["email"]
                                        ) ?>

                                    </p>


                                </td>


                                <!-- Transaction -->

                                <td class="px-6 py-4">


                                    <span class="inline-flex px-2.5 py-1 rounded-full border text-xs font-semibold <?= transactionTypeClass($transaction["type"]) ?>">

                                        <?= htmlspecialchars(
                                            transactionTypeLabel(
                                                $transaction["type"]
                                            )
                                        ) ?>

                                    </span>


                                </td>


                                <!-- Amount -->

                                <td class="px-6 py-4">

                                    <span class="text-sm font-bold <?= transactionAmountClass($transaction["type"]) ?>">

                                        ₦<?= number_format(
                                            (float) $transaction["amount"],
                                            2
                                        ) ?>

                                    </span>

                                </td>


                                <!-- Before -->

                                <td class="px-6 py-4 text-sm text-slate-600 whitespace-nowrap">

                                    ₦<?= number_format(
                                        (float) $transaction["balance_before"],
                                        2
                                    ) ?>

                                </td>


                                <!-- After -->

                                <td class="px-6 py-4 text-sm font-semibold text-slate-800 whitespace-nowrap">

                                    ₦<?= number_format(
                                        (float) $transaction["balance_after"],
                                        2
                                    ) ?>

                                </td>


                                <!-- Reference -->

                                <td class="px-6 py-4">

                                    <span class="text-xs font-mono text-slate-500 break-all">

                                        <?= htmlspecialchars(
                                            $transaction["reference"] ?? "—"
                                        ) ?>

                                    </span>

                                </td>


                                <!-- Description -->

                                <td class="px-6 py-4 max-w-xs">

                                    <p class="text-sm text-slate-600">

                                        <?= htmlspecialchars(
                                            $transaction["description"] ?? "—"
                                        ) ?>

                                    </p>

                                </td>


                                <!-- Status -->

                                <td class="px-6 py-4">

                                    <span class="inline-flex px-2.5 py-1 rounded-full border text-xs font-semibold <?= transactionStatusClass($transaction["status"]) ?>">

                                        <?= htmlspecialchars(
                                            statusLabel(
                                                $transaction["status"]
                                            )
                                        ) ?>

                                    </span>

                                </td>


                                <!-- Date -->

                                <td class="px-6 py-4 text-sm text-slate-500 whitespace-nowrap">

                                    <?= htmlspecialchars(
                                        $transaction["created_at"]
                                    ) ?>

                                </td>


                            </tr>


                        <?php endforeach; ?>


                        </tbody>

                    </table>

                </div>


            <?php endif; ?>


        </section>


        <!-- Footer -->

        <div class="text-center py-8">

            <p class="text-xs text-slate-400">

                Subnext Admin Panel

            </p>

        </div>


    </main>

</div>


</body>

</html>