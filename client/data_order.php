<?php

require_once "../config/database.php";
require_once "../includes/client_auth.php";

$userId = currentUserId();

$stmt = $pdo->prepare("SELECT balance FROM wallets WHERE user_id = ? LIMIT 1");
$stmt->execute([$userId]);
$walletBalance = (float)($stmt->fetchColumn() ?: 0.00);

$fullName = trim((string)($_SESSION["full_name"] ?? "Client"));
$profileInitial = strtoupper(substr($fullName, 0, 1) ?: "C");

/*
|--------------------------------------------------------------------------
| GET ORDER REFERENCE
|--------------------------------------------------------------------------
*/

$orderReference = trim($_GET["ref"] ?? "");

if ($orderReference === "") {
    header("Location: buy_data.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| GET CUSTOMER ORDER
|--------------------------------------------------------------------------
|
| user_id is included in the query so one customer cannot view
| another customer's order by changing the URL.
|
*/

$stmt = $pdo->prepare("
    SELECT
        do.id,
        do.order_reference,
        do.phone_number,
        do.selling_price,
        do.status,
        do.provider_reference,
        do.provider_message,
        do.created_at,
        do.completed_at,

        dp.name AS plan_name,
        dp.data_amount,
        dp.validity,

        n.name AS network_name

    FROM data_orders do

    INNER JOIN data_plans dp
        ON dp.id = do.plan_id

    INNER JOIN networks n
        ON n.id = dp.network_id

    WHERE do.order_reference = ?
      AND do.user_id = ?

    LIMIT 1
");

$stmt->execute([
    $orderReference,
    $userId
]);

$order = $stmt->fetch(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| ORDER NOT FOUND
|--------------------------------------------------------------------------
*/

if (!$order) {
    http_response_code(404);
    exit("Data order not found.");
}


/*
|--------------------------------------------------------------------------
| STATUS DISPLAY
|--------------------------------------------------------------------------
*/

$status = $order["status"];

$statusConfig = match ($status) {

    "successful" => [
        "label" => "Successful",
        "class" => "bg-emerald-50 text-emerald-700 border-emerald-200",
        "message" => "Your data purchase was completed successfully."
    ],

    "failed" => [
        "label" => "Failed",
        "class" => "bg-red-50 text-red-700 border-red-200",
        "message" => "This data purchase could not be completed."
    ],

    "refunded" => [
        "label" => "Refunded",
        "class" => "bg-blue-50 text-blue-700 border-blue-200",
        "message" => "The amount for this purchase has been returned to your wallet."
    ],

    "processing" => [
        "label" => "Processing",
        "class" => "bg-blue-50 text-blue-700 border-blue-200",
        "message" => "Your data purchase is currently being processed."
    ],

    default => [
        "label" => "Pending",
        "class" => "bg-amber-50 text-amber-700 border-amber-200",
        "message" => "Your order has been received and is awaiting processing."
    ]
};

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Data Order | Subnext</title>

    <!-- Precompiled Production Stylesheet -->
    <link rel="stylesheet" href="../assets/css/style.css">

</head>


<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">

<!-- Desktop Navigation Header -->
<header class="hidden md:block sticky top-0 z-40 border-b border-slate-200/80 bg-white/95 backdrop-blur">
    <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6">
        <a href="dashboard.php" class="flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-servora-700 text-lg font-black text-white shadow-sm">S</div>
            <div>
                <div class="text-lg font-bold tracking-tight text-slate-900 leading-tight">Subnext</div>
                <div class="text-[10px] font-medium text-slate-400 uppercase tracking-wider">Data Order</div>
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
        <div class="flex items-center justify-between">
            <a href="orders.php" class="text-sm font-semibold text-white/70 hover:text-white transition">
                ← My Orders
            </a>
            <a href="buy_data.php" class="inline-flex items-center gap-1.5 rounded-xl bg-white/20 hover:bg-white/30 backdrop-blur px-3.5 py-1.5 text-xs font-bold text-white transition">
                Buy New Data →
            </a>
        </div>
        <div class="mt-6">
            <p class="text-sm font-semibold text-white/70">
                Subnext Data Order
            </p>
            <h1 class="mt-1 text-3xl font-black">
                Order Details
            </h1>
            <p class="mt-2 max-w-xl text-sm text-white/70">
                Reference: <?= htmlspecialchars($order["order_reference"], ENT_QUOTES, "UTF-8") ?>
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

    <!-- ORDER CARD -->
    <section class="mt-6 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
        <!-- CONTENT -->
        <div class="p-5 sm:p-8">


            <!-- STATUS -->

            <div
                class="rounded-2xl border p-4
                       <?= $statusConfig["class"] ?>"
            >

                <div
                    class="flex items-center
                           justify-between gap-4"
                >

                    <span class="text-sm font-bold">
                        Order Status
                    </span>

                    <span
                        class="rounded-full bg-white/70
                               px-3 py-1
                               text-xs font-black"
                    >
                        <?= htmlspecialchars(
                            $statusConfig["label"],
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>
                    </span>

                </div>

                <p class="mt-2 text-sm">
                    <?= htmlspecialchars(
                        $statusConfig["message"],
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>
                </p>

            </div>


            <!-- DETAILS -->

            <div class="mt-7 divide-y divide-slate-100">


                <!-- NETWORK -->

                <div
                    class="flex items-center
                           justify-between gap-4 py-4"
                >

                    <span class="text-sm text-slate-500">
                        Network
                    </span>

                    <span
                        class="text-right text-sm
                               font-extrabold"
                    >
                        <?= htmlspecialchars(
                            $order["network_name"],
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>
                    </span>

                </div>


                <!-- PLAN -->

                <div
                    class="flex items-center
                           justify-between gap-4 py-4"
                >

                    <span class="text-sm text-slate-500">
                        Plan
                    </span>

                    <span
                        class="text-right text-sm
                               font-extrabold"
                    >
                        <?= htmlspecialchars(
                            $order["plan_name"],
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>
                    </span>

                </div>


                <!-- DATA -->

                <div
                    class="flex items-center
                           justify-between gap-4 py-4"
                >

                    <span class="text-sm text-slate-500">
                        Data
                    </span>

                    <span
                        class="text-right text-sm
                               font-extrabold"
                    >
                        <?= htmlspecialchars(
                            $order["data_amount"],
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>
                    </span>

                </div>


                <!-- VALIDITY -->

                <?php if (!empty($order["validity"])): ?>

                    <div
                        class="flex items-center
                               justify-between gap-4 py-4"
                    >

                        <span class="text-sm text-slate-500">
                            Validity
                        </span>

                        <span
                            class="text-right text-sm
                                   font-extrabold"
                        >
                            <?= htmlspecialchars(
                                $order["validity"],
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>
                        </span>

                    </div>

                <?php endif; ?>


                <!-- PHONE -->

                <div
                    class="flex items-center
                           justify-between gap-4 py-4"
                >

                    <span class="text-sm text-slate-500">
                        Phone Number
                    </span>

                    <span
                        class="text-right text-sm
                               font-extrabold"
                    >
                        <?= htmlspecialchars(
                            $order["phone_number"],
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>
                    </span>

                </div>


                <!-- AMOUNT -->

                <div
                    class="flex items-center
                           justify-between gap-4 py-4"
                >

                    <span class="text-sm text-slate-500">
                        Amount
                    </span>

                    <span
                        class="text-right text-lg
                               font-black text-servora-700"
                    >
                        ₦<?= number_format(
                            (float) $order["selling_price"],
                            2
                        ) ?>
                    </span>

                </div>


                <!-- DATE -->

                <div
                    class="flex items-center
                           justify-between gap-4 py-4"
                >

                    <span class="text-sm text-slate-500">
                        Date
                    </span>

                    <span
                        class="text-right text-sm
                               font-semibold"
                    >
                        <?= htmlspecialchars(
                            $order["created_at"],
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>
                    </span>

                </div>


                <!-- PROVIDER REFERENCE -->

                <?php if (!empty($order["provider_reference"])): ?>

                    <div
                        class="flex items-center
                               justify-between gap-4 py-4"
                    >

                        <span class="text-sm text-slate-500">
                            Provider Reference
                        </span>

                        <span
                            class="text-right text-sm
                                   font-semibold"
                        >
                            <?= htmlspecialchars(
                                $order["provider_reference"],
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>
                        </span>

                    </div>

                <?php endif; ?>


            </div>


            <!-- ACTIONS -->
            <div class="mt-7 space-y-3">
                <?php if (strtolower((string)$order["status"]) === 'successful'): ?>
                <a
                    href="receipt.php?ref=<?= urlencode((string)$order["order_reference"]) ?>"
                    class="w-full flex items-center justify-center gap-2 rounded-xl
                           bg-emerald-600 px-5 py-3 text-center text-sm
                           font-bold text-white shadow-sm hover:bg-emerald-700 transition"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    View &amp; Print Receipt
                </a>
                <?php endif; ?>

                <div class="grid gap-3 sm:grid-cols-2">
                    <a
                        href="buy_data.php"
                        class="rounded-xl
                               bg-servora-700
                               px-5 py-3
                               text-center text-sm
                               font-bold text-white
                               hover:bg-servora-800"
                    >
                        Buy Another
                    </a>

                    <a
                        href="wallet.php"
                        class="rounded-xl
                               border border-slate-200
                               bg-white
                               px-5 py-3
                               text-center text-sm
                               font-bold text-slate-700
                               hover:bg-slate-50"
                    >
                        View Wallet
                    </a>
                </div>
            </div>


        </div>

    </section>

</main>

<?php require_once __DIR__ . "/../includes/client_bottom_nav.php"; ?>
</body>

</html>