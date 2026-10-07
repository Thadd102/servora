<?php
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/client_auth.php";
require_once __DIR__ . "/../includes/SupportService.php";

$userId = currentUserId();
$fullName = trim((string)($_SESSION["full_name"] ?? "Client"));
$profileInitial = strtoupper(substr($fullName, 0, 1));

// Filter
$statusFilter = trim((string)($_GET['status'] ?? ''));
if (!in_array($statusFilter, ['open', 'in_progress', 'resolved', 'closed'], true)) {
    $statusFilter = null;
}

$tickets = SupportService::getClientTickets($pdo, $userId, $statusFilter);

// Calculate user ticket counts
$allUserTickets = SupportService::getClientTickets($pdo, $userId, null);
$countTotal = count($allUserTickets);
$countOpen = 0;
$countInProgress = 0;
$countResolved = 0;
$countClosed = 0;

foreach ($allUserTickets as $t) {
    if ($t['status'] === 'open') {
        $countOpen++;
    } elseif ($t['status'] === 'in_progress') {
        $countInProgress++;
    } elseif ($t['status'] === 'resolved') {
        $countResolved++;
    } elseif ($t['status'] === 'closed') {
        $countClosed++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Center | Subnext</title>
    <meta name="description" content="Subnext Support Center: Open support tickets, track resolutions, and get prompt assistance with digital services.">
    <!-- Precompiled Production Stylesheet -->
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">

<!-- =========================================================
     DESKTOP NAVIGATION HEADER
========================================================= -->
<header class="hidden md:block sticky top-0 z-40 border-b border-slate-200/80 bg-white/95 backdrop-blur">
    <div class="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
        <!-- Logo -->
        <a href="dashboard.php" class="flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-servora-700 text-lg font-black text-white shadow-sm">S</div>
            <div>
                <div class="text-lg font-bold tracking-tight text-slate-900 leading-tight">Subnext</div>
                <div class="text-[10px] font-medium text-slate-400 uppercase tracking-wider">Support Center</div>
            </div>
        </a>

        <!-- Desktop Navigation Links -->
        <nav class="flex items-center gap-1">
            <a href="dashboard.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">Dashboard</a>
            <a href="orders.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">My Orders</a>
            <a href="wallet.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">Wallet</a>
            <a href="support.php" class="rounded-xl bg-servora-50 px-3.5 py-2 text-xs font-bold text-servora-700">Support</a>
            <a href="profile.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">Profile</a>
        </nav>

        <!-- Right User Actions -->
        <div class="flex items-center gap-2.5">
            <a href="profile.php" class="flex h-10 w-10 items-center justify-center rounded-xl bg-servora-100 font-bold text-servora-700 text-sm" title="My Profile">
                <?= htmlspecialchars($profileInitial, ENT_QUOTES, "UTF-8") ?>
            </a>
            <a href="../logout.php" class="inline-flex items-center gap-1.5 rounded-xl border border-red-200 bg-red-50/80 px-3.5 py-2 text-xs font-bold text-red-600 transition hover:bg-red-100" title="Logout">
                Logout
            </a>
        </div>
    </div>
</header>

<!-- =========================================================
     MAIN BODY
========================================================= -->
<main class="mx-auto w-full max-w-7xl px-3.5 py-4 pb-24 md:py-8 md:pb-12 sm:px-6 lg:px-8">

    <!-- HERO HEADER -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between mb-6">
        <div>
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center gap-1.5 rounded-full bg-servora-50 px-2.5 py-0.5 text-xs font-bold text-servora-700 border border-servora-200/60">
                    <span class="h-1.5 w-1.5 rounded-full bg-servora-600"></span>
                    Helpdesk & Support
                </span>
            </div>
            <h1 class="mt-1.5 text-xl sm:text-2xl font-bold tracking-tight text-slate-900">
                Support Center
            </h1>
            <p class="mt-0.5 text-xs sm:text-sm text-slate-500">
                Need help with a transaction or service? Create a ticket and our support team will assist you.
            </p>
        </div>

        <a href="support_new.php" class="inline-flex items-center justify-center gap-2 rounded-xl bg-servora-700 px-4 py-2.5 text-xs sm:text-sm font-bold text-white shadow-sm transition hover:bg-servora-800 active:scale-95 shrink-0">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
            </svg>
            <span>Open New Ticket</span>
        </a>
    </div>

    <!-- STATS OVERVIEW -->
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 sm:gap-4 mb-6">
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-2xs">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">Total Tickets</p>
            <p class="mt-1 text-xl sm:text-2xl font-extrabold text-slate-900"><?= $countTotal ?></p>
        </div>
        <div class="rounded-2xl border border-amber-200 bg-amber-50/50 p-4 shadow-2xs">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-amber-700">Open</p>
            <p class="mt-1 text-xl sm:text-2xl font-extrabold text-amber-800"><?= $countOpen ?></p>
        </div>
        <div class="rounded-2xl border border-sky-200 bg-sky-50/50 p-4 shadow-2xs">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-sky-700">In Progress</p>
            <p class="mt-1 text-xl sm:text-2xl font-extrabold text-sky-800"><?= $countInProgress ?></p>
        </div>
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50/50 p-4 shadow-2xs">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-emerald-700">Resolved</p>
            <p class="mt-1 text-xl sm:text-2xl font-extrabold text-emerald-800"><?= $countResolved ?></p>
        </div>
    </div>

    <!-- STATUS FILTER TABS -->
    <div class="flex items-center gap-2 overflow-x-auto pb-2 mb-4 scrollbar-none">
        <a href="support.php" class="px-3.5 py-1.5 rounded-xl text-xs font-bold transition whitespace-nowrap <?= $statusFilter === null ? 'bg-servora-700 text-white shadow-xs' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?>">
            All Tickets (<?= $countTotal ?>)
        </a>
        <a href="support.php?status=open" class="px-3.5 py-1.5 rounded-xl text-xs font-bold transition whitespace-nowrap <?= $statusFilter === 'open' ? 'bg-servora-700 text-white shadow-xs' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?>">
            Open (<?= $countOpen ?>)
        </a>
        <a href="support.php?status=in_progress" class="px-3.5 py-1.5 rounded-xl text-xs font-bold transition whitespace-nowrap <?= $statusFilter === 'in_progress' ? 'bg-servora-700 text-white shadow-xs' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?>">
            In Progress (<?= $countInProgress ?>)
        </a>
        <a href="support.php?status=resolved" class="px-3.5 py-1.5 rounded-xl text-xs font-bold transition whitespace-nowrap <?= $statusFilter === 'resolved' ? 'bg-servora-700 text-white shadow-xs' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?>">
            Resolved (<?= $countResolved ?>)
        </a>
        <a href="support.php?status=closed" class="px-3.5 py-1.5 rounded-xl text-xs font-bold transition whitespace-nowrap <?= $statusFilter === 'closed' ? 'bg-servora-700 text-white shadow-xs' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?>">
            Closed (<?= $countClosed ?>)
        </a>
    </div>

    <!-- TICKETS CONTAINER -->
    <div class="rounded-2xl sm:rounded-3xl border border-slate-200 bg-white overflow-hidden shadow-xs">
        <?php if (empty($tickets)): ?>
            <div class="py-12 px-4 text-center">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-servora-50 text-servora-700 mb-3">
                    <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a.75.75 0 01-.876-.876c.15-.71.39-1.393.708-2.023C3.805 16.592 3 14.414 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" />
                    </svg>
                </div>
                <h3 class="text-base font-bold text-slate-900">No support tickets found</h3>
                <p class="mt-1 text-xs sm:text-sm text-slate-500 max-w-sm mx-auto">
                    <?= $statusFilter ? "No tickets found matching the selected status filter." : "You haven't opened any support requests yet. If you ever experience issues, our team is always ready to help." ?>
                </p>
                <div class="mt-5">
                    <a href="support_new.php" class="inline-flex items-center gap-1.5 rounded-xl bg-servora-700 px-4 py-2 text-xs font-bold text-white shadow-xs hover:bg-servora-800 transition">
                        + Create a Ticket Now
                    </a>
                </div>
            </div>
        <?php else: ?>
            <!-- Desktop Table View -->
            <div class="hidden sm:block overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="border-b border-slate-100 bg-slate-50/70 text-[11px] font-bold uppercase tracking-wider text-slate-400">
                            <th class="py-3.5 px-4 sm:px-6">Ticket</th>
                            <th class="py-3.5 px-4">Category</th>
                            <th class="py-3.5 px-4">Priority</th>
                            <th class="py-3.5 px-4">Status</th>
                            <th class="py-3.5 px-4">Last Activity</th>
                            <th class="py-3.5 px-4 sm:px-6 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-xs">
                        <?php foreach ($tickets as $t): ?>
                        <tr class="hover:bg-slate-50/70 transition">
                            <td class="py-4 px-4 sm:px-6 min-w-[220px]">
                                <div class="flex items-center gap-2">
                                    <span class="font-mono font-bold text-servora-700 bg-servora-50 px-2 py-0.5 rounded text-[11px] border border-servora-100">
                                        <?= htmlspecialchars($t['ticket_code']) ?>
                                    </span>
                                    <span class="text-[10px] text-slate-400 font-medium">
                                        (<?= (int)$t['message_count'] ?> <?= (int)$t['message_count'] === 1 ? 'msg' : 'msgs' ?>)
                                    </span>
                                </div>
                                <a href="support_view.php?id=<?= (int)$t['id'] ?>" class="block mt-1 font-bold text-slate-900 hover:text-servora-700 line-clamp-1">
                                    <?= htmlspecialchars($t['subject']) ?>
                                </a>
                            </td>
                            <td class="py-4 px-4 text-slate-600 font-medium whitespace-nowrap">
                                <?= htmlspecialchars($t['category']) ?>
                            </td>
                            <td class="py-4 px-4 whitespace-nowrap">
                                <?= SupportService::renderPriorityBadge($t['priority']) ?>
                            </td>
                            <td class="py-4 px-4 whitespace-nowrap">
                                <?= SupportService::renderStatusBadge($t['status']) ?>
                            </td>
                            <td class="py-4 px-4 whitespace-nowrap text-slate-500 font-mono text-[11px]">
                                <?= date('M d, Y • h:i A', strtotime($t['last_reply_at'])) ?>
                                <?php if ($t['last_reply_by'] === 'admin'): ?>
                                    <span class="block text-[10px] font-bold text-emerald-600 font-sans">Support Replied</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-4 px-4 sm:px-6 text-right whitespace-nowrap">
                                <a href="support_view.php?id=<?= (int)$t['id'] ?>" class="inline-flex items-center gap-1 rounded-xl bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-servora-50 hover:text-servora-700 transition">
                                    <span>View</span>
                                    <span class="text-xs">→</span>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Mobile Card View -->
            <div class="sm:hidden divide-y divide-slate-100">
                <?php foreach ($tickets as $t): ?>
                <a href="support_view.php?id=<?= (int)$t['id'] ?>" class="block p-4 transition hover:bg-slate-50/70">
                    <div class="flex items-center justify-between gap-2 mb-1.5">
                        <span class="font-mono font-bold text-servora-700 bg-servora-50 px-2 py-0.5 rounded text-[10px] border border-servora-100">
                            <?= htmlspecialchars($t['ticket_code']) ?>
                        </span>
                        <div><?= SupportService::renderStatusBadge($t['status']) ?></div>
                    </div>
                    <h3 class="font-bold text-slate-900 text-sm line-clamp-2">
                        <?= htmlspecialchars($t['subject']) ?>
                    </h3>
                    <div class="mt-2 flex items-center justify-between text-[11px] text-slate-500">
                        <span><?= htmlspecialchars($t['category']) ?></span>
                        <span class="font-mono"><?= date('M d, h:i A', strtotime($t['last_reply_at'])) ?></span>
                    </div>
                    <?php if ($t['last_reply_by'] === 'admin'): ?>
                        <div class="mt-2 inline-flex items-center gap-1 rounded-md bg-emerald-50 px-2 py-0.5 text-[10px] font-bold text-emerald-700">
                            <span>✓</span> Staff reply waiting
                        </div>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</main>

<!-- MOBILE BOTTOM NAVIGATION -->
<?php require_once __DIR__ . "/../includes/client_bottom_nav.php"; ?>

</body>
</html>
