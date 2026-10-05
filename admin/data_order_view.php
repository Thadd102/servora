<?php

require_once "../config/database.php";
require_once "../includes/admin_auth.php";
require_once "../includes/CheapDataHubClient.php";
require_once "../includes/DataOrderReconciliationService.php";


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


/*
|--------------------------------------------------------------------------
| GET ORDER ID
|--------------------------------------------------------------------------
*/

$orderId = (int) ($_GET["id"] ?? 0);

if ($orderId <= 0) {
    header("Location: data_orders.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| GET DATA ORDER
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT

        do.id,
        do.order_reference,
        do.client_request_token,
        do.user_id,
        do.plan_id,
        do.phone_number,
        do.cost_price,
        do.selling_price,
        do.profit,
        do.provider_reference,
        do.status,
        do.provider_message,
        do.wallet_transaction_id,
        do.refund_transaction_id,
        do.created_at,
        do.updated_at,
        do.completed_at,

        u.full_name,
        u.email,
        u.phone AS client_phone,
        u.status AS client_status,

        dp.name AS plan_name,
        dp.data_amount,
        dp.validity,
        dp.provider_plan_code,
        dp.status AS plan_status,

        n.name AS network_name,
        n.slug AS network_slug,
        n.status AS network_status,

        dc.name AS category_name,
        dc.slug AS category_slug,
        dc.status AS category_status,

        ap.id AS provider_id,
        COALESCE(do.provider_name, ap.name) AS provider_name,
        ap.slug AS provider_slug,
        ap.status AS provider_status

    FROM data_orders do

    INNER JOIN users u
        ON u.id = do.user_id

    INNER JOIN data_plans dp
        ON dp.id = do.plan_id

    INNER JOIN networks n
        ON n.id = dp.network_id

    LEFT JOIN data_categories dc
        ON dc.id = dp.category_id

    LEFT JOIN api_providers ap
        ON ap.id = dp.provider_id

    WHERE do.id = ?

    LIMIT 1
");

$stmt->execute([
    $orderId
]);

$order = $stmt->fetch(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| ORDER NOT FOUND
|--------------------------------------------------------------------------
*/

if (!$order) {
    header("Location: data_orders.php?error=not_found");
    exit;
}


/*
|--------------------------------------------------------------------------
| GET ORIGINAL WALLET TRANSACTION
|--------------------------------------------------------------------------
*/

$walletTransaction = null;

if (!empty($order["wallet_transaction_id"])) {

    $stmt = $pdo->prepare("
        SELECT
            id,
            user_id,
            type,
            amount,
            balance_before,
            balance_after,
            reference,
            description,
            status,
            created_at
        FROM wallet_transactions
        WHERE id = ?
          AND user_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        (int) $order["wallet_transaction_id"],
        (int) $order["user_id"]
    ]);

    $walletTransaction =
        $stmt->fetch(PDO::FETCH_ASSOC);
}


/*
|--------------------------------------------------------------------------
| GET REFUND TRANSACTION
|--------------------------------------------------------------------------
*/

$refundTransaction = null;

if (!empty($order["refund_transaction_id"])) {

    $stmt = $pdo->prepare("
        SELECT
            id,
            user_id,
            type,
            amount,
            balance_before,
            balance_after,
            reference,
            description,
            status,
            created_at
        FROM wallet_transactions
        WHERE id = ?
          AND user_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        (int) $order["refund_transaction_id"],
        (int) $order["user_id"]
    ]);

    $refundTransaction =
        $stmt->fetch(PDO::FETCH_ASSOC);
}


/*
|--------------------------------------------------------------------------
| GET PROVIDER TRANSACTION HISTORY
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        dpt.id,
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
        ap.slug AS provider_slug

    FROM data_provider_transactions dpt

    LEFT JOIN api_providers ap
        ON ap.id = dpt.provider_id

    WHERE dpt.order_id = ?

    ORDER BY
        dpt.id ASC
");

$stmt->execute([
    $orderId
]);

$providerTransactions =
    $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| DETERMINE LATEST PROVIDER TRANSACTION
|--------------------------------------------------------------------------
*/

$latestProviderTransaction = null;

if ($providerTransactions) {
    $latestProviderTransaction =
        $providerTransactions[
            count($providerTransactions) - 1
        ];
}


/*
|--------------------------------------------------------------------------
| CURRENT WALLET
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        balance
    FROM wallets
    WHERE user_id = ?
    LIMIT 1
");

$stmt->execute([
    (int) $order["user_id"]
]);

$currentWalletBalance =
    $stmt->fetchColumn();

$currentWalletBalance =
    $currentWalletBalance !== false
        ? (float) $currentWalletBalance
        : null;


/*
|--------------------------------------------------------------------------
| INVESTIGATION FLAGS
|--------------------------------------------------------------------------
*/

$warnings = [];

$orderStatus =
    strtolower(
        trim(
            (string) $order["status"]
        )
    );

/*
|--------------------------------------------------------------------------
| CSRF TOKEN FOR ADMIN RESOLUTION ACTION
|--------------------------------------------------------------------------
*/

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$csrfToken = (string) $_SESSION["csrf_token"];


/*
|--------------------------------------------------------------------------
| READ-ONLY SUPPLIER RECONCILIATION
|--------------------------------------------------------------------------
*/

$reconciliation = null;
$reconciliationError = null;

if (
    $orderStatus === "processing" &&
    strtolower(
        trim(
            (string) ($order["provider_slug"] ?? "")
        )
    ) === "cheapdatahub"
) {

    try {

        $cheapDataHubApiKey = trim(
            (string) (
                getenv("CHEAPDATAHUB_API_KEY")
                ?: ($_ENV["CHEAPDATAHUB_API_KEY"] ?? "")
            )
        );

        $cheapDataHubBaseUrl = trim(
            (string) (
                getenv("CHEAPDATAHUB_BASE_URL")
                ?: (
                    $_ENV["CHEAPDATAHUB_BASE_URL"]
                    ?? "https://www.cheapdatahub.ng/api/v1/resellers/"
                )
            )
        );

        if ($cheapDataHubApiKey === "") {
            throw new RuntimeException(
                "CheapDataHub API configuration is unavailable."
            );
        }

        $cheapDataHubClient =
            new CheapDataHubClient(
                $cheapDataHubApiKey,
                $cheapDataHubBaseUrl
            );

        $reconciliationService =
            new DataOrderReconciliationService(
                $pdo,
                $cheapDataHubClient
            );

        $reconciliation =
            $reconciliationService->inspect(
                $orderId
            );

    } catch (Throwable $exception) {

        $reconciliationError =
            "Supplier reconciliation could not be loaded right now. The order remains unchanged.";

        error_log(
            "Data order reconciliation view error for order #" .
            $orderId .
            ": " .
            $exception->getMessage()
        );
    }
}


/*
|--------------------------------------------------------------------------
| PROCESSING ORDER
|--------------------------------------------------------------------------
*/

if ($orderStatus === "processing") {

    $warnings[] =
        "This order is still processing. Do not resend or refund it until the supplier result has been confirmed.";
}


/*
|--------------------------------------------------------------------------
| PROVIDER UNKNOWN
|--------------------------------------------------------------------------
*/

if (
    $latestProviderTransaction &&
    $latestProviderTransaction["status"] === "unknown"
) {

    $warnings[] =
        "The latest provider result is unknown. The supplier may already have delivered the data.";
}


/*
|--------------------------------------------------------------------------
| HTTP 5XX
|--------------------------------------------------------------------------
*/

if (
    $latestProviderTransaction &&
    $latestProviderTransaction["http_status"] !== null
) {

    $latestHttpStatus =
        (int) $latestProviderTransaction["http_status"];

    if ($latestHttpStatus >= 500) {

        $warnings[] =
            "The supplier returned a server-side HTTP error. Treat this result as uncertain until the supplier status is checked.";
    }
}


/*
|--------------------------------------------------------------------------
| HTTP 409
|--------------------------------------------------------------------------
*/

if (
    $latestProviderTransaction &&
    (int) (
        $latestProviderTransaction["http_status"]
        ?? 0
    ) === 409
) {

    $warnings[] =
        "The supplier returned HTTP 409. Do not automatically resend this transaction.";
}


/*
|--------------------------------------------------------------------------
| WALLET TRANSACTION MISSING
|--------------------------------------------------------------------------
*/

if (
    !empty($order["wallet_transaction_id"]) &&
    !$walletTransaction
) {

    $warnings[] =
        "This order references a wallet debit that could not be found. Manual investigation is required.";
}


/*
|--------------------------------------------------------------------------
| REFUND TRANSACTION MISSING
|--------------------------------------------------------------------------
*/

if (
    !empty($order["refund_transaction_id"]) &&
    !$refundTransaction
) {

    $warnings[] =
        "This order references a refund transaction that could not be found. Manual investigation is required.";
}


/*
|--------------------------------------------------------------------------
| REFUNDED WITHOUT REFUND LINK
|--------------------------------------------------------------------------
*/

if (
    $orderStatus === "refunded" &&
    empty($order["refund_transaction_id"])
) {

    $warnings[] =
        "The order is marked refunded but no refund wallet transaction is linked.";
}


/*
|--------------------------------------------------------------------------
| SUCCESSFUL ORDER WITH REFUND
|--------------------------------------------------------------------------
*/

if (
    $orderStatus === "successful" &&
    !empty($order["refund_transaction_id"])
) {

    $warnings[] =
        "This order is marked successful but also has a refund transaction linked. Review the financial records.";
}


/*
|--------------------------------------------------------------------------
| MONEY VALUES
|--------------------------------------------------------------------------
*/

$sellingPrice =
    (float) $order["selling_price"];

$costPrice =
    (float) $order["cost_price"];

$orderMargin =
    (float) $order["profit"];


/*
|--------------------------------------------------------------------------
| PROFIT DISPLAY
|--------------------------------------------------------------------------
*/

if ($orderStatus === "successful") {

    $profitLabel =
        "Realized Profit";

    $displayProfit =
        $orderMargin;

    $profitColorClass =
        "text-emerald-600";

} elseif (
    $orderStatus === "failed" ||
    $orderStatus === "refunded"
) {

    $profitLabel =
        "Realized Profit";

    $displayProfit =
        0.00;

    $profitColorClass =
        "text-slate-500";

} else {

    $profitLabel =
        "Order Margin";

    $displayProfit =
        $orderMargin;

    $profitColorClass =
        "text-amber-600";
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
        Data Order <?= e($order["order_reference"]) ?> | Subnext
    </title>

    <!-- Precompiled Production Stylesheet -->
    <link rel="stylesheet" href="../assets/css/style.css">

</head>


<body class="min-h-screen bg-slate-50 text-slate-900">

<main
    class="mx-auto w-full max-w-7xl px-4 py-5 sm:px-6 sm:py-8 lg:px-8"
>


    <!-- HEADER -->

    <section
        class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-servora-800 via-servora-700 to-servora-500 p-6 text-white shadow-xl sm:p-8"
    >

        <div class="relative z-10">

            <a
                href="data_orders.php"
                class="inline-flex items-center text-sm font-semibold text-white/75 transition hover:text-white"
            >
                ← Data Orders
            </a>


            <div
                class="mt-5 flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between"
            >

                <div>

                    <p
                        class="text-sm font-semibold text-white/70"
                    >
                        Data Order Investigation
                    </p>

                    <h1
                        class="mt-1 break-all text-2xl font-black tracking-tight sm:text-4xl"
                    >
                        <?= e($order["order_reference"]) ?>
                    </h1>

                    <p
                        class="mt-3 text-sm text-white/75"
                    >
                        Created
                        <?= e(
                            date(
                                "d M Y, h:i A",
                                strtotime(
                                    $order["created_at"]
                                )
                            )
                        ) ?>
                    </p>

                </div>


                <div>

                    <?php if (
                        $orderStatus === "successful"
                    ): ?>

                        <span
                            class="inline-flex rounded-full bg-emerald-400/20 px-4 py-2 text-sm font-black text-emerald-100"
                        >
                            Successful
                        </span>

                    <?php elseif (
                        $orderStatus === "refunded"
                    ): ?>

                        <span
                            class="inline-flex rounded-full bg-blue-400/20 px-4 py-2 text-sm font-black text-blue-100"
                        >
                            Refunded
                        </span>

                    <?php elseif (
                        $orderStatus === "failed"
                    ): ?>

                        <span
                            class="inline-flex rounded-full bg-red-400/20 px-4 py-2 text-sm font-black text-red-100"
                        >
                            Failed
                        </span>

                    <?php elseif (
                        $orderStatus === "processing"
                    ): ?>

                        <span
                            class="inline-flex rounded-full bg-amber-400/20 px-4 py-2 text-sm font-black text-amber-100"
                        >
                            Processing
                        </span>

                    <?php else: ?>

                        <span
                            class="inline-flex rounded-full bg-white/15 px-4 py-2 text-sm font-black text-white"
                        >
                            <?= e(
                                ucfirst(
                                    $orderStatus
                                )
                            ) ?>
                        </span>

                    <?php endif; ?>

                </div>

            </div>

        </div>


        <div
            class="absolute -right-16 -top-20 h-56 w-56 rounded-full bg-white/10"
        ></div>

    </section>



    <!-- SAFETY / INVESTIGATION WARNINGS -->

    <?php if ($warnings): ?>

        <section class="mt-5 space-y-3">

            <?php foreach (
                $warnings as $warning
            ): ?>

                <div
                    class="rounded-2xl border border-amber-200 bg-amber-50 p-4"
                >

                    <div class="flex gap-3">

                        <div class="text-xl">
                            ⚠️
                        </div>

                        <p
                            class="text-sm font-semibold leading-6 text-amber-800"
                        >
                            <?= e($warning) ?>
                        </p>

                    </div>

                </div>

            <?php endforeach; ?>

        </section>

    <?php endif; ?>



    <!-- FINANCIAL SUMMARY -->

    <section
        class="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-4"
    >

        <div
            class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
        >

            <p
                class="text-xs font-bold uppercase tracking-wide text-slate-400"
            >
                Customer Paid
            </p>

            <p
                class="mt-2 text-2xl font-black"
            >
                ₦<?= number_format(
                    $sellingPrice,
                    2
                ) ?>
            </p>

        </div>


        <div
            class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
        >

            <p
                class="text-xs font-bold uppercase tracking-wide text-slate-400"
            >
                Supplier Cost
            </p>

            <p
                class="mt-2 text-2xl font-black"
            >
                ₦<?= number_format(
                    $costPrice,
                    2
                ) ?>
            </p>

        </div>


        <div
            class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
        >

            <p
                class="text-xs font-bold uppercase tracking-wide text-slate-400"
            >
                <?= e($profitLabel) ?>
            </p>

            <p
                class="mt-2 text-2xl font-black <?= e($profitColorClass) ?>"
            >
                ₦<?= number_format(
                    $displayProfit,
                    2
                ) ?>
            </p>

            <?php if (
                $orderStatus === "pending" ||
                $orderStatus === "processing"
            ): ?>

                <p
                    class="mt-1 text-xs font-semibold text-amber-600"
                >
                    Not yet realized
                </p>

            <?php endif; ?>

        </div>


        <div
            class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
        >

            <p
                class="text-xs font-bold uppercase tracking-wide text-slate-400"
            >
                Current Wallet
            </p>

            <p
                class="mt-2 text-2xl font-black text-servora-700"
            >

                <?php if (
                    $currentWalletBalance !== null
                ): ?>

                    ₦<?= number_format(
                        $currentWalletBalance,
                        2
                    ) ?>

                <?php else: ?>

                    —

                <?php endif; ?>

            </p>

        </div>

    </section>
        <!-- ORDER + CLIENT -->

    <section
        class="mt-7 grid gap-5 lg:grid-cols-2"
    >


        <!-- ORDER INFORMATION -->

        <div
            class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"
        >

            <p
                class="text-xs font-bold uppercase tracking-widest text-servora-600"
            >
                Order
            </p>

            <h2
                class="mt-1 text-xl font-black"
            >
                Purchase information
            </h2>


            <dl class="mt-5 space-y-4 text-sm">

                <div
                    class="flex justify-between gap-4 border-b border-slate-100 pb-3"
                >
                    <dt class="text-slate-500">
                        Order ID
                    </dt>

                    <dd class="font-bold">
                        #<?= (int) $order["id"] ?>
                    </dd>
                </div>


                <div
                    class="flex justify-between gap-4 border-b border-slate-100 pb-3"
                >
                    <dt class="text-slate-500">
                        Reference
                    </dt>

                    <dd
                        class="break-all text-right font-bold"
                    >
                        <?= e($order["order_reference"]) ?>
                    </dd>
                </div>


                <div
                    class="flex justify-between gap-4 border-b border-slate-100 pb-3"
                >
                    <dt class="text-slate-500">
                        Phone
                    </dt>

                    <dd class="font-bold">
                        <?= e($order["phone_number"]) ?>
                    </dd>
                </div>


                <div
                    class="flex justify-between gap-4 border-b border-slate-100 pb-3"
                >
                    <dt class="text-slate-500">
                        Status
                    </dt>

                    <dd class="font-bold">
                        <?= e(ucfirst($orderStatus)) ?>
                    </dd>
                </div>


                <div
                    class="flex justify-between gap-4 border-b border-slate-100 pb-3"
                >
                    <dt class="text-slate-500">
                        Updated
                    </dt>

                    <dd class="text-right font-bold">
                        <?= e(
                            date(
                                "d M Y, h:i A",
                                strtotime(
                                    $order["updated_at"]
                                )
                            )
                        ) ?>
                    </dd>
                </div>


                <div
                    class="flex justify-between gap-4"
                >
                    <dt class="text-slate-500">
                        Completed
                    </dt>

                    <dd class="text-right font-bold">

                        <?php if (
                            !empty($order["completed_at"])
                        ): ?>

                            <?= e(
                                date(
                                    "d M Y, h:i A",
                                    strtotime(
                                        $order["completed_at"]
                                    )
                                )
                            ) ?>

                        <?php else: ?>

                            —

                        <?php endif; ?>

                    </dd>
                </div>

            </dl>

        </div>



        <!-- CLIENT INFORMATION -->

        <div
            class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"
        >

            <p
                class="text-xs font-bold uppercase tracking-widest text-servora-600"
            >
                Customer
            </p>

            <h2
                class="mt-1 text-xl font-black"
            >
                Client information
            </h2>


            <dl class="mt-5 space-y-4 text-sm">

                <div
                    class="flex justify-between gap-4 border-b border-slate-100 pb-3"
                >
                    <dt class="text-slate-500">
                        Client ID
                    </dt>

                    <dd class="font-bold">
                        #<?= (int) $order["user_id"] ?>
                    </dd>
                </div>


                <div
                    class="flex justify-between gap-4 border-b border-slate-100 pb-3"
                >
                    <dt class="text-slate-500">
                        Name
                    </dt>

                    <dd class="text-right font-bold">
                        <?= e($order["full_name"]) ?>
                    </dd>
                </div>


                <div
                    class="flex justify-between gap-4 border-b border-slate-100 pb-3"
                >
                    <dt class="text-slate-500">
                        Email
                    </dt>

                    <dd
                        class="break-all text-right font-bold"
                    >
                        <?= e($order["email"]) ?>
                    </dd>
                </div>


                <div
                    class="flex justify-between gap-4 border-b border-slate-100 pb-3"
                >
                    <dt class="text-slate-500">
                        Account Phone
                    </dt>

                    <dd class="font-bold">
                        <?= e($order["client_phone"]) ?>
                    </dd>
                </div>


                <div
                    class="flex justify-between gap-4"
                >
                    <dt class="text-slate-500">
                        Account Status
                    </dt>

                    <dd>

                        <?php if (
                            $order["client_status"] === "active"
                        ): ?>

                            <span
                                class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700"
                            >
                                Active
                            </span>

                        <?php else: ?>

                            <span
                                class="rounded-full bg-red-50 px-3 py-1 text-xs font-bold text-red-700"
                            >
                                <?= e(
                                    ucfirst(
                                        $order["client_status"]
                                    )
                                ) ?>
                            </span>

                        <?php endif; ?>

                    </dd>
                </div>

            </dl>

        </div>

    </section>



    <!-- PLAN + PROVIDER -->

    <section
        class="mt-5 grid gap-5 lg:grid-cols-2"
    >


        <!-- PLAN -->

        <div
            class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"
        >

            <p
                class="text-xs font-bold uppercase tracking-widest text-servora-600"
            >
                Product
            </p>

            <h2
                class="mt-1 text-xl font-black"
            >
                Data plan
            </h2>


            <dl class="mt-5 space-y-4 text-sm">

                <div
                    class="flex justify-between gap-4 border-b border-slate-100 pb-3"
                >
                    <dt class="text-slate-500">
                        Network
                    </dt>

                    <dd class="font-bold">
                        <?= e($order["network_name"]) ?>
                    </dd>
                </div>


                <div
                    class="flex justify-between gap-4 border-b border-slate-100 pb-3"
                >
                    <dt class="text-slate-500">
                        Plan
                    </dt>

                    <dd class="text-right font-bold">
                        <?= e($order["plan_name"]) ?>
                    </dd>
                </div>


                <div
                    class="flex justify-between gap-4 border-b border-slate-100 pb-3"
                >
                    <dt class="text-slate-500">
                        Data
                    </dt>

                    <dd class="font-bold">
                        <?= e($order["data_amount"]) ?>
                    </dd>
                </div>


                <div
                    class="flex justify-between gap-4 border-b border-slate-100 pb-3"
                >
                    <dt class="text-slate-500">
                        Validity
                    </dt>

                    <dd class="font-bold">
                        <?= !empty($order["validity"])
                            ? e($order["validity"])
                            : "—"
                        ?>
                    </dd>
                </div>


                <div
                    class="flex justify-between gap-4 border-b border-slate-100 pb-3"
                >
                    <dt class="text-slate-500">
                        Category
                    </dt>

                    <dd class="font-bold">
                        <?= !empty($order["category_name"])
                            ? e($order["category_name"])
                            : "Uncategorized"
                        ?>
                    </dd>
                </div>


                <div
                    class="flex justify-between gap-4"
                >
                    <dt class="text-slate-500">
                        Plan Status
                    </dt>

                    <dd class="font-bold">
                        <?= e(
                            ucfirst(
                                $order["plan_status"]
                            )
                        ) ?>
                    </dd>
                </div>

            </dl>

        </div>



        <!-- PROVIDER -->

        <div
            class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"
        >

            <p
                class="text-xs font-bold uppercase tracking-widest text-servora-600"
            >
                Supplier
            </p>

            <h2
                class="mt-1 text-xl font-black"
            >
                Current provider configuration
            </h2>

            <p
                class="mt-2 text-xs leading-5 text-slate-500"
            >
                This shows the data plan's current supplier mapping.
                The provider or plan code may have changed since this
                order was created.
            </p>


            <dl class="mt-5 space-y-4 text-sm">

                <div
                    class="flex justify-between gap-4 border-b border-slate-100 pb-3"
                >
                    <dt class="text-slate-500">
                        Provider
                    </dt>

                    <dd class="font-bold">
                        <?= !empty($order["provider_name"])
                            ? e($order["provider_name"])
                            : "Not assigned"
                        ?>
                    </dd>
                </div>


                <div
                    class="flex justify-between gap-4 border-b border-slate-100 pb-3"
                >
                    <dt class="text-slate-500">
                        Provider Slug
                    </dt>

                    <dd class="font-bold">
                        <?= !empty($order["provider_slug"])
                            ? e($order["provider_slug"])
                            : "—"
                        ?>
                    </dd>
                </div>


                <div
                    class="flex justify-between gap-4 border-b border-slate-100 pb-3"
                >
                    <dt class="text-slate-500">
                        Bundle / Plan Code
                    </dt>

                    <dd
                        class="break-all text-right font-bold"
                    >
                        <?= !empty($order["provider_plan_code"])
                            ? e($order["provider_plan_code"])
                            : "—"
                        ?>
                    </dd>
                </div>


                <div
                    class="flex justify-between gap-4 border-b border-slate-100 pb-3"
                >
                    <dt class="text-slate-500">
                        Order Provider Ref
                    </dt>

                    <dd
                        class="break-all text-right font-bold"
                    >
                        <?= !empty($order["provider_reference"])
                            ? e($order["provider_reference"])
                            : "—"
                        ?>
                    </dd>
                </div>


                <div
                    class="flex justify-between gap-4"
                >
                    <dt class="text-slate-500">
                        Provider Status
                    </dt>

                    <dd class="font-bold">
                        <?= !empty($order["provider_status"])
                            ? e(
                                ucfirst(
                                    $order["provider_status"]
                                )
                            )
                            : "—"
                        ?>
                    </dd>
                </div>

            </dl>


            <?php if (
                !empty($order["provider_message"])
            ): ?>

                <div
                    class="mt-5 rounded-xl bg-slate-50 p-4"
                >

                    <p
                        class="text-xs font-bold uppercase tracking-wide text-slate-400"
                    >
                        Provider Message
                    </p>

                    <p
                        class="mt-2 break-words text-sm leading-6 text-slate-700"
                    >
                        <?= e($order["provider_message"]) ?>
                    </p>

                </div>

            <?php endif; ?>

        </div>

    </section>



    <!-- WALLET TRANSACTIONS -->

    <section
        class="mt-7 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"
    >

        <p
            class="text-xs font-bold uppercase tracking-widest text-servora-600"
        >
            Financial Trail
        </p>

        <h2
            class="mt-1 text-xl font-black"
        >
            Wallet transactions
        </h2>


        <div
            class="mt-5 grid gap-4 lg:grid-cols-2"
        >


            <!-- DEBIT -->

            <div
                class="rounded-2xl border border-slate-200 p-4"
            >

                <div
                    class="flex items-center justify-between gap-3"
                >

                    <h3 class="font-black">
                        Original Debit
                    </h3>

                    <?php if ($walletTransaction): ?>

                        <span
                            class="rounded-full bg-red-50 px-3 py-1 text-xs font-bold text-red-700"
                        >
                            -₦<?= number_format(
                                (float)
                                $walletTransaction["amount"],
                                2
                            ) ?>
                        </span>

                    <?php endif; ?>

                </div>


                <?php if ($walletTransaction): ?>

                    <dl class="mt-4 space-y-3 text-sm">

                        <div class="flex justify-between gap-3">

                            <dt class="text-slate-500">
                                Transaction ID
                            </dt>

                            <dd class="font-bold">
                                #<?= (int)
                                    $walletTransaction["id"]
                                ?>
                            </dd>

                        </div>


                        <div class="flex justify-between gap-3">

                            <dt class="text-slate-500">
                                Reference
                            </dt>

                            <dd
                                class="break-all text-right font-bold"
                            >
                                <?= e(
                                    $walletTransaction[
                                        "reference"
                                    ]
                                ) ?>
                            </dd>

                        </div>


                        <div class="flex justify-between gap-3">

                            <dt class="text-slate-500">
                                Before
                            </dt>

                            <dd class="font-bold">
                                ₦<?= number_format(
                                    (float)
                                    $walletTransaction[
                                        "balance_before"
                                    ],
                                    2
                                ) ?>
                            </dd>

                        </div>


                        <div class="flex justify-between gap-3">

                            <dt class="text-slate-500">
                                After
                            </dt>

                            <dd class="font-bold">
                                ₦<?= number_format(
                                    (float)
                                    $walletTransaction[
                                        "balance_after"
                                    ],
                                    2
                                ) ?>
                            </dd>

                        </div>


                        <div class="flex justify-between gap-3">

                            <dt class="text-slate-500">
                                Status
                            </dt>

                            <dd class="font-bold">
                                <?= e(
                                    ucfirst(
                                        $walletTransaction[
                                            "status"
                                        ]
                                    )
                                ) ?>
                            </dd>

                        </div>

                    </dl>

                <?php else: ?>

                    <p
                        class="mt-4 text-sm text-slate-500"
                    >
                        No linked wallet debit was found.
                    </p>

                <?php endif; ?>

            </div>



            <!-- REFUND -->

            <div
                class="rounded-2xl border border-slate-200 p-4"
            >

                <div
                    class="flex items-center justify-between gap-3"
                >

                    <h3 class="font-black">
                        Refund
                    </h3>

                    <?php if ($refundTransaction): ?>

                        <span
                            class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700"
                        >
                            +₦<?= number_format(
                                (float)
                                $refundTransaction["amount"],
                                2
                            ) ?>
                        </span>

                    <?php endif; ?>

                </div>


                <?php if ($refundTransaction): ?>

                    <dl class="mt-4 space-y-3 text-sm">

                        <div class="flex justify-between gap-3">

                            <dt class="text-slate-500">
                                Transaction ID
                            </dt>

                            <dd class="font-bold">
                                #<?= (int)
                                    $refundTransaction["id"]
                                ?>
                            </dd>

                        </div>


                        <div class="flex justify-between gap-3">

                            <dt class="text-slate-500">
                                Reference
                            </dt>

                            <dd
                                class="break-all text-right font-bold"
                            >
                                <?= e(
                                    $refundTransaction[
                                        "reference"
                                    ]
                                ) ?>
                            </dd>

                        </div>


                        <div class="flex justify-between gap-3">

                            <dt class="text-slate-500">
                                Before
                            </dt>

                            <dd class="font-bold">
                                ₦<?= number_format(
                                    (float)
                                    $refundTransaction[
                                        "balance_before"
                                    ],
                                    2
                                ) ?>
                            </dd>

                        </div>


                        <div class="flex justify-between gap-3">

                            <dt class="text-slate-500">
                                After
                            </dt>

                            <dd class="font-bold">
                                ₦<?= number_format(
                                    (float)
                                    $refundTransaction[
                                        "balance_after"
                                    ],
                                    2
                                ) ?>
                            </dd>

                        </div>


                        <div class="flex justify-between gap-3">

                            <dt class="text-slate-500">
                                Status
                            </dt>

                            <dd class="font-bold">
                                <?= e(
                                    ucfirst(
                                        $refundTransaction[
                                            "status"
                                        ]
                                    )
                                ) ?>
                            </dd>

                        </div>

                    </dl>

                <?php else: ?>

                    <p
                        class="mt-4 text-sm text-slate-500"
                    >
                        No refund has been linked to this order.
                    </p>

                <?php endif; ?>

            </div>

        </div>

    </section>



    <!-- PROVIDER HISTORY -->

    <section class="mt-7">

        <div class="mb-4">

            <p
                class="text-xs font-bold uppercase tracking-widest text-servora-600"
            >
                API Trail
            </p>

            <h2
                class="mt-1 text-xl font-black"
            >
                Provider transaction history
            </h2>

        </div>


        <?php if (!$providerTransactions): ?>

            <div
                class="rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm"
            >

                <p class="font-bold text-slate-700">
                    No provider transaction recorded
                </p>

                <p
                    class="mt-1 text-sm text-slate-500"
                >
                    This order has no supplier API activity in
                    the provider transaction log.
                </p>

            </div>

        <?php else: ?>

            <div class="space-y-4">

                <?php foreach (
                    $providerTransactions as $transaction
                ): ?>

                    <?php

                    $providerTxStatus =
                        strtolower(
                            trim(
                                (string)
                                $transaction["status"]
                            )
                        );

                    ?>

                    <article
                        class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
                    >

                        <div
                            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
                        >

                            <div>

                                <div
                                    class="flex flex-wrap items-center gap-2"
                                >

                                    <h3
                                        class="font-black text-slate-900"
                                    >
                                        Transaction
                                        #<?= (int)
                                            $transaction["id"]
                                        ?>
                                    </h3>


                                    <?php if (
                                        $providerTxStatus ===
                                        "successful"
                                    ): ?>

                                        <span
                                            class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700"
                                        >
                                            Successful
                                        </span>

                                    <?php elseif (
                                        $providerTxStatus ===
                                        "failed"
                                    ): ?>

                                        <span
                                            class="rounded-full bg-red-50 px-3 py-1 text-xs font-bold text-red-700"
                                        >
                                            Failed
                                        </span>

                                    <?php elseif (
                                        $providerTxStatus ===
                                        "unknown"
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

                                </div>


                                <p
                                    class="mt-1 text-xs text-slate-500"
                                >
                                    <?= e(
                                        ucfirst(
                                            str_replace(
                                                "_",
                                                " ",
                                                $transaction[
                                                    "action"
                                                ]
                                            )
                                        )
                                    ) ?>

                                    · Attempt

                                    <?= (int)
                                        $transaction[
                                            "attempt_no"
                                        ]
                                    ?>
                                </p>

                            </div>


                            <p
                                class="text-xs font-semibold text-slate-400"
                            >
                                <?= e(
                                    date(
                                        "d M Y, h:i A",
                                        strtotime(
                                            $transaction[
                                                "created_at"
                                            ]
                                        )
                                    )
                                ) ?>
                            </p>

                        </div>


                        <div
                            class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4"
                        >

                            <div>

                                <p
                                    class="text-xs font-bold uppercase tracking-wide text-slate-400"
                                >
                                    Provider
                                </p>

                                <p
                                    class="mt-1 text-sm font-bold"
                                >
                                    <?= !empty(
                                        $transaction[
                                            "provider_name"
                                        ]
                                    )
                                        ? e(
                                            $transaction[
                                                "provider_name"
                                            ]
                                        )
                                        : "—"
                                    ?>
                                </p>

                            </div>


                            <div>

                                <p
                                    class="text-xs font-bold uppercase tracking-wide text-slate-400"
                                >
                                    HTTP Status
                                </p>

                                <p
                                    class="mt-1 text-sm font-bold"
                                >
                                    <?= $transaction[
                                        "http_status"
                                    ] !== null
                                        ? (int)
                                            $transaction[
                                                "http_status"
                                            ]
                                        : "—"
                                    ?>
                                </p>

                            </div>


                            <div>

                                <p
                                    class="text-xs font-bold uppercase tracking-wide text-slate-400"
                                >
                                    Response Code
                                </p>

                                <p
                                    class="mt-1 break-all text-sm font-bold"
                                >
                                    <?= !empty(
                                        $transaction[
                                            "response_code"
                                        ]
                                    )
                                        ? e(
                                            $transaction[
                                                "response_code"
                                            ]
                                        )
                                        : "—"
                                    ?>
                                </p>

                            </div>


                            <div>

                                <p
                                    class="text-xs font-bold uppercase tracking-wide text-slate-400"
                                >
                                    Last Checked
                                </p>

                                <p
                                    class="mt-1 text-sm font-bold"
                                >

                                    <?php if (
                                        !empty(
                                            $transaction[
                                                "last_checked_at"
                                            ]
                                        )
                                    ): ?>

                                        <?= e(
                                            date(
                                                "d M Y, h:i A",
                                                strtotime(
                                                    $transaction[
                                                        "last_checked_at"
                                                    ]
                                                )
                                            )
                                        ) ?>

                                    <?php else: ?>

                                        —

                                    <?php endif; ?>

                                </p>

                            </div>

                        </div>


                        <div
                            class="mt-5 grid gap-4 lg:grid-cols-2"
                        >

                            <div
                                class="rounded-xl bg-slate-50 p-4"
                            >

                                <p
                                    class="text-xs font-bold uppercase tracking-wide text-slate-400"
                                >
                                    Subnext Request Reference
                                </p>

                                <p
                                    class="mt-2 break-all text-sm font-semibold text-slate-700"
                                >
                                    <?= e(
                                        $transaction[
                                            "request_reference"
                                        ]
                                    ) ?>
                                </p>

                            </div>


                            <div
                                class="rounded-xl bg-slate-50 p-4"
                            >

                                <p
                                    class="text-xs font-bold uppercase tracking-wide text-slate-400"
                                >
                                    Provider Reference
                                </p>

                                <p
                                    class="mt-2 break-all text-sm font-semibold text-slate-700"
                                >
                                    <?= !empty(
                                        $transaction[
                                            "provider_reference"
                                        ]
                                    )
                                        ? e(
                                            $transaction[
                                                "provider_reference"
                                            ]
                                        )
                                        : "—"
                                    ?>
                                </p>

                            </div>

                        </div>


                        <?php if (
                            !empty(
                                $transaction[
                                    "response_message"
                                ]
                            )
                        ): ?>

                            <div
                                class="mt-4 rounded-xl border border-slate-200 p-4"
                            >

                                <p
                                    class="text-xs font-bold uppercase tracking-wide text-slate-400"
                                >
                                    Supplier Response
                                </p>

                                <p
                                    class="mt-2 break-words text-sm leading-6 text-slate-700"
                                >
                                    <?= e(
                                        $transaction[
                                            "response_message"
                                        ]
                                    ) ?>
                                </p>

                            </div>

                        <?php endif; ?>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </section>

    <!-- ADMIN RESOLUTION FEEDBACK -->

    <?php if (
        ($_GET["success"] ?? "") === "order_confirmed"
    ): ?>

        <div
            class="mt-7 rounded-2xl border border-emerald-200 bg-emerald-50 p-4"
        >
            <p class="text-sm font-bold text-emerald-800">
                Supplier success was confirmed and the data order was marked successful.
            </p>
        </div>

    <?php elseif (
        !empty($_GET["error"])
    ): ?>

        <?php

        $resolutionError =
            (string) $_GET["error"];

        $resolutionErrorMessages = [
            "invalid_request" =>
                "The confirmation request was invalid or expired.",
            "provider_configuration" =>
                "Supplier configuration is unavailable.",
            "confirmation_failed" =>
                "The order could not be confirmed safely.",
            "not_high_confidence" =>
                "This order no longer has a unique high-confidence supplier match.",
            "candidate_missing" =>
                "The supplier transaction candidate is unavailable.",
            "candidate_not_confirmed" =>
                "The supplier candidate is no longer classified as high confidence.",
            "cross_order_collision" =>
                "Another Subnext order could match this supplier transaction.",
            "insufficient_evidence" =>
                "The supplier evidence is not strong enough to confirm this order.",
            "provider_reference_missing" =>
                "The supplier transaction has no usable provider reference.",
            "provider_reference_unavailable" =>
                "The supplier reference cannot be safely assigned.",
            "supplier_not_completed" =>
                "The supplier transaction is not explicitly confirmed as completed.",
            "order_not_processing" =>
                "This order is no longer processing.",
            "order_refunded" =>
                "This order already has a refund transaction.",
            "provider_reference_conflict" =>
                "The supplier reference is already linked to another order.",
            "order_changed" =>
                "The order changed while confirmation was being processed.",
            "reconciliation_error" =>
                "Supplier reconciliation could not be completed.",
            "reconciliation_failed" =>
                "Supplier reconciliation could not verify this order.",
            "database_error" =>
                "The order could not be updated safely."
        ];

        $resolutionErrorMessage =
            $resolutionErrorMessages[
                $resolutionError
            ]
            ?? "The order could not be confirmed safely.";

        ?>

        <div
            class="mt-7 rounded-2xl border border-red-200 bg-red-50 p-4"
        >
            <p class="text-sm font-bold text-red-800">
                <?= e($resolutionErrorMessage) ?>
            </p>
        </div>

    <?php endif; ?>


        <!-- SUPPLIER RECONCILIATION -->

    <?php if (
        $orderStatus === "processing" &&
        strtolower(
            trim(
                (string) ($order["provider_slug"] ?? "")
            )
        ) === "cheapdatahub"
    ): ?>

        <?php

        $reconciliationState =
            strtolower(
                trim(
                    (string) (
                        $reconciliation["state"]
                        ?? (
                            $reconciliationError
                            ? "unavailable"
                            : "unknown"
                        )
                    )
                )
            );

        $reconciliationMessage =
            $reconciliationError
            ?: (
                $reconciliation["message"]
                ?? "No reconciliation information is currently available."
            );

        $supplierTransactionCount =
            (int) (
                $reconciliation["supplier_record_count"]
                ?? 0
            );

        $candidateCount =
            (int) (
                $reconciliation["candidate_count"]
                ?? (
                    is_array(
                        $reconciliation["candidates"]
                        ?? null
                    )
                        ? count(
                            $reconciliation["candidates"]
                        )
                        : 0
                )
            );

        $highConfidenceCount =
            $reconciliationState === "high_confidence_candidate"
                ? 1
                : 0;

        $reconciliationCandidates =
            is_array(
                $reconciliation["candidates"]
                ?? null
            )
                ? $reconciliation["candidates"]
                : [];

        $bestCandidate =
            is_array(
                $reconciliation["candidate"]
                ?? null
            )
                ? $reconciliation["candidate"]
                : null;

        foreach (
            $reconciliationCandidates as $candidate
        ) {

            if (!is_array($candidate)) {
                continue;
            }

            $candidateClassification =
                strtolower(
                    trim(
                        (string) (
                            $candidate["classification"]
                            ?? ""
                        )
                    )
                );

            if (
                $candidateClassification ===
                "ambiguous" &&
                $bestCandidate === null
            ) {
                $bestCandidate = $candidate;
                break;
            }

            if (
                $candidateClassification ===
                "high_confidence" &&
                $bestCandidate === null
            ) {
                $bestCandidate = $candidate;
            }

            if ($bestCandidate === null) {
                $bestCandidate = $candidate;
            }
        }


        /*
        |--------------------------------------------------------------------------
        | RECONCILIATION BADGE
        |--------------------------------------------------------------------------
        */

        switch ($reconciliationState) {

            case "high_confidence":
            case "high_confidence_candidate":
                $reconciliationLabel =
                    "High Confidence Candidate";

                $reconciliationBadge =
                    "bg-emerald-100 text-emerald-800";

                $reconciliationBorder =
                    "border-emerald-200";

                $reconciliationBackground =
                    "bg-emerald-50";
                break;


            case "ambiguous":
                $reconciliationLabel =
                    "Ambiguous";

                $reconciliationBadge =
                    "bg-red-100 text-red-800";

                $reconciliationBorder =
                    "border-red-200";

                $reconciliationBackground =
                    "bg-red-50";
                break;


            case "possible":
            case "possible_match":
            case "possible_candidate":
                $reconciliationLabel =
                    "Possible Candidate";

                $reconciliationBadge =
                    "bg-amber-100 text-amber-800";

                $reconciliationBorder =
                    "border-amber-200";

                $reconciliationBackground =
                    "bg-amber-50";
                break;


            case "no_match":
                $reconciliationLabel =
                    "No Match";

                $reconciliationBadge =
                    "bg-slate-100 text-slate-700";

                $reconciliationBorder =
                    "border-slate-200";

                $reconciliationBackground =
                    "bg-slate-50";
                break;


            case "unavailable":
            case "provider_error":
            case "supplier_error":
            case "database_error":
            case "unsupported_provider":
            case "error":
                $reconciliationLabel =
                    "Unavailable";

                $reconciliationBadge =
                    "bg-purple-100 text-purple-800";

                $reconciliationBorder =
                    "border-purple-200";

                $reconciliationBackground =
                    "bg-purple-50";
                break;


            default:
                $reconciliationLabel =
                    ucwords(
                        str_replace(
                            "_",
                            " ",
                            $reconciliationState
                        )
                    );

                $reconciliationBadge =
                    "bg-slate-100 text-slate-700";

                $reconciliationBorder =
                    "border-slate-200";

                $reconciliationBackground =
                    "bg-slate-50";
                break;
        }

        ?>


        <section
            class="mt-7 rounded-2xl border <?= e($reconciliationBorder) ?> bg-white p-5 shadow-sm sm:p-6"
        >

            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
            >

                <div>

                    <p
                        class="text-xs font-bold uppercase tracking-widest text-servora-600"
                    >
                        Supplier Investigation
                    </p>

                    <h2
                        class="mt-1 text-xl font-black"
                    >
                        Supplier Reconciliation
                    </h2>

                    <p
                        class="mt-2 max-w-3xl text-sm leading-6 text-slate-500"
                    >
                        Subnext is comparing this uncertain
                        processing order with the supplier's
                        transaction history. This investigation
                        is read-only.
                    </p>

                </div>


                <div>

                    <span
                        class="inline-flex rounded-full px-4 py-2 text-xs font-black <?= e($reconciliationBadge) ?>"
                    >
                        <?= e($reconciliationLabel) ?>
                    </span>

                </div>

            </div>



            <!-- RECONCILIATION RESULT -->

            <div
                class="mt-5 rounded-2xl border <?= e($reconciliationBorder) ?> <?= e($reconciliationBackground) ?> p-4"
            >

                <p
                    class="text-xs font-black uppercase tracking-wide text-slate-500"
                >
                    Reconciliation Result
                </p>

                <p
                    class="mt-2 text-sm font-semibold leading-6 text-slate-800"
                >
                    <?= e($reconciliationMessage) ?>
                </p>

            </div>



            <!-- RECONCILIATION COUNTS -->

            <div
                class="mt-5 grid gap-3 sm:grid-cols-3"
            >

                <div
                    class="rounded-2xl border border-slate-200 bg-slate-50 p-4"
                >

                    <p
                        class="text-xs font-bold uppercase tracking-wide text-slate-400"
                    >
                        Supplier History Matches
                    </p>

                    <p
                        class="mt-2 text-2xl font-black text-slate-900"
                    >
                        <?= $supplierTransactionCount ?>
                    </p>

                </div>


                <div
                    class="rounded-2xl border border-slate-200 bg-slate-50 p-4"
                >

                    <p
                        class="text-xs font-bold uppercase tracking-wide text-slate-400"
                    >
                        Candidate Records
                    </p>

                    <p
                        class="mt-2 text-2xl font-black text-slate-900"
                    >
                        <?= $candidateCount ?>
                    </p>

                </div>


                <div
                    class="rounded-2xl border border-slate-200 bg-slate-50 p-4"
                >

                    <p
                        class="text-xs font-bold uppercase tracking-wide text-slate-400"
                    >
                        High Confidence
                    </p>

                    <p
                        class="mt-2 text-2xl font-black text-slate-900"
                    >
                        <?= $highConfidenceCount ?>
                    </p>

                </div>

            </div>



            <?php if (
                is_array($bestCandidate)
            ): ?>

                <?php

                $candidateMatches =
                    is_array(
                        $bestCandidate["matches"]
                        ?? null
                    )
                        ? $bestCandidate["matches"]
                        : [];

                $candidateSupplier =
                    is_array(
                        $bestCandidate["supplier"]
                        ?? null
                    )
                        ? $bestCandidate["supplier"]
                        : $bestCandidate;


                /*
                |--------------------------------------------------------------------------
                | MATCH EVIDENCE
                |--------------------------------------------------------------------------
                */

                $phoneMatch =
                    (bool) (
                        $bestCandidate["phone_match"]
                        ?? $candidateMatches["phone"]
                        ?? false
                    );

                $costMatch =
                    (bool) (
                        $bestCandidate["cost_match"]
                        ?? $bestCandidate["amount_match"]
                        ?? $candidateMatches["cost"]
                        ?? $candidateMatches["amount"]
                        ?? false
                    );

                $dataMatch =
                    (bool) (
                        $bestCandidate["data_match"]
                        ?? $candidateMatches["data"]
                        ?? false
                    );

                $networkMatch =
                    (bool) (
                        $bestCandidate["network_match"]
                        ?? $candidateMatches["network"]
                        ?? false
                    );

                $timeMatch =
                    (bool) (
                        $bestCandidate["time_match"]
                        ?? $candidateMatches["time"]
                        ?? false
                    );

                $referenceAvailable =
                    (bool) (
                        $bestCandidate[
                            "provider_reference_available"
                        ]
                        ?? $bestCandidate[
                            "reference_available"
                        ]
                        ?? $candidateMatches[
                            "provider_reference_available"
                        ]
                        ?? false
                    );


                /*
                |--------------------------------------------------------------------------
                | SUPPLIER VALUES
                |--------------------------------------------------------------------------
                */

                $supplierItem =
                    $candidateSupplier["item"]
                    ?? $bestCandidate["supplier_item"]
                    ?? "—";

                $supplierRecipient =
                    $candidateSupplier["recipient"]
                    ?? $bestCandidate[
                        "supplier_recipient"
                    ]
                    ?? "—";

                $supplierAmount =
                    $candidateSupplier["amount"]
                    ?? $bestCandidate[
                        "supplier_amount"
                    ]
                    ?? null;

                $supplierStatus =
                    $candidateSupplier["category"]
                    ?? $candidateSupplier["status"]
                    ?? $bestCandidate[
                        "supplier_status"
                    ]
                    ?? "—";

                $supplierTimeline =
                    $candidateSupplier["timeline"]
                    ?? $bestCandidate[
                        "supplier_timeline"
                    ]
                    ?? null;

                $supplierReference =
                    $candidateSupplier["tx_ref"]
                    ?? $candidateSupplier[
                        "reference"
                    ]
                    ?? $bestCandidate[
                        "supplier_reference"
                    ]
                    ?? null;

                $candidateScore =
                    (int) (
                        $bestCandidate["score"]
                        ?? 0
                    );

                $candidateClassification =
                    strtolower(
                        trim(
                            (string) (
                                $bestCandidate[
                                    "classification"
                                ]
                                ?? "possible"
                            )
                        )
                    );

                $hasCrossOrderCollision =
                    (bool) (
                        $bestCandidate[
                            "has_cross_order_collision"
                        ]
                        ?? false
                    );

                $crossOrderCollisions =
                    is_array(
                        $bestCandidate[
                            "cross_order_collisions"
                        ]
                        ?? null
                    )
                        ? $bestCandidate[
                            "cross_order_collisions"
                        ]
                        : [];

                $crossOrderCollisionCount =
                    (int) (
                        $bestCandidate[
                            "cross_order_collision_count"
                        ]
                        ?? count(
                            $crossOrderCollisions
                        )
                    );

                ?>


                <?php

                $canConfirmSupplierSuccess =
                    $reconciliationState ===
                        "high_confidence_candidate" &&
                    $candidateClassification ===
                        "high_confidence" &&
                    !$hasCrossOrderCollision &&
                    $phoneMatch &&
                    $costMatch &&
                    $dataMatch &&
                    $networkMatch &&
                    $referenceAvailable &&
                    !empty(
                        $bestCandidate[
                            "provider_reference"
                        ]
                    );

                ?>


                <?php if (
                    $canConfirmSupplierSuccess
                ): ?>

                    <div
                        class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-5"
                    >

                        <h3
                            class="font-black text-emerald-900"
                        >
                            High-confidence supplier match
                        </h3>

                        <p
                            class="mt-2 text-sm leading-6 text-emerald-800"
                        >
                            Subnext found one unique supplier transaction with strong matching evidence. Confirmation will re-check the supplier evidence on the server before changing the order.
                        </p>

                        <form
                            method="POST"
                            action="confirm_data_order_success.php"
                            class="mt-4"
                            onsubmit="return confirm('Confirm this supplier transaction as successful? Subnext will verify the evidence again before updating the order.');"
                        >

                            <input
                                type="hidden"
                                name="order_id"
                                value="<?= (int) $orderId ?>"
                            >

                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= e($csrfToken) ?>"
                            >

                            <button
                                type="submit"
                                class="inline-flex items-center justify-center rounded-xl bg-emerald-600 px-5 py-3 text-sm font-black text-white shadow-sm transition hover:bg-emerald-700"
                            >
                                Confirm Supplier Success
                            </button>

                        </form>

                    </div>

                <?php endif; ?>


                <!-- BEST CANDIDATE -->

                <div
                    class="mt-6 overflow-hidden rounded-2xl border border-slate-200"
                >

                    <div
                        class="border-b border-slate-200 bg-slate-50 p-4 sm:p-5"
                    >

                        <div
                            class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                        >

                            <div>

                                <p
                                    class="text-xs font-bold uppercase tracking-wide text-slate-400"
                                >
                                    Candidate Evidence
                                </p>

                                <h3
                                    class="mt-1 font-black text-slate-900"
                                >
                                    Best supplier candidate
                                </h3>

                            </div>


                            <div
                                class="flex flex-wrap gap-2"
                            >

                                <span
                                    class="rounded-full bg-slate-200 px-3 py-1 text-xs font-black text-slate-700"
                                >
                                    Score
                                    <?= $candidateScore ?>
                                </span>


                                <?php if (
                                    $candidateClassification ===
                                    "ambiguous"
                                ): ?>

                                    <span
                                        class="rounded-full bg-red-100 px-3 py-1 text-xs font-black text-red-700"
                                    >
                                        Ambiguous
                                    </span>

                                <?php elseif (
                                    $candidateClassification ===
                                    "high_confidence"
                                ): ?>

                                    <span
                                        class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-black text-emerald-700"
                                    >
                                        High Confidence
                                    </span>

                                <?php else: ?>

                                    <span
                                        class="rounded-full bg-amber-100 px-3 py-1 text-xs font-black text-amber-700"
                                    >
                                        Possible
                                    </span>

                                <?php endif; ?>

                            </div>

                        </div>

                    </div>



                    <!-- MATCH CHECKS -->

                    <div class="p-4 sm:p-5">

                        <p
                            class="text-xs font-black uppercase tracking-wide text-slate-400"
                        >
                            Match Evidence
                        </p>


                        <div
                            class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6"
                        >

                            <?php

                            $matchChecks = [
                                "Phone" => $phoneMatch,
                                "Cost" => $costMatch,
                                "Data" => $dataMatch,
                                "Network" => $networkMatch,
                                "Time" => $timeMatch,
                                "Ref Free" => $referenceAvailable
                            ];

                            ?>

                            <?php foreach (
                                $matchChecks
                                as $matchLabel => $matchValue
                            ): ?>

                                <div
                                    class="rounded-xl border border-slate-200 p-3"
                                >

                                    <p
                                        class="text-xs font-bold text-slate-500"
                                    >
                                        <?= e($matchLabel) ?>
                                    </p>

                                    <?php if ($matchValue): ?>

                                        <p
                                            class="mt-2 text-sm font-black text-emerald-600"
                                        >
                                            Yes
                                        </p>

                                    <?php else: ?>

                                        <p
                                            class="mt-2 text-sm font-black text-red-600"
                                        >
                                            No
                                        </p>

                                    <?php endif; ?>

                                </div>

                            <?php endforeach; ?>

                        </div>



                        <!-- SUPPLIER RECORD -->

                        <div
                            class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
                        >

                            <div
                                class="rounded-xl bg-slate-50 p-4"
                            >

                                <p
                                    class="text-xs font-bold uppercase tracking-wide text-slate-400"
                                >
                                    Supplier Item
                                </p>

                                <p
                                    class="mt-2 break-words text-sm font-bold text-slate-800"
                                >
                                    <?= e($supplierItem) ?>
                                </p>

                            </div>


                            <div
                                class="rounded-xl bg-slate-50 p-4"
                            >

                                <p
                                    class="text-xs font-bold uppercase tracking-wide text-slate-400"
                                >
                                    Recipient
                                </p>

                                <p
                                    class="mt-2 break-all text-sm font-bold text-slate-800"
                                >
                                    <?= e(
                                        $supplierRecipient
                                    ) ?>
                                </p>

                            </div>


                            <div
                                class="rounded-xl bg-slate-50 p-4"
                            >

                                <p
                                    class="text-xs font-bold uppercase tracking-wide text-slate-400"
                                >
                                    Supplier Amount
                                </p>

                                <p
                                    class="mt-2 text-sm font-bold text-slate-800"
                                >

                                    <?php if (
                                        $supplierAmount !== null &&
                                        is_numeric(
                                            $supplierAmount
                                        )
                                    ): ?>

                                        ₦<?= number_format(
                                            (float)
                                            $supplierAmount,
                                            2
                                        ) ?>

                                    <?php else: ?>

                                        —

                                    <?php endif; ?>

                                </p>

                            </div>


                            <div
                                class="rounded-xl bg-slate-50 p-4"
                            >

                                <p
                                    class="text-xs font-bold uppercase tracking-wide text-slate-400"
                                >
                                    Supplier Status
                                </p>

                                <p
                                    class="mt-2 text-sm font-bold text-slate-800"
                                >
                                    <?= e(
                                        ucfirst(
                                            (string)
                                            $supplierStatus
                                        )
                                    ) ?>
                                </p>

                            </div>


                            <div
                                class="rounded-xl bg-slate-50 p-4"
                            >

                                <p
                                    class="text-xs font-bold uppercase tracking-wide text-slate-400"
                                >
                                    Timeline
                                </p>

                                <p
                                    class="mt-2 break-words text-sm font-bold text-slate-800"
                                >
                                    <?= !empty(
                                        $supplierTimeline
                                    )
                                        ? e(
                                            $supplierTimeline
                                        )
                                        : "—"
                                    ?>
                                </p>

                            </div>


                            <div
                                class="rounded-xl bg-slate-50 p-4"
                            >

                                <p
                                    class="text-xs font-bold uppercase tracking-wide text-slate-400"
                                >
                                    Supplier Reference
                                </p>

                                <p
                                    class="mt-2 break-all text-sm font-bold text-slate-800"
                                >
                                    <?= !empty(
                                        $supplierReference
                                    )
                                        ? e(
                                            $supplierReference
                                        )
                                        : "—"
                                    ?>
                                </p>

                            </div>

                        </div>



                        <!-- CROSS ORDER COLLISION -->

                        <?php if (
                            $hasCrossOrderCollision
                        ): ?>

                            <div
                                class="mt-6 rounded-2xl border border-red-200 bg-red-50 p-4"
                            >

                                <div
                                    class="flex gap-3"
                                >

                                    <div class="text-xl">
                                        ⚠️
                                    </div>

                                    <div>

                                        <h4
                                            class="font-black text-red-900"
                                        >
                                            Other Subnext orders also match
                                        </h4>

                                        <p
                                            class="mt-1 text-sm leading-6 text-red-800"
                                        >
                                            This supplier record cannot safely
                                            be assigned to this order because
                                            <?= $crossOrderCollisionCount ?>
                                            other Subnext
                                            <?= $crossOrderCollisionCount === 1
                                                ? "order also matches"
                                                : "orders also match"
                                            ?>
                                            the same evidence.
                                        </p>

                                    </div>

                                </div>


                                <?php if (
                                    $crossOrderCollisions
                                ): ?>

                                    <div
                                        class="mt-4 space-y-3"
                                    >

                                        <?php foreach (
                                            $crossOrderCollisions
                                            as $collision
                                        ): ?>

                                            <?php if (
                                                !is_array(
                                                    $collision
                                                )
                                            ) {
                                                continue;
                                            } ?>

                                            <div
                                                class="rounded-xl border border-red-200 bg-white p-4"
                                            >

                                                <div
                                                    class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                                                >

                                                    <div>

                                                        <p
                                                            class="break-all text-sm font-black text-slate-900"
                                                        >
                                                            <?= e(
                                                                $collision[
                                                                    "order_reference"
                                                                ]
                                                                ?? "Unknown order"
                                                            ) ?>
                                                        </p>

                                                        <p
                                                            class="mt-1 text-xs font-semibold text-slate-500"
                                                        >
                                                            Status:
                                                            <?= e(
                                                                ucfirst(
                                                                    (string) (
                                                                        $collision[
                                                                            "status"
                                                                        ]
                                                                        ?? "unknown"
                                                                    )
                                                                )
                                                            ) ?>
                                                        </p>

                                                    </div>


                                                    <?php if (
                                                        !empty(
                                                            $collision[
                                                                "order_id"
                                                            ]
                                                        )
                                                    ): ?>

                                                        <a
                                                            href="data_order_view.php?id=<?= (int) $collision["order_id"] ?>"
                                                            class="inline-flex items-center justify-center rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-black text-red-700 transition hover:bg-red-100"
                                                        >
                                                            View Order
                                                        </a>

                                                    <?php endif; ?>

                                                </div>


                                                <div
                                                    class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
                                                >

                                                    <div>

                                                        <p
                                                            class="text-xs font-bold text-slate-400"
                                                        >
                                                            Plan
                                                        </p>

                                                        <p
                                                            class="mt-1 text-xs font-bold text-slate-700"
                                                        >
                                                            <?= e(
                                                                $collision[
                                                                    "plan_name"
                                                                ]
                                                                ?? "—"
                                                            ) ?>
                                                        </p>

                                                    </div>


                                                    <div>

                                                        <p
                                                            class="text-xs font-bold text-slate-400"
                                                        >
                                                            Data
                                                        </p>

                                                        <p
                                                            class="mt-1 text-xs font-bold text-slate-700"
                                                        >
                                                            <?= e(
                                                                $collision[
                                                                    "data_amount"
                                                                ]
                                                                ?? "—"
                                                            ) ?>
                                                        </p>

                                                    </div>


                                                    <div>

                                                        <p
                                                            class="text-xs font-bold text-slate-400"
                                                        >
                                                            Supplier Cost
                                                        </p>

                                                        <p
                                                            class="mt-1 text-xs font-bold text-slate-700"
                                                        >
                                                            <?php if (
                                                                isset(
                                                                    $collision[
                                                                        "cost_price"
                                                                    ]
                                                                ) &&
                                                                is_numeric(
                                                                    $collision[
                                                                        "cost_price"
                                                                    ]
                                                                )
                                                            ): ?>

                                                                ₦<?= number_format(
                                                                    (float)
                                                                    $collision[
                                                                        "cost_price"
                                                                    ],
                                                                    2
                                                                ) ?>

                                                            <?php else: ?>

                                                                —

                                                            <?php endif; ?>
                                                        </p>

                                                    </div>


                                                    <div>

                                                        <p
                                                            class="text-xs font-bold text-slate-400"
                                                        >
                                                            Created
                                                        </p>

                                                        <p
                                                            class="mt-1 text-xs font-bold text-slate-700"
                                                        >
                                                            <?= !empty(
                                                                $collision[
                                                                    "created_at"
                                                                ]
                                                            )
                                                                ? e(
                                                                    $collision[
                                                                        "created_at"
                                                                    ]
                                                                )
                                                                : "—"
                                                            ?>
                                                        </p>

                                                    </div>

                                                </div>

                                            </div>

                                        <?php endforeach; ?>

                                    </div>

                                <?php endif; ?>

                            </div>

                        <?php endif; ?>


                    </div>

                </div>

            <?php endif; ?>



            <!-- NO AUTOMATIC ACTION -->

            <div
                class="mt-6 rounded-2xl border border-purple-200 bg-purple-50 p-4"
            >

                <p
                    class="font-black text-purple-900"
                >
                    Evidence only — no automatic action
                </p>

                <p
                    class="mt-2 text-sm leading-6 text-purple-800"
                >
                    This reconciliation check does not change
                    the order status, retry the supplier purchase,
                    refund the customer, alter the wallet, or assign
                    a supplier transaction to this order.
                </p>

            </div>

        </section>

    <?php endif; ?>



    <!-- SAFETY PANEL -->

    <section
        class="mt-7 rounded-2xl border border-purple-200 bg-purple-50 p-5"
    >

        <h2
            class="font-black text-purple-900"
        >
            Reconciliation Safety
        </h2>

        <p
            class="mt-2 max-w-4xl text-sm leading-6 text-purple-800"
        >
            This page is currently read-only. Do not resend or refund an
            uncertain data transaction simply because it has remained in
            processing. The supplier's final result must be established first
            to prevent duplicate delivery or an incorrect wallet refund.
        </p>

    </section>



    <!-- NAVIGATION -->

    <section
        class="mt-8 border-t border-slate-200 pt-6"
    >

        <div
            class="flex flex-col gap-3 sm:flex-row"
        >

            <a
                href="data_orders.php"
                class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-bold text-slate-700 transition hover:bg-slate-50"
            >
                ← Data Orders
            </a>


            <a
                href="provider_transactions.php"
                class="inline-flex items-center justify-center rounded-xl bg-servora-700 px-5 py-3 text-sm font-bold text-white transition hover:bg-servora-800"
            >
                Provider Transactions
            </a>


            <a
                href="data_control.php"
                class="inline-flex items-center justify-center rounded-xl border border-servora-200 bg-servora-50 px-5 py-3 text-sm font-bold text-servora-700 transition hover:bg-servora-100"
            >
                Data Control
            </a>

        </div>

    </section>


    <footer
        class="py-8 text-center text-xs text-slate-400"
    >
        Subnext Data Order Investigation
    </footer>


</main>

</body>

</html>