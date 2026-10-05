<?php

require_once "../config/database.php";
require_once "../includes/client_auth.php";
require_once "../includes/BrandAssetHelper.php";

$userId = (int) ($_SESSION["user_id"] ?? 0);

if ($userId <= 0) {
    header("Location: ../login.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| ORDER ID
|--------------------------------------------------------------------------
*/

$orderId = filter_input(
    INPUT_GET,
    "id",
    FILTER_VALIDATE_INT
);

if (!$orderId || $orderId <= 0) {
    header("Location: foreign_numbers.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| LOAD ORDER
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        order_reference,
        service_code,
        service_name,
        country_code,
        country_name,
        operator_code,
        operator_name,
        provider,
        provider_order_id,
        provider_phone_number,
        selling_price,
        status,
        sms_code,
        provider_message,
        created_at,
        updated_at,
        completed_at
    FROM virtual_number_orders
    WHERE id = ?
      AND user_id = ?
    LIMIT 1
");

$stmt->execute([
    $orderId,
    $userId
]);

$order = $stmt->fetch(
    PDO::FETCH_ASSOC
);

if (!$order) {
    http_response_code(404);
    exit("Order not found.");
}

/*
|--------------------------------------------------------------------------
| WALLET
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT balance
    FROM wallets
    WHERE user_id = ?
    LIMIT 1
");

$stmt->execute([
    $userId
]);

$walletBalance = (float) (
    $stmt->fetchColumn() ?: 0
);

$fullName = trim((string)($_SESSION["full_name"] ?? "Client"));
$profileInitial = strtoupper(substr($fullName, 0, 1) ?: "C");

/*
|--------------------------------------------------------------------------
| STATUS
|--------------------------------------------------------------------------
*/

$status = strtolower(
    trim(
        (string) (
            $order["status"] ?? ""
        )
    )
);

$statusConfig = match ($status) {

    "waiting_sms" => [
        "label" => "Waiting for OTP",
        "class" => "waiting",
        "title" => "Number Ready",
        "message" =>
            "Keep this page open. Your verification code will appear here automatically."
    ],

    "completed" => [
        "label" => "OTP Received",
        "class" => "success",
        "title" => "OTP Received",
        "message" =>
            "Your verification code has been received."
    ],

    "processing" => [
        "label" => "Processing",
        "class" => "processing",
        "title" => "Preparing Number",
        "message" =>
            "Your verification number is being prepared."
    ],

    "failed" => [
        "label" => "Unavailable",
        "class" => "failed",
        "title" => "Number Currently Unavailable",
        "message" =>
            "No number is currently available for the selected country/service. Please try again later or choose another option."
    ],

    "refunded" => [
        "label" => "Refunded",
        "class" => "refunded",
        "title" => "Number Currently Unavailable",
        "message" =>
            "No number is currently available for the selected country/service. Please try again later or choose another option."
    ],

    "expired" => [
        "label" => "Expired",
        "class" => "expired",
        "title" => "Order Expired",
        "message" =>
            "This verification order has expired."
    ],

    "cancelled" => [
        "label" => "Cancelled",
        "class" => "cancelled",
        "title" => "Order Cancelled",
        "message" =>
            "This verification order was cancelled."
    ],

    default => [
        "label" => "Checking",
        "class" => "unknown",
        "title" => "Checking Status",
        "message" =>
            "Subnext is checking the verification status."
    ]
};

/*
|--------------------------------------------------------------------------
| VALUES
|--------------------------------------------------------------------------
*/

$phoneNumber = trim(
    (string) (
        $order["provider_phone_number"]
        ?? ""
    )
);

$smsCode = trim(
    (string) (
        $order["sms_code"]
        ?? ""
    )
);

$providerMessage = trim(
    (string) (
        $order["provider_message"]
        ?? ""
    )
);

$isMock =
    strtolower(
        trim(
            (string) (
                $order["provider"]
                ?? ""
            )
        )
    ) === "mock";

$shouldAutoCheck =
    in_array(
        $status,
        [
            "waiting_sms",
            "processing",
            "unknown"
        ],
        true
    )
    && trim(
        (string) (
            $order["provider_order_id"]
            ?? ""
        )
    ) !== "";

function formatDateTimeValue(
    ?string $value
): string {
    if (!$value) {
        return "—";
    }

    $timestamp = strtotime($value);

    if ($timestamp === false) {
        return "—";
    }

    return date(
        "d M Y, h:i A",
        $timestamp
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

<meta
    name="robots"
    content="noindex,nofollow"
>

<title>
    Foreign Number Order | Subnext
</title>

<!-- Precompiled Production Stylesheet -->
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family:
        Inter,
        Arial,
        Helvetica,
        sans-serif;

    background: #f5f7fb;
    color: #172033;
}

a {
    text-decoration: none;
    color: inherit;
}

button {
    font: inherit;
}

.topbar {
    background: #ffffff;

    border-bottom:
        1px solid #e6eaf1;

    padding: 18px 24px;
}

.topbar-inner {
    max-width: 900px;
    margin: 0 auto;

    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 20px;
}

.brand {
    color: #144be6;

    font-size: 23px;
    font-weight: 800;
}

.wallet {
    background: #f5f7ff;

    border:
        1px solid #e1e6ff;

    border-radius: 11px;

    padding: 10px 14px;
}

.wallet-label {
    color: #737c90;

    font-size: 11px;

    margin-bottom: 3px;
}

.wallet-amount {
    font-weight: 800;
}

.container {
    max-width: 900px;

    margin: 0 auto;

    padding:
        35px 24px 70px;
}

.back {
    display: inline-block;

    margin-bottom: 24px;

    color: #667085;

    font-size: 14px;
    font-weight: 600;
}

.result-card {
    background: #ffffff;

    border:
        1px solid #e2e7ef;

    border-radius: 18px;

    overflow: hidden;

    box-shadow:
        0 8px 28px
        rgba(23, 32, 51, 0.05);
}

/*
|--------------------------------------------------------------------------
| STATUS
|--------------------------------------------------------------------------
*/

.status-area {
    padding: 28px;

    border-bottom:
        1px solid #edf0f4;
}

.status-top {
    display: flex;

    align-items: flex-start;
    justify-content: space-between;

    gap: 20px;
}

.status-area h1 {
    font-size: 27px;

    margin-bottom: 8px;
}

.status-area p {
    color: #727b8e;

    line-height: 1.6;
}

.badge {
    display: inline-flex;

    border-radius: 999px;

    padding:
        7px 11px;

    font-size: 11px;
    font-weight: 800;

    white-space: nowrap;
}

.badge.waiting {
    background: #fff5d9;
    color: #88640b;
}

.badge.success {
    background: #e9f9ef;
    color: #17753a;
}

.badge.processing {
    background: #eaf0ff;
    color: #144be6;
}

.badge.failed,
.badge.expired,
.badge.cancelled {
    background: #fff0f0;
    color: #a53b3b;
}

.badge.refunded {
    background: #f1ecff;
    color: #6841c6;
}

.badge.unknown {
    background: #eef0f3;
    color: #596273;
}

/*
|--------------------------------------------------------------------------
| NUMBER
|--------------------------------------------------------------------------
*/

.number-area {
    padding: 28px;

    background: #f8faff;

    border-bottom:
        1px solid #e8edf6;
}

.number-label,
.otp-label {
    color: #747e91;

    font-size: 12px;
    font-weight: 700;

    margin-bottom: 8px;
}

.phone-row {
    display: flex;

    align-items: center;

    gap: 12px;

    flex-wrap: wrap;
}

.phone-number {
    font-size: 29px;

    font-weight: 900;

    letter-spacing:
        0.02em;
}

.copy-button {
    border: 0;

    background: #144be6;
    color: #ffffff;

    border-radius: 9px;

    padding:
        8px 12px;

    font-size: 12px;
    font-weight: 800;

    cursor: pointer;
}

/*
|--------------------------------------------------------------------------
| OTP AREA
|--------------------------------------------------------------------------
*/

.otp-area {
    padding: 28px;

    border-bottom:
        1px solid #edf0f4;
}

.otp-box {
    background: #f8faff;

    border:
        1px solid #e0e7f5;

    border-radius: 14px;

    padding: 20px;
}

.otp-code {
    display: inline-block;

    background: #eef3ff;
    color: #144be6;

    border:
        1px solid #dbe4ff;

    border-radius: 12px;

    padding:
        12px 18px;

    font-size: 28px;
    font-weight: 900;

    letter-spacing:
        0.15em;
}

.waiting-box {
    display: flex;

    align-items: center;

    gap: 13px;
}

.spinner {
    width: 22px;
    height: 22px;

    flex: 0 0 22px;

    border:
        3px solid #dfe6f7;

    border-top-color:
        #144be6;

    border-radius: 50%;

    animation:
        spin 0.8s linear infinite;
}

@keyframes spin {

    to {
        transform:
            rotate(360deg);
    }

}

.waiting-title {
    font-size: 14px;

    font-weight: 800;

    margin-bottom: 4px;
}

.waiting-text {
    color: #7b8495;

    font-size: 12px;

    line-height: 1.55;
}

.auto-status {
    margin-top: 12px;

    color: #8992a3;

    font-size: 11px;
}

/*
|--------------------------------------------------------------------------
| DETAILS
|--------------------------------------------------------------------------
*/

.details {
    padding: 28px;
}

.details h2 {
    font-size: 17px;

    margin-bottom: 16px;
}

.detail-row {
    display: flex;

    justify-content:
        space-between;

    gap: 20px;

    padding:
        12px 0;

    border-bottom:
        1px solid #edf0f4;
}

.detail-row:last-child {
    border-bottom: 0;
}

.detail-label {
    color: #778094;

    font-size: 13px;
}

.detail-value {
    font-size: 13px;

    font-weight: 800;

    text-align: right;
}

.price {
    color: #144be6;
}

.message {
    margin-top: 20px;

    background: #f7f8fa;

    border:
        1px solid #e5e8ed;

    border-radius: 11px;

    padding: 13px 14px;

    color: #626c7e;

    font-size: 12px;

    line-height: 1.55;
}

.mock-notice {
    margin-top: 20px;

    background: #fff9e8;

    border:
        1px solid #f2dfa5;

    border-radius: 11px;

    padding: 13px 14px;

    color: #72591a;

    font-size: 12px;

    line-height: 1.55;
}

/*
|--------------------------------------------------------------------------
| ACTIONS
|--------------------------------------------------------------------------
*/

.actions {
    display: flex;

    gap: 12px;

    margin-top: 22px;

    flex-wrap: wrap;
}

.secondary-button {
    display: inline-flex;

    align-items: center;
    justify-content: center;

    border:
        1px solid #dce1e9;

    background: #ffffff;

    color: #3e4859;

    border-radius: 10px;

    padding:
        11px 16px;

    font-size: 13px;

    font-weight: 800;
}

@media (max-width: 600px) {

    .status-top {
        flex-direction: column;
    }

    .phone-number {
        font-size: 23px;
    }

    .detail-row {
        flex-direction: column;

        gap: 5px;
    }

    .detail-value {
        text-align: left;
    }

}

</style>

</head>

<body>

<!-- Desktop Navigation Header -->
<header class="hidden md:block sticky top-0 z-40 border-b border-slate-200/80 bg-white/95 backdrop-blur">
    <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6">
        <a href="dashboard.php" class="flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-servora-700 text-lg font-black text-white shadow-sm">S</div>
            <div>
                <div class="text-lg font-bold tracking-tight text-slate-900 leading-tight">Subnext</div>
                <div class="text-[10px] font-medium text-slate-400 uppercase tracking-wider">Virtual Numbers</div>
            </div>
        </a>

        <!-- Desktop Navigation Links -->
        <nav class="flex items-center gap-1">
            <a href="dashboard.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">Dashboard</a>
            <a href="orders.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">My Orders</a>
            <a href="wallet.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">Wallet</a>
            <a href="profile.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">Profile</a>
        </nav>

        <div class="flex items-center gap-2.5">
            <a href="notifications.php" class="relative flex h-10 w-10 items-center justify-center rounded-xl text-slate-600 transition hover:bg-slate-100" aria-label="Notifications">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 1-5.714 0M18 8A6 6 0 0 0 6 8c0 7-3 7-3 9h18c0-2-3-2-3-9M13.73 21a2 2 0 0 1-3.46 0"/>
                </svg>
            </a>
            <a href="profile.php" class="flex h-10 w-10 items-center justify-center rounded-xl bg-servora-100 font-bold text-servora-700 text-sm" title="My Profile">
                <?= htmlspecialchars($profileInitial, ENT_QUOTES, "UTF-8") ?>
            </a>
            <a href="../logout.php" class="inline-flex items-center gap-1.5 rounded-xl border border-red-200 bg-red-50/80 px-3.5 py-2 text-xs font-bold text-red-600 transition hover:bg-red-100 hover:border-red-300" title="Logout">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                </svg>
                <span>Logout</span>
            </a>
        </div>
    </div>
</header>

<main class="mx-auto w-full max-w-5xl px-4 py-6 pb-28 md:pb-8 sm:px-6">

    <!-- Hero Card -->
    <section class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-servora-800 via-servora-700 to-servora-500 p-6 sm:p-8 text-white shadow-xl">
        <a href="foreign_numbers.php" class="text-sm font-semibold text-white/70 hover:text-white transition">
            ← Foreign Numbers
        </a>
        <div class="mt-6">
            <p class="text-sm font-semibold text-white/70">
                Subnext Virtual Number
            </p>
            <h1 class="mt-1 text-3xl font-black">
                <?= htmlspecialchars($order["service_name"] ?? "Virtual Number", ENT_QUOTES, "UTF-8") ?> Verification
            </h1>
            <p class="mt-2 max-w-xl text-sm text-white/70">
                Live verification status and OTP reception for your virtual number.
            </p>
        </div>
    </section>

    <!-- Wallet Balance Card -->
    <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex items-center justify-between gap-4">
            <div>
                <p class="text-xs font-bold uppercase tracking-wide text-slate-400">
                    Wallet Balance
                </p>
                <p class="mt-1 text-2xl font-black text-slate-900">
                    ₦<?= number_format($walletBalance, 2) ?>
                </p>
            </div>
            <a href="fund_wallet.php" class="inline-flex items-center justify-center rounded-xl bg-servora-50 px-4 py-2 text-xs font-bold text-servora-700 transition hover:bg-servora-100">
                Fund Wallet
            </a>
        </div>
    </section>

    <div class="mt-6">

<section class="result-card">

<div class="status-area">

    <div class="status-top">

        <div>

            <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
                <?= BrandAssetHelper::renderServiceLogo($order["service_code"] ?? '', 'sm', true) ?>
                <?= BrandAssetHelper::renderCountryFlag($order["country_code"] ?? '', $order["country_name"] ?? '', 'sm', true) ?>
                <span style="font-size:13px;font-weight:800;color:#475569;"><?= htmlspecialchars((string) $order["service_name"]) ?> &bull; <?= htmlspecialchars((string) $order["country_name"]) ?></span>
            </div>

            <h1>
                <?= htmlspecialchars(
                    $statusConfig["title"],
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>
            </h1>

            <p>
                <?= htmlspecialchars(
                    $statusConfig["message"],
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>
            </p>

        </div>

        <span
            class="badge <?= htmlspecialchars(
                $statusConfig["class"],
                ENT_QUOTES,
                "UTF-8"
            ) ?>"
        >
            <?= htmlspecialchars(
                $statusConfig["label"],
                ENT_QUOTES,
                "UTF-8"
            ) ?>
        </span>

    </div>

</div>

<?php if ($phoneNumber !== ""): ?>

<div class="number-area">

    <div class="number-label">
        Verification Number
    </div>

    <div class="phone-row">

        <div
            class="phone-number"
            id="phoneNumber"
        >
            <?= htmlspecialchars(
                $phoneNumber,
                ENT_QUOTES,
                "UTF-8"
            ) ?>
        </div>

        <button
            type="button"
            class="copy-button"
            data-copy-target="phoneNumber"
        >
            Copy
        </button>

    </div>

</div>

<?php endif; ?>

<div class="otp-area">

    <div class="otp-label">
        Verification Code
    </div>

    <div class="otp-box">

    <?php if (
        $status === "completed"
        && $smsCode !== ""
    ): ?>

        <div class="phone-row">

            <div
                class="otp-code"
                id="otpCode"
            >
                <?= htmlspecialchars(
                    $smsCode,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>
            </div>

            <button
                type="button"
                class="copy-button"
                data-copy-target="otpCode"
            >
                Copy OTP
            </button>

        </div>

    <?php elseif ($shouldAutoCheck): ?>

        <div class="waiting-box">

            <div class="spinner"></div>

            <div>

                <div class="waiting-title">
                    Waiting for OTP
                </div>

                <div class="waiting-text">
                    Your verification code will
                    appear here automatically.
                    Keep this page open.
                </div>

            </div>

        </div>

        <div class="auto-status">
            Checking for new verification
            messages...
        </div>

    <?php else: ?>

        <div class="waiting-text">
            No verification code is available
            for this order.
        </div>

    <?php endif; ?>

    </div>

</div>

<div class="details">

    <h2>
        Order Details
    </h2>

    <div class="detail-row">

        <div class="detail-label">
            Reference
        </div>

        <div class="detail-value">
            <?= htmlspecialchars(
                (string) $order["order_reference"],
                ENT_QUOTES,
                "UTF-8"
            ) ?>
        </div>

    </div>

    <div class="detail-row">

        <div class="detail-label">
            Service
        </div>

        <div class="detail-value" style="display:flex;align-items:center;gap:8px;">
            <?= BrandAssetHelper::renderServiceLogo($order["service_code"] ?? '', 'sm', true) ?>
            <span><?= htmlspecialchars(
                (string) $order["service_name"],
                ENT_QUOTES,
                "UTF-8"
            ) ?></span>
        </div>

    </div>

    <div class="detail-row">

        <div class="detail-label">
            Country
        </div>

        <div class="detail-value" style="display:flex;align-items:center;gap:8px;">
            <?= BrandAssetHelper::renderCountryFlag($order["country_code"] ?? '', $order["country_name"] ?? '', 'sm', true) ?>
            <span><?= htmlspecialchars(
                (string) $order["country_name"],
                ENT_QUOTES,
                "UTF-8"
            ) ?></span>
        </div>

    </div>

    <div class="detail-row">

        <div class="detail-label">
            Option
        </div>

        <div class="detail-value">
            <?= htmlspecialchars(
                (string) (
                    $order["operator_name"]
                    ?: "Available Number"
                ),
                ENT_QUOTES,
                "UTF-8"
            ) ?>
        </div>

    </div>

    <div class="detail-row">

        <div class="detail-label">
            Price
        </div>

        <div class="detail-value price">
            ₦<?= number_format(
                (float) $order["selling_price"],
                2
            ) ?>
        </div>

    </div>

    <div class="detail-row">

        <div class="detail-label">
            Ordered
        </div>

        <div class="detail-value">
            <?= htmlspecialchars(
                formatDateTimeValue(
                    $order["created_at"]
                ),
                ENT_QUOTES,
                "UTF-8"
            ) ?>
        </div>

    </div>

    <?php if ($status === "completed"): ?>

    <div class="detail-row">

        <div class="detail-label">
            Completed
        </div>

        <div class="detail-value">
            <?= htmlspecialchars(
                formatDateTimeValue(
                    $order["completed_at"]
                ),
                ENT_QUOTES,
                "UTF-8"
            ) ?>
        </div>

    </div>

    <?php endif; ?>

    <?php if ($providerMessage !== ""): ?>

        <div class="message">
            <?= htmlspecialchars(
                $providerMessage,
                ENT_QUOTES,
                "UTF-8"
            ) ?>
        </div>

    <?php endif; ?>

    <?php if ($status === "refunded"): ?>
        <div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:12px;padding:14px;margin-bottom:18px;color:#065f46;font-size:13px;line-height:1.5;">
            <strong style="display:block;margin-bottom:2px;">Payment Automatically Refunded</strong>
            Your payment of <strong>₦<?= number_format((float)$order["selling_price"], 2) ?></strong> has been refunded back to your Subnext wallet.
        </div>
    <?php endif; ?>

    <div class="actions">

        <?php if ($status === "completed"): ?>
            <a
                href="receipt.php?ref=<?= urlencode((string)$order["order_reference"]) ?>"
                class="primary-button"
                style="background:#059669;color:#ffffff;display:inline-flex;align-items:center;gap:6px;padding:12px 18px;border-radius:11px;font-size:13px;font-weight:800;"
            >
                <svg style="width:16px;height:16px;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                View &amp; Print Receipt
            </a>
        <?php endif; ?>

        <?php if ($status === "refunded" || $status === "failed"): ?>
            <a
                href="foreign_number_options.php?service=<?= urlencode((string)$order["service_code"]) ?>"
                class="primary-button"
                style="background:#635BDB;color:#ffffff;padding:12px 18px;border-radius:11px;font-size:13px;font-weight:800;"
            >
                Choose Another Option
            </a>
        <?php endif; ?>

        <a
            href="foreign_numbers.php"
            class="secondary-button"
        >
            New Order
        </a>

        <a
            href="foreign_number_history.php"
            class="secondary-button"
        >
            Order History
        </a>

    </div>

</div>

</section>

</div>

</main>

<script>

/*
|--------------------------------------------------------------------------
| COPY BUTTONS
|--------------------------------------------------------------------------
*/

document.querySelectorAll(
    "[data-copy-target]"
).forEach(
    function (button) {

        button.addEventListener(
            "click",
            async function () {

                const target =
                    document.getElementById(
                        button.dataset.copyTarget
                    );

                if (!target) {
                    return;
                }

                const value =
                    target.textContent.trim();

                if (!value) {
                    return;
                }

                try {

                    await navigator.clipboard
                        .writeText(value);

                    const original =
                        button.textContent;

                    button.textContent =
                        "Copied";

                    setTimeout(
                        function () {
                            button.textContent =
                                original;
                        },
                        1200
                    );

                } catch (error) {
                }

            }
        );

    }
);

/*
|--------------------------------------------------------------------------
| AUTOMATIC OTP CHECK
|--------------------------------------------------------------------------
|
| No Check Status button is required.
|
| While the order is waiting, this page quietly asks our existing
| check_foreign_number.php endpoint for an update.
|
| After the endpoint updates the database, this page reloads and the
| OTP appears directly inside the Verification Code box.
|
*/

<?php if ($shouldAutoCheck): ?>

let statusCheckRunning = false;

async function checkForOtp() {

    if (statusCheckRunning) {
        return;
    }

    statusCheckRunning = true;

    try {

        const response = await fetch(
            "check_foreign_number.php?id=<?= (int) $order["id"] ?>",
            {
                method: "GET",
                credentials: "same-origin",
                cache: "no-store",
                redirect: "follow"
            }
        );

        if (response.ok) {

            /*
             * The status endpoint has now checked
             * the provider and updated the database.
             *
             * Reload this result page so the newest
             * status / OTP appears here.
             */

            window.location.reload();

            return;
        }

    } catch (error) {

        /*
         * Temporary network/browser errors should
         * not destroy the order.
         */

    }

    statusCheckRunning = false;
}

/*
 * Wait a few seconds before the first check.
 */

setTimeout(
    checkForOtp,
    5000
);

<?php endif; ?>

</script>

<?php require_once __DIR__ . "/../includes/client_bottom_nav.php"; ?>
</body>
</html>