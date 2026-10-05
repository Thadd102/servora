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
| WALLET
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT balance
    FROM wallets
    WHERE user_id = ?
    LIMIT 1
");

$stmt->execute([$userId]);

$walletBalance = (float) (
    $stmt->fetchColumn() ?: 0
);

$fullName = trim((string)($_SESSION["full_name"] ?? "Client"));
$profileInitial = strtoupper(substr($fullName, 0, 1));

/*
|--------------------------------------------------------------------------
| LOAD CLIENT ORDERS
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
        operator_name,
        provider_phone_number,
        selling_price,
        status,
        sms_code,
        created_at,
        completed_at
    FROM virtual_number_orders
    WHERE user_id = ?
    ORDER BY id DESC
");

$stmt->execute([$userId]);

$orders = $stmt->fetchAll(
    PDO::FETCH_ASSOC
);

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function formatOrderDate(
    ?string $date
): string {

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

function orderStatusConfig(
    string $status
): array {

    $status = strtolower(
        trim($status)
    );

    return match ($status) {

        "completed" => [
            "label" => "Completed",
            "class" => "success"
        ],

        "waiting_sms" => [
            "label" => "Waiting for OTP",
            "class" => "waiting"
        ],

        "processing" => [
            "label" => "Processing",
            "class" => "processing"
        ],

        "failed" => [
            "label" => "Failed",
            "class" => "failed"
        ],

        "refunded" => [
            "label" => "Refunded",
            "class" => "refunded"
        ],

        "expired" => [
            "label" => "Expired",
            "class" => "expired"
        ],

        "cancelled" => [
            "label" => "Cancelled",
            "class" => "cancelled"
        ],

        default => [
            "label" => "Unknown",
            "class" => "unknown"
        ]
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

<meta
    name="robots"
    content="noindex,nofollow"
>

<title>
    Foreign Number History | Servora
</title>

<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = {
        theme: {
            extend: {
                colors: {
                    servora: {
                        50: '#F5F3FF', 100: '#EDE9FE', 200: '#DDD6FE',
                        500: '#635BDB', 600: '#5146C7', 700: '#3E37B7',
                        800: '#312E81', 900: '#1E1B4B'
                    }
                }
            }
        }
    }
</script>

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
    color: inherit;
    text-decoration: none;
}

/*
|--------------------------------------------------------------------------
| HEADER
|--------------------------------------------------------------------------
*/

.topbar {
    background: #ffffff;

    border-bottom:
        1px solid #e5e9f0;

    padding: 18px 24px;
}

.topbar-inner {
    max-width: 1100px;

    margin: 0 auto;

    display: flex;

    align-items: center;

    justify-content:
        space-between;

    gap: 20px;
}

.brand {
    color: #144be6;

    font-size: 23px;
    font-weight: 900;
}

.wallet {
    background: #f5f7ff;

    border:
        1px solid #dfe5ff;

    border-radius: 11px;

    padding: 10px 14px;
}

.wallet-label {
    color: #737c90;

    font-size: 11px;

    margin-bottom: 3px;
}

.wallet-amount {
    font-weight: 900;
}

/*
|--------------------------------------------------------------------------
| PAGE
|--------------------------------------------------------------------------
*/

.container {
    max-width: 1100px;

    margin: 0 auto;

    padding:
        35px 24px 70px;
}

.back {
    display: inline-block;

    margin-bottom: 24px;

    color: #687286;

    font-size: 14px;
    font-weight: 700;
}

.page-header {
    display: flex;

    align-items: flex-end;

    justify-content:
        space-between;

    gap: 20px;

    margin-bottom: 25px;
}

.page-header h1 {
    font-size: 30px;

    margin-bottom: 8px;
}

.page-header p {
    color: #747e91;

    line-height: 1.6;
}

.new-order {
    display: inline-flex;

    align-items: center;

    justify-content: center;

    background: #144be6;

    color: #ffffff;

    border-radius: 10px;

    padding:
        11px 16px;

    font-size: 13px;
    font-weight: 800;

    white-space: nowrap;
}

/*
|--------------------------------------------------------------------------
| CARD
|--------------------------------------------------------------------------
*/

.card {
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
| TABLE
|--------------------------------------------------------------------------
*/

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;

    border-collapse: collapse;

    min-width: 900px;
}

