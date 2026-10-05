<?php

require_once "../config/database.php";
require_once "../includes/admin_auth.php";

// --------------------------------------------------
// Get client ID
// --------------------------------------------------

$clientId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$clientId) {
    die("Invalid client ID.");
}

// --------------------------------------------------
// Get client information
// --------------------------------------------------

$stmt = $pdo->prepare("
    SELECT
        id,
        full_name,
        email,
        phone,
        status,
        created_at
    FROM users
    WHERE id = ?
      AND role = 'client'
    LIMIT 1
");

$stmt->execute([$clientId]);

$client = $stmt->fetch();

if (!$client) {
    die("Client not found.");
}

// --------------------------------------------------
// Get wallet
// --------------------------------------------------

$stmt = $pdo->prepare("
    SELECT balance
    FROM wallets
    WHERE user_id = ?
    LIMIT 1
");

$stmt->execute([$clientId]);

$wallet = $stmt->fetch();

$balance = $wallet ? (float) $wallet['balance'] : 0;

// --------------------------------------------------
// Get request statistics
// --------------------------------------------------

$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_requests,
        SUM(status = 'processing') AS processing_requests,
        SUM(status = 'completed') AS completed_requests,
        SUM(status = 'cancelled') AS cancelled_requests
    FROM service_requests
    WHERE user_id = ?
");

$stmt->execute([$clientId]);

$requestStats = $stmt->fetch();

// --------------------------------------------------
// Get recent requests
// --------------------------------------------------

$stmt = $pdo->prepare("
    SELECT
        sr.id,
        sr.request_code,
        sr.amount,
        sr.status,
        sr.submitted_at,
        s.name AS service_name
    FROM service_requests sr
    INNER JOIN services s
        ON s.id = sr.service_id
    WHERE sr.user_id = ?
    ORDER BY sr.submitted_at DESC
    LIMIT 10
");

$stmt->execute([$clientId]);

$requests = $stmt->fetchAll();

// --------------------------------------------------
// Get recent wallet transactions
// --------------------------------------------------

$stmt = $pdo->prepare("
    SELECT
        id,
        type,
        amount,
        balance_before,
        balance_after,
        reference,
        description,
        status,
        created_at
    FROM wallet_transactions
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 10
");

$stmt->execute([$clientId]);

$transactions = $stmt->fetchAll();

// --------------------------------------------------
// Get notifications
// --------------------------------------------------

$stmt = $pdo->prepare("
    SELECT
        id,
        title,
        message,
        is_read,
        created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 10
");

$stmt->execute([$clientId]);

$notifications = $stmt->fetchAll();


// --------------------------------------------------
// Helper functions
// --------------------------------------------------

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function statusClasses(string $status): string
{
    return match ($status) {

        'active',
        'completed',
        'successful' =>
            'bg-emerald-50 text-emerald-700 border-emerald-200',

        'processing',
        'pending' =>
            'bg-amber-50 text-amber-700 border-amber-200',

        'cancelled',
        'failed',
        'reversed' =>
            'bg-red-50 text-red-700 border-red-200',

        'submitted',
        'payment_confirmed' =>
            'bg-blue-50 text-blue-700 border-blue-200',

        'under_review' =>
            'bg-orange-50 text-orange-700 border-orange-200',

        'awaiting_information' =>
            'bg-purple-50 text-purple-700 border-purple-200',

        'ready_for_download' =>
            'bg-indigo-50 text-indigo-700 border-indigo-200',

        'suspended',
        'inactive' =>
            'bg-slate-100 text-slate-600 border-slate-200',

        default =>
            'bg-slate-100 text-slate-600 border-slate-200'
    };
}

function readableStatus(string $status): string
{
    return ucwords(str_replace('_', ' ', $status));
}

function transactionAmountClass(string $type): string
{
    return in_array(
        $type,
        ['funding', 'refund', 'adjustment'],
        true
    )
        ? 'text-emerald-600'
        : 'text-slate-800';
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

    <title>
        Client Profile - <?= e($client['full_name']) ?>
    </title>

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


<body class="bg-slate-50 text-slate-800">

<div class="min-h-screen">

    <!-- Page container -->

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8">

        <!-- Header -->

        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between mb-7">

            <div>

                <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-servora-50 border border-servora-100 text-servora-700 text-xs font-semibold mb-3">

                    <span class="w-2 h-2 rounded-full bg-servora-600"></span>

                    Client Management

                </div>

                <h1 class="text-2xl sm:text-3xl font-bold tracking-tight text-slate-900">
                    Client Profile
                </h1>

                <p class="text-sm text-slate-500 mt-1">
                    Review this client's account, wallet activity and service requests.
                </p>

            </div>


            <a
                href="clients.php"
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

                Back to Clients

            </a>

        </div>


        <!-- Client profile -->

        <section class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden mb-6">

            <!-- Profile banner -->

            <div class="h-28 sm:h-36 bg-gradient-to-r from-servora-800 via-servora-700 to-servora-500"></div>


            <div class="px-5 sm:px-7 pb-6">

                <div class="flex flex-col sm:flex-row sm:items-end gap-4 -mt-10 sm:-mt-12">

                    <!-- Avatar -->

                    <div class="w-20 h-20 sm:w-24 sm:h-24 rounded-2xl bg-white p-1.5 shadow-lg">

                        <div class="w-full h-full rounded-xl bg-servora-50 flex items-center justify-center text-servora-700 text-2xl sm:text-3xl font-bold">

                            <?= e(strtoupper(substr($client['full_name'], 0, 1))) ?>

                        </div>

                    </div>


                    <div class="flex-1 min-w-0">

                        <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-3">

                            <h2 class="text-xl sm:text-2xl font-bold text-slate-900 break-words">
                                <?= e($client['full_name']) ?>
                            </h2>

                            <span class="inline-flex w-fit items-center px-2.5 py-1 rounded-full border text-xs font-semibold <?= statusClasses($client['status']) ?>">

                                <?= e(ucfirst($client['status'])) ?>

                            </span>

                        </div>

                        <p class="text-sm text-slate-500 mt-1">
                            Client ID #<?= (int) $client['id'] ?>
                        </p>

                    </div>

                </div>


                <!-- Client details -->

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 mt-6">

                    <div class="rounded-2xl bg-slate-50 border border-slate-100 p-4">

                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">
                            Email
                        </p>

                        <p class="mt-1 text-sm font-medium text-slate-800 break-all">
                            <?= e($client['email']) ?>
                        </p>

                    </div>


                    <div class="rounded-2xl bg-slate-50 border border-slate-100 p-4">

                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">
                            Phone
                        </p>

                        <p class="mt-1 text-sm font-medium text-slate-800">
                            <?= e($client['phone'] ?: 'Not provided') ?>
                        </p>

                    </div>


                    <div class="rounded-2xl bg-slate-50 border border-slate-100 p-4">

                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">
                            Registered
                        </p>

                        <p class="mt-1 text-sm font-medium text-slate-800">
                            <?= e($client['created_at']) ?>
                        </p>

                    </div>

                </div>

            </div>

        </section>


        <!-- Wallet -->

        <section class="mb-6">

            <div class="bg-gradient-to-br from-servora-900 via-servora-800 to-servora-600 rounded-3xl p-6 sm:p-8 text-white shadow-lg relative overflow-hidden">

                <div class="absolute -right-16 -top-16 w-40 h-40 bg-white/10 rounded-full"></div>

                <div class="absolute -right-6 -bottom-20 w-48 h-48 bg-white/5 rounded-full"></div>


                <div class="relative">

                    <div class="flex items-center justify-between gap-4">

                        <div>

                            <p class="text-sm text-indigo-100">
                                Current Wallet Balance
                            </p>

                            <p class="text-3xl sm:text-4xl font-bold mt-2">
                                ₦<?= number_format($balance, 2) ?>
                            </p>

                        </div>


                        <div class="w-12 h-12 rounded-2xl bg-white/10 flex items-center justify-center">

                            <svg
                                class="w-6 h-6"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="1.8"
                                    d="M3 7h18M5 7V5a2 2 0 012-2h10a2 2 0 012 2v2M5 7h14a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V9a2 2 0 012-2zm11 6h.01"
                                />
                            </svg>

                        </div>

                    </div>

                    <p class="text-xs text-indigo-200 mt-5">
                        Available funds in the client's Servora wallet.
                    </p>

                </div>

            </div>

        </section>


        <!-- Statistics -->

        <section class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-6">

            <!-- Total -->

            <div class="bg-white border border-slate-200 rounded-2xl p-4 sm:p-5 shadow-sm">

                <div class="flex items-center justify-between">

                    <p class="text-xs sm:text-sm font-medium text-slate-500">
                        Total Requests
                    </p>

                    <div class="w-9 h-9 rounded-xl bg-servora-50 text-servora-700 flex items-center justify-center">

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
                                d="M9 12h6m-6 4h4M7 3h10a2 2 0 012 2v14a2 2 0 01-2 2H7a2 2 0 01-2-2V5a2 2 0 012-2z"
                            />
                        </svg>

                    </div>

                </div>

                <p class="text-2xl sm:text-3xl font-bold text-slate-900 mt-3">
                    <?= (int) ($requestStats['total_requests'] ?? 0) ?>
                </p>

            </div>


            <!-- Processing -->

            <div class="bg-white border border-slate-200 rounded-2xl p-4 sm:p-5 shadow-sm">

                <div class="flex items-center justify-between">

                    <p class="text-xs sm:text-sm font-medium text-slate-500">
                        Processing
                    </p>

                    <div class="w-9 h-9 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">

                        <svg
                            class="w-4 h-4"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-width="2"
                                d="M12 6v6l4 2"
                            />
                            <circle
                                cx="12"
                                cy="12"
                                r="9"
                                stroke-width="2"
                            />
                        </svg>

                    </div>

                </div>

                <p class="text-2xl sm:text-3xl font-bold text-slate-900 mt-3">
                    <?= (int) ($requestStats['processing_requests'] ?? 0) ?>
                </p>

            </div>


            <!-- Completed -->

            <div class="bg-white border border-slate-200 rounded-2xl p-4 sm:p-5 shadow-sm">

                <div class="flex items-center justify-between">

                    <p class="text-xs sm:text-sm font-medium text-slate-500">
                        Completed
                    </p>

                    <div class="w-9 h-9 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">

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
                                d="M5 13l4 4L19 7"
                            />
                        </svg>

                    </div>

                </div>

                <p class="text-2xl sm:text-3xl font-bold text-slate-900 mt-3">
                    <?= (int) ($requestStats['completed_requests'] ?? 0) ?>
                </p>

            </div>


            <!-- Cancelled -->

            <div class="bg-white border border-slate-200 rounded-2xl p-4 sm:p-5 shadow-sm">

                <div class="flex items-center justify-between">

                    <p class="text-xs sm:text-sm font-medium text-slate-500">
                        Cancelled
                    </p>

                    <div class="w-9 h-9 rounded-xl bg-red-50 text-red-600 flex items-center justify-center">

                        <svg
                            class="w-4 h-4"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-width="2"
                                d="M6 6l12 12M18 6L6 18"
                            />
                        </svg>

                    </div>

                </div>

                <p class="text-2xl sm:text-3xl font-bold text-slate-900 mt-3">
                    <?= (int) ($requestStats['cancelled_requests'] ?? 0) ?>
                </p>

            </div>

        </section>


        <!-- Client Requests -->

        <section class="bg-white border border-slate-200 rounded-3xl shadow-sm overflow-hidden mb-6">

            <div class="px-5 sm:px-6 py-5 border-b border-slate-100">

                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">

                    <div>

                        <h2 class="text-lg font-bold text-slate-900">
                            Client Requests
                        </h2>

                        <p class="text-sm text-slate-500 mt-1">
                            Recent services requested by this client.
                        </p>

                    </div>

                    <span class="text-xs font-semibold text-slate-400">
                        Last 10 requests
                    </span>

                </div>

            </div>


            <?php if (!$requests): ?>

                <div class="px-6 py-12 text-center">

                    <div class="w-14 h-14 mx-auto rounded-2xl bg-slate-100 flex items-center justify-center">

                        <svg
                            class="w-6 h-6 text-slate-400"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="1.8"
                                d="M9 12h6m-6 4h4M7 3h10a2 2 0 012 2v14a2 2 0 01-2 2H7a2 2 0 01-2-2V5a2 2 0 012-2z"
                            />
                        </svg>

                    </div>

                    <h3 class="font-semibold text-slate-800 mt-4">
                        No requests yet
                    </h3>

                    <p class="text-sm text-slate-500 mt-1">
                        This client has not submitted any service requests.
                    </p>

                </div>

            <?php else: ?>


                <!-- Mobile cards -->

                <div class="lg:hidden divide-y divide-slate-100">

                    <?php foreach ($requests as $request): ?>

                        <div class="p-5">

                            <div class="flex items-start justify-between gap-3">

                                <div class="min-w-0">

                                    <p class="font-semibold text-slate-900">
                                        <?= e($request['service_name']) ?>
                                    </p>

                                    <p class="text-xs text-slate-400 mt-1">
                                        <?= e($request['request_code']) ?>
                                    </p>

                                </div>

                                <span class="shrink-0 inline-flex px-2.5 py-1 rounded-full border text-xs font-semibold <?= statusClasses($request['status']) ?>">

                                    <?= e(readableStatus($request['status'])) ?>

                                </span>

                            </div>


                            <div class="grid grid-cols-2 gap-3 mt-4">

                                <div>

                                    <p class="text-xs text-slate-400">
                                        Amount
                                    </p>

                                    <p class="text-sm font-semibold text-slate-800 mt-1">
                                        ₦<?= number_format((float) $request['amount'], 2) ?>
                                    </p>

                                </div>


                                <div>

                                    <p class="text-xs text-slate-400">
                                        Submitted
                                    </p>

                                    <p class="text-sm font-medium text-slate-700 mt-1">
                                        <?= e($request['submitted_at']) ?>
                                    </p>

                                </div>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>


                <!-- Desktop table -->

                <div class="hidden lg:block overflow-x-auto">

                    <table class="w-full">

                        <thead>

                        <tr class="bg-slate-50 border-b border-slate-100">

                            <th class="text-left px-6 py-4 text-xs font-semibold uppercase tracking-wide text-slate-400">
                                Request
                            </th>

                            <th class="text-left px-6 py-4 text-xs font-semibold uppercase tracking-wide text-slate-400">
                                Service
                            </th>

                            <th class="text-left px-6 py-4 text-xs font-semibold uppercase tracking-wide text-slate-400">
                                Amount
                            </th>

                            <th class="text-left px-6 py-4 text-xs font-semibold uppercase tracking-wide text-slate-400">
                                Status
                            </th>

                            <th class="text-left px-6 py-4 text-xs font-semibold uppercase tracking-wide text-slate-400">
                                Submitted
                            </th>

                        </tr>

                        </thead>

                        <tbody class="divide-y divide-slate-100">

                        <?php foreach ($requests as $request): ?>

                            <tr class="hover:bg-slate-50/70 transition">

                                <td class="px-6 py-4">

                                    <span class="font-semibold text-sm text-slate-800">
                                        <?= e($request['request_code']) ?>
                                    </span>

                                </td>

                                <td class="px-6 py-4">

                                    <span class="text-sm text-slate-700">
                                        <?= e($request['service_name']) ?>
                                    </span>

                                </td>

                                <td class="px-6 py-4">

                                    <span class="text-sm font-semibold text-slate-800">
                                        ₦<?= number_format((float) $request['amount'], 2) ?>
                                    </span>

                                </td>

                                <td class="px-6 py-4">

                                    <span class="inline-flex px-2.5 py-1 rounded-full border text-xs font-semibold <?= statusClasses($request['status']) ?>">

                                        <?= e(readableStatus($request['status'])) ?>

                                    </span>

                                </td>

                                <td class="px-6 py-4 text-sm text-slate-500">

                                    <?= e($request['submitted_at']) ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        </section>


        <!-- Wallet Transactions -->

        <section class="bg-white border border-slate-200 rounded-3xl shadow-sm overflow-hidden mb-6">

            <div class="px-5 sm:px-6 py-5 border-b border-slate-100">

                <h2 class="text-lg font-bold text-slate-900">
                    Wallet Transactions
                </h2>

                <p class="text-sm text-slate-500 mt-1">
                    Recent wallet activity for this client.
                </p>

            </div>


            <?php if (!$transactions): ?>

                <div class="px-6 py-12 text-center">

                    <div class="w-14 h-14 mx-auto rounded-2xl bg-slate-100 flex items-center justify-center">

                        <svg
                            class="w-6 h-6 text-slate-400"
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

                    <h3 class="font-semibold text-slate-800 mt-4">
                        No wallet transactions
                    </h3>

                    <p class="text-sm text-slate-500 mt-1">
                        This client has no recorded wallet activity.
                    </p>

                </div>

            <?php else: ?>


                <!-- Mobile transactions -->

                <div class="lg:hidden divide-y divide-slate-100">

                    <?php foreach ($transactions as $transaction): ?>

                        <div class="p-5">

                            <div class="flex items-start justify-between gap-3">

                                <div>

                                    <p class="font-semibold text-slate-800">
                                        <?= e(ucwords(str_replace('_', ' ', $transaction['type']))) ?>
                                    </p>

                                    <p class="text-xs text-slate-400 mt-1">
                                        <?= e($transaction['created_at']) ?>
                                    </p>

                                </div>


                                <span class="text-base font-bold <?= transactionAmountClass($transaction['type']) ?>">

                                    ₦<?= number_format((float) $transaction['amount'], 2) ?>

                                </span>

                            </div>


                            <div class="grid grid-cols-2 gap-3 mt-4">

                                <div class="bg-slate-50 rounded-xl p-3">

                                    <p class="text-xs text-slate-400">
                                        Before
                                    </p>

                                    <p class="text-sm font-semibold text-slate-700 mt-1">
                                        ₦<?= number_format((float) $transaction['balance_before'], 2) ?>
                                    </p>

                                </div>


                                <div class="bg-slate-50 rounded-xl p-3">

                                    <p class="text-xs text-slate-400">
                                        After
                                    </p>

                                    <p class="text-sm font-semibold text-slate-700 mt-1">
                                        ₦<?= number_format((float) $transaction['balance_after'], 2) ?>
                                    </p>

                                </div>

                            </div>


                            <div class="flex items-center justify-between gap-3 mt-4">

                                <span class="text-xs text-slate-400 truncate">
                                    <?= e($transaction['reference'] ?? '-') ?>
                                </span>

                                <span class="shrink-0 inline-flex px-2.5 py-1 rounded-full border text-xs font-semibold <?= statusClasses($transaction['status']) ?>">

                                    <?= e(ucfirst($transaction['status'])) ?>

                                </span>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>


                <!-- Desktop transactions -->

                <div class="hidden lg:block overflow-x-auto">

                    <table class="w-full">

                        <thead>

                        <tr class="bg-slate-50 border-b border-slate-100">

                            <th class="text-left px-6 py-4 text-xs font-semibold uppercase tracking-wide text-slate-400">
                                Type
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

                                <td class="px-6 py-4 text-sm font-medium text-slate-700">

                                    <?= e(ucwords(str_replace('_', ' ', $transaction['type']))) ?>

                                </td>

                                <td class="px-6 py-4">

                                    <span class="text-sm font-bold <?= transactionAmountClass($transaction['type']) ?>">

                                        ₦<?= number_format((float) $transaction['amount'], 2) ?>

                                    </span>

                                </td>

                                <td class="px-6 py-4 text-sm text-slate-600">

                                    ₦<?= number_format((float) $transaction['balance_before'], 2) ?>

                                </td>

                                <td class="px-6 py-4 text-sm font-medium text-slate-800">

                                    ₦<?= number_format((float) $transaction['balance_after'], 2) ?>

                                </td>

                                <td class="px-6 py-4 text-xs text-slate-500">

                                    <?= e($transaction['reference'] ?? '-') ?>

                                </td>

                                <td class="px-6 py-4">

                                    <span class="inline-flex px-2.5 py-1 rounded-full border text-xs font-semibold <?= statusClasses($transaction['status']) ?>">

                                        <?= e(ucfirst($transaction['status'])) ?>

                                    </span>

                                </td>

                                <td class="px-6 py-4 text-sm text-slate-500">

                                    <?= e($transaction['created_at']) ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        </section>


        <!-- Notifications -->

        <section class="bg-white border border-slate-200 rounded-3xl shadow-sm overflow-hidden">

            <div class="px-5 sm:px-6 py-5 border-b border-slate-100">

                <div class="flex items-center justify-between gap-4">

                    <div>

                        <h2 class="text-lg font-bold text-slate-900">
                            Client Notifications
                        </h2>

                        <p class="text-sm text-slate-500 mt-1">
                            Recent messages and account updates.
                        </p>

                    </div>

                    <div class="hidden sm:flex w-10 h-10 rounded-xl bg-servora-50 text-servora-700 items-center justify-center">

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
                                d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 00-5-5.9V4a1 1 0 00-2 0v1.1A6 6 0 006 11v3.2a2 2 0 01-.6 1.4L4 17h5m6 0a3 3 0 01-6 0"
                            />
                        </svg>

                    </div>

                </div>

            </div>


            <?php if (!$notifications): ?>

                <div class="px-6 py-12 text-center">

                    <div class="w-14 h-14 mx-auto rounded-2xl bg-slate-100 flex items-center justify-center">

                        <svg
                            class="w-6 h-6 text-slate-400"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="1.8"
                                d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 00-5-5.9V4a1 1 0 00-2 0v1.1A6 6 0 006 11v3.2a2 2 0 01-.6 1.4L4 17h5m6 0a3 3 0 01-6 0"
                            />
                        </svg>

                    </div>

                    <h3 class="font-semibold text-slate-800 mt-4">
                        No notifications
                    </h3>

                    <p class="text-sm text-slate-500 mt-1">
                        This client has no recent notifications.
                    </p>

                </div>

            <?php else: ?>

                <div class="divide-y divide-slate-100">

                    <?php foreach ($notifications as $notification): ?>

                        <div class="p-5 sm:px-6 <?= !$notification['is_read'] ? 'bg-servora-50/60' : 'bg-white' ?>">

                            <div class="flex items-start gap-4">

                                <div class="shrink-0 w-10 h-10 rounded-xl <?= !$notification['is_read'] ? 'bg-servora-100 text-servora-700' : 'bg-slate-100 text-slate-500' ?> flex items-center justify-center">

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
                                            d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 00-5-5.9V4a1 1 0 00-2 0v1.1A6 6 0 006 11v3.2a2 2 0 01-.6 1.4L4 17h5m6 0a3 3 0 01-6 0"
                                        />
                                    </svg>

                                </div>


                                <div class="min-w-0 flex-1">

                                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">

                                        <div class="flex items-center gap-2">

                                            <h3 class="text-sm font-semibold <?= !$notification['is_read'] ? 'text-slate-900' : 'text-slate-700' ?>">

                                                <?= e($notification['title']) ?>

                                            </h3>


                                            <?php if (!$notification['is_read']): ?>

                                                <span class="px-2 py-0.5 rounded-full bg-servora-100 text-servora-700 text-[10px] font-bold uppercase tracking-wide">

                                                    Unread

                                                </span>

                                            <?php endif; ?>

                                        </div>


                                        <span class="text-xs text-slate-400">

                                            <?= e($notification['created_at']) ?>

                                        </span>

                                    </div>


                                    <p class="text-sm text-slate-600 leading-6 mt-2">

                                        <?= nl2br(e($notification['message'])) ?>

                                    </p>

                                </div>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        </section>


        <!-- Footer -->

        <div class="text-center py-8">

            <p class="text-xs text-slate-400">
                Servora Admin Panel
            </p>

        </div>

    </main>

</div>

</body>

</html>