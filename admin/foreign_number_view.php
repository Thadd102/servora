<?php

require_once "../config/database.php";
require_once "../includes/admin_auth.php";
require_once "../includes/BrandAssetHelper.php";

/*
|--------------------------------------------------------------------------
| ADMIN
|--------------------------------------------------------------------------
*/

$adminName = $_SESSION["full_name"] ?? "Admin";

/*
|--------------------------------------------------------------------------
| GET ORDER ID
|--------------------------------------------------------------------------
*/

$orderId = filter_var(
    $_GET["id"] ?? null,
    FILTER_VALIDATE_INT
);

if (!$orderId || $orderId < 1) {
    http_response_code(400);
    exit("Invalid order ID.");
}

/*
|--------------------------------------------------------------------------
| LOAD ORDER + CLIENT
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        vno.id,
        vno.order_reference,
        vno.client_request_token,
        vno.user_id,
        vno.service_code,
        vno.service_name,
        vno.country_code,
        vno.country_name,
        vno.operator_code,
        vno.operator_name,
        vno.provider,
        vno.provider_order_id,
        vno.provider_phone_number,
        vno.provider_cost,
        vno.selling_price,
        vno.status,
        vno.sms_code,
        vno.wallet_transaction_id,
        vno.provider_message,
        vno.created_at,
        vno.updated_at,
        vno.completed_at,

        u.full_name AS client_name,
        u.email AS client_email,
        u.phone AS client_phone,
        u.status AS client_status

    FROM virtual_number_orders AS vno

    LEFT JOIN users AS u
        ON u.id = vno.user_id

    WHERE vno.id = ?

    LIMIT 1
");

$stmt->execute([$orderId]);

$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    http_response_code(404);
    exit("Foreign number order not found.");
}

/*
|--------------------------------------------------------------------------
| LOAD PROVIDER TRANSACTIONS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        virtual_number_order_id,
        provider,
        action,
        provider_order_id,
        http_status,
        provider_status,
        outcome,
        message,
        created_at

    FROM virtual_number_provider_transactions

    WHERE virtual_number_order_id = ?

    ORDER BY id DESC
");

$stmt->execute([$orderId]);

$providerTransactions =
    $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| WALLET TRANSACTION
|--------------------------------------------------------------------------
*/

$walletTransaction = null;

if (!empty($order["wallet_transaction_id"])) {

    $stmt = $pdo->prepare("
        SELECT
            id,
            type,
            amount,
            balance_before,
            balance_after,
            reference,
            description,
            status
        FROM wallet_transactions
        WHERE id = ?
          AND user_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $order["wallet_transaction_id"],
        $order["user_id"]
    ]);

    $walletTransaction =
        $stmt->fetch(PDO::FETCH_ASSOC);
}

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES,
            "UTF-8"
        );
    }
}

if (!function_exists('money')) {
    function money($amount): string
    {
        return "₦" . number_format(
            (float) $amount,
            2
        );
    }
}

if (!function_exists('formatDate')) {
    function formatDate(?string $date): string
    {
        if (!$date) {
            return "—";
        }

    $timestamp = strtotime($date);

    if ($timestamp === false) {
        return "—";
    }

    return date(
        "d M Y, h:i A",
        $timestamp
    );
    }
}

function displayValue($value): string
{
    if ($value === null) {
        return "—";
    }

    $value = trim((string) $value);

    return $value !== ""
        ? $value
        : "—";
}

function statusConfig(string $status): array
{
    return match (strtolower($status)) {

        "completed" => [
            "label" => "Completed",
            "class" =>
                "bg-emerald-50 text-emerald-700 ring-emerald-600/20"
        ],

        "waiting_sms" => [
            "label" => "Waiting for OTP",
            "class" =>
                "bg-amber-50 text-amber-700 ring-amber-600/20"
        ],

        "processing" => [
            "label" => "Processing",
            "class" =>
                "bg-blue-50 text-blue-700 ring-blue-600/20"
        ],

        "failed" => [
            "label" => "Failed",
            "class" =>
                "bg-red-50 text-red-700 ring-red-600/20"
        ],

        "expired" => [
            "label" => "Expired",
            "class" =>
                "bg-red-50 text-red-700 ring-red-600/20"
        ],

        "cancelled" => [
            "label" => "Cancelled",
            "class" =>
                "bg-red-50 text-red-700 ring-red-600/20"
        ],

        "refunded" => [
            "label" => "Refunded",
            "class" =>
                "bg-violet-50 text-violet-700 ring-violet-600/20"
        ],

        "unknown" => [
            "label" => "Unknown",
            "class" =>
                "bg-slate-100 text-slate-700 ring-slate-600/20"
        ],

        default => [
            "label" => ucfirst($status),
            "class" =>
                "bg-slate-100 text-slate-700 ring-slate-600/20"
        ]
    };
}