thead {
    background: #f8faff;
}

th {
    padding:
        14px 17px;

    color: #737c90;

    font-size: 11px;

    font-weight: 800;

    text-align: left;

    text-transform: uppercase;

    letter-spacing:
        0.04em;

    border-bottom:
        1px solid #e7ebf1;
}

td {
    padding:
        16px 17px;

    border-bottom:
        1px solid #edf0f4;

    font-size: 13px;

    vertical-align: middle;
}

tbody tr:last-child td {
    border-bottom: 0;
}

tbody tr:hover {
    background: #fafbfe;
}

.reference {
    font-size: 12px;

    font-weight: 800;

    color: #5f6878;
}

.service-name {
    font-weight: 900;

    margin-bottom: 4px;
}

.country {
    color: #7b8495;

    font-size: 12px;
}

.number {
    font-weight: 800;
}

.price {
    color: #144be6;

    font-weight: 900;
}

/*
|--------------------------------------------------------------------------
| STATUS
|--------------------------------------------------------------------------
*/

.badge {
    display: inline-flex;

    align-items: center;

    justify-content: center;

    border-radius: 999px;

    padding:
        6px 10px;

    font-size: 10px;

    font-weight: 900;

    white-space: nowrap;
}

.badge.success {
    background: #e9f9ef;
    color: #17753a;
}

