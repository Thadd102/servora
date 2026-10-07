<?php
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/admin_auth.php";
require_once __DIR__ . "/../includes/SupportService.php";
require_once __DIR__ . "/../includes/EmailService.php";

$adminUserId = currentUserId();
$adminName = $_SESSION["full_name"] ?? "Admin";

$ticketId = (int)($_GET['id'] ?? 0);
if ($ticketId <= 0) {
    header("Location: support_tickets.php");
    exit;
}

$ticket = SupportService::getAdminTicket($pdo, $ticketId);
if (!$ticket) {
    header("Location: support_tickets.php?error=not_found");
    exit;
}

$error = '';
$success = '';

if (isset($_GET['replied'])) {
    $success = 'Your reply was published and the ticket was updated.';
} elseif (isset($_GET['status_updated'])) {
    $success = 'Ticket status was changed successfully.';
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();

    $formAction = trim((string)($_POST['form_action'] ?? ''));

    // 1. QUICK STATUS UPDATE ONLY
    if ($formAction === 'update_status') {
        $newStatus = trim((string)($_POST['ticket_status'] ?? ''));
        if (in_array($newStatus, [SupportService::STATUS_OPEN, SupportService::STATUS_IN_PROGRESS, SupportService::STATUS_RESOLVED, SupportService::STATUS_CLOSED], true)) {
            SupportService::updateStatus($pdo, $ticketId, $newStatus);

            // Notify client if resolved
            if ($newStatus === SupportService::STATUS_RESOLVED && isset($_POST['notify_status'])) {
                EmailService::sendTicketNotification(
                    $ticket['email'],
                    $ticket['full_name'],
                    $ticket['ticket_code'],
                    $ticket['subject'],
                    "Your ticket has been marked as Resolved by our support staff.",
                    "Resolved"
                );
            }

            header("Location: support_ticket_view.php?id={$ticketId}&status_updated=1");
            exit;
        } else {
            $error = 'Invalid status selected.';
        }
    }

    // 2. ADMIN REPLY TO TICKET
    elseif ($formAction === 'reply') {
        $replyText = trim((string)($_POST['reply'] ?? ''));
        $replyStatus = trim((string)($_POST['reply_status'] ?? SupportService::STATUS_IN_PROGRESS));
        $sendEmail = isset($_POST['send_email_notification']);

        if ($replyText === '') {
            $error = 'Please enter a reply message.';
        } elseif (mb_strlen($replyText) < 2) {
            $error = 'Reply is too short.';
        } else {
            $posted = SupportService::addMessage(
                $pdo,
                $ticketId,
                $adminUserId,
                'admin',
                $replyText,
                $replyStatus
            );

            if ($posted) {
                // Send branded email notification to client
                if ($sendEmail) {
                    $statusLabel = match ($replyStatus) {
                        'open' => 'Open',
                        'in_progress' => 'In Progress',
                        'resolved' => 'Resolved',
                        'closed' => 'Closed',
                        default => 'Updated'
                    };

                    EmailService::sendTicketNotification(
                        $ticket['email'],
                        $ticket['full_name'],
                        $ticket['ticket_code'],
                        $ticket['subject'],
                        $replyText,
                        $statusLabel
                    );
                }

                header("Location: support_ticket_view.php?id={$ticketId}&replied=1");
                exit;
            } else {
                $error = 'Failed to post reply. Please try again.';
            }
        }
    }
}

// Fetch all messages
$messages = SupportService::getTicketMessages($pdo, $ticketId);

