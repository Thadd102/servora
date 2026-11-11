<?php

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/admin_auth.php";
require_once __DIR__ . "/../includes/AnnouncementService.php";
require_once __DIR__ . "/../includes/PushNotificationService.php";

$adminId = (int)($_SESSION['user_id'] ?? 0);
$announcementService = new AnnouncementService($pdo);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$success = $_SESSION['announcement_success'] ?? null;
$error = $_SESSION['announcement_error'] ?? null;
unset($_SESSION['announcement_success'], $_SESSION['announcement_error']);

// Handle Actions (Create, Update, Delete, Toggle)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $postedToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrfToken, $postedToken)) {
        $_SESSION['announcement_error'] = "Invalid CSRF security token.";
        header("Location: announcements.php");
        exit;
    }

    try {
        if ($action === 'create') {
            $title = trim((string)($_POST['title'] ?? ''));
            $message = trim((string)($_POST['message'] ?? ''));
            $type = trim((string)($_POST['type'] ?? 'info'));
            $targetType = trim((string)($_POST['target_type'] ?? 'all'));
            $targetUserIds = trim((string)($_POST['target_user_ids'] ?? ''));
            $expiresAt = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
            $isActive = !empty($_POST['is_active']) ? 1 : 0;
            $sendPush = !empty($_POST['send_push']) ? 1 : 0;

            if ($title === '' || $message === '') {
                throw new Exception("Title and message are required.");
            }

            $newId = $announcementService->create([
                'title' => $title,
                'message' => $message,
                'type' => $type,
                'target_type' => $targetType,
                'target_user_ids' => $targetUserIds,
                'expires_at' => $expiresAt,
                'is_active' => $isActive,
            ], $adminId);

            // Optional instant Web Push
            if ($sendPush && $isActive) {
                try {
                    $pushService = new PushNotificationService($pdo);
                    $pushService->send([
                        'title' => $title,
                        'body' => mb_substr($message, 0, 180) . (mb_strlen($message) > 180 ? '...' : ''),
                        'target_url' => '/client/dashboard.php',
                        'target_type' => $targetType,
                        'target_user_ids' => $targetUserIds,
                    ], $adminId);
                } catch (\Throwable $pe) {
                    error_log("[PushNotification] Error sending push for announcement: " . $pe->getMessage());
                }
            }

            $_SESSION['announcement_success'] = "Announcement created successfully!" . ($sendPush ? " Push notification dispatched." : "");
            header("Location: announcements.php");
            exit;
        }

        if ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            $message = trim((string)($_POST['message'] ?? ''));
            $type = trim((string)($_POST['type'] ?? 'info'));
            $targetType = trim((string)($_POST['target_type'] ?? 'all'));
            $targetUserIds = trim((string)($_POST['target_user_ids'] ?? ''));
            $expiresAt = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
            $isActive = !empty($_POST['is_active']) ? 1 : 0;

            if ($id <= 0 || $title === '' || $message === '') {
                throw new Exception("Invalid announcement data provided.");
            }

            $announcementService->update($id, [
                'title' => $title,
                'message' => $message,
                'type' => $type,
                'target_type' => $targetType,
                'target_user_ids' => $targetUserIds,
                'expires_at' => $expiresAt,
                'is_active' => $isActive,
            ]);

            $_SESSION['announcement_success'] = "Announcement updated successfully.";
            header("Location: announcements.php");
            exit;
        }

        if ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $announcementService->toggleStatus($id);
                $_SESSION['announcement_success'] = "Announcement status toggled.";
            }
            header("Location: announcements.php");
            exit;
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $announcementService->delete($id);
                $_SESSION['announcement_success'] = "Announcement deleted successfully.";
            }
            header("Location: announcements.php");
            exit;
        }
    } catch (Exception $e) {
        $_SESSION['announcement_error'] = $e->getMessage();
        header("Location: announcements.php");
        exit;
    }
}

// Filter
$typeFilter = isset($_GET['type']) ? trim((string)$_GET['type']) : null;
$announcements = [];
try {
    $announcements = $announcementService->getAll($typeFilter);
} catch (Throwable $e) {
    $error = "Notice: Could not load announcements list: " . $e->getMessage();
}

// Calculate Stats
$totalCount = count($announcements);
$activeCount = 0;
$urgentCount = 0;
$totalDismissals = 0;
$now = time();

