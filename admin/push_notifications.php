<?php

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/admin_auth.php";
require_once __DIR__ . "/../includes/PushNotificationService.php";

$adminId = (int)($_SESSION['user_id'] ?? 0);
$pushService = new PushNotificationService($pdo);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$success = $_SESSION['push_success'] ?? null;
$error = $_SESSION['push_error'] ?? null;
unset($_SESSION['push_success'], $_SESSION['push_error']);

// Handle Push Dispatch
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $postedToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrfToken, $postedToken)) {
        $_SESSION['push_error'] = "Invalid CSRF security token.";
        header("Location: push_notifications.php");
        exit;
    }

    try {
        if ($action === 'send') {
            $title = trim((string)($_POST['title'] ?? ''));
            $body = trim((string)($_POST['body'] ?? ''));
            $targetUrl = trim((string)($_POST['target_url'] ?? '/client/dashboard.php'));
            $targetType = trim((string)($_POST['target_type'] ?? 'all'));
            $targetUserIds = trim((string)($_POST['target_user_ids'] ?? ''));

            if ($title === '' || $body === '') {
                throw new Exception("Title and body are required to send a push notification.");
            }

            $res = $pushService->send([
                'title' => $title,
                'body' => $body,
                'target_url' => $targetUrl,
                'target_type' => $targetType,
                'target_user_ids' => $targetUserIds,
            ], $adminId);

            if (!empty($res['ok'])) {
                $sent = (int)($res['total_sent'] ?? 0);
                $targeted = (int)($res['total_targeted'] ?? 0);
                $failed = (int)($res['total_failed'] ?? 0);
                $cleaned = (int)($res['expired_cleaned'] ?? 0);

                $msg = "Push notification dispatched! Delivered to $sent / $targeted subscribers.";
                if ($cleaned > 0) {
                    $msg .= " ($cleaned expired subscriptions automatically cleaned up)";
                }
                $_SESSION['push_success'] = $msg;
            } else {
                throw new Exception($res['error'] ?? "Failed to dispatch push notification.");
            }

            header("Location: push_notifications.php");
            exit;
        }
    } catch (Exception $e) {
        $_SESSION['push_error'] = $e->getMessage();
        header("Location: push_notifications.php");
        exit;
    }
}

// Retrieve stats & history
$stats = [
    'total_subscribers' => 0,
    'total_notifications_sent' => 0,
    'successful_deliveries' => 0,
    'delivery_rate' => 0,
];
$history = [];
$vapidPubKey = '';

try {
    $stats = $pushService->getStats();
    $history = $pushService->getHistory(50);
    $vapidPubKey = $pushService->getVapidPublicKey();
} catch (Throwable $e) {
    $error = "Notice: Could not load push statistics: " . $e->getMessage();
}