function transactionOutcomeConfig(
    string $outcome
): array {

    return match (strtolower($outcome)) {

        "successful" => [
            "label" => "Successful",
            "class" =>
                "bg-emerald-50 text-emerald-700"
        ],

        "failed" => [
            "label" => "Failed",
            "class" =>
                "bg-red-50 text-red-700"
        ],

        default => [
            "label" => "Unknown",
            "class" =>
                "bg-slate-100 text-slate-700"
        ]
    };
}

function actionLabel(string $action): string
{
    return match ($action) {
        "purchase" => "Purchase",
        "status_check" => "Status Check",
        "cancel" => "Cancellation",
        default => ucwords(
            str_replace("_", " ", $action)
        )
    };
}

/*
|--------------------------------------------------------------------------
| CALCULATIONS
|--------------------------------------------------------------------------
*/

$statusInfo = statusConfig(
    (string) $order["status"]
);

$providerCost =
    (float) $order["provider_cost"];

$sellingPrice =
    (float) $order["selling_price"];

$profit = round(
    $sellingPrice - $providerCost,
    2
);

$profitPercentage = 0;

if ($providerCost > 0) {
    $profitPercentage =
        ($profit / $providerCost) * 100;
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

    <meta
        name="robots"
        content="noindex,nofollow"
    >

    <title>
        <?= e($order["order_reference"]) ?>
        | Subnext
    </title>

    <!-- Precompiled Production Stylesheet -->
    <link rel="stylesheet" href="../assets/css/style.css">

</head>

<body class="min-h-screen bg-slate-50 text-slate-900">

<main
    class="mx-auto w-full max-w-7xl
    px-4 py-5 sm:px-6 sm:py-8 lg:px-8"
>

    <!-- HEADER -->

    <section
        class="relative overflow-hidden
        rounded-3xl bg-gradient-to-br
        from-servora-800 via-servora-700
        to-servora-500 p-6 text-white
        shadow-xl sm:p-8"
    >

        <div class="relative z-10">

            <div
                class="mb-6 flex flex-wrap
                items-center justify-between gap-3"
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
                            Subnext
                        </p>

                        <p
                            class="text-xs text-white/50"
                        >
                            Foreign Number Management
                        </p>

                    </div>

                </div>

                <a
                    href="foreign_number_orders.php"
                    class="rounded-xl border
                    border-white/15 bg-white/10
                    px-4 py-2 text-xs font-bold
                    backdrop-blur transition
                    hover:bg-white/15"
                >
                    ← All Orders
                </a>

            </div>

            <div
                class="flex flex-wrap
                items-center gap-3"
            >

                <h1
                    class="text-2xl font-black
                    tracking-tight sm:text-4xl"
                >
                    Order Details
                </h1>

                <span
                    class="rounded-full px-3 py-1.5
                    text-xs font-bold ring-1 ring-inset
                    <?= e($statusInfo["class"]) ?>"
                >
                    <?= e($statusInfo["label"]) ?>
                </span>

            </div>

            <div class="mt-4 flex flex-wrap items-center gap-2.5">
                <div class="inline-flex items-center gap-2 rounded-xl bg-white/10 px-3 py-1.5 backdrop-blur border border-white/15 text-xs font-bold text-white">
                    <?= BrandAssetHelper::renderServiceLogo($order["service_code"] ?? '', 'sm', true) ?>
                    <span><?= e($order["service_name"]) ?></span>
                </div>
                <div class="inline-flex items-center gap-2 rounded-xl bg-white/10 px-3 py-1.5 backdrop-blur border border-white/15 text-xs font-bold text-white">
                    <?= BrandAssetHelper::renderCountryFlag($order["country_code"] ?? '', $order["country_name"] ?? '', 'sm', true) ?>
                    <span><?= e($order["country_name"]) ?></span>
                </div>
            </div>

            <p
                class="mt-3 break-all text-sm
                font-semibold text-white/75"
            >
                <?= e($order["order_reference"]) ?>
            </p>

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

    <!-- SUMMARY -->

    <section
        class="mt-6 grid grid-cols-2
        gap-3 sm:gap-5 lg:grid-cols-4"
    >

        <div
            class="rounded-2xl border
            border-slate-200 bg-white
            p-4 shadow-sm sm:p-5"
        >

            <p
                class="text-xs font-bold uppercase
                tracking-wide text-slate-400"
            >
                Selling Price
            </p>

            <p
                class="mt-2 text-xl font-black
                text-slate-900 sm:text-2xl"
            >
                <?= money($sellingPrice) ?>
            </p>

        </div>

        <div
            class="rounded-2xl border
            border-slate-200 bg-white
            p-4 shadow-sm sm:p-5"
        >

            <p
                class="text-xs font-bold uppercase
                tracking-wide text-slate-400"
            >
                Provider Cost
            </p>

            <p
                class="mt-2 text-xl font-black
                text-slate-900 sm:text-2xl"
            >
                <?= money($providerCost) ?>
            </p>

        </div>

        <div
            class="rounded-2xl border
            border-slate-200 bg-white
            p-4 shadow-sm sm:p-5"
        >

            <p
                class="text-xs font-bold uppercase
                tracking-wide text-slate-400"
            >
                Profit
            </p>

            <p
                class="mt-2 text-xl font-black
                text-servora-700 sm:text-2xl"
            >
                <?= money($profit) ?>
            </p>

            <p
                class="mt-1 text-xs text-slate-400"
            >
                <?= number_format(
                    $profitPercentage,
                    1
                ) ?>% markup on provider cost
            </p>

        </div>

        <div
            class="rounded-2xl border
            border-slate-200 bg-white
            p-4 shadow-sm sm:p-5"
        >

            <p
                class="text-xs font-bold uppercase
                tracking-wide text-slate-400"
            >
                Provider
            </p>

            <p
                class="mt-2 text-xl font-black
                text-slate-900 sm:text-2xl"
            >
                <?= e(
                    ucfirst(
                        (string) $order["provider"]
                    )
                ) ?>
            </p>

        </div>

    </section>

    <!-- DETAILS GRID -->

    <section
        class="mt-6 grid gap-5
        lg:grid-cols-2"
    >

        <!-- ORDER INFORMATION -->

        <article
            class="rounded-2xl border
            border-slate-200 bg-white
            p-5 shadow-sm sm:p-6"
        >

            <div class="mb-5">

                <p
                    class="text-xs font-bold uppercase
                    tracking-widest text-servora-600"
                >
                    Order
                </p>

                <h2
                    class="mt-1 text-xl font-black
                    text-slate-900"
                >
                    Order Information
                </h2>

            </div>

            <div class="space-y-4">

                <div
                    class="flex items-start
                    justify-between gap-4
                    border-b border-slate-100 pb-4"
                >
                    <span class="text-sm text-slate-500">
                        Reference
                    </span>

                    <span
                        class="max-w-[65%] break-all
                        text-right text-sm font-bold
                        text-slate-900"
                    >
                        <?= e(
                            $order["order_reference"]
                        ) ?>
                    </span>
                </div>

                <div
                    class="flex items-center
                    justify-between gap-4
                    border-b border-slate-100 pb-4"
                >
                    <span class="text-sm text-slate-500">
                        Service
                    </span>

                    <span
                        class="inline-flex items-center gap-2 text-right text-sm
                        font-bold text-slate-900"
                    >
                        <?= BrandAssetHelper::renderServiceLogo($order["service_code"] ?? '', 'sm', true) ?>
                        <span><?= e($order["service_name"]) ?></span>
                    </span>
                </div>

                <div
                    class="flex items-center
                    justify-between gap-4
                    border-b border-slate-100 pb-4"
                >
                    <span class="text-sm text-slate-500">
                        Country
                    </span>

                    <span
                        class="inline-flex items-center gap-2 text-right text-sm
                        font-bold text-slate-900"
                    >
                        <?= BrandAssetHelper::renderCountryFlag($order["country_code"] ?? '', $order["country_name"] ?? '', 'sm', true) ?>
                        <span><?= e($order["country_name"]) ?></span>

                        <?php if (!empty($order["country_code"])): ?>
                            <span class="text-xs text-slate-400 font-semibold">(<?= e(strtoupper((string) $order["country_code"])) ?>)</span>
                        <?php endif; ?>
                    </span>
                </div>

                <div
                    class="flex items-start
                    justify-between gap-4
                    border-b border-slate-100 pb-4"
                >
                    <span class="text-sm text-slate-500">
                        Operator
                    </span>

                    <span
                        class="text-right text-sm
                        font-bold text-slate-900"
                    >
                        <?= e(
                            displayValue(
                                $order["operator_name"]
                            )
                        ) ?>
                    </span>
                </div>

                <div
                    class="flex items-start
                    justify-between gap-4
                    border-b border-slate-100 pb-4"
                >
                    <span class="text-sm text-slate-500">
                        Phone Number
                    </span>

                    <span
                        class="text-right text-sm
                        font-black text-slate-900"
                    >
                        <?= e(
                            displayValue(
                                $order[
                                    "provider_phone_number"
                                ]
                            )
                        ) ?>
                    </span>
                </div>

                <div
                    class="flex items-start
                    justify-between gap-4"
                >
                    <span class="text-sm text-slate-500">
                        Status
                    </span>

                    <span
                        class="rounded-full px-2.5
                        py-1 text-xs font-bold
                        ring-1 ring-inset
                        <?= e(
                            $statusInfo["class"]
                        ) ?>"
                    >
                        <?= e(
                            $statusInfo["label"]
                        ) ?>
                    </span>
                </div>

            </div>

        </article>

        <!-- CLIENT -->

        <article
            class="rounded-2xl border
            border-slate-200 bg-white
            p-5 shadow-sm sm:p-6"
        >

            <div class="mb-5">

                <p
                    class="text-xs font-bold uppercase
                    tracking-widest text-servora-600"
                >
                    Customer
                </p>

                <h2
                    class="mt-1 text-xl font-black
                    text-slate-900"
                >
                    Client Information
                </h2>

            </div>

            <div class="space-y-4">

                <div
                    class="flex items-start
                    justify-between gap-4
                    border-b border-slate-100 pb-4"
                >
                    <span class="text-sm text-slate-500">
                        Client ID
                    </span>

                    <span
                        class="text-sm font-bold
                        text-slate-900"
                    >
                        #<?= (int) $order["user_id"] ?>
                    </span>
                </div>

                <div
                    class="flex items-start
                    justify-between gap-4
                    border-b border-slate-100 pb-4"
                >
                    <span class="text-sm text-slate-500">
                        Name
                    </span>

                    <span
                        class="text-right text-sm
                        font-bold text-slate-900"
                    >
                        <?= e(
                            displayValue(
                                $order["client_name"]
                            )
                        ) ?>
                    </span>
                </div>

                <div
                    class="flex items-start
                    justify-between gap-4
                    border-b border-slate-100 pb-4"
                >
                    <span class="text-sm text-slate-500">
                        Email
                    </span>

                    <span
                        class="break-all text-right
                        text-sm font-semibold
                        text-slate-700"
                    >
                        <?= e(
                            displayValue(
                                $order["client_email"]
                            )
                        ) ?>
                    </span>
                </div>

                <div
                    class="flex items-start
                    justify-between gap-4
                    border-b border-slate-100 pb-4"
                >
                    <span class="text-sm text-slate-500">
                        Phone
                    </span>

                    <span
                        class="text-right text-sm
                        font-semibold text-slate-700"
                    >
                        <?= e(
                            displayValue(
                                $order["client_phone"]
                            )
                        ) ?>
                    </span>
                </div>

                <div
                    class="flex items-start
                    justify-between gap-4"
                >
                    <span class="text-sm text-slate-500">
                        Account Status
                    </span>

                    <?php
                    $clientStatus = strtolower(
                        trim(
                            (string) (
                                $order["client_status"]
                                ?? ""
                            )
                        )
                    );
                    ?>

                    <span
                        class="text-sm font-bold
                        <?= $clientStatus === "active"
                            ? "text-emerald-600"
                            : "text-slate-600" ?>"
                    >
                        <?= e(
                            $clientStatus !== ""
                                ? ucfirst($clientStatus)
                                : "Unavailable"
                        ) ?>
                    </span>
                </div>

            </div>

        </article>

    </section>

    <!-- OTP / PROVIDER -->

    <section
        class="mt-5 grid gap-5
        lg:grid-cols-2"
    >

        <!-- OTP -->

        <article
            class="rounded-2xl border
            border-slate-200 bg-white
            p-5 shadow-sm sm:p-6"
        >

            <p
                class="text-xs font-bold uppercase
                tracking-widest text-servora-600"
            >
                Verification
            </p>

            <h2
                class="mt-1 text-xl font-black
                text-slate-900"
            >
                Verification Code
            </h2>

            <?php if (
                !empty($order["sms_code"])
            ): ?>

                <div
                    class="mt-5 rounded-2xl
                    border border-emerald-200
                    bg-emerald-50 p-5"
                >

                    <p
                        class="text-xs font-bold uppercase
                        tracking-wide text-emerald-600"
                    >
                        OTP Received
                    </p>

                    <div
                        class="mt-2 flex flex-wrap
                        items-center justify-between gap-3"
                    >

                        <p
                            id="otpCode"
                            class="break-all text-3xl
                            font-black tracking-wider
                            text-emerald-800"
                        >
                            <?= e(
                                $order["sms_code"]
                            ) ?>
                        </p>

                        <button
                            type="button"
                            onclick="copyOtp(this)"
                            class="rounded-xl bg-white
                            px-4 py-2 text-xs font-bold
                            text-emerald-700 shadow-sm
                            transition hover:bg-emerald-100"
                        >
                            Copy OTP
                        </button>

                    </div>

                </div>

            <?php else: ?>

                <div
                    class="mt-5 rounded-2xl
                    border border-slate-200
                    bg-slate-50 p-5"
                >

                    <p
                        class="font-bold text-slate-700"
                    >
                        No OTP received
                    </p>

                    <p
                        class="mt-1 text-sm
                        leading-6 text-slate-500"
                    >
                        No verification code has been
                        stored for this order.
                    </p>

                </div>

            <?php endif; ?>

        </article>

        <!-- PROVIDER -->

        <article
            class="rounded-2xl border
            border-slate-200 bg-white
            p-5 shadow-sm sm:p-6"
        >

            <p
                class="text-xs font-bold uppercase
                tracking-widest text-servora-600"
            >
                Provider
            </p>

            <h2
                class="mt-1 text-xl font-black
                text-slate-900"
            >
                Provider Information
            </h2>

            <div class="mt-5 space-y-4">

                <div
                    class="flex items-start
                    justify-between gap-4
                    border-b border-slate-100 pb-4"
                >
                    <span class="text-sm text-slate-500">
                        Provider
                    </span>

                    <span
                        class="text-sm font-bold
                        text-slate-900"
                    >
                        <?= e(
                            ucfirst(
                                (string) $order["provider"]
                            )
                        ) ?>
                    </span>
                </div>

                <div
                    class="flex items-start
                    justify-between gap-4
                    border-b border-slate-100 pb-4"
                >
                    <span class="text-sm text-slate-500">
                        Provider Order ID
                    </span>

                    <span
                        class="max-w-[65%] break-all
                        text-right text-sm font-bold
                        text-slate-900"
                    >
                        <?= e(
                            displayValue(
                                $order[
                                    "provider_order_id"
                                ]
                            )
                        ) ?>
                    </span>
                </div>

                <div
                    class="flex items-start
                    justify-between gap-4"
                >
                    <span class="text-sm text-slate-500">
                        Last Message
                    </span>

                    <span
                        class="max-w-[65%]
                        break-words text-right
                        text-sm text-slate-700"
                    >
                        <?= e(
                            displayValue(
                                $order[
                                    "provider_message"
                                ]
                            )
                        ) ?>
                    </span>
                </div>

            </div>

        </article>

    </section>

    <!-- DATES -->

    <section
        class="mt-5 rounded-2xl border
        border-slate-200 bg-white
        p-5 shadow-sm sm:p-6"
    >

        <p
            class="text-xs font-bold uppercase
            tracking-widest text-servora-600"
        >
            Timeline
        </p>

        <h2
            class="mt-1 text-xl font-black
            text-slate-900"
        >
            Order Timeline
        </h2>

        <div
            class="mt-5 grid gap-4
            sm:grid-cols-3"
        >

            <div
                class="rounded-xl bg-slate-50 p-4"
            >
                <p
                    class="text-xs font-bold
                    uppercase text-slate-400"
                >
                    Created
                </p>

                <p
                    class="mt-1 text-sm font-bold
                    text-slate-800"
                >
                    <?= e(
                        formatDate(
                            $order["created_at"]
                        )
                    ) ?>
                </p>
            </div>

            <div
                class="rounded-xl bg-slate-50 p-4"
            >
                <p
                    class="text-xs font-bold
                    uppercase text-slate-400"
                >
                    Last Updated
                </p>

                <p
                    class="mt-1 text-sm font-bold
                    text-slate-800"
                >
                    <?= e(
                        formatDate(
                            $order["updated_at"]
                        )
                    ) ?>
                </p>
            </div>

            <div
                class="rounded-xl bg-slate-50 p-4"
            >
                <p
                    class="text-xs font-bold
                    uppercase text-slate-400"
                >
                    Completed
                </p>

                <p
                    class="mt-1 text-sm font-bold
                    text-slate-800"
                >
                    <?= e(
                        formatDate(
                            $order["completed_at"]
                        )
                    ) ?>
                </p>
            </div>

        </div>

    </section>

    <!-- WALLET TRANSACTION -->

    <section
        class="mt-5 rounded-2xl border
        border-slate-200 bg-white
        p-5 shadow-sm sm:p-6"
    >

        <p
            class="text-xs font-bold uppercase
            tracking-widest text-servora-600"
        >
            Payment
        </p>

        <h2
            class="mt-1 text-xl font-black
            text-slate-900"
        >
            Wallet Transaction
        </h2>

        <?php if ($walletTransaction): ?>

            <div
                class="mt-5 grid gap-3
                sm:grid-cols-2 lg:grid-cols-4"
            >

                <div
                    class="rounded-xl bg-slate-50 p-4"
                >
                    <p
                        class="text-xs font-bold
                        uppercase text-slate-400"
                    >
                        Amount
                    </p>

                    <p
                        class="mt-1 text-sm font-black
                        text-slate-900"
                    >
                        <?= money(
                            $walletTransaction["amount"]
                        ) ?>
                    </p>
                </div>

                <div
                    class="rounded-xl bg-slate-50 p-4"
                >
                    <p
                        class="text-xs font-bold
                        uppercase text-slate-400"
                    >
                        Balance Before
                    </p>

                    <p
                        class="mt-1 text-sm font-bold
                        text-slate-800"
                    >
                        <?= money(
                            $walletTransaction[
                                "balance_before"
                            ]
                        ) ?>
                    </p>
                </div>

                <div
                    class="rounded-xl bg-slate-50 p-4"
                >
                    <p
                        class="text-xs font-bold
                        uppercase text-slate-400"
                    >
                        Balance After
                    </p>

                    <p
                        class="mt-1 text-sm font-bold
                        text-slate-800"
                    >
                        <?= money(
                            $walletTransaction[
                                "balance_after"
                            ]
                        ) ?>
                    </p>
                </div>

                <div
                    class="rounded-xl bg-slate-50 p-4"
                >
                    <p
                        class="text-xs font-bold
                        uppercase text-slate-400"
                    >
                        Status
                    </p>

                    <p
                        class="mt-1 text-sm font-bold
                        text-slate-800"
                    >
                        <?= e(
                            ucfirst(
                                (string)
                                $walletTransaction["status"]
                            )
                        ) ?>
                    </p>
                </div>

            </div>

            <div
                class="mt-3 rounded-xl
                border border-slate-100 p-4"
            >

                <p
                    class="text-xs font-bold
                    uppercase text-slate-400"
                >
                    Transaction Reference
                </p>

                <p
                    class="mt-1 break-all text-sm
                    font-semibold text-slate-700"
                >
                    <?= e(
                        $walletTransaction["reference"]
                    ) ?>
                </p>

            </div>

        <?php else: ?>

            <div
                class="mt-5 rounded-xl
                bg-amber-50 p-4"
            >

                <p
                    class="text-sm font-bold
                    text-amber-800"
                >
                    No wallet transaction linked
                </p>

                <p
                    class="mt-1 text-sm
                    text-amber-700"
                >
                    This is expected for mock orders
                    because the development provider
                    does not debit the client's wallet.
                </p>

            </div>

        <?php endif; ?>

    </section>

    <!-- PROVIDER TRANSACTION HISTORY -->

    <section class="mt-6">

        <div class="mb-4">

            <p
                class="text-xs font-bold uppercase
                tracking-widest text-servora-600"
            >
                Provider Activity
            </p>

            <h2
                class="mt-1 text-xl font-black
                text-slate-900 sm:text-2xl"
            >
                Provider Transaction History
            </h2>

            <p
                class="mt-1 text-sm text-slate-500"
            >
                <?= count($providerTransactions) ?>
                provider event<?= count(
                    $providerTransactions
                ) === 1 ? "" : "s" ?>
            </p>

        </div>

        <?php if (
            empty($providerTransactions)
        ): ?>

            <div
                class="rounded-2xl border
                border-dashed border-slate-300
                bg-white p-8 text-center"
            >

                <p
                    class="font-bold text-slate-700"
                >
                    No provider activity recorded.
                </p>

            </div>

        <?php else: ?>

            <div class="space-y-3">

                <?php foreach (
                    $providerTransactions
                    as $transaction
                ): ?>

                    <?php
                    $outcomeInfo =
                        transactionOutcomeConfig(
                            (string)
                            $transaction["outcome"]
                        );
                    ?>

                    <article
                        class="rounded-2xl border
                        border-slate-200 bg-white
                        p-4 shadow-sm sm:p-5"
                    >

                        <div
                            class="flex flex-wrap
                            items-start justify-between
                            gap-3"
                        >

                            <div>

                                <div
                                    class="flex flex-wrap
                                    items-center gap-2"
                                >

                                    <h3
                                        class="font-black
                                        text-slate-900"
                                    >
                                        <?= e(
                                            actionLabel(
                                                (string)
                                                $transaction[
                                                    "action"
                                                ]
                                            )
                                        ) ?>
                                    </h3>

                                    <span
                                        class="rounded-full
                                        px-2.5 py-1
                                        text-xs font-bold
                                        <?= e(
                                            $outcomeInfo[
                                                "class"
                                            ]
                                        ) ?>"
                                    >
                                        <?= e(
                                            $outcomeInfo[
                                                "label"
                                            ]
                                        ) ?>
                                    </span>

                                </div>

                                <p
                                    class="mt-1 text-xs
                                    text-slate-400"
                                >
                                    <?= e(
                                        formatDate(
                                            $transaction[
                                                "created_at"
                                            ]
                                        )
                                    ) ?>
                                </p>

                            </div>

                            <p
                                class="text-xs font-bold
                                uppercase tracking-wide
                                text-slate-400"
                            >
                                <?= e(
                                    ucfirst(
                                        (string)
                                        $transaction[
                                            "provider"
                                        ]
                                    )
                                ) ?>
                            </p>

                        </div>

                        <div
                            class="mt-4 grid gap-3
                            sm:grid-cols-3"
                        >

                            <div
                                class="rounded-xl
                                bg-slate-50 p-3"
                            >

                                <p
                                    class="text-[10px]
                                    font-bold uppercase
                                    text-slate-400"
                                >
                                    HTTP Status
                                </p>

                                <p
                                    class="mt-1 text-sm
                                    font-bold text-slate-700"
                                >
                                    <?= e(
                                        displayValue(
                                            $transaction[
                                                "http_status"
                                            ]
                                        )
                                    ) ?>
                                </p>

                            </div>

                            <div
                                class="rounded-xl
                                bg-slate-50 p-3"
                            >

                                <p
                                    class="text-[10px]
                                    font-bold uppercase
                                    text-slate-400"
                                >
                                    Provider Status
                                </p>

                                <p
                                    class="mt-1 text-sm
                                    font-bold text-slate-700"
                                >
                                    <?= e(
                                        displayValue(
                                            $transaction[
                                                "provider_status"
                                            ]
                                        )
                                    ) ?>
                                </p>

                            </div>

                            <div
                                class="rounded-xl
                                bg-slate-50 p-3"
                            >

                                <p
                                    class="text-[10px]
                                    font-bold uppercase
                                    text-slate-400"
                                >
                                    Provider Order
                                </p>

                                <p
                                    class="mt-1 break-all
                                    text-sm font-bold
                                    text-slate-700"
                                >
                                    <?= e(
                                        displayValue(
                                            $transaction[
                                                "provider_order_id"
                                            ]
                                        )
                                    ) ?>
                                </p>

                            </div>

                        </div>

                        <?php if (
                            !empty(
                                $transaction["message"]
                            )
                        ): ?>

                            <div
                                class="mt-3 rounded-xl
                                border border-slate-100
                                p-3"
                            >

                                <p
                                    class="text-[10px]
                                    font-bold uppercase
                                    text-slate-400"
                                >
                                    Message
                                </p>

                                <p
                                    class="mt-1 break-words
                                    text-sm leading-6
                                    text-slate-600"
                                >
                                    <?= e(
                                        $transaction[
                                            "message"
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

    <!-- NAVIGATION -->

    <section
        class="mt-8 flex flex-wrap gap-3
        border-t border-slate-200 pt-6"
    >

        <a
            href="foreign_number_orders.php"
            class="rounded-xl bg-servora-700
            px-5 py-3 text-sm font-bold
            text-white transition
            hover:bg-servora-800"
        >
            ← Foreign Number Orders
        </a>

        <a
            href="dashboard.php"
            class="rounded-xl border
            border-slate-200 bg-white
            px-5 py-3 text-sm font-bold
            text-slate-700 transition
            hover:bg-slate-50"
        >
            Admin Dashboard
        </a>

    </section>

    <footer
        class="py-8 text-center
        text-xs text-slate-400"
    >
        Subnext Administration
        · <?= e($adminName) ?>
    </footer>

</main>

<script>

function copyOtp(button) {

    const otpElement =
        document.getElementById(
            "otpCode"
        );

    if (!otpElement || !button) {
        return;
    }

    const otp =
        otpElement.textContent.trim();

    if (!otp) {
        return;
    }

    const originalText =
        button.textContent;

    function showCopied() {

        button.textContent =
            "Copied ✓";

        setTimeout(
            function () {

                button.textContent =
                    originalText;

            },
            1500
        );
    }

    if (
        navigator.clipboard
        && window.isSecureContext
    ) {

        navigator.clipboard
            .writeText(otp)
            .then(showCopied)
            .catch(
                function () {

                    fallbackCopy(
                        otp,
                        button,
                        originalText
                    );
                }
            );

        return;
    }

    fallbackCopy(
        otp,
        button,
        originalText
    );
}

function fallbackCopy(
    text,
    button,
    originalText
) {

    const textarea =
        document.createElement(
            "textarea"
        );

    textarea.value = text;

    textarea.setAttribute(
        "readonly",
        ""
    );

    textarea.style.position =
        "fixed";

    textarea.style.opacity =
        "0";

    document.body.appendChild(
        textarea
    );

    textarea.select();

    try {

        const copied =
            document.execCommand(
                "copy"
            );

        if (!copied) {
            throw new Error(
                "Copy failed."
            );
        }

        button.textContent =
            "Copied ✓";

        setTimeout(
            function () {

                button.textContent =
                    originalText;

            },
            1500
        );

    } catch (error) {

        alert(
            "Could not copy the OTP automatically."
        );

    } finally {

        textarea.remove();
    }
}

</script>

</body>
</html>