foreach ($announcements as $a) {
    $isExp = !empty($a['expires_at']) && strtotime($a['expires_at']) < $now;
    if ($a['is_active'] && !$isExp) {
        $activeCount++;
    }
    if ($a['type'] === 'important' || $a['type'] === 'maintenance') {
        $urgentCount++;
    }
    $totalDismissals += (int)$a['dismiss_count'];
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
    <title>Announcements Management | Subnext Admin</title>
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
                        Customer Communications
                    </div>
                    <h1 class="text-2xl font-black tracking-tight text-slate-900 sm:text-3xl">
                        Announcements Management
                    </h1>
                    <p class="mt-1 text-xs sm:text-sm text-slate-500">
                        Create, publish, schedule and target alerts, notices and maintenance announcements.
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <button type="button" onclick="openCreateModal()" class="inline-flex items-center gap-2 rounded-xl bg-[#3E37B7] hover:bg-[#312E81] px-5 py-2.5 text-xs sm:text-sm font-bold text-white shadow-md shadow-servora-700/20 transition hover:-translate-y-0.5">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        <span>New Announcement</span>
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

        <!-- METRICS -->
        <div class="mb-8 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Total Created</p>
                <p class="mt-2 text-3xl font-black text-slate-900"><?= $totalCount ?></p>
                <p class="mt-1 text-xs text-slate-500">Historical records</p>
            </div>

            <div class="rounded-2xl border border-emerald-200 bg-emerald-50/50 p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-emerald-700">Currently Active</p>
                <p class="mt-2 text-3xl font-black text-emerald-700"><?= $activeCount ?></p>
                <p class="mt-1 text-xs text-emerald-600">Visible to target customers</p>
            </div>

            <div class="rounded-2xl border border-rose-200 bg-rose-50/50 p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-rose-700">Urgent / Maintenance</p>
                <p class="mt-2 text-3xl font-black text-rose-700"><?= $urgentCount ?></p>
                <p class="mt-1 text-xs text-rose-600">High priority alerts</p>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Total Dismissals</p>
                <p class="mt-2 text-3xl font-black text-slate-900"><?= $totalDismissals ?></p>
                <p class="mt-1 text-xs text-slate-500">Read & acknowledged</p>
            </div>
        </div>

        <!-- FILTER TABS -->
        <div class="mb-6 flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 pb-4">
            <div class="flex flex-wrap items-center gap-2">
                <a href="announcements.php" class="rounded-xl px-4 py-2 text-xs font-bold transition <?= empty($typeFilter) ? 'bg-[#3E37B7] text-white shadow-sm' : 'bg-white text-slate-600 hover:bg-slate-100 border border-slate-200' ?>">
                    All Types
                </a>
                <a href="announcements.php?type=info" class="rounded-xl px-4 py-2 text-xs font-bold transition <?= $typeFilter === 'info' ? 'bg-[#3E37B7] text-white shadow-sm' : 'bg-white text-slate-600 hover:bg-slate-100 border border-slate-200' ?>">
                    Information
                </a>
                <a href="announcements.php?type=warning" class="rounded-xl px-4 py-2 text-xs font-bold transition <?= $typeFilter === 'warning' ? 'bg-[#3E37B7] text-white shadow-sm' : 'bg-white text-slate-600 hover:bg-slate-100 border border-slate-200' ?>">
                    Warning
                </a>
                <a href="announcements.php?type=maintenance" class="rounded-xl px-4 py-2 text-xs font-bold transition <?= $typeFilter === 'maintenance' ? 'bg-[#3E37B7] text-white shadow-sm' : 'bg-white text-slate-600 hover:bg-slate-100 border border-slate-200' ?>">
                    Maintenance
                </a>
                <a href="announcements.php?type=important" class="rounded-xl px-4 py-2 text-xs font-bold transition <?= $typeFilter === 'important' ? 'bg-[#3E37B7] text-white shadow-sm' : 'bg-white text-slate-600 hover:bg-slate-100 border border-slate-200' ?>">
                    Important
                </a>
            </div>

            <div class="text-xs text-slate-500 font-medium">
                Showing <strong><?= count($announcements) ?></strong> announcements
            </div>
        </div>

        <!-- TABLE -->
        <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50/80 text-[11px] font-black uppercase tracking-wider text-slate-500">
                            <th class="py-4 px-6">Announcement Details</th>
                            <th class="py-4 px-6">Type</th>
                            <th class="py-4 px-6">Audience</th>
                            <th class="py-4 px-6">Expiry</th>
                            <th class="py-4 px-6">Dismissals</th>
                            <th class="py-4 px-6">Status</th>
                            <th class="py-4 px-6 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($announcements)): ?>
                            <tr>
                                <td colspan="7" class="py-12 text-center text-slate-400">
                                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-2xl">📢</div>
                                    <p class="mt-3 text-sm font-bold text-slate-700">No announcements found</p>
                                    <p class="text-xs text-slate-400">Click "New Announcement" above to publish your first notification.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($announcements as $ann): ?>
                                <?php
                                $id = (int)$ann['id'];
                                $isExp = !empty($ann['expires_at']) && strtotime($ann['expires_at']) < $now;
                                $statusLabel = $ann['is_active'] ? ($isExp ? 'Expired' : 'Active') : 'Inactive';
                                $statusBadgeClass = $ann['is_active'] 
                                    ? ($isExp ? 'bg-slate-100 text-slate-600 border-slate-200' : 'bg-emerald-50 text-emerald-700 border-emerald-200') 
                                    : 'bg-amber-50 text-amber-700 border-amber-200';
                                
                                $typeBadgeClass = match($ann['type']) {
                                    'warning' => 'bg-amber-100 text-amber-800 border-amber-200',
                                    'maintenance' => 'bg-rose-100 text-rose-800 border-rose-200',
                                    'important' => 'bg-purple-100 text-purple-800 border-purple-200',
                                    default => 'bg-indigo-100 text-indigo-800 border-indigo-200',
                                };
                                ?>
                                <tr class="hover:bg-slate-50/70 transition">
                                    <td class="py-4 px-6">
                                        <p class="font-bold text-slate-900"><?= e($ann['title']) ?></p>
                                        <p class="mt-1 text-xs text-slate-500 line-clamp-2 max-w-md"><?= e($ann['message']) ?></p>
                                        <span class="mt-2 inline-block text-[10px] text-slate-400">
                                            Created <?= date('M j, Y • g:i a', strtotime($ann['created_at'])) ?>
                                        </span>
                                    </td>

                                    <td class="py-4 px-6">
                                        <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-bold capitalize <?= $typeBadgeClass ?>">
                                            <?= e($ann['type']) ?>
                                        </span>
                                    </td>

                                    <td class="py-4 px-6">
                                        <?php if ($ann['target_type'] === 'all'): ?>
                                            <span class="inline-flex items-center gap-1 text-xs font-bold text-slate-700">
                                                <span>👥</span> All Customers
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 text-xs font-bold text-servora-700" title="User IDs: <?= e($ann['target_user_ids']) ?>">
                                                <span>🎯</span> Specific (<?= count(array_filter(explode(',', (string)$ann['target_user_ids']))) ?> users)
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="py-4 px-6 text-xs text-slate-600">
                                        <?php if (!empty($ann['expires_at'])): ?>
                                            <span class="<?= $isExp ? 'text-rose-600 font-bold' : '' ?>">
                                                <?= date('M j, Y • g:i a', strtotime($ann['expires_at'])) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-slate-400 font-medium">No Expiry (Indefinite)</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="py-4 px-6 text-xs font-bold text-slate-700">
                                        <?= (int)$ann['dismiss_count'] ?> <span class="text-[11px] font-normal text-slate-400">times</span>
                                    </td>

                                    <td class="py-4 px-6">
                                        <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-bold <?= $statusBadgeClass ?>">
                                            <span class="h-1.5 w-1.5 rounded-full <?= $ann['is_active'] && !$isExp ? 'bg-emerald-500' : 'bg-slate-400' ?>"></span>
                                            <?= $statusLabel ?>
                                        </span>
                                    </td>

                                    <td class="py-4 px-6 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <!-- Toggle publish -->
                                            <form method="POST" action="announcements.php" class="inline">
                                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                                <input type="hidden" name="action" value="toggle">
                                                <input type="hidden" name="id" value="<?= $id ?>">
                                                <button type="submit" class="rounded-lg border border-slate-200 bg-white p-2 text-slate-600 hover:bg-slate-100 hover:text-slate-900 transition" title="<?= $ann['is_active'] ? 'Deactivate' : 'Publish' ?>">
                                                    <?php if ($ann['is_active']): ?>
                                                        <svg class="h-4 w-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                    <?php else: ?>
                                                        <svg class="h-4 w-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                    <?php endif; ?>
                                                </button>
                                            </form>

                                            <!-- Edit button -->
                                            <button type="button" onclick="openEditModal(<?= htmlspecialchars(json_encode($ann), ENT_QUOTES, 'UTF-8') ?>)" class="rounded-lg border border-slate-200 bg-white p-2 text-slate-600 hover:bg-slate-100 hover:text-slate-900 transition" title="Edit Announcement">
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                            </button>

                                            <!-- Delete button -->
                                            <form method="POST" action="announcements.php" class="inline" onsubmit="return confirm('Are you sure you want to permanently delete this announcement?');">
                                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $id ?>">
                                                <button type="submit" class="rounded-lg border border-red-200 bg-red-50 p-2 text-red-600 hover:bg-red-100 transition" title="Delete Announcement">
                                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

</div>

<!-- =========================================================================
     MODAL: CREATE ANNOUNCEMENT
========================================================================== -->
<div id="createModal" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="w-full max-w-xl rounded-3xl bg-white p-6 sm:p-8 shadow-2xl max-h-[92vh] overflow-y-auto">
        <div class="flex items-center justify-between border-b border-slate-100 pb-4 mb-5">
            <div>
                <h3 class="text-lg font-black text-slate-900">Create New Announcement</h3>
                <p class="text-xs text-slate-500">Publish high-impact notifications to customer portals</p>
            </div>
            <button type="button" onclick="closeCreateModal()" class="rounded-xl p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <form method="POST" action="announcements.php" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="create">

            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">Title *</label>
                <input type="text" name="title" required placeholder="e.g. MTN Data Service Maintenance" class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-900 focus:border-[#3E37B7] focus:outline-none">
            </div>

            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">Announcement Type *</label>
                <select name="type" class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-900 focus:border-[#3E37B7] focus:outline-none">
                    <option value="info">ℹ️ Information (General news & feature updates)</option>
                    <option value="warning">⚠️ Warning (Network delays or temporary slowness)</option>
                    <option value="maintenance">🛠️ Maintenance (Active service work & downtimes)</option>
                    <option value="important">🚨 Important (Urgent action required or critical bulletin)</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">Message Body *</label>
                <textarea name="message" rows="4" required placeholder="Type the customer-facing message here..." class="w-full rounded-xl border border-slate-200 p-4 text-sm font-medium text-slate-900 focus:border-[#3E37B7] focus:outline-none"></textarea>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">Target Audience</label>
                    <select name="target_type" id="createTargetType" onchange="toggleTargetUsers('create')" class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-900 focus:border-[#3E37B7] focus:outline-none">
                        <option value="all">All Customers</option>
                        <option value="specific">Specific User IDs</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">Expiry Date (Optional)</label>
                    <input type="datetime-local" name="expires_at" class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-900 focus:border-[#3E37B7] focus:outline-none">
                </div>
            </div>

            <div id="createSpecificUserBox" class="hidden">
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">User IDs (Comma-separated)</label>
                <input type="text" name="target_user_ids" placeholder="e.g. 101, 145, 202" class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-900 focus:border-[#3E37B7] focus:outline-none">
                <p class="text-[11px] text-slate-400 mt-1">Leave empty to target all clients.</p>
            </div>

            <div class="rounded-2xl border border-slate-100 bg-slate-50 p-4 space-y-3">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="is_active" value="1" checked class="h-4 w-4 rounded text-[#3E37B7] focus:ring-[#3E37B7]">
                    <span class="text-xs font-bold text-slate-800">Publish immediately to customer portals</span>
                </label>

                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="send_push" value="1" class="h-4 w-4 rounded text-[#3E37B7] focus:ring-[#3E37B7]">
                    <span class="text-xs font-bold text-slate-800">Also send as Phone Push Notification (Web Push)</span>
                </label>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                <button type="button" onclick="closeCreateModal()" class="rounded-xl border border-slate-200 px-5 py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-50">
                    Cancel
                </button>
                <button type="submit" class="rounded-xl bg-[#3E37B7] hover:bg-[#312E81] px-6 py-2.5 text-xs font-bold text-white shadow-md">
                    Publish Announcement
                </button>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================================
     MODAL: EDIT ANNOUNCEMENT
========================================================================== -->
<div id="editModal" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="w-full max-w-xl rounded-3xl bg-white p-6 sm:p-8 shadow-2xl max-h-[92vh] overflow-y-auto">
        <div class="flex items-center justify-between border-b border-slate-100 pb-4 mb-5">
            <div>
                <h3 class="text-lg font-black text-slate-900">Edit Announcement</h3>
                <p class="text-xs text-slate-500">Update announcement content, type, or audience</p>
            </div>
            <button type="button" onclick="closeEditModal()" class="rounded-xl p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <form method="POST" action="announcements.php" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="editId" value="">

            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">Title *</label>
                <input type="text" name="title" id="editTitle" required class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-900 focus:border-[#3E37B7] focus:outline-none">
            </div>

            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">Announcement Type *</label>
                <select name="type" id="editType" class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-900 focus:border-[#3E37B7] focus:outline-none">
                    <option value="info">ℹ️ Information</option>
                    <option value="warning">⚠️ Warning</option>
                    <option value="maintenance">🛠️ Maintenance</option>
                    <option value="important">🚨 Important</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">Message Body *</label>
                <textarea name="message" id="editMessage" rows="4" required class="w-full rounded-xl border border-slate-200 p-4 text-sm font-medium text-slate-900 focus:border-[#3E37B7] focus:outline-none"></textarea>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">Target Audience</label>
                    <select name="target_type" id="editTargetType" onchange="toggleTargetUsers('edit')" class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-900 focus:border-[#3E37B7] focus:outline-none">
                        <option value="all">All Customers</option>
                        <option value="specific">Specific User IDs</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">Expiry Date</label>
                    <input type="datetime-local" name="expires_at" id="editExpiresAt" class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-900 focus:border-[#3E37B7] focus:outline-none">
                </div>
            </div>

            <div id="editSpecificUserBox" class="hidden">
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">User IDs (Comma-separated)</label>
                <input type="text" name="target_user_ids" id="editTargetUserIds" class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-900 focus:border-[#3E37B7] focus:outline-none">
            </div>

            <div class="rounded-2xl border border-slate-100 bg-slate-50 p-4">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="is_active" id="editIsActive" value="1" class="h-4 w-4 rounded text-[#3E37B7] focus:ring-[#3E37B7]">
                    <span class="text-xs font-bold text-slate-800">Active (Visible to target customers)</span>
                </label>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                <button type="button" onclick="closeEditModal()" class="rounded-xl border border-slate-200 px-5 py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-50">
                    Cancel
                </button>
                <button type="submit" class="rounded-xl bg-[#3E37B7] hover:bg-[#312E81] px-6 py-2.5 text-xs font-bold text-white shadow-md">
                    Update Announcement
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openCreateModal() {
    document.getElementById('createModal').classList.remove('hidden');
}
function closeCreateModal() {
    document.getElementById('createModal').classList.add('hidden');
}

function openEditModal(ann) {
    document.getElementById('editId').value = ann.id;
    document.getElementById('editTitle').value = ann.title;
    document.getElementById('editMessage').value = ann.message;
    document.getElementById('editType').value = ann.type;
    document.getElementById('editTargetType').value = ann.target_type;
    document.getElementById('editTargetUserIds').value = ann.target_user_ids || '';
    document.getElementById('editIsActive').checked = (parseInt(ann.is_active) === 1);

    if (ann.expires_at) {
        var dt = new Date(ann.expires_at.replace(' ', 'T'));
        var pad = function(n) { return (n < 10 ? '0' : '') + n; };
        var formatted = dt.getFullYear() + '-' + pad(dt.getMonth() + 1) + '-' + pad(dt.getDate()) + 'T' + pad(dt.getHours()) + ':' + pad(dt.getMinutes());
        document.getElementById('editExpiresAt').value = formatted;
    } else {
        document.getElementById('editExpiresAt').value = '';
    }

    toggleTargetUsers('edit');
    document.getElementById('editModal').classList.remove('hidden');
}
function closeEditModal() {
    document.getElementById('editModal').classList.add('hidden');
}

function toggleTargetUsers(mode) {
    var select = document.getElementById(mode + 'TargetType');
    var box = document.getElementById(mode + 'SpecificUserBox');
    if (select && box) {
        if (select.value === 'specific') {
            box.classList.remove('hidden');
        } else {
            box.classList.add('hidden');
        }
    }
}
</script>

</body>
</html>

