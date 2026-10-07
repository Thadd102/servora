<?php
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/admin_auth.php";
require_once __DIR__ . "/../includes/SupportService.php";

$adminName = $_SESSION["full_name"] ?? "Admin";

// Filter & search
$statusFilter = trim((string)($_GET['status'] ?? ''));
if (!in_array($statusFilter, ['open', 'in_progress', 'resolved', 'closed'], true)) {
    $statusFilter = null;
}
$search = trim((string)($_GET['search'] ?? ''));

// Get statistics
$stats = SupportService::getStats($pdo);

// Get tickets
$tickets = SupportService::getAdminTickets($pdo, $statusFilter, $search !== '' ? $search : null, 100);

$message = '';
$messageType = 'success';

if (isset($_GET['status_updated'])) {
    $message = 'Ticket status was updated successfully.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Tickets Management | Subnext Admin</title>
    <!-- Precompiled Production Stylesheet -->
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">

<!-- Admin Navigation Header -->
<header class="border-b border-slate-200 bg-white sticky top-0 z-30">
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
                <a href="utility_orders.php" class="px-3 py-2 rounded-lg hover:bg-slate-100">Utility Orders</a>
                <a href="data_orders.php" class="px-3 py-2 rounded-lg hover:bg-slate-100">Data Orders</a>
                <a href="foreign_number_orders.php" class="px-3 py-2 rounded-lg hover:bg-slate-100">Foreign Numbers</a>
                <a href="support_tickets.php" class="px-3 py-2 rounded-lg bg-servora-50 text-servora-700">Support</a>
                <a href="clients.php" class="px-3 py-2 rounded-lg hover:bg-slate-100">Clients</a>
            </nav>
        </div>
        <div class="flex items-center gap-3">
            <span class="hidden sm:inline-block text-xs font-semibold text-slate-500">Logged in as <?= htmlspecialchars($adminName) ?></span>
            <a href="../logout.php" class="rounded-xl border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-bold text-red-600 hover:bg-red-100">Logout</a>
        </div>
    </div>
</header>

<main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">

    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center gap-1.5 rounded-full bg-servora-100 px-2.5 py-0.5 text-xs font-bold text-servora-800">
                    <span class="h-1.5 w-1.5 rounded-full bg-servora-600"></span>
                    Helpdesk Operations
                </span>
            </div>
            <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">Support Tickets</h1>
            <p class="text-sm text-slate-500">View, reply to, and resolve client inquiries, payments disputes, and order issues.</p>
        </div>
        <a href="dashboard.php" class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-600 hover:text-servora-700 bg-white border border-slate-200 px-3.5 py-2 rounded-xl shadow-xs">
            ← Back to Dashboard
        </a>
    </div>

    <?php if ($message !== ''): ?>
    <div class="mb-6 rounded-2xl bg-emerald-50 text-emerald-900 border border-emerald-200 p-4 text-sm font-semibold flex items-center gap-2">
        <span>✓</span>
        <span><?= htmlspecialchars($message) ?></span>
    </div>
    <?php endif; ?>

    <!-- STATS CARDS -->
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 sm:gap-4 mb-6">
        <a href="support_tickets.php" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-2xs transition hover:border-slate-300">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">Total Tickets</p>
            <p class="mt-1 text-2xl font-black text-slate-900"><?= $stats['total'] ?></p>
        </a>
        <a href="support_tickets.php?status=open" class="rounded-2xl border border-amber-200 bg-amber-50/70 p-4 shadow-2xs transition hover:border-amber-300">
            <div class="flex items-center justify-between">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-amber-700">Open Tickets</p>
                <?php if ($stats['open'] > 0): ?>
                    <span class="flex h-2 w-2 rounded-full bg-amber-500 animate-ping"></span>
                <?php endif; ?>
            </div>
            <p class="mt-1 text-2xl font-black text-amber-900"><?= $stats['open'] ?></p>
        </a>
        <a href="support_tickets.php?status=in_progress" class="rounded-2xl border border-sky-200 bg-sky-50/70 p-4 shadow-2xs transition hover:border-sky-300">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-sky-700">In Progress</p>
            <p class="mt-1 text-2xl font-black text-sky-900"><?= $stats['in_progress'] ?></p>
        </a>
        <a href="support_tickets.php?status=resolved" class="rounded-2xl border border-emerald-200 bg-emerald-50/70 p-4 shadow-2xs transition hover:border-emerald-300">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-emerald-700">Resolved</p>
            <p class="mt-1 text-2xl font-black text-emerald-900"><?= $stats['resolved'] ?></p>
        </a>
    </div>

    <!-- FILTER & SEARCH BAR -->
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-xs mb-6">
        <form method="GET" action="support_tickets.php" class="flex flex-col sm:flex-row sm:items-center gap-3">
            <div class="relative flex-1">
                <input
                    type="text"
                    name="search"
                    value="<?= htmlspecialchars($search) ?>"
                    placeholder="Search by ticket code, client name, email, or subject..."
                    class="w-full h-11 rounded-xl border border-slate-200 bg-slate-50 pl-10 pr-4 text-xs sm:text-sm text-slate-900 placeholder-slate-400 outline-none transition focus:bg-white focus:border-servora-600 focus:ring-4 focus:ring-servora-500/10"
                >
                <svg class="w-4 h-4 text-slate-400 absolute left-3.5 top-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                </svg>
            </div>

            <div class="flex items-center gap-2">
                <select
                    name="status"
                    class="h-11 rounded-xl border border-slate-200 bg-slate-50 px-3 text-xs sm:text-sm text-slate-800 outline-none transition focus:bg-white focus:border-servora-600"
                >
                    <option value="">All Statuses</option>
                    <option value="open" <?= $statusFilter === 'open' ? 'selected' : '' ?>>Open</option>
                    <option value="in_progress" <?= $statusFilter === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                    <option value="resolved" <?= $statusFilter === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                    <option value="closed" <?= $statusFilter === 'closed' ? 'selected' : '' ?>>Closed</option>
                </select>

                <button
                    type="submit"
                    class="h-11 px-4 rounded-xl bg-servora-700 text-white text-xs font-bold hover:bg-servora-800 transition shadow-xs"
                >
                    Filter
                </button>

                <?php if ($statusFilter || $search): ?>
                    <a
                        href="support_tickets.php"
                        class="h-11 px-3 inline-flex items-center rounded-xl border border-slate-200 bg-slate-100 text-xs font-semibold text-slate-600 hover:bg-slate-200 transition"
                    >
                        Clear
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- TICKETS TABLE -->
    <div class="rounded-3xl border border-slate-200 bg-white overflow-hidden shadow-xs">
        <?php if (empty($tickets)): ?>
            <div class="py-16 px-4 text-center">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-500 mb-3">
                    <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                    </svg>
                </div>
                <h3 class="text-base font-bold text-slate-900">No support tickets found</h3>
                <p class="mt-1 text-xs text-slate-500 max-w-sm mx-auto">
                    <?= ($statusFilter || $search) ? 'No tickets matched your filter criteria.' : 'There are no client support tickets currently in the system.' ?>
                </p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="border-b border-slate-100 bg-slate-50/70 text-[11px] font-bold uppercase tracking-wider text-slate-400">
                            <th class="py-3.5 px-6">Ticket Code</th>
                            <th class="py-3.5 px-4">Client</th>
                            <th class="py-3.5 px-4">Subject & Category</th>
                            <th class="py-3.5 px-4">Priority</th>
                            <th class="py-3.5 px-4">Status</th>
                            <th class="py-3.5 px-4">Last Activity</th>
                            <th class="py-3.5 px-6 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-xs">
                        <?php foreach ($tickets as $t): ?>
                        <tr class="hover:bg-slate-50/70 transition <?= $t['status'] === 'open' ? 'bg-amber-50/20' : '' ?>">
                            <td class="py-4 px-6 whitespace-nowrap">
                                <span class="font-mono font-bold text-servora-700 bg-servora-50 px-2 py-1 rounded text-xs border border-servora-100">
                                    <?= htmlspecialchars($t['ticket_code']) ?>
                                </span>
                                <span class="block text-[10px] text-slate-400 font-medium mt-1">
                                    <?= (int)$t['message_count'] ?> <?= (int)$t['message_count'] === 1 ? 'message' : 'messages' ?>
                                </span>
                            </td>
                            <td class="py-4 px-4 min-w-[160px]">
                                <a href="view_client.php?id=<?= (int)$t['user_id'] ?>" class="font-bold text-slate-900 hover:text-servora-700 block">
                                    <?= htmlspecialchars($t['full_name']) ?>
                                </a>
                                <span class="text-[11px] text-slate-400 block truncate"><?= htmlspecialchars($t['email']) ?></span>
                            </td>
                            <td class="py-4 px-4 min-w-[240px]">
                                <a href="support_ticket_view.php?id=<?= (int)$t['id'] ?>" class="font-bold text-slate-900 hover:text-servora-700 line-clamp-1 block">
                                    <?= htmlspecialchars($t['subject']) ?>
                                </a>
                                <span class="inline-block mt-0.5 text-[11px] font-medium text-slate-500">
                                    <?= htmlspecialchars($t['category']) ?>
                                </span>
                            </td>
                            <td class="py-4 px-4 whitespace-nowrap">
                                <?= SupportService::renderPriorityBadge($t['priority']) ?>
                            </td>
                            <td class="py-4 px-4 whitespace-nowrap">
                                <?= SupportService::renderStatusBadge($t['status']) ?>
                            </td>
                            <td class="py-4 px-4 whitespace-nowrap text-slate-500 font-mono text-[11px]">
                                <?= date('M d, Y • h:i A', strtotime($t['last_reply_at'])) ?>
                                <span class="block text-[10px] font-sans font-semibold <?= $t['last_reply_by'] === 'client' ? 'text-amber-600 font-bold' : 'text-slate-400' ?>">
                                    <?= $t['last_reply_by'] === 'client' ? 'Client replied' : 'Admin replied' ?>
                                </span>
                            </td>
                            <td class="py-4 px-6 text-right whitespace-nowrap">
                                <a href="support_ticket_view.php?id=<?= (int)$t['id'] ?>" class="inline-flex items-center gap-1 rounded-xl bg-servora-50 px-3 py-1.5 text-xs font-bold text-servora-700 hover:bg-servora-100 transition shadow-2xs">
                                    <span>Reply & Manage</span>
                                    <span>→</span>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</main>

</body>
</html>