function e($str): string {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Real-Time Push Notifications | Subnext Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/images/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="../assets/images/favicon-16x16.png">
    <link rel="shortcut icon" href="../favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="../assets/images/apple-touch-icon.png">
</head>
<body class="min-h-screen bg-slate-50 text-slate-800">

<div class="min-h-screen pb-16">

    <!-- TOP HEADER -->
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto max-w-7xl px-4 py-5 sm:px-6 lg:px-8">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div class="mb-2 inline-flex items-center gap-2 rounded-full bg-servora-50 px-3 py-1 text-xs font-semibold text-servora-700">
                        <span class="h-2 w-2 rounded-full bg-servora-600"></span>
                        Web Push & Mobile Alerts
                    </div>
                    <h1 class="text-2xl font-black tracking-tight text-slate-900 sm:text-3xl">
                        Phone Push Notifications
                    </h1>
                    <p class="mt-1 text-xs sm:text-sm text-slate-500">
                        Send real-time alerts directly to customer phones via standard Web Push API & VAPID.
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <button type="button" onclick="openComposeModal()" class="inline-flex items-center gap-2 rounded-xl bg-[#3E37B7] hover:bg-[#312E81] px-5 py-2.5 text-xs sm:text-sm font-bold text-white shadow-md shadow-servora-700/20 transition hover:-translate-y-0.5">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                        <span>Compose Push</span>
                    </button>
                    <a href="dashboard.php" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-xs sm:text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50">
                        <span>Dashboard</span>
                        <span>&rarr;</span>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">

        <!-- ALERTS -->
        <?php if ($success): ?>
            <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-800 shadow-sm flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <svg class="h-5 w-5 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    <span><?= e($success) ?></span>
                </div>
                <button type="button" onclick="this.parentElement.remove()" class="text-xs underline text-emerald-700">Dismiss</button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="mb-6 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-800 shadow-sm flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <svg class="h-5 w-5 text-red-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    <span><?= e($error) ?></span>
                </div>
                <button type="button" onclick="this.parentElement.remove()" class="text-xs underline text-red-700">Dismiss</button>
            </div>
        <?php endif; ?>

        <!-- METRICS CARDS -->
        <div class="mb-8 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Active Subscriptions</p>
                <p class="mt-2 text-3xl font-black text-emerald-600"><?= (int)$stats['active_subscriptions'] ?></p>
                <p class="mt-1 text-xs text-slate-500">Phones ready to receive alerts</p>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Total Registered Devices</p>
                <p class="mt-2 text-3xl font-black text-slate-900"><?= (int)$stats['total_subscriptions'] ?></p>
                <p class="mt-1 text-xs text-slate-500">All registered browser tokens</p>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Campaigns Sent</p>
                <p class="mt-2 text-3xl font-black text-[#3E37B7]"><?= (int)$stats['total_dispatched'] ?></p>
                <p class="mt-1 text-xs text-slate-500">Broadcast campaigns dispatched</p>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Delivered Alerts</p>
                <p class="mt-2 text-3xl font-black text-slate-900"><?= (int)$stats['total_sent'] ?></p>
                <p class="mt-1 text-xs text-slate-500">Successful device handoffs</p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- HISTORY TABLE (2 Cols) -->
            <div class="lg:col-span-2 space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-black text-slate-900">Broadcast History</h3>
                    <span class="text-xs text-slate-500">Last 50 push notifications</span>
                </div>

                <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="overflow-x-auto">
                        <table class="w-full border-collapse text-left text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 bg-slate-50/80 text-[11px] font-black uppercase tracking-wider text-slate-500">
                                    <th class="py-4 px-5">Notification</th>
                                    <th class="py-4 px-5">Target</th>
                                    <th class="py-4 px-5">Delivery Rate</th>
                                    <th class="py-4 px-5">Date</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if (empty($history)): ?>
                                    <tr>
                                        <td colspan="4" class="py-12 text-center text-slate-400">
                                            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-2xl">📱</div>
                                            <p class="mt-3 text-sm font-bold text-slate-700">No push notifications sent yet</p>
                                            <p class="text-xs text-slate-400">Click "Compose Push" above to broadcast to subscribed devices.</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($history as $item): ?>
                                        <?php
                                        $targeted = (int)$item['total_targeted'];
                                        $sent = (int)$item['total_sent'];
                                        $failed = (int)$item['total_failed'];
                                        $rate = $targeted > 0 ? round(($sent / $targeted) * 100) : 0;
                                        ?>
                                        <tr class="hover:bg-slate-50/70 transition">
                                            <td class="py-4 px-5">
                                                <p class="font-bold text-slate-900"><?= e($item['title']) ?></p>
                                                <p class="mt-0.5 text-xs text-slate-500 line-clamp-2 max-w-sm"><?= e($item['body']) ?></p>
                                                <span class="mt-1 inline-block text-[10px] text-slate-400 font-mono">
                                                    URL: <?= e($item['target_url']) ?>
                                                </span>
                                            </td>

                                            <td class="py-4 px-5 text-xs">
                                                <?php if ($item['target_type'] === 'all'): ?>
                                                    <span class="font-bold text-slate-700">All Subscribers</span>
                                                <?php else: ?>
                                                    <span class="font-bold text-servora-700">Specific Users</span>
                                                <?php endif; ?>
                                                <p class="text-[11px] text-slate-400 mt-0.5"><?= $targeted ?> targeted</p>
                                            </td>

                                            <td class="py-4 px-5">
                                                <div class="flex items-center gap-2">
                                                    <div class="h-2 w-16 rounded-full bg-slate-100 overflow-hidden">
                                                        <div class="h-full bg-emerald-500" style="width: <?= $rate ?>%;"></div>
                                                    </div>
                                                    <span class="text-xs font-black text-slate-800"><?= $rate ?>%</span>
                                                </div>
                                                <p class="text-[10px] text-slate-400 mt-0.5"><?= $sent ?> sent, <?= $failed ?> failed</p>
                                            </td>

                                            <td class="py-4 px-5 text-xs text-slate-500">
                                                <?= date('M j, Y • g:i a', strtotime($item['created_at'])) ?>
                                                <?php if (!empty($item['sender_name'])): ?>
                                                    <p class="text-[10px] text-slate-400">by <?= e($item['sender_name']) ?></p>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- VAPID & PROTOCOL STATUS (1 Col) -->
            <div class="space-y-6">
                <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h3 class="text-base font-black text-slate-900 mb-2">VAPID Security Protocol</h3>
                    <p class="text-xs text-slate-500 leading-relaxed">
                        Subnext authenticates every push request against Google FCM and Mozilla push services using Voluntary Application Server Identification (VAPID).
                    </p>

                    <div class="mt-4 pt-4 border-t border-slate-100 space-y-3">
                        <div>
                            <span class="text-[10px] font-black uppercase tracking-wider text-slate-400">VAPID Public Key</span>
                            <p class="text-xs font-mono font-bold text-slate-800 break-all bg-slate-50 p-2 rounded-xl mt-1 select-all border border-slate-200/60">
                                <?= e($vapidPubKey) ?>
                            </p>
                        </div>

                        <div>
                            <span class="text-[10px] font-black uppercase tracking-wider text-slate-400">Encryption Method</span>
                            <p class="text-xs font-bold text-slate-700 mt-0.5">RFC 8291 AES-128-GCM + P-256 ECDH</p>
                        </div>

                        <div>
                            <span class="text-[10px] font-black uppercase tracking-wider text-slate-400">Subscription Cleaning</span>
                            <p class="text-xs font-semibold text-emerald-700 mt-0.5">Automated on HTTP 404/410 feedback</p>
                        </div>
                    </div>
                </div>

                <div class="rounded-3xl border border-indigo-100 bg-indigo-50/60 p-6 shadow-sm">
                    <div class="flex items-center gap-3 mb-2">
                        <span class="text-2xl">💡</span>
                        <h4 class="text-sm font-bold text-indigo-950">How Web Push Works</h4>
                    </div>
                    <p class="text-xs text-indigo-800/80 leading-relaxed">
                        Subscribers receive notifications even when their browser or Subnext is completely closed. When clicked, their device opens the exact Subnext service page you specified.
                    </p>
                </div>
            </div>

        </div>

    </main>

</div>

<!-- =========================================================================
     MODAL: COMPOSE PUSH NOTIFICATION
========================================================================== -->
<div id="composeModal" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="w-full max-w-xl rounded-3xl bg-white p-6 sm:p-8 shadow-2xl max-h-[92vh] overflow-y-auto">
        <div class="flex items-center justify-between border-b border-slate-100 pb-4 mb-5">
            <div>
                <h3 class="text-lg font-black text-slate-900">Compose Phone Push Notification</h3>
                <p class="text-xs text-slate-500">Broadcast a real-time banner to customer devices</p>
            </div>
            <button type="button" onclick="closeComposeModal()" class="rounded-xl p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <form method="POST" action="push_notifications.php" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="send">

            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">Notification Title *</label>
                <input type="text" name="title" required placeholder="e.g. MTN Data Price Drop! ⚡" class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-900 focus:border-[#3E37B7] focus:outline-none">
            </div>

            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">Message Body *</label>
                <textarea name="body" rows="3" required placeholder="Keep it concise and clear for lock-screen display..." class="w-full rounded-xl border border-slate-200 p-4 text-sm font-medium text-slate-900 focus:border-[#3E37B7] focus:outline-none"></textarea>
                <p class="text-[11px] text-slate-400 mt-1">Recommended length: 80-160 characters for best display on Android and iOS.</p>
            </div>

            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">Click Action / Target URL</label>
                <select name="target_url" class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-900 focus:border-[#3E37B7] focus:outline-none">
                    <option value="/client/dashboard.php">Client Dashboard (/client/dashboard.php)</option>
                    <option value="/client/buy_data.php">Data Purchase Page (/client/buy_data.php)</option>
                    <option value="/client/fund_wallet.php">Wallet Funding (/client/fund_wallet.php)</option>
                    <option value="/client/orders.php">Order History (/client/orders.php)</option>
                    <option value="/client/support.php">Help & Support (/client/support.php)</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">Target Audience</label>
                <select name="target_type" id="pushTargetType" onchange="togglePushUsers()" class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-900 focus:border-[#3E37B7] focus:outline-none">
                    <option value="all">All Active Subscribers (<?= (int)$stats['active_subscriptions'] ?> devices)</option>
                    <option value="specific">Specific User IDs</option>
                </select>
            </div>

            <div id="pushSpecificUserBox" class="hidden">
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">User IDs (Comma-separated)</label>
                <input type="text" name="target_user_ids" placeholder="e.g. 101, 145, 202" class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-900 focus:border-[#3E37B7] focus:outline-none">
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                <button type="button" onclick="closeComposeModal()" class="rounded-xl border border-slate-200 px-5 py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-50">
                    Cancel
                </button>
                <button type="submit" class="rounded-xl bg-[#3E37B7] hover:bg-[#312E81] px-6 py-2.5 text-xs font-bold text-white shadow-md">
                    Send Real-Time Notification
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openComposeModal() {
    document.getElementById('composeModal').classList.remove('hidden');
}
function closeComposeModal() {
    document.getElementById('composeModal').classList.add('hidden');
}
function togglePushUsers() {
    var sel = document.getElementById('pushTargetType');
    var box = document.getElementById('pushSpecificUserBox');
    if (sel && box) {
        if (sel.value === 'specific') {
            box.classList.remove('hidden');
        } else {
            box.classList.add('hidden');
        }
    }
}
</script>

</body>
</html>

