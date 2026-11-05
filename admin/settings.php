<?php

require_once "../config/database.php";
require_once "../includes/admin_auth.php";

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$success = '';
$error = '';

// Handle Settings Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        empty($_POST['csrf_token']) ||
        !hash_equals($csrfToken, $_POST['csrf_token'])
    ) {
        $error = "Security token expired or invalid. Please try again.";
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'update_whatsapp') {
            $waNumber = trim($_POST['whatsapp_support_number'] ?? '');
            $waMessage = trim($_POST['whatsapp_support_message'] ?? '');
            $waStatus = isset($_POST['whatsapp_support_status']) && $_POST['whatsapp_support_status'] === '1' ? '1' : '0';

            // Clean number check
            $digitsOnly = preg_replace('/[^\d]/', '', $waNumber);
            if ($waNumber !== '' && strlen($digitsOnly) < 8) {
                $error = "Please enter a valid phone number with at least 8 digits (e.g. +2348012345678 or 08012345678).";
            } else {
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO app_settings (setting_key, setting_value, updated_at)
                        VALUES (?, ?, NOW())
                        ON DUPLICATE KEY UPDATE
                            setting_value = VALUES(setting_value),
                            updated_at = NOW()
                    ");

                    $stmt->execute(['whatsapp_support_number', $waNumber]);
                    $stmt->execute(['whatsapp_support_message', $waMessage]);
                    $stmt->execute(['whatsapp_support_status', $waStatus]);

                    $success = "WhatsApp Support settings updated successfully! Customer-facing pages now reflect this number.";
                } catch (Throwable $e) {
                    $error = "Failed to update settings: " . $e->getMessage();
                }
            }
        } elseif ($action === 'update_smeapi') {
            $smeKey = trim($_POST['smeapi_api_key'] ?? '');
            $smeBaseUrl = rtrim(trim($_POST['smeapi_base_url'] ?? 'https://smeapi.com.ng'), '/');
            $smeStatus = isset($_POST['smeapi_status']) && $_POST['smeapi_status'] === '1' ? '1' : '0';

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO app_settings (setting_key, setting_value, updated_at)
                    VALUES (?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        setting_value = VALUES(setting_value),
                        updated_at = NOW()
                ");

                $stmt->execute(['smeapi_api_key', $smeKey]);
                $stmt->execute(['smeapi_base_url', $smeBaseUrl]);
                $stmt->execute(['smeapi_status', $smeStatus]);

                // Also keep api_providers table status in sync
                $stmtProv = $pdo->prepare("UPDATE api_providers SET status = ?, base_url = ? WHERE slug = 'smeapi'");
                $stmtProv->execute([$smeStatus === '1' ? 'active' : 'inactive', $smeBaseUrl]);

                $success = "SMEAPI data credentials updated successfully! Live balance and data purchases will use these settings.";
            } catch (Throwable $e) {
                $error = "Failed to update SMEAPI settings: " . $e->getMessage();
            }
        } elseif ($action === 'update_maintenance') {
            $mMode = isset($_POST['client_login_enabled']) && $_POST['client_login_enabled'] === '1' ? '1' : '0';
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO app_settings (setting_key, setting_value, updated_at)
                    VALUES ('client_login_enabled', ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        setting_value = VALUES(setting_value),
                        updated_at = NOW()
                ");
                $stmt->execute([$mMode]);
                $success = "Platform maintenance status updated successfully.";
            } catch (Throwable $e) {
                $error = "Failed to update platform maintenance: " . $e->getMessage();
            }
        }
    }
}

