<?php
/**
 * Subnext Client Mobile Bottom Navigation Bar
 * Unified, thumb-friendly mobile app navigation across all client pages.
 */
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');

$isHome = in_array($currentPage, [
    'dashboard.php', 'services.php', 'buy_data.php', 'airtime.php', 
    'electricity.php', 'cable.php', 'bulk_sms.php', 'exam_pins.php', 
    'foreign_numbers.php', 'foreign_number_options.php', 'foreign_number_result.php',
    'request_service.php'
], true);

$isOrders = in_array($currentPage, [
    'orders.php', 'data_order.php', 'foreign_number_history.php', 
    'request.php', 'request_tracker.php', 'my_request.php', 'view_request.php'
], true);

$isWallet = in_array($currentPage, [
    'wallet.php', 'fund_wallet.php', 'verify_payment.php', 'payment_cancelled.php'
], true);

$isProfile = in_array($currentPage, [
    'profile.php', 'change_password.php', 'notifications.php'
], true);
?>
<!-- Subnext Fixed Mobile Bottom Navigation Bar -->
<nav class="fixed bottom-0 left-0 right-0 z-50 border-t border-slate-200/90 bg-white/95 backdrop-blur-md md:hidden shadow-[0_-4px_25px_rgba(0,0,0,0.06)]">
    <div class="mx-auto grid max-w-md grid-cols-4 px-2 pt-2 pb-[max(0.6rem,env(safe-area-inset-bottom))]">
        <!-- 1. Home / Dashboard -->
        <a href="dashboard.php" class="flex flex-col items-center gap-1 rounded-2xl py-1 px-1 transition <?= $isHome ? 'text-servora-700 font-bold' : 'text-slate-400 hover:text-slate-700 font-medium' ?>" aria-label="Dashboard">
            <div class="relative">
                <svg class="h-5 w-5" fill="<?= $isHome ? 'currentColor' : 'none' ?>" viewBox="0 0 24 24" stroke="currentColor" stroke-width="<?= $isHome ? '2' : '1.8' ?>">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 10.5L12 3l9 7.5V21a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-10.5z"/>
                </svg>
                <?php if ($isHome): ?>
                    <span class="absolute -bottom-1.5 left-1/2 -translate-x-1/2 h-1 w-3 rounded-full bg-servora-600"></span>
                <?php endif; ?>
            </div>
            <span class="text-[10px] tracking-tight">Home</span>
        </a>

        <!-- 2. Orders / History -->
        <a href="orders.php" class="flex flex-col items-center gap-1 rounded-2xl py-1 px-1 transition <?= $isOrders ? 'text-servora-700 font-bold' : 'text-slate-400 hover:text-slate-700 font-medium' ?>" aria-label="Orders and History">
            <div class="relative">
                <svg class="h-5 w-5" fill="<?= $isOrders ? 'currentColor' : 'none' ?>" viewBox="0 0 24 24" stroke="currentColor" stroke-width="<?= $isOrders ? '2' : '1.8' ?>">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                </svg>
                <?php if ($isOrders): ?>
                    <span class="absolute -bottom-1.5 left-1/2 -translate-x-1/2 h-1 w-3 rounded-full bg-servora-600"></span>
                <?php endif; ?>
            </div>
            <span class="text-[10px] tracking-tight">Orders</span>
        </a>

        <!-- 3. Wallet -->
        <a href="wallet.php" class="flex flex-col items-center gap-1 rounded-2xl py-1 px-1 transition <?= $isWallet ? 'text-servora-700 font-bold' : 'text-slate-400 hover:text-slate-700 font-medium' ?>" aria-label="Wallet and Balance">
            <div class="relative">
                <svg class="h-5 w-5" fill="<?= $isWallet ? 'currentColor' : 'none' ?>" viewBox="0 0 24 24" stroke="currentColor" stroke-width="<?= $isWallet ? '2' : '1.8' ?>">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>
                </svg>
                <?php if ($isWallet): ?>
                    <span class="absolute -bottom-1.5 left-1/2 -translate-x-1/2 h-1 w-3 rounded-full bg-servora-600"></span>
                <?php endif; ?>
            </div>
            <span class="text-[10px] tracking-tight">Wallet</span>
        </a>

        <!-- 4. Profile / Settings -->
        <a href="profile.php" class="flex flex-col items-center gap-1 rounded-2xl py-1 px-1 transition <?= $isProfile ? 'text-servora-700 font-bold' : 'text-slate-400 hover:text-slate-700 font-medium' ?>" aria-label="Profile and Settings">
            <div class="relative">
                <svg class="h-5 w-5" fill="<?= $isProfile ? 'currentColor' : 'none' ?>" viewBox="0 0 24 24" stroke="currentColor" stroke-width="<?= $isProfile ? '2' : '1.8' ?>">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
                <?php if ($isProfile): ?>
                    <span class="absolute -bottom-1.5 left-1/2 -translate-x-1/2 h-1 w-3 rounded-full bg-servora-600"></span>
                <?php endif; ?>
            </div>
            <span class="text-[10px] tracking-tight">Profile</span>
        </a>
    </div>
</nav>