// Refresh ticket record
$ticket = SupportService::getAdminTicket($pdo, $ticketId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket #<?= htmlspecialchars($ticket['ticket_code']) ?> | Subnext Admin</title>
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
            <a href="../logout.php" class="rounded-xl border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-bold text-red-600 hover:bg-red-100">Logout</a>
        </div>
    </div>
</header>

<main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">

    <!-- Top Action Row -->
    <div class="mb-5 flex items-center justify-between">
        <a href="support_tickets.php" class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-600 hover:text-servora-700 transition">
            <span>←</span>
            <span>Back to All Support Tickets</span>
        </a>

        <div class="flex items-center gap-2">
            <?= SupportService::renderStatusBadge($ticket['status']) ?>
            <?= SupportService::renderPriorityBadge($ticket['priority']) ?>
        </div>
    </div>

    <?php if ($success !== ''): ?>
    <div class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-xs sm:text-sm font-semibold text-emerald-800 flex items-center gap-2">
        <span>✓</span>
        <span><?= htmlspecialchars($success) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
    <div class="mb-5 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-xs sm:text-sm font-semibold text-rose-800 flex items-center gap-2">
        <span>⚠️</span>
        <span><?= htmlspecialchars($error) ?></span>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

        <!-- LEFT 2 COLS: TICKET CONVERSATION & REPLY FORM -->
        <div class="lg:col-span-2 space-y-6">

            <!-- TICKET SUBJECT CARD -->
            <div class="rounded-3xl border border-slate-200 bg-white p-5 sm:p-6 shadow-xs">
                <div class="flex items-center gap-2 mb-2">
                    <span class="font-mono text-xs font-bold text-servora-700 bg-servora-50 px-2.5 py-1 rounded-lg border border-servora-100">
                        <?= htmlspecialchars($ticket['ticket_code']) ?>
                    </span>
                    <span class="text-xs text-slate-400">•</span>
                    <span class="text-xs font-semibold text-slate-600"><?= htmlspecialchars($ticket['category']) ?></span>
                </div>
                <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900">
                    <?= htmlspecialchars($ticket['subject']) ?>
                </h1>
                <div class="mt-4 pt-3 border-t border-slate-100 flex flex-wrap items-center gap-4 text-xs text-slate-500 font-mono">
                    <div>Created: <span class="text-slate-800 font-sans font-semibold"><?= date('M d, Y • h:i A', strtotime($ticket['created_at'])) ?></span></div>
                    <div>Updated: <span class="text-slate-800 font-sans font-semibold"><?= date('M d, Y • h:i A', strtotime($ticket['last_reply_at'])) ?></span></div>
                </div>
            </div>

            <!-- MESSAGE TIMELINE -->
            <div class="space-y-4">
                <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400 px-1">
                    Conversation Thread (<?= count($messages) ?>)
                </h3>

                <?php foreach ($messages as $msg):
                    $isAdmin = ($msg['sender_type'] === 'admin');
                ?>
                    <?php if ($isAdmin): ?>
                    <!-- Admin Reply Bubble -->
                    <div class="rounded-2xl border border-indigo-100 bg-indigo-50/50 p-4 sm:p-5 shadow-2xs">
                        <div class="flex items-center justify-between border-b border-indigo-100 pb-2 mb-3">
                            <div class="flex items-center gap-2">
                                <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-servora-700 text-xs font-black text-white">S</div>
                                <div>
                                    <span class="text-xs font-bold text-slate-900"><?= htmlspecialchars($msg['full_name']) ?></span>
                                    <span class="text-[10px] uppercase font-bold text-servora-700 bg-servora-100 px-1.5 py-0.2 rounded ml-1">Staff</span>
                                </div>
                            </div>
                            <span class="text-[11px] text-slate-400 font-mono"><?= date('M d, Y • h:i A', strtotime($msg['created_at'])) ?></span>
                        </div>
                        <div class="text-xs sm:text-sm text-slate-800 leading-relaxed whitespace-pre-line pl-9">
                            <?= htmlspecialchars($msg['message']) ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <!-- Client Message Bubble -->
                    <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5 shadow-2xs">
                        <div class="flex items-center justify-between border-b border-slate-100 pb-2 mb-3">
                            <div class="flex items-center gap-2">
                                <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-slate-100 text-xs font-bold text-slate-700">
                                    <?= strtoupper(substr($msg['full_name'], 0, 1)) ?>
                                </div>
                                <div>
                                    <span class="text-xs font-bold text-slate-900"><?= htmlspecialchars($msg['full_name']) ?></span>
                                    <span class="text-[10px] text-slate-400 ml-1">Client</span>
                                </div>
                            </div>
                            <span class="text-[11px] text-slate-400 font-mono"><?= date('M d, Y • h:i A', strtotime($msg['created_at'])) ?></span>
                        </div>
                        <div class="text-xs sm:text-sm text-slate-800 leading-relaxed whitespace-pre-line pl-9">
                            <?= htmlspecialchars($msg['message']) ?>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <!-- ADMIN REPLY FORM -->
            <div class="rounded-3xl border border-slate-200 bg-white p-5 sm:p-6 shadow-xs">
                <h3 class="text-base font-bold text-slate-900 mb-1">Reply to Ticket</h3>
                <p class="text-xs text-slate-500 mb-4">Your reply will be displayed to the client on their dashboard and can also be emailed.</p>

                <form method="POST" action="support_ticket_view.php?id=<?= $ticketId ?>" class="space-y-4">
                    <?= csrfField() ?>
                    <input type="hidden" name="form_action" value="reply">

                    <div>
                        <textarea
                            name="reply"
                            rows="5"
                            placeholder="Type your official support response here..."
                            required
                            class="w-full rounded-2xl border border-slate-200 bg-slate-50 p-4 text-xs sm:text-sm text-slate-900 placeholder-slate-400 outline-none transition focus:bg-white focus:border-servora-600 focus:ring-4 focus:ring-servora-500/10 leading-relaxed"
                        ></textarea>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 items-center">
                        <div>
                            <label for="reply_status" class="block text-xs font-semibold text-slate-700 mb-1">
                                Update Status to:
                            </label>
                            <select
                                id="reply_status"
                                name="reply_status"
                                class="w-full h-11 rounded-xl border border-slate-200 bg-slate-50 px-3 text-xs sm:text-sm text-slate-900 outline-none focus:bg-white focus:border-servora-600"
                            >
                                <option value="in_progress" <?= $ticket['status'] === 'in_progress' ? 'selected' : '' ?>>In Progress (Awaiting resolution)</option>
                                <option value="resolved" <?= $ticket['status'] === 'resolved' ? 'selected' : '' ?>>Resolved (Issue completed)</option>
                                <option value="closed" <?= $ticket['status'] === 'closed' ? 'selected' : '' ?>>Closed (Completed & locked)</option>
                                <option value="open" <?= $ticket['status'] === 'open' ? 'selected' : '' ?>>Open</option>
                            </select>
                        </div>

                        <div class="pt-5">
                            <label class="flex items-center gap-2 cursor-pointer select-none">
                                <input
                                    type="checkbox"
                                    name="send_email_notification"
                                    value="1"
                                    checked
                                    class="h-4 w-4 accent-servora-700 rounded cursor-pointer"
                                >
                                <span class="text-xs font-semibold text-slate-700">
                                    Send email notification to client
                                </span>
                            </label>
                        </div>
                    </div>

                    <div class="pt-2 flex justify-end">
                        <button
                            type="submit"
                            class="rounded-xl bg-servora-700 px-6 py-2.5 text-xs sm:text-sm font-bold text-white shadow-sm hover:bg-servora-800 transition active:scale-95"
                        >
                            Submit Support Response
                        </button>
                    </div>
                </form>
            </div>

        </div>

        <!-- RIGHT 1 COL: CLIENT INFO & QUICK STATUS CHANGER -->
        <div class="space-y-6">

            <!-- STATUS CONTROL CARD -->
            <div class="rounded-3xl border border-slate-200 bg-white p-5 sm:p-6 shadow-xs">
                <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-3">Status Management</h3>

                <form method="POST" action="support_ticket_view.php?id=<?= $ticketId ?>" class="space-y-3">
                    <?= csrfField() ?>
                    <input type="hidden" name="form_action" value="update_status">

                    <div>
                        <label for="ticket_status" class="block text-xs font-semibold text-slate-700 mb-1">Change Current Status</label>
                        <select
                            id="ticket_status"
                            name="ticket_status"
                            class="w-full h-11 rounded-xl border border-slate-200 bg-slate-50 px-3 text-xs sm:text-sm text-slate-900 outline-none focus:bg-white focus:border-servora-600"
                        >
                            <option value="open" <?= $ticket['status'] === 'open' ? 'selected' : '' ?>>Open</option>
                            <option value="in_progress" <?= $ticket['status'] === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                            <option value="resolved" <?= $ticket['status'] === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                            <option value="closed" <?= $ticket['status'] === 'closed' ? 'selected' : '' ?>>Closed</option>
                        </select>
                    </div>

                    <button
                        type="submit"
                        class="w-full h-10 rounded-xl border border-slate-200 bg-slate-100 text-xs font-bold text-slate-700 hover:bg-slate-200 transition"
                    >
                        Update Status Now
                    </button>
                </form>
            </div>

            <!-- CLIENT DETAILS CARD -->
            <div class="rounded-3xl border border-slate-200 bg-white p-5 sm:p-6 shadow-xs">
                <div class="flex items-center justify-between mb-4 border-b border-slate-100 pb-3">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400">Client Details</h3>
                    <a
                        href="view_client.php?id=<?= (int)$ticket['user_id'] ?>"
                        class="text-xs font-bold text-servora-700 hover:underline"
                    >
                        View Profile →
                    </a>
                </div>

                <div class="space-y-3 text-xs">
                    <div>
                        <span class="text-slate-400 block text-[11px]">Full Name</span>
                        <span class="font-bold text-slate-900 text-sm"><?= htmlspecialchars($ticket['full_name']) ?></span>
                    </div>

                    <div>
                        <span class="text-slate-400 block text-[11px]">Email Address</span>
                        <a href="mailto:<?= htmlspecialchars($ticket['email']) ?>" class="font-semibold text-servora-700 hover:underline">
                            <?= htmlspecialchars($ticket['email']) ?>
                        </a>
                    </div>

                    <div>
                        <span class="text-slate-400 block text-[11px]">Phone Number</span>
                        <span class="font-mono text-slate-800"><?= htmlspecialchars($ticket['phone'] ?: 'N/A') ?></span>
                    </div>

                    <div>
                        <span class="text-slate-400 block text-[11px]">Wallet Balance</span>
                        <span class="font-mono font-bold text-emerald-700 text-sm">₦<?= number_format((float)($ticket['wallet_balance'] ?? 0), 2) ?></span>
                    </div>

                    <div>
                        <span class="text-slate-400 block text-[11px]">Member Since</span>
                        <span class="text-slate-600 font-mono text-[11px]"><?= date('M d, Y', strtotime($ticket['user_registered_at'])) ?></span>
                    </div>
                </div>

                <div class="mt-5 pt-4 border-t border-slate-100">
                    <a
                        href="view_client.php?id=<?= (int)$ticket['user_id'] ?>"
                        class="block text-center rounded-xl bg-slate-50 py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-100 border border-slate-200/80 transition"
                    >
                        Manage Client & Orders
                    </a>
                </div>
            </div>

        </div>

    </div>

</main>

</body>
</html>
