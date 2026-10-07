<?php
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/client_auth.php";
require_once __DIR__ . "/../includes/SupportService.php";

$userId = currentUserId();
$fullName = trim((string)($_SESSION["full_name"] ?? "Client"));
$profileInitial = strtoupper(substr($fullName, 0, 1));

$ticketId = (int)($_GET['id'] ?? 0);
if ($ticketId <= 0) {
    header("Location: support.php");
    exit;
}

// STRICT OWNERSHIP CHECK: Ensure client only accesses their own ticket
$ticket = SupportService::getClientTicket($pdo, $ticketId, $userId);
if (!$ticket) {
    header("Location: support.php?error=unauthorized");
    exit;
}

$error = '';
$success = '';

if (isset($_GET['created'])) {
    $success = "Your support ticket #{$ticket['ticket_code']} has been submitted successfully! Our team will reply shortly.";
} elseif (isset($_GET['replied'])) {
    $success = "Your reply was posted successfully.";
}

// Handle Client Reply
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();

    $reply = trim((string)($_POST['reply'] ?? ''));

    if ($ticket['status'] === SupportService::STATUS_CLOSED) {
        $error = 'This ticket has been closed. Please open a new ticket if you require further assistance.';
    } elseif ($reply === '') {
        $error = 'Please enter a reply before submitting.';
    } elseif (mb_strlen($reply) < 3) {
        $error = 'Reply is too short.';
    } else {
        $posted = SupportService::addMessage(
            $pdo,
            $ticketId,
            $userId,
            'client',
            $reply
        );

        if ($posted) {
            header("Location: support_view.php?id={$ticketId}&replied=1");
            exit;
        } else {
            $error = 'Unable to send reply. Please try again.';
        }
    }
}

