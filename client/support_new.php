<?php
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/client_auth.php";
require_once __DIR__ . "/../includes/SupportService.php";

$userId = currentUserId();
$fullName = trim((string)($_SESSION["full_name"] ?? "Client"));
$profileInitial = strtoupper(substr($fullName, 0, 1));

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();

    $subject = trim((string)($_POST['subject'] ?? ''));
    $category = trim((string)($_POST['category'] ?? 'General Enquiry'));
    $priority = trim((string)($_POST['priority'] ?? 'medium'));
    $message = trim((string)($_POST['message'] ?? ''));

    if ($subject === '') {
        $error = 'Please enter a ticket subject describing your issue.';
    } elseif (mb_strlen($subject) < 4) {
        $error = 'Subject must be at least 4 characters.';
    } elseif (!array_key_exists($category, SupportService::CATEGORIES)) {
        $error = 'Please select a valid ticket category.';
    } elseif ($message === '') {
        $error = 'Please enter a detailed message explaining your inquiry.';
    } elseif (mb_strlen($message) < 10) {
        $error = 'Message is too short. Please provide more details so we can resolve your request quickly.';
    } else {
        try {
            $ticketId = SupportService::createTicket(
                $pdo,
                $userId,
                $subject,
                $category,
                $priority,
                $message
            );

            header("Location: support_view.php?id={$ticketId}&created=1");
            exit;
        } catch (Throwable $e) {
            error_log('Failed to create ticket: ' . $e->getMessage());
            $error = 'An error occurred while creating your ticket. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Open Support Ticket | Subnext</title>
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
<main class="mx-auto w-full max-w-3xl px-3.5 py-4 pb-24 md:py-8 md:pb-12 sm:px-6">

    <div class="mb-5 flex items-center justify-between">
        <a href="support.php" class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-600 hover:text-servora-700 transition">
            <span>←</span>
            <span>Back to Support Tickets</span>
        </a>
    </div>

    <div class="rounded-2xl sm:rounded-3xl border border-slate-200 bg-white p-5 sm:p-8 shadow-xs">
        <div class="mb-6">
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center justify-center w-7 h-7 rounded-xl bg-servora-100 text-servora-700 font-black text-xs">
                    +
                </span>
                <h1 class="text-lg sm:text-xl font-bold tracking-tight text-slate-900">
                    Open a Support Ticket
                </h1>
            </div>
            <p class="mt-1 text-xs sm:text-sm text-slate-500">
                Describe your issue clearly. Our support representatives typically respond within minutes during business hours.
            </p>
        </div>

        <?php if ($error !== ''): ?>
        <div class="mb-6 rounded-xl border border-rose-200 bg-rose-50 p-3.5 text-xs font-semibold text-rose-800 flex items-center gap-2">
            <span>⚠️</span>
            <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <?php endif; ?>

        <form method="POST" action="support_new.php" class="space-y-4 sm:space-y-5">
            <?= csrfField() ?>

            <!-- SUBJECT -->
            <div>
                <label for="subject" class="block text-xs sm:text-sm font-semibold text-slate-700 mb-1.5">
                    Ticket Subject <span class="text-rose-500">*</span>
                </label>
                <input
                    type="text"
                    id="subject"
                    name="subject"
                    value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>"
                    placeholder="e.g. Data order delayed for phone number 08012345678"
                    required
                    class="w-full h-12 sm:h-13 rounded-xl border border-slate-200 bg-slate-50 px-4 text-xs sm:text-sm text-slate-900 placeholder-slate-400 outline-none transition focus:bg-white focus:border-servora-600 focus:ring-4 focus:ring-servora-500/10"
                >
            </div>

            <!-- CATEGORY & PRIORITY -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="category" class="block text-xs sm:text-sm font-semibold text-slate-700 mb-1.5">
                        Category <span class="text-rose-500">*</span>
                    </label>
                    <select
                        id="category"
                        name="category"
                        required
                        class="w-full h-12 sm:h-13 rounded-xl border border-slate-200 bg-slate-50 px-3.5 text-xs sm:text-sm text-slate-900 outline-none transition focus:bg-white focus:border-servora-600 focus:ring-4 focus:ring-servora-500/10"
                    >
                        <?php foreach (SupportService::CATEGORIES as $catName => $catDesc): ?>
                        <option value="<?= htmlspecialchars($catName) ?>" <?= (($_POST['category'] ?? '') === $catName) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($catName) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="priority" class="block text-xs sm:text-sm font-semibold text-slate-700 mb-1.5">
                        Urgency / Priority
                    </label>
                    <select
                        id="priority"
                        name="priority"
                        class="w-full h-12 sm:h-13 rounded-xl border border-slate-200 bg-slate-50 px-3.5 text-xs sm:text-sm text-slate-900 outline-none transition focus:bg-white focus:border-servora-600 focus:ring-4 focus:ring-servora-500/10"
                    >
                        <option value="medium" <?= (($_POST['priority'] ?? 'medium') === 'medium') ? 'selected' : '' ?>>Normal (Standard inquiry)</option>
                        <option value="high" <?= (($_POST['priority'] ?? '') === 'high') ? 'selected' : '' ?>>High (Wallet or order issue)</option>
                        <option value="urgent" <?= (($_POST['priority'] ?? '') === 'urgent') ? 'selected' : '' ?>>Urgent (Time-critical transaction)</option>
                        <option value="low" <?= (($_POST['priority'] ?? '') === 'low') ? 'selected' : '' ?>>Low (General question)</option>
                    </select>
                </div>
            </div>

            <!-- MESSAGE -->
            <div>
                <label for="message" class="block text-xs sm:text-sm font-semibold text-slate-700 mb-1.5">
                    Detailed Message <span class="text-rose-500">*</span>
                </label>
                <textarea
                    id="message"
                    name="message"
                    rows="6"
                    placeholder="Provide full details, order references, phone numbers, or amounts involved so we can resolve this as quickly as possible..."
                    required
                    class="w-full rounded-xl border border-slate-200 bg-slate-50 p-4 text-xs sm:text-sm text-slate-900 placeholder-slate-400 outline-none transition focus:bg-white focus:border-servora-600 focus:ring-4 focus:ring-servora-500/10 leading-relaxed"
                ><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
                <p class="mt-1 text-[11px] text-slate-400">Include any relevant transaction reference numbers or phone numbers.</p>
            </div>

            <div class="pt-2 flex items-center justify-end gap-3">
                <a href="support.php" class="rounded-xl px-4 py-2.5 text-xs sm:text-sm font-semibold text-slate-600 hover:bg-slate-100 transition">
                    Cancel
                </a>
                <button
                    type="submit"
                    class="rounded-xl bg-servora-700 px-6 py-2.5 text-xs sm:text-sm font-bold text-white shadow-sm hover:bg-servora-800 transition active:scale-95"
                >
                    Submit Ticket
                </button>
            </div>
        </form>
    </div>

</main>

<!-- MOBILE BOTTOM NAVIGATION -->
<?php require_once __DIR__ . "/../includes/client_bottom_nav.php"; ?>

</body>
</html>
