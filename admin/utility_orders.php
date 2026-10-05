<?php
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/admin_auth.php";

$adminName = $_SESSION["full_name"] ?? "Admin";

// CSRF token
if (empty($_SESSION["admin_csrf"])) {
    $_SESSION["admin_csrf"] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION["admin_csrf"];

$message = null;
$messageType = 'success';

// Handle manual refund action
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $submittedCsrf = trim((string)($_POST["csrf_token"] ?? ""));
    if (!hash_equals($csrfToken, $submittedCsrf)) {
        $message = "Invalid security token.";
        $messageType = "error";
    } else {
        $action = trim((string)($_POST["action"] ?? ""));
        if ($action === 'refund_order') {
            $orderId = (int)($_POST["order_id"] ?? 0);

            // Fetch order with user info
            $stmt = $pdo->prepare("SELECT * FROM utility_orders WHERE id = ? LIMIT 1");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                $message = "Order not found.";
                $messageType = "error";
            } elseif ($order['status'] === 'refunded') {
                $message = "This order has already been refunded.";
                $messageType = "error";
            } else {
                $pdo->beginTransaction();
                try {
                    $userId = (int)$order['user_id'];
                    $amount = (float)$order['selling_price'];

                    // Lock wallet
                    $stmt = $pdo->prepare("SELECT id, balance FROM wallets WHERE user_id = ? FOR UPDATE");
                    $stmt->execute([$userId]);
                    $w = $stmt->fetch(PDO::FETCH_ASSOC);

                    $balBefore = (float)$w['balance'];
                    $balAfter = $balBefore + $amount;

                    $stmt = $pdo->prepare("UPDATE wallets SET balance = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$balAfter, $w['id']]);

                    $ref = 'ADM-REF-' . $order['order_reference'];
                    $stmt = $pdo->prepare("
                        INSERT INTO wallet_transactions
                        (user_id, type, amount, balance_before, balance_after, reference, description, status, created_at, updated_at)
                        VALUES (?, 'refund', ?, ?, ?, ?, ?, 'successful', NOW(), NOW())
                    ");
                    $stmt->execute([
                        $userId,
                        $amount,
                        $balBefore,
                        $balAfter,
                        $ref,
                        "Admin refund for order " . $order['order_reference'] . " (" . $order['product_name'] . ")"
                    ]);

                    $stmt = $pdo->prepare("UPDATE utility_orders SET status = 'refunded', updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$orderId]);

                    $pdo->commit();
                    $message = "Order " . $order['order_reference'] . " refunded ₦" . number_format($amount, 2) . " successfully to client wallet.";
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $message = "Refund failed: " . $e->getMessage();
                    $messageType = "error";
                }
            }
        }
    }
}

// Filters & Search
$selectedService = trim((string)($_GET['service'] ?? 'all'));
$selectedStatus = trim((string)($_GET['status'] ?? 'all'));
$search = trim((string)($_GET['search'] ?? ''));

$sql = "
    SELECT uo.*, u.full_name AS client_name, u.email AS client_email
    FROM utility_orders uo
    LEFT JOIN users u ON u.id = uo.user_id
    WHERE 1=1
";
$params = [];

if ($selectedService !== 'all' && $selectedService !== '') {
    $sql .= " AND uo.service_slug = ?";
    $params[] = $selectedService;
}

if ($selectedStatus !== 'all' && $selectedStatus !== '') {
    $sql .= " AND uo.status = ?";
    $params[] = $selectedStatus;
}

if (!empty($search)) {
    $sql .= " AND (uo.order_reference LIKE ? OR uo.customer_identifier LIKE ? OR uo.customer_name LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY uo.id DESC LIMIT 100";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Utility Orders - Subnext Admin</title>
    <!-- Precompiled Production Stylesheet -->
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="bg-slate-50 text-slate-900 min-h-screen">

<!-- Admin Navigation Bar -->
<header class="sticky top-0 z-40 border-b border-slate-200 bg-white/95 backdrop-blur">
    <div class="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
        <div class="flex items-center gap-4">
            <a href="dashboard.php" class="flex items-center gap-2">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-servora-700 text-lg font-black text-white">S</div>
                <div>
                    <span class="font-bold text-slate-900 block leading-tight">Subnext</span>
                    <span class="text-[10px] uppercase font-bold text-servora-700">Admin Panel</span>
                </div>
            </a>
            <nav class="hidden md:flex items-center gap-1 ml-6 text-xs font-semibold text-slate-600">
                <a href="dashboard.php" class="px-3 py-2 rounded-lg hover:bg-slate-100">Dashboard</a>
                <a href="service_pricing.php" class="px-3 py-2 rounded-lg hover:bg-slate-100">Services & Pricing</a>
                <a href="utility_orders.php" class="px-3 py-2 rounded-lg bg-servora-50 text-servora-700">Utility Orders</a>
                <a href="data_orders.php" class="px-3 py-2 rounded-lg hover:bg-slate-100">Data Orders</a>
                <a href="foreign_number_orders.php" class="px-3 py-2 rounded-lg hover:bg-slate-100">Foreign Numbers</a>
                <a href="clients.php" class="px-3 py-2 rounded-lg hover:bg-slate-100">Clients</a>
            </nav>
        </div>
        <div class="flex items-center gap-3">
            <a href="../logout.php" class="rounded-xl border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-bold text-red-600 hover:bg-red-100">Logout</a>
        </div>
    </div>
</header>

<main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-slate-900">Utility & Bills Orders</h1>
            <p class="text-sm text-slate-500">Monitor Airtime, Electricity tokens, Cable subscriptions, Bulk SMS, and Exam PINs.</p>
        </div>
        <a href="dashboard.php" class="inline-flex items-center gap-2 text-xs font-bold text-slate-600 hover:text-servora-700 bg-white border border-slate-200 px-3 py-2 rounded-xl shadow-sm">
            ← Back to Admin Dashboard
        </a>
    </div>

    <?php if ($message): ?>
    <div class="mb-6 rounded-2xl <?= $messageType === 'success' ? 'bg-emerald-50 text-emerald-900 border-emerald-200' : 'bg-rose-50 text-rose-900 border-rose-200' ?> border p-4 text-sm font-semibold">
        <?= htmlspecialchars($message) ?>
    </div>
    <?php endif; ?>

    <!-- Search & Filters -->
    <div class="mb-6 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
        <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <div>
                <label class="block text-xs font-bold uppercase text-slate-500 mb-1">Service</label>
                <select name="service" class="w-full rounded-xl border border-slate-300 p-2.5 text-xs font-semibold">
                    <option value="all" <?= $selectedService === 'all' ? 'selected' : '' ?>>All Services</option>
                    <option value="airtime" <?= $selectedService === 'airtime' ? 'selected' : '' ?>>Airtime</option>
                    <option value="electricity" <?= $selectedService === 'electricity' ? 'selected' : '' ?>>Electricity</option>
                    <option value="cable_tv" <?= $selectedService === 'cable_tv' ? 'selected' : '' ?>>Cable TV</option>
                    <option value="exam_pins" <?= $selectedService === 'exam_pins' ? 'selected' : '' ?>>Exam PINs</option>
                    <option value="bulk_sms" <?= $selectedService === 'bulk_sms' ? 'selected' : '' ?>>Bulk SMS</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-bold uppercase text-slate-500 mb-1">Status</label>
                <select name="status" class="w-full rounded-xl border border-slate-300 p-2.5 text-xs font-semibold">
                    <option value="all" <?= $selectedStatus === 'all' ? 'selected' : '' ?>>All Statuses</option>
                    <option value="successful" <?= $selectedStatus === 'successful' ? 'selected' : '' ?>>Successful</option>
                    <option value="pending" <?= $selectedStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="failed" <?= $selectedStatus === 'failed' ? 'selected' : '' ?>>Failed</option>
                    <option value="refunded" <?= $selectedStatus === 'refunded' ? 'selected' : '' ?>>Refunded</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-bold uppercase text-slate-500 mb-1">Search</label>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Ref, Meter, Phone, Client"
                    class="w-full rounded-xl border border-slate-300 p-2.5 text-xs font-semibold">
            </div>

            <div class="flex items-end gap-2">
                <button type="submit" class="flex-1 rounded-xl bg-servora-700 py-2.5 text-xs font-bold text-white hover:bg-servora-800">
                    Apply Filter
                </button>
                <a href="utility_orders.php" class="rounded-xl border border-slate-200 px-3 py-2.5 text-xs font-bold text-slate-600 hover:bg-slate-50">
                    Reset
                </a>
            </div>
        </form>
    </div>

    <!-- Orders Container -->
    <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">

        <!-- MOBILE CARDS (Phones < 768px) -->
        <div class="block md:hidden divide-y divide-slate-100">
            <?php if (empty($orders)): ?>
            <div class="p-8 text-center text-xs text-slate-400">No matching utility orders found.</div>
            <?php else: ?>
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

                <div class="rounded-xl bg-slate-50 p-2.5 text-xs space-y-1">
                    <div class="flex justify-between">
                        <span class="text-slate-500">Client:</span>
                        <span class="font-bold text-slate-900"><?= htmlspecialchars($o['client_name'] ?? 'Client') ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-500">Recipient:</span>
                        <span class="font-mono font-bold text-slate-900"><?= htmlspecialchars($o['customer_identifier']) ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-500">Selling Price:</span>
                        <span class="font-black text-slate-900">₦<?= number_format((float)$o['selling_price'], 2) ?></span>
                    </div>
                    <div class="flex justify-between text-[11px]">
                        <span class="text-slate-400">Cost: ₦<?= number_format((float)$o['provider_cost'], 2) ?></span>
                        <span class="font-bold text-emerald-600">Profit: +₦<?= number_format((float)$o['profit'], 2) ?></span>
                    </div>
                </div>

                <?php if (!empty($o['token_or_pin'])): ?>
                <div class="rounded-xl bg-indigo-50/50 p-2.5 border border-indigo-100 flex items-center justify-between gap-2">
                    <div class="overflow-hidden">
                        <span class="block text-[10px] font-bold text-indigo-400 uppercase">Token / PIN</span>
                        <span class="font-mono text-xs font-bold text-indigo-950 truncate block"><?= htmlspecialchars($o['token_or_pin']) ?></span>
                    </div>
                    <button type="button" onclick="navigator.clipboard.writeText('<?= addslashes($o['token_or_pin']) ?>'); alert('Copied!');"
                        class="shrink-0 rounded-lg bg-white px-2 py-1 text-[10px] font-bold text-servora-700 border border-slate-200">
                        Copy
                    </button>
                </div>
                <?php endif; ?>

                <div class="flex items-center justify-between pt-1">
                    <div class="text-[10px] text-slate-400 font-mono">
                        <?= htmlspecialchars($o['order_reference']) ?> • <?= date('M d, h:i A', strtotime($o['created_at'])) ?>
                    </div>
                    <?php if ($status !== 'refunded'): ?>
                    <form method="POST" class="inline" onsubmit="return confirm('Refund ₦<?= number_format((float)$o['selling_price'], 2) ?> to <?= htmlspecialchars($o['client_name'] ?? 'client') ?> wallet?');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="refund_order">
                        <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                        <button type="submit" class="rounded-lg border border-rose-200 bg-rose-50 px-2.5 py-1 text-xs font-bold text-rose-700 hover:bg-rose-100">
                            Refund
                        </button>
                    </form>
                    <?php else: ?>
                    <span class="text-[11px] text-slate-400 font-semibold">Refunded</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- DESKTOP TABLE (Screens >= 768px) -->
        <div class="hidden md:block overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-600">
                <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wider text-slate-500 border-b border-slate-200">
                    <tr>
                        <th class="px-6 py-4">Reference / Client</th>
                        <th class="px-6 py-4">Service / Product</th>
                        <th class="px-6 py-4">Customer Identifier</th>
                        <th class="px-6 py-4">Selling / Cost</th>
                        <th class="px-6 py-4">Profit</th>
                        <th class="px-6 py-4">Status</th>
                        <th class="px-6 py-4">Token / Output</th>
                        <th class="px-6 py-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($orders)): ?>
                    <tr>
                        <td colspan="8" class="p-8 text-center text-xs text-slate-400">No matching utility orders found.</td>
                    </tr>
                    <?php else: ?>
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
                        <td class="px-6 py-4">
                            <span class="font-mono font-bold text-slate-900 block"><?= htmlspecialchars($o['order_reference']) ?></span>
                            <span class="text-xs text-slate-500"><?= htmlspecialchars($o['client_name'] ?? 'Client') ?> (<?= htmlspecialchars($o['client_email'] ?? '') ?>)</span>
                            <span class="block text-[10px] text-slate-400"><?= date('M d, Y • h:i A', strtotime($o['created_at'])) ?></span>
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
                            <span class="font-bold text-slate-900 block">₦<?= number_format((float)$o['selling_price'], 2) ?></span>
                            <span class="text-xs text-slate-400 font-mono">Cost: ₦<?= number_format((float)$o['provider_cost'], 2) ?></span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="font-bold text-emerald-600">+₦<?= number_format((float)$o['profit'], 2) ?></span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="inline-flex rounded-full border px-2.5 py-0.5 text-xs font-bold capitalize <?= $statusClass ?>">
                                <?= htmlspecialchars($status) ?>
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            <?php if (!empty($o['token_or_pin'])): ?>
                            <div class="max-w-[200px] overflow-hidden text-ellipsis whitespace-nowrap font-mono text-xs font-semibold text-slate-800 bg-slate-50 p-1.5 rounded-lg border border-slate-200" title="<?= htmlspecialchars($o['token_or_pin']) ?>">
                                <?= htmlspecialchars($o['token_or_pin']) ?>
                            </div>
                            <?php else: ?>
                            <span class="text-xs text-slate-400">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 text-right whitespace-nowrap">
                            <?php if ($status !== 'refunded'): ?>
                            <form method="POST" class="inline" onsubmit="return confirm('Refund ₦<?= number_format((float)$o['selling_price'], 2) ?> to <?= htmlspecialchars($o['client_name'] ?? 'client') ?> wallet?');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="action" value="refund_order">
                                <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                                <button type="submit" class="rounded-xl border border-rose-200 bg-rose-50 px-2.5 py-1 text-xs font-bold text-rose-700 hover:bg-rose-100">
                                    Refund
                                </button>
                            </form>
                            <?php else: ?>
                            <span class="text-xs text-slate-400 font-medium">Refunded</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

</body>
</html>