.badge.waiting {
    background: #fff5d9;
    color: #88640b;
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
| VIEW
|--------------------------------------------------------------------------
*/

.view-button {
    display: inline-flex;

    align-items: center;

    justify-content: center;

    border:
        1px solid #dbe1ea;

    background: #ffffff;

    color: #344054;

    border-radius: 8px;

    padding:
        8px 12px;

    font-size: 11px;

    font-weight: 800;
}

.view-button:hover {
    border-color: #144be6;

    color: #144be6;
}

/*
|--------------------------------------------------------------------------
| EMPTY
|--------------------------------------------------------------------------
*/

.empty {
    padding:
        65px 25px;

    text-align: center;
}

.empty-icon {
    width: 60px;
    height: 60px;

    display: flex;

    align-items: center;
    justify-content: center;

    margin:
        0 auto 18px;

    background: #eef3ff;

    color: #144be6;

    border-radius: 18px;

    font-size: 25px;
}

.empty h2 {
    font-size: 19px;

    margin-bottom: 8px;
}

.empty p {
    color: #7b8495;

    font-size: 13px;

    line-height: 1.6;

    margin-bottom: 20px;
}

.empty-button {
    display: inline-flex;

    background: #144be6;

    color: #ffffff;

    border-radius: 10px;

    padding:
        11px 17px;

    font-size: 13px;

    font-weight: 800;
}

/*
|--------------------------------------------------------------------------
| MOBILE
|--------------------------------------------------------------------------
*/

@media (max-width: 700px) {

    .page-header {
        align-items: flex-start;

        flex-direction: column;
    }

    .page-header h1 {
        font-size: 25px;
    }

    .new-order {
        width: 100%;
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
                <div class="text-lg font-bold tracking-tight text-slate-900 leading-tight">Servora</div>
                <div class="text-[10px] font-medium text-slate-400 uppercase tracking-wider">Number Orders</div>
            </div>
        </a>

        <!-- Desktop Navigation Links -->
        <nav class="flex items-center gap-1">
            <a href="dashboard.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">Dashboard</a>
            <a href="orders.php" class="rounded-xl bg-servora-50 px-3.5 py-2 text-xs font-bold text-servora-700">My Orders</a>
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
        </div>
    </div>
</header>

<main class="mx-auto w-full max-w-5xl px-4 py-6 pb-28 md:pb-8 sm:px-6">

    <!-- HERO BANNER CARD -->
    <section class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-servora-800 via-servora-700 to-servora-500 p-6 sm:p-8 text-white shadow-xl">
        <a href="orders.php" class="text-sm font-semibold text-white/70 hover:text-white">
            ← Orders
        </a>

        <div class="mt-6 flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div>
                <p class="text-sm font-semibold text-white/70">
                    Servora Verification
                </p>
                <h1 class="mt-1 text-3xl font-black">
                    Virtual Number Orders
                </h1>
                <p class="mt-2 max-w-xl text-sm text-white/70">
                    View your foreign number verification purchases and verification status.
                </p>
            </div>
            <div>
                <a href="foreign_numbers.php" class="inline-flex rounded-xl bg-white px-4 py-2.5 text-xs font-bold text-servora-800 hover:bg-slate-100 transition shadow-sm">
                    + New Number
                </a>
            </div>
        </div>
    </section>

    <!-- STANDARDIZED WALLET BALANCE CARD -->
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
            <a href="fund_wallet.php" class="rounded-xl bg-servora-50 px-4 py-2.5 text-sm font-bold text-servora-700 hover:bg-servora-100 transition">
                Fund Wallet
            </a>
        </div>
    </section>

    <!-- CONTENT CARD -->
    <section class="mt-6 rounded-3xl border border-slate-200 bg-white p-5 sm:p-7 shadow-sm">


<?php if ($orders === []): ?>

<div class="empty">

    <div class="empty-icon">
        #
    </div>

    <h2>
        No orders yet
    </h2>

    <p>
        Your foreign-number verification
        orders will appear here.
    </p>

    <a
        href="foreign_numbers.php"
        class="empty-button"
    >
        Create First Order
    </a>

</div>

<?php else: ?>

<div class="table-wrap">

<table>

<thead>

<tr>

    <th>
        Reference
    </th>

    <th>
        Service
    </th>

    <th>
        Number
    </th>

    <th>
        Price
    </th>

    <th>
        Status
    </th>

    <th>
        Date
    </th>

    <th>
        Action
    </th>

</tr>

</thead>

<tbody>

<?php foreach ($orders as $order): ?>

<?php

$statusConfig =
    orderStatusConfig(
        (string) (
            $order["status"] ?? ""
        )
    );

$phoneNumber = trim(
    (string) (
        $order["provider_phone_number"]
        ?? ""
    )
);

?>

<tr>

<td>

    <div class="reference">

        <?= htmlspecialchars(
            (string) (
                $order["order_reference"]
                ?? ""
            ),
            ENT_QUOTES,
            "UTF-8"
        ) ?>

    </div>

</td>

<td>

    <div style="display:flex;align-items:center;gap:10px;">
        <?= BrandAssetHelper::renderServiceLogo($order["service_code"] ?? '', 'sm', true) ?>
        <div>
            <div class="service-name">
                <?= htmlspecialchars(
                    (string) (
                        $order["service_name"]
                        ?? "Unknown Service"
                    ),
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>
            </div>

            <div class="country" style="display:flex;align-items:center;gap:5px;margin-top:2px;">
                <?= BrandAssetHelper::renderCountryFlag($order["country_code"] ?? '', $order["country_name"] ?? '', 'xs') ?>
                <span><?= htmlspecialchars(
                    (string) (
                        $order["country_name"]
                        ?? "Unknown Country"
                    ),
                    ENT_QUOTES,
                    "UTF-8"
                ) ?></span>
            </div>
        </div>
    </div>

</td>

<td>

    <div class="number">

        <?= $phoneNumber !== ""
            ? htmlspecialchars(
                $phoneNumber,
                ENT_QUOTES,
                "UTF-8"
            )
            : "—"
        ?>

    </div>

</td>

<td>

    <div class="price">

        ₦<?= number_format(
            (float) (
                $order["selling_price"]
                ?? 0
            ),
            2
        ) ?>

    </div>

</td>

<td>

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

</td>

<td>

    <?= htmlspecialchars(
        formatOrderDate(
            $order["created_at"]
            ?? null
        ),
        ENT_QUOTES,
        "UTF-8"
    ) ?>

</td>

<td>

    <div style="display:flex;align-items:center;gap:6px;">
        <a
            href="foreign_number_result.php?id=<?= (int) $order["id"] ?>"
            class="view-button"
        >
            View
        </a>
        <?php if (strtolower((string)($order["status"] ?? '')) === 'completed'): ?>
        <a
            href="receipt.php?ref=<?= urlencode((string)$order["order_reference"]) ?>"
            class="view-button"
            style="background:#059669;color:#ffffff;"
            title="View & Print Official Receipt"
        >
            Receipt
        </a>
        <?php endif; ?>
    </div>

</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

<?php endif; ?>

</section>

</main>

<?php require_once __DIR__ . "/../includes/client_bottom_nav.php"; ?>
</body>

</html>