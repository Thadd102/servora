<?php
require_once "../config/database.php";
require_once "../includes/client_auth.php";

$userId = currentUserId();

/*
|--------------------------------------------------------------------------
| Get wallet balance
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT balance
    FROM wallets
    WHERE user_id = ?
    LIMIT 1
");
$stmt->execute([$userId]);
$wallet = $stmt->fetch();
$balance = $wallet ? (float)$wallet["balance"] : 0.00;

/*
|--------------------------------------------------------------------------
| Get wallet transactions
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT
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
");
$stmt->execute([$userId]);
$transactions = $stmt->fetchAll();

$profileInitial = strtoupper(substr($_SESSION["full_name"] ?? "U", 0, 1));

// Payment status message
$paymentStatus = $_GET['payment'] ?? '';
$paymentMessage = match ($paymentStatus) {
    'success' => 'Payment confirmed. Your wallet has been successfully updated.',
    'cancelled' => 'Payment was cancelled. Your wallet was not debited.',
    'failed' => 'Payment failed. Your wallet was not credited.',
    'invalid' => 'The payment response was invalid. No wallet credit was made.',
    'verification_error' => 'We could not verify that payment yet. Please check your transactions before retrying.',
    'processing_error' => 'Payment verification needs attention. Please contact support if you were debited.',
    default => '',
};

$isPaymentSuccess = ($paymentStatus === 'success');
$isPaymentCancelled = ($paymentStatus === 'cancelled');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Wallet | Subnext</title>
    <!-- Precompiled Production Stylesheet -->
    <link rel="stylesheet" href="../assets/css/style.css">
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
                <div class="text-lg font-bold tracking-tight text-slate-900 leading-tight">Subnext</div>
                <div class="text-[10px] font-medium text-slate-400 uppercase tracking-wider">Wallet & Transactions</div>
            </div>
        </a>

        <!-- Desktop Navigation Links -->
        <nav class="flex items-center gap-1">
            <a href="dashboard.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">Dashboard</a>
            <a href="orders.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">My Orders</a>
            <a href="wallet.php" class="rounded-xl bg-servora-50 px-3.5 py-2 text-xs font-bold text-servora-700">Wallet</a>
            <a href="support.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">Support</a>
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
            <a href="../logout.php" class="inline-flex items-center gap-1.5 rounded-xl border border-red-200 bg-red-50/80 px-3.5 py-2 text-xs font-bold text-red-600 transition hover:bg-red-100 hover:border-red-300" title="Logout">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                </svg>
                <span>Logout</span>
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
                Subnext Wallet
            </p>
            <h1 class="mt-1 text-3xl font-black">
                My Wallet
            </h1>
            <p class="mt-2 max-w-xl text-sm text-white/70">
                Manage your Subnext balance and view your complete transaction history.
            </p>
        </div>
    </section>

    <!-- Payment Notice Feedback Banner (if present) -->
    <?php if ($paymentMessage !== ''): ?>
        <div class="mt-6 rounded-2xl border p-4 text-sm font-semibold shadow-sm <?= $isPaymentSuccess ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : ($isPaymentCancelled ? 'border-slate-200 bg-slate-50 text-slate-700' : 'border-red-200 bg-red-50 text-red-900') ?>">
            <?= htmlspecialchars($paymentMessage, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <!-- WALLET BALANCE CARD (STANDARDIZED) -->
    <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex items-center justify-between gap-4">
            <div>
                <p class="text-xs font-bold uppercase tracking-wide text-slate-400">
                    Wallet Balance
                </p>
                <p class="mt-1 text-2xl font-black text-slate-900">
                    ₦<?= number_format($balance, 2) ?>
                </p>
            </div>

            <a href="fund_wallet.php" class="rounded-xl bg-servora-50 px-4 py-2.5 text-sm font-bold text-servora-700 hover:bg-servora-100 transition">
                Fund Wallet
            </a>
        </div>
    </section>

    <!-- TRANSACTION HISTORY (CONTENT CARD FORMAT) -->
    <section class="mt-6 rounded-3xl border border-slate-200 bg-white p-5 sm:p-7 shadow-sm">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-widest text-servora-600">
                    Ledger Statement
                </p>
                <h2 class="mt-1 text-xl font-black text-slate-900">
                    Transaction History
                </h2>
                <p class="mt-1 text-sm text-slate-500">
                    All credits, debits, payments, and refunds.
                </p>
            </div>

            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">
                <?= count($transactions) ?> <?= count($transactions) === 1 ? 'record' : 'records' ?>
            </span>
        </div>

        <?php if (empty($transactions)): ?>
            <!-- Empty State -->
            <div class="p-8 sm:p-12 text-center">
                <div class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-servora-50 text-servora-700">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <circle cx="12" cy="12" r="9"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3"/>
                    </svg>
                </div>
                <h3 class="text-base font-bold text-slate-900">No transactions yet</h3>
                <p class="mt-1 text-xs text-slate-400 max-w-sm mx-auto">
                    Your wallet activity will appear here when you fund your wallet or make a service payment.
                </p>
                <div class="mt-5">
                    <a href="fund_wallet.php" class="inline-flex items-center gap-1.5 rounded-xl bg-servora-700 px-4 py-2.5 text-xs font-bold text-white shadow-sm transition hover:bg-servora-800">
                        Fund Wallet
                    </a>
                </div>
            </div>
        <?php else: ?>

            <!-- MOBILE VIEW: TRANSACTION CARDS -->
            <div class="mt-5 md:hidden divide-y divide-slate-100">
                <?php foreach ($transactions as $transaction):
                    $type = $transaction["type"];
                    $typeLabel = ucwords(str_replace("_", " ", $type));
                    $status = strtolower((string)$transaction["status"]);

                    $isCredit = in_array($type, ['credit', 'refund', 'funding', 'adjustment'], true);
                    $amountColor = $isCredit ? 'text-emerald-600' : 'text-slate-900';

                    $statusBadge = match ($status) {
                        'successful' => 'text-emerald-700 bg-emerald-50',
                        'failed' => 'text-red-700 bg-red-50',
                        'reversed' => 'text-red-700 bg-red-50',
                        'pending' => 'text-amber-700 bg-amber-50',
                        default => 'text-slate-600 bg-slate-100'
                    };
                ?>
                <div class="py-3.5 transition hover:bg-slate-50/70">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl <?= $isCredit ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-600' ?>">
                                <?php if ($isCredit): ?>
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
                                <?php else: ?>
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 10l7-7m0 0l7 7m-7-7v18"/></svg>
                                <?php endif; ?>
                            </div>
                            <div class="min-w-0">
                                <p class="truncate text-xs font-bold text-slate-900">
                                    <?= htmlspecialchars($transaction["description"] ?? $typeLabel) ?>
                                </p>
                                <p class="text-[10px] text-slate-400 font-mono truncate">
                                    <?= date('M d, Y • h:i A', strtotime($transaction["created_at"])) ?>
                                </p>
                            </div>
                        </div>

                        <div class="text-right shrink-0">
                            <p class="font-mono text-xs font-black <?= $amountColor ?>">
                                <?= $isCredit ? '+' : '-' ?>₦<?= number_format((float)$transaction["amount"], 2) ?>
                            </p>
                            <span class="inline-block mt-0.5 rounded px-1.5 py-0.5 text-[9px] font-bold <?= $statusBadge ?>">
                                <?= htmlspecialchars(ucfirst($status)) ?>
                            </span>
                        </div>
                    </div>

                    <div class="mt-2 flex items-center justify-between text-[11px] text-slate-400 border-t border-slate-100/80 pt-1.5">
                        <div class="flex items-center gap-2">
                            <span>Balance after:</span>
                            <span class="font-mono font-semibold text-slate-600">₦<?= isset($transaction["balance_after"]) && $transaction["balance_after"] !== null ? number_format((float)$transaction["balance_after"], 2) : '—' ?></span>
                        </div>
                        <?php if ($status === 'successful' && !empty($transaction["reference"])): ?>
                        <a href="receipt.php?ref=<?= urlencode((string)$transaction["reference"]) ?>" class="font-bold text-servora-600 hover:text-servora-800 inline-flex items-center gap-0.5">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            Receipt
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- DESKTOP VIEW: CLEAN DATA TABLE -->
            <div class="mt-5 hidden md:block overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-100 text-slate-400 font-bold uppercase tracking-wider text-[10px]">
                            <th class="px-5 py-3">Type & Description</th>
                            <th class="px-5 py-3">Reference</th>
                            <th class="px-5 py-3 text-right">Amount</th>
                            <th class="px-5 py-3 text-right">Balance After</th>
                            <th class="px-5 py-3 text-center">Status</th>
                            <th class="px-5 py-3 text-right">Date</th>
                            <th class="px-5 py-3 text-right">Receipt</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($transactions as $transaction):
                            $type = $transaction["type"];
                            $typeLabel = ucwords(str_replace("_", " ", $type));
                            $status = strtolower((string)$transaction["status"]);

                            $isCredit = in_array($type, ['credit', 'refund', 'funding', 'adjustment'], true);
                            $amountColor = $isCredit ? 'text-emerald-600' : 'text-slate-900';

                            $statusBadge = match ($status) {
                                'successful' => 'text-emerald-700 bg-emerald-50',
                                'failed' => 'text-red-700 bg-red-50',
                                'reversed' => 'text-red-700 bg-red-50',
                                'pending' => 'text-amber-700 bg-amber-50',
                                default => 'text-slate-600 bg-slate-100'
                            };
                        ?>
                        <tr class="hover:bg-slate-50/70 transition">
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-2.5">
                                    <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg <?= $isCredit ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-600' ?>">
                                        <?php if ($isCredit): ?>
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
                                        <?php else: ?>
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 10l7-7m0 0l7 7m-7-7v18"/></svg>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <p class="font-bold text-slate-900"><?= htmlspecialchars($transaction["description"] ?? $typeLabel) ?></p>
                                        <span class="text-[10px] text-slate-400"><?= htmlspecialchars($typeLabel) ?></span>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3.5 font-mono text-[11px] text-slate-500">
                                <?= htmlspecialchars($transaction["reference"] ?? '—') ?>
                            </td>
                            <td class="px-5 py-3.5 text-right font-mono font-bold <?= $amountColor ?>">
                                <?= $isCredit ? '+' : '-' ?>₦<?= number_format((float)$transaction["amount"], 2) ?>
                            </td>
                            <td class="px-5 py-3.5 text-right font-mono text-slate-600">
                                <?= isset($transaction["balance_after"]) && $transaction["balance_after"] !== null ? '₦' . number_format((float)$transaction["balance_after"], 2) : '—' ?>
                            </td>
                            <td class="px-5 py-3.5 text-center">
                                <span class="inline-block rounded-md px-2 py-0.5 text-[10px] font-bold <?= $statusBadge ?>">
                                    <?= htmlspecialchars(ucfirst($status)) ?>
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-right text-slate-400 font-mono text-[11px]">
                                <?= date('M d, Y • h:i A', strtotime($transaction["created_at"])) ?>
                            </td>
                            <td class="px-5 py-3.5 text-right whitespace-nowrap">
                                <?php if ($status === 'successful' && !empty($transaction["reference"])): ?>
                                <a href="receipt.php?ref=<?= urlencode((string)$transaction["reference"]) ?>" class="inline-flex items-center gap-1 px-2 py-0.5 text-[11px] font-semibold text-servora-700 bg-servora-50 hover:bg-servora-100 border border-servora-200 rounded-lg transition">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                    Receipt
                                </a>
                                <?php else: ?>
                                <span class="text-slate-300">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>
    </section>

</main>

<!-- =========================================================
     STANDARDIZED MOBILE BOTTOM NAVIGATION
========================================================= -->
<?php require_once __DIR__ . "/../includes/client_bottom_nav.php"; ?>

</body>
</html>