// Fetch complete message timeline
$messages = SupportService::getTicketMessages($pdo, $ticketId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket #<?= htmlspecialchars($ticket['ticket_code']) ?> | Subnext Support</title>
    <!-- Precompiled Production Stylesheet -->
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">

<!-- =========================================================
     DESKTOP NAVIGATION HEADER
========================================================= -->
<header class="hidden md:block sticky top-0 z-40 border-b border-slate-200/80 bg-white/95 backdrop-blur">
    <div class="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
        <a href="dashboard.php" class="flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-servora-700 text-lg font-black text-white shadow-sm">S</div>
            <div>
                <div class="text-lg font-bold tracking-tight text-slate-900 leading-tight">Subnext</div>
                <div class="text-[10px] font-medium text-slate-400 uppercase tracking-wider">Support Center</div>
            </div>
        </a>

        <nav class="flex items-center gap-1">
            <a href="dashboard.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">Dashboard</a>
            <a href="orders.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">My Orders</a>
            <a href="wallet.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">Wallet</a>
            <a href="support.php" class="rounded-xl bg-servora-50 px-3.5 py-2 text-xs font-bold text-servora-700">Support</a>
            <a href="profile.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">Profile</a>
        </nav>

        <div class="flex items-center gap-2.5">
            <a href="profile.php" class="flex h-10 w-10 items-center justify-center rounded-xl bg-servora-100 font-bold text-servora-700 text-sm">
                <?= htmlspecialchars($profileInitial, ENT_QUOTES, "UTF-8") ?>
            </a>
            <a href="../logout.php" class="inline-flex items-center gap-1.5 rounded-xl border border-red-200 bg-red-50/80 px-3.5 py-2 text-xs font-bold text-red-600 transition hover:bg-red-100">
                Logout
            </a>
        </div>
    </div>
</header>

<!-- =========================================================
     MAIN BODY
========================================================= -->
<main class="mx-auto w-full max-w-4xl px-3.5 py-4 pb-28 md:py-8 md:pb-12 sm:px-6">

    <!-- BACK LINK & ACTIONS -->
    <div class="mb-4 flex items-center justify-between">
        <a href="support.php" class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-600 hover:text-servora-700 transition">
            <span>←</span>
            <span>Back to All Tickets</span>
        </a>

        <div>
            <?= SupportService::renderStatusBadge($ticket['status']) ?>
        </div>
    </div>

    <?php if ($success !== ''): ?>
    <div class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50/90 p-4 text-xs sm:text-sm font-semibold text-emerald-800 flex items-center gap-2.5 shadow-2xs">
        <span class="text-base">✓</span>
        <span><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
    <div class="mb-5 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-xs sm:text-sm font-semibold text-rose-800 flex items-center gap-2.5 shadow-2xs">
        <span class="text-base">⚠️</span>
        <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <?php endif; ?>

    <!-- TICKET DETAILS HEADER CARD -->
    <div class="rounded-2xl sm:rounded-3xl border border-slate-200 bg-white p-5 sm:p-6 shadow-xs mb-6">
        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 border-b border-slate-100 pb-4">
            <div>
                <div class="flex items-center gap-2 mb-1">
                    <span class="font-mono text-xs font-bold text-servora-700 bg-servora-50 px-2 py-0.5 rounded border border-servora-100">
                        <?= htmlspecialchars($ticket['ticket_code']) ?>
                    </span>
                    <span class="text-xs text-slate-400 font-medium">•</span>
                    <span class="text-xs font-semibold text-slate-600"><?= htmlspecialchars($ticket['category']) ?></span>
                </div>
                <h1 class="text-lg sm:text-xl font-bold tracking-tight text-slate-900">
                    <?= htmlspecialchars($ticket['subject']) ?>
                </h1>
            </div>

            <div class="flex items-center gap-2 shrink-0">
                <?= SupportService::renderPriorityBadge($ticket['priority']) ?>
            </div>
        </div>

        <div class="pt-3 flex flex-wrap items-center gap-y-2 gap-x-6 text-[11px] text-slate-500 font-medium">
            <div>
                <span class="text-slate-400">Opened:</span>
                <span class="font-mono text-slate-700"><?= date('M d, Y • h:i A', strtotime($ticket['created_at'])) ?></span>
            </div>
            <div>
                <span class="text-slate-400">Last updated:</span>
                <span class="font-mono text-slate-700"><?= date('M d, Y • h:i A', strtotime($ticket['last_reply_at'])) ?></span>
            </div>
        </div>
    </div>

    <!-- CONVERSATION TIMELINE -->
    <div class="space-y-4 mb-6">
        <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400 px-1">
            Conversation History (<?= count($messages) ?> <?= count($messages) === 1 ? 'Message' : 'Messages' ?>)
        </div>

        <?php foreach ($messages as $msg):
            $isAdmin = ($msg['sender_type'] === 'admin');
        ?>
            <?php if ($isAdmin): ?>
            <!-- Admin Support Reply -->
            <div class="rounded-2xl border border-indigo-100 bg-indigo-50/40 p-4 sm:p-5 shadow-2xs">
                <div class="flex items-center justify-between gap-2 border-b border-indigo-100/70 pb-2.5 mb-3">
                    <div class="flex items-center gap-2.5">
                        <div class="flex h-8 w-8 items-center justify-center rounded-xl bg-servora-700 text-xs font-black text-white shadow-xs">
                            S
                        </div>
                        <div>
                            <div class="flex items-center gap-1.5">
                                <span class="text-xs font-bold text-slate-900">Subnext Support</span>
                                <span class="rounded bg-servora-100 px-1.5 py-0.2 text-[9px] font-black uppercase text-servora-800 tracking-wider">Official</span>
                            </div>
                            <span class="text-[10px] text-slate-400">Customer Success Team</span>
                        </div>
                    </div>
                    <span class="text-[11px] text-slate-400 font-mono">
                        <?= date('M d, h:i A', strtotime($msg['created_at'])) ?>
                    </span>
                </div>
                <div class="text-xs sm:text-sm text-slate-800 leading-relaxed whitespace-pre-line pl-10">
                    <?= htmlspecialchars($msg['message'], ENT_QUOTES, 'UTF-8') ?>
                </div>
            </div>
            <?php else: ?>
            <!-- Client Message -->
            <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5 shadow-2xs">
                <div class="flex items-center justify-between gap-2 border-b border-slate-100 pb-2.5 mb-3">
                    <div class="flex items-center gap-2.5">
                        <div class="flex h-8 w-8 items-center justify-center rounded-xl bg-slate-100 text-xs font-bold text-slate-700">
                            <?= htmlspecialchars($profileInitial, ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <div>
                            <span class="text-xs font-bold text-slate-900"><?= htmlspecialchars($msg['full_name'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="text-[10px] text-slate-400 block">You</span>
                        </div>
                    </div>
                    <span class="text-[11px] text-slate-400 font-mono">
                        <?= date('M d, h:i A', strtotime($msg['created_at'])) ?>
                    </span>
                </div>
                <div class="text-xs sm:text-sm text-slate-800 leading-relaxed whitespace-pre-line pl-10">
                    <?= htmlspecialchars($msg['message'], ENT_QUOTES, 'UTF-8') ?>
                </div>
            </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <!-- REPLY BOX -->
    <?php if ($ticket['status'] === SupportService::STATUS_CLOSED): ?>
    <div class="rounded-2xl border border-slate-200 bg-slate-100 p-5 text-center shadow-xs">
        <p class="text-xs sm:text-sm font-semibold text-slate-600">
            🔒 This support ticket has been marked as <strong>Closed</strong>.
        </p>
        <p class="mt-1 text-xs text-slate-400">
            If you need further help or have a new inquiry, please open a new ticket.
        </p>
        <div class="mt-3">
            <a href="support_new.php" class="inline-flex items-center gap-1.5 rounded-xl bg-servora-700 px-4 py-2 text-xs font-bold text-white shadow-xs hover:bg-servora-800 transition">
                + Open New Ticket
            </a>
        </div>
    </div>
    <?php else: ?>
    <div class="rounded-2xl sm:rounded-3xl border border-slate-200 bg-white p-5 sm:p-6 shadow-xs">
        <h3 class="text-sm font-bold text-slate-900 mb-2">
            Post a Reply
        </h3>
        <p class="text-xs text-slate-500 mb-4">
            Need to add more information or follow up on support's response? Type your reply below.
        </p>

        <form method="POST" action="support_view.php?id=<?= $ticketId ?>" class="space-y-4">
            <?= csrfField() ?>
            <div>
                <textarea
                    name="reply"
                    rows="4"
                    placeholder="Type your response here..."
                    required
                    class="w-full rounded-xl border border-slate-200 bg-slate-50 p-4 text-xs sm:text-sm text-slate-900 placeholder-slate-400 outline-none transition focus:bg-white focus:border-servora-600 focus:ring-4 focus:ring-servora-500/10 leading-relaxed"
                ></textarea>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-[11px] text-slate-400">
                    <?php if ($ticket['status'] === SupportService::STATUS_RESOLVED): ?>
                        💡 Replying will automatically reopen this ticket.
                    <?php endif; ?>
                </span>
                <button
                    type="submit"
                    class="rounded-xl bg-servora-700 px-5 py-2.5 text-xs sm:text-sm font-bold text-white shadow-sm hover:bg-servora-800 transition active:scale-95"
                >
                    Send Reply
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>

</main>

<!-- MOBILE BOTTOM NAVIGATION -->
<?php require_once __DIR__ . "/../includes/client_bottom_nav.php"; ?>

</body>
</html>