// Fetch current settings
$stmt = $pdo->query("
    SELECT setting_key, setting_value
    FROM app_settings
    WHERE setting_key IN ('whatsapp_support_number', 'whatsapp_support_message', 'whatsapp_support_status', 'client_login_enabled', 'smeapi_api_key', 'smeapi_base_url', 'smeapi_status')
");
$currentSettings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$waNumber = $currentSettings['whatsapp_support_number'] ?? '+2348000000000';
$waMessage = $currentSettings['whatsapp_support_message'] ?? 'Hello Subnext Support, I need assistance with your services.';
$waStatus = $currentSettings['whatsapp_support_status'] ?? '1';
$clientLoginEnabled = ($currentSettings['client_login_enabled'] ?? '1') === '1';

// SMEAPI settings (fallback to .env if not set in DB)
$smeApiKeyDb = $currentSettings['smeapi_api_key'] ?? '';
if ($smeApiKeyDb === '') {
    $smeApiKeyDb = trim((string)(getenv('SMEAPI_API_KEY') ?: ($_ENV['SMEAPI_API_KEY'] ?? '')));
}
$smeBaseUrlDb = $currentSettings['smeapi_base_url'] ?? (getenv('SMEAPI_BASE_URL') ?: ($_ENV['SMEAPI_BASE_URL'] ?? 'https://smeapi.com.ng'));
$smeStatusDb = $currentSettings['smeapi_status'] ?? '1';

// Build preview link
$previewDigits = preg_replace('/[^\d]/', '', $waNumber);
if (str_starts_with($previewDigits, '0') && strlen($previewDigits) === 11) {
    $previewDigits = '234' . substr($previewDigits, 1);
}
$previewUrl = 'https://wa.me/' . $previewDigits . '?text=' . rawurlencode($waMessage);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Platform & WhatsApp Settings | Subnext Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/images/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="../assets/images/favicon-16x16.png">
    <link rel="shortcut icon" href="../favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="../assets/images/apple-touch-icon.png">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">

<main class="mx-auto w-full max-w-5xl px-4 py-5 sm:px-6 sm:py-8 lg:px-8">

    <!-- TOP BAR -->
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <a href="dashboard.php" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 shadow-sm transition hover:bg-slate-100 hover:text-slate-900" title="Back to Dashboard">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                </svg>
            </a>
            <div>
                <h1 class="text-xl font-black text-slate-900 sm:text-2xl">Platform & Support Settings</h1>
                <p class="text-xs text-slate-500 sm:text-sm">Manage WhatsApp live support, customer contacts and platform controls</p>
            </div>
        </div>
        <a href="dashboard.php" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-bold text-slate-700 shadow-sm transition hover:bg-slate-50">
            <span>Admin Dashboard</span>
            <span>&rarr;</span>
        </a>
    </div>

    <!-- NOTIFICATIONS -->
    <?php if ($success): ?>
        <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-800 shadow-sm flex items-center justify-between">
            <div class="flex items-center gap-2">
                <svg class="h-5 w-5 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                <span><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <a href="settings.php" class="text-xs underline text-emerald-700">Dismiss</a>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="mb-6 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-800 shadow-sm flex items-center justify-between">
            <div class="flex items-center gap-2">
                <svg class="h-5 w-5 text-red-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
                <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <a href="settings.php" class="text-xs underline text-red-700">Dismiss</a>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

        <!-- MAIN SETTINGS: WHATSAPP CUSTOMER SUPPORT (Compulsory Admin Control) -->
        <div class="lg:col-span-2 space-y-6">

            <div class="rounded-3xl border border-slate-200 bg-white p-6 sm:p-8 shadow-sm">
                <div class="mb-6 flex items-center justify-between border-b border-slate-100 pb-5">
                    <div class="flex items-center gap-3">
                        <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-[#25D366]/15 text-[#25D366]">
                            <svg class="h-7 w-7 fill-current" viewBox="0 0 24 24">
                                <path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91C2.13 13.66 2.59 15.36 3.45 16.86L2.05 22L7.3 20.62C8.75 21.41 10.38 21.83 12.04 21.83C17.5 21.83 21.95 17.38 21.95 11.92C21.95 9.27 20.92 6.78 19.05 4.91C17.18 3.03 14.69 2 12.04 2M12.05 3.67C14.25 3.67 16.31 4.53 17.87 6.09C19.42 7.65 20.28 9.72 20.28 11.92C20.28 16.46 16.58 20.15 12.04 20.15C10.56 20.15 9.11 19.76 7.85 19.01L7.55 18.83L4.43 19.65L5.26 16.61L5.06 16.29C4.24 14.99 3.8 13.47 3.8 11.91C3.81 7.37 7.5 3.67 12.05 3.67M9.53 7.34C9.33 7.34 9 7.42 8.71 7.73C8.42 8.04 7.6 8.81 7.6 10.37C7.6 11.93 8.74 13.44 8.9 13.65C9.06 13.86 11.13 17.05 14.3 18.42C15.06 18.75 15.65 18.95 16.11 19.09C16.87 19.33 17.57 19.3 18.12 19.22C18.74 19.13 20.02 18.45 20.28 17.7C20.55 16.96 20.55 16.33 20.47 16.2C20.39 16.07 20.19 16 19.88 15.84C19.58 15.68 18.07 14.94 17.79 14.84C17.51 14.73 17.31 14.68 17.11 14.99C16.9 15.29 16.33 15.97 16.15 16.17C15.98 16.38 15.8 16.4 15.5 16.25C15.19 16.1 13.9 15.67 12.36 14.3C11.17 13.23 10.36 11.91 10.06 11.4C9.75 10.89 10.03 10.61 10.18 10.46C10.32 10.32 10.49 10.09 10.65 9.91C10.8 9.72 10.85 9.59 10.95 9.38C11.06 9.18 11 9 10.93 8.85C10.85 8.7 10.28 7.31 10.05 6.74C9.82 6.19 9.58 6.27 9.4 6.26L8.85 6.25C8.65 6.25 8.35 6.33 8.05 6.64L9.53 7.34Z"/>
                            </svg>
                        </div>
                        <div>
                            <h2 class="text-lg font-bold text-slate-900">WhatsApp Customer Support</h2>
                            <p class="text-xs text-slate-500">The floating WhatsApp icon dynamically routes to this number without code changes.</p>
                        </div>
                    </div>
                    <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold <?= $waStatus === '1' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-100 text-slate-600 border border-slate-200' ?>">
                        <span class="h-2 w-2 rounded-full <?= $waStatus === '1' ? 'bg-emerald-500' : 'bg-slate-400' ?>"></span>
                        <?= $waStatus === '1' ? 'Active' : 'Disabled' ?>
                    </span>
                </div>

                <form method="POST" action="settings.php" class="space-y-5">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="update_whatsapp">

                    <!-- Support WhatsApp Number -->
                    <div>
                        <label for="whatsapp_support_number" class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                            Support WhatsApp Number <span class="text-red-500">*</span>
                        </label>
                        <p class="mt-1 text-xs text-slate-500">Enter your official business WhatsApp line (e.g. +2348012345678 or 08012345678).</p>
                        <div class="mt-2 relative">
                            <input
                                type="text"
                                id="whatsapp_support_number"
                                name="whatsapp_support_number"
                                value="<?= htmlspecialchars($waNumber, ENT_QUOTES, 'UTF-8') ?>"
                                placeholder="+2348012345678"
                                required
                                class="w-full rounded-2xl border border-slate-300 bg-slate-50/50 px-4 py-3 text-sm font-semibold text-slate-900 shadow-sm focus:border-servora-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-servora-100 transition"
                            >
                        </div>
                    </div>

                    <!-- Welcoming Message -->
                    <div>
                        <label for="whatsapp_support_message" class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                            Default Welcoming Message
                        </label>
                        <p class="mt-1 text-xs text-slate-500">The pre-filled text when a customer clicks the WhatsApp button.</p>
                        <textarea
                            id="whatsapp_support_message"
                            name="whatsapp_support_message"
                            rows="3"
                            class="mt-2 w-full rounded-2xl border border-slate-300 bg-slate-50/50 p-4 text-sm font-normal text-slate-900 shadow-sm focus:border-servora-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-servora-100 transition"
                        ><?= htmlspecialchars($waMessage, ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>

                    <!-- Visibility Toggle -->
                    <div class="flex items-center justify-between rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
                        <div>
                            <p class="text-sm font-bold text-slate-900">Display Floating WhatsApp Icon</p>
                            <p class="text-xs text-slate-500">Show the floating customer support button across landing and user pages.</p>
                        </div>
                        <label class="relative inline-flex cursor-pointer items-center">
                            <input type="checkbox" name="whatsapp_support_status" value="1" <?= $waStatus === '1' ? 'checked' : '' ?> class="peer sr-only">
                            <div class="peer h-6 w-11 rounded-full bg-slate-300 after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:bg-white after:transition-all after:content-[''] peer-checked:bg-[#25D366] peer-checked:after:translate-x-full peer-focus:outline-none"></div>
                        </label>
                    </div>

                    <!-- Save Button -->
                    <div class="pt-2 flex items-center gap-3">
                        <button
                            type="submit"
                            class="inline-flex items-center justify-center rounded-2xl bg-servora-700 hover:bg-servora-800 px-6 py-3.5 text-sm font-bold text-white shadow-lg shadow-servora-700/25 transition active:scale-95"
                        >
                            Save WhatsApp Settings
                        </button>

                        <?php if ($previewDigits): ?>
                            <a
                                href="<?= htmlspecialchars($previewUrl, ENT_QUOTES, 'UTF-8') ?>"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-2 rounded-2xl border border-emerald-200 bg-emerald-50 hover:bg-emerald-100 px-4 py-3.5 text-xs font-bold text-emerald-800 transition"
                            >
                                <svg class="h-4 w-4 fill-current text-emerald-600" viewBox="0 0 24 24">
                                    <path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91C2.13 13.66 2.59 15.36 3.45 16.86L2.05 22L7.3 20.62C8.75 21.41 10.38 21.83 12.04 21.83C17.5 21.83 21.95 17.38 21.95 11.92C21.95 9.27 20.92 6.78 19.05 4.91C17.18 3.03 14.69 2 12.04 2M12.05 3.67C14.25 3.67 16.31 4.53 17.87 6.09C19.42 7.65 20.28 9.72 20.28 11.92C20.28 16.46 16.58 20.15 12.04 20.15C10.56 20.15 9.11 19.76 7.85 19.01L7.55 18.83L4.43 19.65L5.26 16.61L5.06 16.29C4.24 14.99 3.8 13.47 3.8 11.91C3.81 7.37 7.5 3.67 12.05 3.67M9.53 7.34C9.33 7.34 9 7.42 8.71 7.73C8.42 8.04 7.6 8.81 7.6 10.37C7.6 11.93 8.74 13.44 8.9 13.65C9.06 13.86 11.13 17.05 14.3 18.42C15.06 18.75 15.65 18.95 16.11 19.09C16.87 19.33 17.57 19.3 18.12 19.22C18.74 19.13 20.02 18.45 20.28 17.7C20.55 16.96 20.55 16.33 20.47 16.2C20.39 16.07 20.19 16 19.88 15.84C19.58 15.68 18.07 14.94 17.79 14.84C17.51 14.73 17.31 14.68 17.11 14.99C16.9 15.29 16.33 15.97 16.15 16.17C15.98 16.38 15.8 16.4 15.5 16.25C15.19 16.1 13.9 15.67 12.36 14.3C11.17 13.23 10.36 11.91 10.06 11.4C9.75 10.89 10.03 10.61 10.18 10.46C10.32 10.32 10.49 10.09 10.65 9.91C10.8 9.72 10.85 9.59 10.95 9.38C11.06 9.18 11 9 10.93 8.85C10.85 8.7 10.28 7.31 10.05 6.74C9.82 6.19 9.58 6.27 9.4 6.26L8.85 6.25C8.65 6.25 8.35 6.33 8.05 6.64L9.53 7.34Z"/>
                                </svg>
                                <span>Test Link</span>
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- SMEAPI DATA SUBSCRIPTION SETTINGS -->
            <div class="rounded-3xl border border-slate-200 bg-white p-6 sm:p-8 shadow-sm">
                <div class="mb-6 flex items-center justify-between border-b border-slate-100 pb-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                            </svg>
                        </div>
                        <div>
                            <h2 class="text-lg font-bold text-slate-900">SMEAPI Data Integration</h2>
                            <p class="text-xs text-slate-500">Configure your SMEAPI token for automated mobile data subscriptions.</p>
                        </div>
                    </div>
                    <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold <?= $smeStatusDb === '1' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-100 text-slate-600 border border-slate-200' ?>">
                        <span class="h-2 w-2 rounded-full <?= $smeStatusDb === '1' ? 'bg-emerald-500' : 'bg-slate-400' ?>"></span>
                        <?= $smeStatusDb === '1' ? 'Provider Active' : 'Provider Disabled' ?>
                    </span>
                </div>

                <form method="POST" action="settings.php" class="space-y-5">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="update_smeapi">

                    <!-- API Token -->
                    <div>
                        <label for="smeapi_api_key" class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                            SMEAPI Authorization Token (API Key)
                        </label>
                        <p class="mt-1 text-xs text-slate-500">Your private token from <a href="https://smeapi.com.ng" target="_blank" rel="noopener noreferrer" class="text-servora-600 underline">smeapi.com.ng</a>. Passed securely as <code>Authorization: Token {key}</code>.</p>
                        <input
                            type="password"
                            id="smeapi_api_key"
                            name="smeapi_api_key"
                            value="<?= htmlspecialchars($smeApiKeyDb, ENT_QUOTES, 'UTF-8') ?>"
                            placeholder="Enter your SMEAPI token..."
                            class="mt-2 block w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm font-mono text-slate-900 focus:border-servora-500 focus:outline-none focus:ring-2 focus:ring-servora-200"
                        >
                    </div>

                    <!-- Base URL -->
                    <div>
                        <label for="smeapi_base_url" class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                            API Endpoint Base URL
                        </label>
                        <input
                            type="text"
                            id="smeapi_base_url"
                            name="smeapi_base_url"
                            value="<?= htmlspecialchars($smeBaseUrlDb, ENT_QUOTES, 'UTF-8') ?>"
                            required
                            class="mt-2 block w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm font-mono text-slate-900 focus:border-servora-500 focus:outline-none focus:ring-2 focus:ring-servora-200"
                        >
                    </div>

                    <!-- Status Toggle -->
                    <div class="flex items-center justify-between rounded-2xl border border-slate-100 bg-slate-50 p-4">
                        <div>
                            <p class="text-sm font-bold text-slate-900">Enable SMEAPI Provider</p>
                            <p class="text-xs text-slate-500">When active, SMEAPI plans will be available for fulfillment.</p>
                        </div>
                        <label class="relative inline-flex cursor-pointer items-center">
                            <input
                                type="checkbox"
                                name="smeapi_status"
                                value="1"
                                class="peer sr-only"
                                <?= $smeStatusDb === '1' ? 'checked' : '' ?>
                            >
                            <div class="peer h-6 w-11 rounded-full bg-slate-200 after:absolute after:top-[2px] after:left-[2px] after:h-5 after:w-5 after:rounded-full after:bg-white after:transition-all after:content-[''] peer-checked:bg-indigo-600 peer-checked:after:translate-x-full peer-focus:outline-none"></div>
                        </label>
                    </div>

                    <!-- Submit & Actions -->
                    <div class="flex flex-wrap items-center justify-between gap-3 pt-2">
                        <button
                            type="submit"
                            class="inline-flex items-center gap-2 rounded-2xl bg-indigo-600 px-6 py-3.5 text-xs font-bold text-white shadow-md shadow-indigo-600/20 transition hover:bg-indigo-700 active:scale-95"
                        >
                            <span>Save SMEAPI Settings</span>
                        </button>
                        <a
                            href="sync_smeapi_plans.php"
                            class="inline-flex items-center gap-2 rounded-2xl border border-indigo-200 bg-indigo-50 hover:bg-indigo-100 px-4 py-3.5 text-xs font-bold text-indigo-800 transition"
                        >
                            <span>Sync SMEAPI Plans →</span>
                        </a>
                    </div>
                </form>
            </div>

            <!-- MAINTENANCE CONTROL -->
            <div class="rounded-3xl border border-slate-200 bg-white p-6 sm:p-8 shadow-sm">
                <div class="mb-4 flex items-center justify-between border-b border-slate-100 pb-4">
                    <div>
                        <h2 class="text-lg font-bold text-slate-900">Platform Maintenance Mode</h2>
                        <p class="text-xs text-slate-500">Lock or unlock customer login and registration access.</p>
                    </div>
                    <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold <?= $clientLoginEnabled ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-red-50 text-red-700 border border-red-200' ?>">
                        <span class="h-2 w-2 rounded-full <?= $clientLoginEnabled ? 'bg-emerald-500' : 'bg-red-500' ?>"></span>
                        <?= $clientLoginEnabled ? 'Live & Open' : 'Maintenance Mode Active' ?>
                    </span>
                </div>

                <form method="POST" action="settings.php" class="flex flex-col sm:flex-row items-center justify-between gap-4">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="update_maintenance">
                    <input type="hidden" name="client_login_enabled" value="<?= $clientLoginEnabled ? '0' : '1' ?>">

                    <p class="text-xs text-slate-600 leading-relaxed">
                        <?= $clientLoginEnabled 
                            ? 'Client portal and registration are currently <strong>ACTIVE</strong>. Putting the platform in maintenance mode will temporarily lock client logins.' 
                            : 'Client portal is currently <strong>LOCKED</strong>. Admin accounts can still log in and operate.' ?>
                    </p>

                    <button
                        type="submit"
                        class="w-full sm:w-auto shrink-0 rounded-2xl px-5 py-3 text-xs font-bold transition shadow-sm <?= $clientLoginEnabled ? 'bg-amber-600 hover:bg-amber-700 text-white' : 'bg-emerald-600 hover:bg-emerald-700 text-white' ?>"
                    >
                        <?= $clientLoginEnabled ? 'Enable Maintenance Mode' : 'Disable Maintenance Mode' ?>
                    </button>
                </form>
            </div>

        </div>

        <!-- SIDEBAR INFO CARD -->
        <div class="space-y-6">

            <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                <h3 class="text-sm font-bold text-slate-900">How WhatsApp Support Works</h3>
                <div class="mt-4 space-y-3 text-xs text-slate-600 leading-relaxed">
                    <p>
                        🟢 <strong>Automatic Synced:</strong> When you update the support WhatsApp number above, all customer pages automatically update instantly.
                    </p>
                    <p>
                        💬 <strong>Direct WhatsApp Chat:</strong> When visitors click the floating icon on desktop or mobile, WhatsApp opens directly with your specified greeting.
                    </p>
                    <p>
                        🛡️ <strong>Zero Code Editing:</strong> No code deployment or file modifications needed when your support line changes.
                    </p>
                </div>

                <div class="mt-6 rounded-2xl bg-slate-50 p-4 border border-slate-100">
                    <p class="text-[11px] font-bold text-slate-500 uppercase tracking-wider">Current Live Link</p>
                    <p class="mt-1 font-mono text-[11px] text-slate-700 break-all select-all"><?= htmlspecialchars($previewUrl, ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>

            <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                <h3 class="text-sm font-bold text-slate-900">Quick Navigation</h3>
                <div class="mt-4 space-y-2">
                    <a href="dashboard.php" class="block rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-servora-50 hover:text-servora-700 transition">
                        &larr; Admin Dashboard
                    </a>
                    <a href="support_tickets.php" class="block rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-servora-50 hover:text-servora-700 transition">
                        Support Tickets
                    </a>
                    <a href="clients.php" class="block rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-servora-50 hover:text-servora-700 transition">
                        Registered Clients
                    </a>
                </div>
            </div>

        </div>

    </div>

</main>

</body>
</html>

