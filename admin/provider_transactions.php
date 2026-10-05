<?php

require_once "../config/database.php";
require_once "../includes/admin_auth.php";


/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/

$status = trim($_GET["status"] ?? "");
$providerId = (int) ($_GET["provider_id"] ?? 0);
$action = trim($_GET["action"] ?? "");
$search = trim($_GET["search"] ?? "");

$allowedStatuses = [
    "pending",
    "successful",
    "failed",
    "unknown"
];

$allowedActions = [
    "purchase",
    "status_check"
];

if (
    $status !== "" &&
    !in_array($status, $allowedStatuses, true)
) {
    $status = "";
}

if (
    $action !== "" &&
    !in_array($action, $allowedActions, true)
) {
    $action = "";
}


/*
|--------------------------------------------------------------------------
| PROVIDERS FOR FILTER
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT
        id,
        name,
        slug
    FROM api_providers
    ORDER BY name ASC
");

$providers = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| STATISTICS
|--------------------------------------------------------------------------
*/

$statsStmt = $pdo->query("
    SELECT
        COUNT(*) AS total,

        SUM(
            CASE
                WHEN status = 'pending'
                THEN 1
                ELSE 0
            END
        ) AS pending,

        SUM(
            CASE
                WHEN status = 'successful'
                THEN 1
                ELSE 0
            END
        ) AS successful,

        SUM(
            CASE
                WHEN status = 'failed'
                THEN 1
                ELSE 0
            END
        ) AS failed,

        SUM(
            CASE
                WHEN status = 'unknown'
                THEN 1
                ELSE 0
            END
        ) AS unknown_transactions

    FROM data_provider_transactions
");

$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

$totalTransactions =
    (int) ($stats["total"] ?? 0);

$pendingTransactions =
    (int) ($stats["pending"] ?? 0);

$successfulTransactions =
    (int) ($stats["successful"] ?? 0);

$failedTransactions =
    (int) ($stats["failed"] ?? 0);

$unknownTransactions =
    (int) ($stats["unknown_transactions"] ?? 0);


/*
|--------------------------------------------------------------------------
| BUILD FILTERED QUERY
|--------------------------------------------------------------------------
*/

$where = [];
$params = [];


if ($status !== "") {

    $where[] = "dpt.status = ?";
    $params[] = $status;
}


if ($providerId > 0) {

    $where[] = "dpt.provider_id = ?";
    $params[] = $providerId;
}


if ($action !== "") {

    $where[] = "dpt.action = ?";
    $params[] = $action;
}


if ($search !== "") {

    $where[] = "
        (
            dpt.request_reference LIKE ?
            OR dpt.provider_reference LIKE ?
            OR dpt.response_code LIKE ?
            OR dpt.response_message LIKE ?
            OR do.order_reference LIKE ?
            OR do.phone_number LIKE ?
            OR u.full_name LIKE ?
            OR u.email LIKE ?
        )
    ";

    $searchValue = "%" . $search . "%";

    for ($i = 0; $i < 8; $i++) {
        $params[] = $searchValue;
    }
}


$whereSql = "";

if ($where) {
    $whereSql =
        "WHERE " .
        implode(" AND ", $where);
}


/*
|--------------------------------------------------------------------------
| GET PROVIDER TRANSACTIONS
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT

        dpt.id,
        dpt.order_id,
        dpt.provider_id,
        dpt.request_reference,
        dpt.provider_reference,
        dpt.action,
        dpt.status,
        dpt.http_status,
        dpt.response_code,
        dpt.response_message,
        dpt.attempt_no,
        dpt.created_at,
        dpt.updated_at,
        dpt.last_checked_at,

        ap.name AS provider_name,
        ap.slug AS provider_slug,

        do.order_reference,
        do.phone_number,
        do.status AS order_status,
        do.selling_price,

        u.id AS user_id,
        u.full_name,
        u.email,

        dp.name AS plan_name,
        dp.data_amount,

        n.name AS network_name

    FROM data_provider_transactions dpt

    INNER JOIN data_orders do
        ON do.id = dpt.order_id

    INNER JOIN users u
        ON u.id = do.user_id

    INNER JOIN data_plans dp
        ON dp.id = do.plan_id

    INNER JOIN networks n
        ON n.id = dp.network_id

    LEFT JOIN api_providers ap
        ON ap.id = dpt.provider_id

    $whereSql

    ORDER BY
        dpt.id DESC

    LIMIT 200
";


$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$transactions =
    $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| HELPER
|--------------------------------------------------------------------------
*/

function e($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        "UTF-8"
    );
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

    <title>Provider Transactions | Subnext Admin</title>

    <!-- Precompiled Production Stylesheet -->
    <link rel="stylesheet" href="../assets/css/style.css">

</head>


<body class="min-h-screen bg-slate-50 text-slate-900">

<main
    class="mx-auto w-full max-w-[1500px] px-4 py-5 sm:px-6 sm:py-8 lg:px-8"
>


    <!-- HEADER -->

    <section
        class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-servora-800 via-servora-700 to-servora-500 p-6 text-white shadow-xl sm:p-8"
    >

        <div class="relative z-10">

            <a
                href="data_control.php"
                class="inline-flex items-center text-sm font-semibold text-white/75 transition hover:text-white"
            >
                ← Data Control
            </a>


            <div class="mt-5">

                <p
                    class="text-sm font-semibold text-white/70"
                >
                    Subnext Data Management
                </p>

                <h1
                    class="mt-1 text-3xl font-black tracking-tight sm:text-4xl"
                >
                    Provider Transactions
                </h1>

                <p
                    class="mt-3 max-w-3xl text-sm leading-6 text-white/75 sm:text-base"
                >
                    Monitor communication between Subnext and
                    your data API providers.
                </p>

            </div>

        </div>


        <div
            class="absolute -right-16 -top-20 h-56 w-56 rounded-full bg-white/10"
        ></div>

    </section>



    <!-- SAFETY NOTICE -->

    <section
        class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-4"
    >

        <div class="flex gap-3">

            <div class="text-xl">
                ⚠️
            </div>

            <div>

                <p
                    class="font-bold text-amber-900"
                >
                    Monitoring only
                </p>

                <p
                    class="mt-1 text-sm leading-6 text-amber-800"
                >
                    A failed provider transaction may be safe to
                    reverse only when failure is confirmed.
                    An unknown transaction must be investigated
                    before retrying or refunding because the
                    supplier may already have delivered the data.
                </p>

            </div>

        </div>

    </section>



    <!-- STATISTICS -->

    <section
        class="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-5"
    >

        <!-- TOTAL -->

        <div
            class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"
        >

            <p
                class="text-xs font-bold uppercase tracking-wide text-slate-400"
            >
                Total
            </p>

            <p
                class="mt-2 text-2xl font-black"
            >
                <?= $totalTransactions ?>
            </p>

        </div>


        <!-- PENDING -->

        <div
            class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"
        >

            <p
                class="text-xs font-bold uppercase tracking-wide text-slate-400"
            >
                Pending
            </p>

            <p
                class="mt-2 text-2xl font-black text-amber-600"
            >
                <?= $pendingTransactions ?>
            </p>

        </div>


        <!-- SUCCESSFUL -->

        <div
            class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"
        >

            <p
                class="text-xs font-bold uppercase tracking-wide text-slate-400"
            >
                Successful
            </p>

            <p
                class="mt-2 text-2xl font-black text-emerald-600"
            >
                <?= $successfulTransactions ?>
            </p>

        </div>


        <!-- FAILED -->

        <div
            class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"
        >

            <p
                class="text-xs font-bold uppercase tracking-wide text-slate-400"
            >
                Failed
            </p>

            <p
                class="mt-2 text-2xl font-black text-red-600"
            >
                <?= $failedTransactions ?>
            </p>

        </div>


        <!-- UNKNOWN -->

        <div
            class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"
        >

            <p
                class="text-xs font-bold uppercase tracking-wide text-slate-400"
            >
                Unknown
            </p>

            <p
                class="mt-2 text-2xl font-black text-purple-600"
            >
                <?= $unknownTransactions ?>
            </p>

        </div>

    </section>



    <!-- FILTERS -->

    <section
        class="mt-7 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
    >

        <div>

            <p
                class="text-xs font-bold uppercase tracking-widest text-servora-600"
            >
                Transaction Search
            </p>

            <h2
                class="mt-1 text-xl font-black"
            >
                Filter provider activity
            </h2>

        </div>


        <form
            method="GET"
            class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-5"
        >


            <!-- SEARCH -->

            <div>

                <label
                    for="search"
                    class="mb-2 block text-sm font-bold text-slate-700"
                >
                    Search
                </label>

                <input
                    type="text"
                    id="search"
                    name="search"
                    value="<?= e($search) ?>"
                    placeholder="Reference, phone, client..."
                    class="w-full rounded-xl border border-slate-300 px-4 py-3 text-sm outline-none transition focus:border-servora-500 focus:ring-4 focus:ring-servora-100"
                >

            </div>


            <!-- STATUS -->

            <div>

                <label
                    for="status"
                    class="mb-2 block text-sm font-bold text-slate-700"
                >
                    Provider Status
                </label>

                <select
                    id="status"
                    name="status"
                    class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none transition focus:border-servora-500 focus:ring-4 focus:ring-servora-100"
                >

                    <option value="">
                        All Statuses
                    </option>

                    <?php foreach ($allowedStatuses as $option): ?>

                        <option
                            value="<?= e($option) ?>"
                            <?= $status === $option ? "selected" : "" ?>
                        >
                            <?= e(ucfirst($option)) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <!-- PROVIDER -->

            <div>

                <label
                    for="provider_id"
                    class="mb-2 block text-sm font-bold text-slate-700"
                >
                    Provider
                </label>

                <select
                    id="provider_id"
                    name="provider_id"
                    class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none transition focus:border-servora-500 focus:ring-4 focus:ring-servora-100"
                >

                    <option value="">
                        All Providers
                    </option>

                    <?php foreach ($providers as $provider): ?>

                        <option
                            value="<?= (int) $provider["id"] ?>"
                            <?= $providerId === (int) $provider["id"] ? "selected" : "" ?>
                        >
                            <?= e($provider["name"]) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <!-- ACTION -->

            <div>

                <label
                    for="action"
                    class="mb-2 block text-sm font-bold text-slate-700"
                >
                    API Action
                </label>

                <select
                    id="action"
                    name="action"
                    class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none transition focus:border-servora-500 focus:ring-4 focus:ring-servora-100"
                >

                    <option value="">
                        All Actions
                    </option>

                    <option
                        value="purchase"
                        <?= $action === "purchase" ? "selected" : "" ?>
                    >
                        Purchase
                    </option>

                    <option
                        value="status_check"
                        <?= $action === "status_check" ? "selected" : "" ?>
                    >
                        Status Check
                    </option>

                </select>

            </div>


            <!-- BUTTON -->

            <div class="flex items-end gap-2">

                <button
                    type="submit"
                    class="flex-1 rounded-xl bg-servora-700 px-5 py-3 text-sm font-bold text-white transition hover:bg-servora-800"
                >
                    Filter
                </button>


                <a
                    href="provider_transactions.php"
                    class="rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-600 transition hover:bg-slate-50"
                >
                    Reset
                </a>

            </div>

        </form>

    </section>



    <!-- TRANSACTIONS -->

    <section class="mt-7">

        <div
            class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between"
        >

            <div>

                <p
                    class="text-xs font-bold uppercase tracking-widest text-servora-600"
                >
                    API Activity
                </p>

                <h2
                    class="mt-1 text-xl font-black"
                >
                    Provider transaction logs
                </h2>

            </div>


            <p
                class="text-xs font-semibold text-slate-400"
            >
                Showing up to 200 transactions
            </p>

        </div>


        <?php if (!$transactions): ?>

            <div
                class="rounded-2xl border border-slate-200 bg-white p-10 text-center shadow-sm"
            >

                <div class="text-4xl">
                    🔌
                </div>

                <p
                    class="mt-3 font-bold text-slate-700"
                >
                    No provider transactions found
                </p>

                <p
                    class="mt-1 text-sm text-slate-500"
                >
                    Try changing your filters or wait for
                    a new data purchase.
                </p>

            </div>

        <?php else: ?>


            <div
                class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
            >

                <div class="overflow-x-auto">

                    <table
                        class="min-w-[1350px] w-full text-left text-sm"
                    >

                        <thead
                            class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"
                        >

                            <tr>

                                <th class="px-4 py-4">
                                    Transaction
                                </th>

                                <th class="px-4 py-4">
                                    Order
                                </th>

                                <th class="px-4 py-4">
                                    Client
                                </th>

                                <th class="px-4 py-4">
                                    Data
                                </th>

                                <th class="px-4 py-4">
                                    Provider
                                </th>

                                <th class="px-4 py-4">
                                    Action
                                </th>

                                <th class="px-4 py-4">
                                    HTTP
                                </th>

                                <th class="px-4 py-4">
                                    Provider Status
                                </th>

                                <th class="px-4 py-4">
                                    Order Status
                                </th>

                                <th class="px-4 py-4">
                                    Response
                                </th>

                                <th class="px-4 py-4">
                                    Date
                                </th>

                            </tr>

                        </thead>


                        <tbody
                            class="divide-y divide-slate-100"
                        >


                        <?php foreach ($transactions as $transaction): ?>


                            <?php

                            $providerStatus =
                                $transaction["status"];

                            $orderStatus =
                                $transaction["order_status"];

                            ?>


                            <tr
                                class="align-top transition hover:bg-slate-50/70"
                            >


                                <!-- TRANSACTION -->

                                <td class="px-4 py-4">

                                    <p
                                        class="font-black text-slate-900"
                                    >
                                        #<?= (int) $transaction["id"] ?>
                                    </p>

                                    <p
                                        class="mt-1 max-w-[190px] break-all text-xs text-slate-500"
                                    >
                                        <?= e($transaction["request_reference"]) ?>
                                    </p>

                                    <p
                                        class="mt-1 text-xs text-slate-400"
                                    >
                                        Attempt
                                        <?= (int) $transaction["attempt_no"] ?>
                                    </p>

                                </td>



                                <!-- ORDER -->

                                <td class="px-4 py-4">

                                    <p
                                        class="font-bold text-servora-700"
                                    >
                                        <?= e($transaction["order_reference"]) ?>
                                    </p>

                                    <?php if (
                                        !empty(
                                            $transaction["provider_reference"]
                                        )
                                    ): ?>

                                        <p
                                            class="mt-1 max-w-[180px] break-all text-xs text-slate-500"
                                        >
                                            Provider:
                                            <?= e($transaction["provider_reference"]) ?>
                                        </p>

                                    <?php endif; ?>

                                </td>



                                <!-- CLIENT -->

                                <td class="px-4 py-4">

                                    <p
                                        class="font-bold text-slate-800"
                                    >
                                        <?= e($transaction["full_name"]) ?>
                                    </p>

                                    <p
                                        class="mt-1 text-xs text-slate-500"
                                    >
                                        <?= e($transaction["phone_number"]) ?>
                                    </p>

                                    <p
                                        class="mt-1 text-xs text-slate-400"
                                    >
                                        <?= e($transaction["email"]) ?>
                                    </p>

                                </td>



                                <!-- DATA -->

                                <td class="px-4 py-4">

                                    <p
                                        class="font-bold text-slate-800"
                                    >
                                        <?= e($transaction["network_name"]) ?>
                                    </p>

                                    <p
                                        class="mt-1 text-xs text-slate-500"
                                    >
                                        <?= e($transaction["plan_name"]) ?>
                                    </p>

                                    <?php if (
                                        !empty(
                                            $transaction["data_amount"]
                                        )
                                    ): ?>

                                        <p
                                            class="mt-1 text-xs text-slate-400"
                                        >
                                            <?= e($transaction["data_amount"]) ?>
                                        </p>

                                    <?php endif; ?>


                                    <p
                                        class="mt-2 font-bold text-slate-700"
                                    >
                                        ₦<?= number_format(
                                            (float) $transaction["selling_price"],
                                            2
                                        ) ?>
                                    </p>

                                </td>



                                <!-- PROVIDER -->

                                <td class="px-4 py-4">

                                    <?php if (
                                        !empty(
                                            $transaction["provider_name"]
                                        )
                                    ): ?>

                                        <p
                                            class="font-bold text-slate-800"
                                        >
                                            <?= e($transaction["provider_name"]) ?>
                                        </p>

                                        <p
                                            class="mt-1 text-xs text-slate-400"
                                        >
                                            <?= e($transaction["provider_slug"]) ?>
                                        </p>

                                    <?php else: ?>

                                        <span
                                            class="text-xs font-semibold text-slate-400"
                                        >
                                            Provider unavailable
                                        </span>

                                    <?php endif; ?>

                                </td>



                                <!-- ACTION -->

                                <td class="px-4 py-4">

                                    <?php if (
                                        $transaction["action"] === "purchase"
                                    ): ?>

                                        <span
                                            class="rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700"
                                        >
                                            Purchase
                                        </span>

                                    <?php else: ?>

                                        <span
                                            class="rounded-full bg-purple-50 px-3 py-1 text-xs font-bold text-purple-700"
                                        >
                                            Status Check
                                        </span>

                                    <?php endif; ?>

                                </td>



                                <!-- HTTP STATUS -->

                                <td class="px-4 py-4">

                                    <?php if (
                                        $transaction["http_status"] !== null
                                    ): ?>

                                        <?php

                                        $httpStatus =
                                            (int) $transaction["http_status"];

                                        ?>

                                        <?php if (
                                            $httpStatus >= 200 &&
                                            $httpStatus < 300
                                        ): ?>

                                            <span
                                                class="rounded-lg bg-emerald-50 px-2.5 py-1 text-xs font-black text-emerald-700"
                                            >
                                                <?= $httpStatus ?>
                                            </span>

                                        <?php elseif (
                                            $httpStatus >= 500
                                        ): ?>

                                            <span
                                                class="rounded-lg bg-purple-50 px-2.5 py-1 text-xs font-black text-purple-700"
                                            >
                                                <?= $httpStatus ?>
                                            </span>

                                        <?php else: ?>

                                            <span
                                                class="rounded-lg bg-red-50 px-2.5 py-1 text-xs font-black text-red-700"
                                            >
                                                <?= $httpStatus ?>
                                            </span>

                                        <?php endif; ?>

                                    <?php else: ?>

                                        <span
                                            class="text-xs font-semibold text-slate-400"
                                        >
                                            —
                                        </span>

                                    <?php endif; ?>

                                </td>



                                <!-- PROVIDER STATUS -->

                                <td class="px-4 py-4">

                                    <?php if (
                                        $providerStatus === "successful"
                                    ): ?>

                                        <span
                                            class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700"
                                        >
                                            Successful
                                        </span>

                                    <?php elseif (
                                        $providerStatus === "failed"
                                    ): ?>

                                        <span
                                            class="rounded-full bg-red-50 px-3 py-1 text-xs font-bold text-red-700"
                                        >
                                            Failed
                                        </span>

                                    <?php elseif (
                                        $providerStatus === "unknown"
                                    ): ?>

                                        <span
                                            class="rounded-full bg-purple-50 px-3 py-1 text-xs font-bold text-purple-700"
                                        >
                                            Unknown
                                        </span>

                                    <?php else: ?>

                                        <span
                                            class="rounded-full bg-amber-50 px-3 py-1 text-xs font-bold text-amber-700"
                                        >
                                            Pending
                                        </span>

                                    <?php endif; ?>

                                </td>



                                <!-- ORDER STATUS -->

                                <td class="px-4 py-4">

                                    <?php if (
                                        $orderStatus === "successful"
                                    ): ?>

                                        <span
                                            class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700"
                                        >
                                            Successful
                                        </span>

                                    <?php elseif (
                                        $orderStatus === "refunded"
                                    ): ?>

                                        <span
                                            class="rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700"
                                        >
                                            Refunded
                                        </span>

                                    <?php elseif (
                                        $orderStatus === "failed"
                                    ): ?>

                                        <span
                                            class="rounded-full bg-red-50 px-3 py-1 text-xs font-bold text-red-700"
                                        >
                                            Failed
                                        </span>

                                    <?php elseif (
                                        $orderStatus === "processing"
                                    ): ?>

                                        <span
                                            class="rounded-full bg-amber-50 px-3 py-1 text-xs font-bold text-amber-700"
                                        >
                                            Processing
                                        </span>

                                    <?php else: ?>

                                        <span
                                            class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600"
                                        >
                                            <?= e(ucfirst($orderStatus)) ?>
                                        </span>

                                    <?php endif; ?>

                                </td>



                                <!-- RESPONSE -->

                                <td class="px-4 py-4">

                                    <?php if (
                                        !empty(
                                            $transaction["response_code"]
                                        )
                                    ): ?>

                                        <p
                                            class="text-xs font-bold text-slate-700"
                                        >
                                            Code:
                                            <?= e($transaction["response_code"]) ?>
                                        </p>

                                    <?php endif; ?>


                                    <?php if (
                                        !empty(
                                            $transaction["response_message"]
                                        )
                                    ): ?>

                                        <p
                                            class="mt-1 max-w-[240px] break-words text-xs leading-5 text-slate-500"
                                        >
                                            <?= e($transaction["response_message"]) ?>
                                        </p>

                                    <?php else: ?>

                                        <span
                                            class="text-xs text-slate-400"
                                        >
                                            No response message
                                        </span>

                                    <?php endif; ?>

                                </td>



                                <!-- DATE -->

                                <td class="px-4 py-4">

                                    <p
                                        class="whitespace-nowrap text-xs font-semibold text-slate-600"
                                    >
                                        <?= e(
                                            date(
                                                "d M Y",
                                                strtotime(
                                                    $transaction["created_at"]
                                                )
                                            )
                                        ) ?>
                                    </p>

                                    <p
                                        class="mt-1 whitespace-nowrap text-xs text-slate-400"
                                    >
                                        <?= e(
                                            date(
                                                "h:i A",
                                                strtotime(
                                                    $transaction["created_at"]
                                                )
                                            )
                                        ) ?>
                                    </p>


                                    <?php if (
                                        !empty(
                                            $transaction["last_checked_at"]
                                        )
                                    ): ?>

                                        <p
                                            class="mt-2 text-[11px] text-slate-400"
                                        >
                                            Checked:
                                            <?= e(
                                                date(
                                                    "d M, h:i A",
                                                    strtotime(
                                                        $transaction["last_checked_at"]
                                                    )
                                                )
                                            ) ?>
                                        </p>

                                    <?php endif; ?>

                                </td>


                            </tr>


                        <?php endforeach; ?>


                        </tbody>

                    </table>

                </div>

            </div>

        <?php endif; ?>

    </section>



    <!-- STATUS GUIDE -->

    <section
        class="mt-7 grid gap-4 md:grid-cols-2 lg:grid-cols-4"
    >

        <div
            class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4"
        >

            <p
                class="font-black text-emerald-800"
            >
                Successful
            </p>

            <p
                class="mt-1 text-xs leading-5 text-emerald-700"
            >
                The supplier request was confirmed successful.
            </p>

        </div>


        <div
            class="rounded-2xl border border-red-200 bg-red-50 p-4"
        >

            <p
                class="font-black text-red-800"
            >
                Failed
            </p>

            <p
                class="mt-1 text-xs leading-5 text-red-700"
            >
                The supplier returned a confirmed failure.
            </p>

        </div>


        <div
            class="rounded-2xl border border-purple-200 bg-purple-50 p-4"
        >

            <p
                class="font-black text-purple-800"
            >
                Unknown
            </p>

            <p
                class="mt-1 text-xs leading-5 text-purple-700"
            >
                The final supplier result is uncertain and
                should be investigated before retry or refund.
            </p>

        </div>


        <div
            class="rounded-2xl border border-amber-200 bg-amber-50 p-4"
        >

            <p
                class="font-black text-amber-800"
            >
                Pending
            </p>

            <p
                class="mt-1 text-xs leading-5 text-amber-700"
            >
                Subnext is still waiting for a confirmed result.
            </p>

        </div>

    </section>



    <!-- NAVIGATION -->

    <section
        class="mt-8 border-t border-slate-200 pt-6"
    >

        <div
            class="flex flex-col gap-3 sm:flex-row"
        >

            <a
                href="data_control.php"
                class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-bold text-slate-700 transition hover:bg-slate-50"
            >
                ← Data Control
            </a>


            <a
                href="data_orders.php"
                class="inline-flex items-center justify-center rounded-xl bg-servora-700 px-5 py-3 text-sm font-bold text-white transition hover:bg-servora-800"
            >
                View Data Orders
            </a>

        </div>

    </section>


    <footer
        class="py-8 text-center text-xs text-slate-400"
    >
        Subnext Provider Transaction Monitoring
    </footer>


</main>

</body>

</html>