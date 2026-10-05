<?php
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/client_auth.php";

$userId = currentUserId();

$fullName = trim((string)($_SESSION["full_name"] ?? "Client"));
$profileInitial = strtoupper(substr($fullName, 0, 1));

// Service filter
$filterService = trim((string)($_GET['service'] ?? 'all'));

// Fetch wallet balance
$stmt = $pdo->prepare("SELECT balance FROM wallets WHERE user_id = ? LIMIT 1");
$stmt->execute([$userId]);
$wallet = $stmt->fetch(PDO::FETCH_ASSOC);
$walletBalance = $wallet ? (float)$wallet['balance'] : 0.00;

// Fetch utility orders
$params = [$userId];
$sql = "
    SELECT
        id,
        order_reference,
        service_slug,
        product_name,
        customer_identifier,
        customer_name,
        selling_price AS amount,
        status,
        token_or_pin,
        created_at
    FROM utility_orders
    WHERE user_id = ?
";

if ($filterService !== 'all' && $filterService !== '') {
    $sql .= " AND service_slug = ?";
    $params[] = $filterService;
}

$sql .= " ORDER BY id DESC LIMIT 50";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order History - Servora</title>
    <!-- Tailwind CSS -->
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
        body { font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
    </style>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">

<!-- =========================================================
     DESKTOP NAVIGATION HEADER (VISIBLE ON MD+ SCREENS)
========================================================= -->
<header class="hidden md:block sticky top-0 z-40 border-b border-slate-200/80 bg-white/95 backdrop-blur">
    <div class="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
        <!-- Logo -->
        <a href="dashboard.php" class="flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-servora-700 text-lg font-black text-white shadow-sm">S</div>
            <div>
                <div class="text-lg font-bold tracking-tight text-slate-900 leading-tight">Servora</div>
                <div class="text-[10px] font-medium text-slate-400 uppercase tracking-wider">Order History</div>
            </div>
        </a>

        <!-- Desktop Navigation Links -->
        <nav class="flex items-center gap-1">
            <a href="dashboard.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">Dashboard</a>
            <a href="orders.php" class="rounded-xl bg-servora-50 px-3.5 py-2 text-xs font-bold text-servora-700">My Orders</a>
            <a href="wallet.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">Wallet</a>
            <a href="profile.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">Profile</a>
        </nav>

        <!-- Right User Actions -->
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

<!-- =========================================================
     MAIN BODY CONTAINER (EXACT SERVORA DESIGN FORMAT)
========================================================= -->
<main class="mx-auto w-full max-w-7xl px-4 py-6 pb-28 md:pb-8 sm:px-6 lg:px-8">

    <!-- HERO BANNER CARD -->
    <section class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-servora-800 via-servora-700 to-servora-500 p-6 sm:p-8 text-white shadow-xl">
        <a href="dashboard.php" class="text-sm font-semibold text-white/70 hover:text-white">
            ← Dashboard
        </a>

        <div class="mt-6">
            <p class="text-sm font-semibold text-white/70">
                Servora Activity
            </p>
            <h1 class="mt-1 text-3xl font-black">
                Order History
            </h1>
            <p class="mt-2 max-w-xl text-sm text-white/70">
                Track and view all your utility, airtime, bills, and digital service purchases.
            </p>
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

    <!-- ORDERS CONTENT CARD -->
    <section class="mt-6 rounded-3xl border border-slate-200 bg-white p-5 sm:p-7 shadow-sm">
        <!-- Section Header -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 border-b border-slate-100 pb-5">
            <div>
                <p class="text-xs font-bold uppercase tracking-widest text-servora-600">Purchases & Deliveries</p>
                <h2 class="mt-1 text-xl font-black text-slate-900">Recent Orders</h2>
                <p class="mt-1 text-xs text-slate-500">Filter orders by service type below.</p>
            </div>
            <div class="flex gap-2">
                <a href="data_order.php" class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-100 transition shadow-xs">Data Orders</a>
                <a href="foreign_number_history.php" class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-100 transition shadow-xs">Number Orders</a>
            </div>
        </div>

        <!-- Filter Pills -->
        <div class="mt-5 flex flex-wrap gap-2">
            <?php
            $filters = [
                'all' => 'All Services',
                'airtime' => 'Airtime',
                'electricity' => 'Electricity',
                'cable_tv' => 'Cable TV',
                'exam_pins' => 'Exam PINs',
                'bulk_sms' => 'Bulk SMS'
            ];
            foreach ($filters as $k => $label):
                $isActive = ($filterService === $k);
            ?>
            <a href="orders.php?service=<?= urlencode($k) ?>"
                class="rounded-xl px-3.5 py-1.5 text-xs font-bold transition <?= $isActive ? 'bg-servora-700 text-white shadow-sm' : 'bg-slate-50 text-slate-600 border border-slate-200 hover:bg-slate-100' ?>">
                <?= htmlspecialchars($label) ?>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- Orders Table / Mobile List -->
        <div class="mt-6 overflow-hidden rounded-2xl border border-slate-200">
            <?php if (empty($orders)): ?>
            <div class="p-12 text-center">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400 mb-3">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                </div>
                <h3 class="font-bold text-slate-800 text-base">No orders found</h3>
                <p class="text-sm text-slate-500 mt-1">You have not placed any orders under this category yet.</p>
                <a href="dashboard.php" class="inline-block mt-4 rounded-xl bg-servora-700 px-4 py-2 text-xs font-bold text-white shadow-sm hover:bg-servora-800 transition">Explore Services</a>
            </div>
            <?php else: ?>

            <!-- MOBILE CARD VIEW -->
            <div class="block md:hidden divide-y divide-slate-100">
                <?php foreach ($orders as $o):
                    $status = strtolower($o['status']);
                    $statusClass = match($status) {
                        'successful' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                        'pending' => 'bg-amber-50 text-amber-700 border-amber-200',
                        'refunded' => 'bg-blue-50 text-blue-700 border-blue-200',
                        default => 'bg-rose-50 text-rose-700 border-rose-200'
                    };
                ?>
                <div class="p-4 space-y-2.5">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400"><?= htmlspecialchars($o['service_slug']) ?></span>
                            <h4 class="text-sm font-bold text-slate-900"><?= htmlspecialchars($o['product_name']) ?></h4>
                        </div>
                        <span class="inline-flex rounded-full border px-2 py-0.5 text-[10px] font-bold capitalize <?= $statusClass ?>">
                            <?= htmlspecialchars($status) ?>
                        </span>
                    </div>

                    <div class="flex items-center justify-between text-xs">
                        <span class="text-slate-500">Recipient: <strong class="text-slate-800"><?= htmlspecialchars($o['customer_identifier']) ?></strong></span>
                        <span class="text-sm font-black text-slate-900">₦<?= number_format((float)$o['amount'], 2) ?></span>
                    </div>

                    <?php if (!empty($o['token_or_pin'])): ?>
                    <div class="rounded-xl bg-slate-50 p-2.5 border border-slate-200 flex items-center justify-between gap-2">
                        <div class="overflow-hidden">
                            <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-wide">Token / Generated PIN</span>
                            <span class="font-mono text-xs font-bold text-slate-900 truncate block"><?= htmlspecialchars($o['token_or_pin']) ?></span>
                        </div>
                        <button type="button" onclick="navigator.clipboard.writeText('<?= addslashes($o['token_or_pin']) ?>'); alert('Copied to clipboard!');"
                            class="shrink-0 rounded-lg bg-white px-2.5 py-1 text-[10px] font-bold text-servora-700 border border-slate-200 shadow-xs hover:bg-slate-50">
                            Copy
                        </button>
                    </div>
                    <?php endif; ?>

                    <div class="flex items-center justify-between text-[10px] text-slate-400 pt-1 border-t border-slate-50">
                        <span class="font-mono"><?= htmlspecialchars($o['order_reference']) ?></span>
                        <div class="flex items-center gap-3">
                            <?php if ($status === 'successful'): ?>
                            <a href="receipt.php?ref=<?= urlencode($o['order_reference']) ?>" class="font-semibold text-servora-600 hover:text-servora-800 inline-flex items-center gap-1">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                Receipt
                            </a>
                            <?php endif; ?>
                            <span><?= date('M d, Y • h:i A', strtotime($o['created_at'])) ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- DESKTOP TABLE VIEW -->
            <div class="hidden md:block overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-600">
                    <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wider text-slate-500 border-b border-slate-200">
                        <tr>
                            <th class="px-6 py-3.5">Reference / Date</th>
                            <th class="px-6 py-3.5">Service / Product</th>
                            <th class="px-6 py-3.5">Recipient / Details</th>
                            <th class="px-6 py-3.5">Amount</th>
                            <th class="px-6 py-3.5">Status</th>
                            <th class="px-6 py-3.5">Token / Result</th>
                            <th class="px-6 py-3.5 text-right">Receipt</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($orders as $o):
                            $status = strtolower($o['status']);
                            $statusClass = match($status) {
                                'successful' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                'pending' => 'bg-amber-50 text-amber-700 border-amber-200',
                                'refunded' => 'bg-blue-50 text-blue-700 border-blue-200',
                                default => 'bg-rose-50 text-rose-700 border-rose-200'
                            };
                        ?>
                        <tr class="hover:bg-slate-50/80 transition">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="font-mono font-bold text-slate-900 block"><?= htmlspecialchars($o['order_reference']) ?></span>
                                <span class="text-xs text-slate-400"><?= date('M d, Y • h:i A', strtotime($o['created_at'])) ?></span>
                            </td>
                            <td class="px-6 py-4">
                                <span class="font-bold text-slate-800 block"><?= htmlspecialchars($o['product_name']) ?></span>
                                <span class="text-xs uppercase text-slate-400"><?= htmlspecialchars($o['service_slug']) ?></span>
                            </td>
                            <td class="px-6 py-4">
                                <span class="font-semibold text-slate-900 block"><?= htmlspecialchars($o['customer_identifier']) ?></span>
                                <?php if (!empty($o['customer_name'])): ?>
                                <span class="text-xs text-slate-500"><?= htmlspecialchars($o['customer_name']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="font-bold text-slate-900">₦<?= number_format((float)$o['amount'], 2) ?></span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="inline-flex rounded-full border px-2.5 py-0.5 text-xs font-bold capitalize <?= $statusClass ?>">
                                    <?= htmlspecialchars($status) ?>
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <?php if (!empty($o['token_or_pin'])): ?>
                                <div class="flex items-center gap-2">
                                    <div class="max-w-xs overflow-hidden text-ellipsis whitespace-nowrap font-mono text-xs font-semibold text-slate-900 bg-slate-50 p-1.5 rounded-lg border border-slate-200" title="<?= htmlspecialchars($o['token_or_pin']) ?>">
                                        <?= htmlspecialchars($o['token_or_pin']) ?>
                                    </div>
                                    <button type="button" onclick="navigator.clipboard.writeText('<?= addslashes($o['token_or_pin']) ?>'); alert('Copied!');"
                                        class="shrink-0 rounded-lg bg-white px-2 py-1 text-xs font-semibold text-servora-700 border border-slate-200 hover:bg-slate-50">
                                        Copy
                                    </button>
                                </div>
                                <?php else: ?>
                                <span class="text-xs text-slate-400">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right">
                                <?php if ($status === 'successful'): ?>
                                <a href="receipt.php?ref=<?= urlencode($o['order_reference']) ?>" class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold text-servora-700 bg-servora-50 hover:bg-servora-100 border border-servora-200 rounded-lg transition">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                    Receipt
                                </a>
                                <?php else: ?>
                                <span class="text-xs text-slate-300">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </section>

</main>

<?php require_once __DIR__ . "/../includes/client_bottom_nav.php"; ?>

</body>
</html>
