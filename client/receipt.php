<?php

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/client_auth.php";
require_once __DIR__ . "/../includes/ReceiptService.php";

$userId = currentUserId();
if ($userId <= 0) {
    header("Location: ../login.php");
    exit;
}

$ref = trim((string)($_GET['ref'] ?? ''));
$type = trim((string)($_GET['type'] ?? ''));
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;

// Fetch verified receipt with strict IDOR user isolation
$receipt = ReceiptService::getReceipt($pdo, $userId, $ref, $type, $id);

$fullName = trim((string)($_SESSION["full_name"] ?? "Client"));
$profileInitial = strtoupper(substr($fullName, 0, 1) ?: "C");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $receipt ? 'Receipt #' . htmlspecialchars($receipt['receipt_no']) : 'Receipt Not Found' ?> - Subnext</title>
    <!-- Precompiled Production Stylesheet -->
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        body {
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        /* =========================================================
           PRINT OPTIMIZATIONS
           Strips headers, footers, action buttons, backgrounds, and
           ensures only the professional receipt is printed.
        ========================================================= */
        @media print {
            body {
                background: #ffffff !important;
                color: #0f172a !important;
                padding: 0 !important;
                margin: 0 !important;
            }

            .no-print,
            header,
            nav,
            footer,
            .action-bar,
            .client-bottom-nav,
            #mobileBottomNav {
                display: none !important;
            }

            .receipt-wrapper {
                max-width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
                border: none !important;
            }

            .receipt-card {
                box-shadow: none !important;
                border: 1px solid #cbd5e1 !important;
                border-radius: 12px !important;
                padding: 24px !important;
            }

            .token-box {
                border: 2px dashed #64748b !important;
                background: #f8fafc !important;
            }

            @page {
                size: auto;
                margin: 15mm;
            }
        }
    </style>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 antialiased selection:bg-servora-500 selection:text-white">

<!-- DESKTOP TOPBAR (HIDDEN IN PRINT) -->
<header class="no-print sticky top-0 z-40 border-b border-slate-200/80 bg-white/95 backdrop-blur">
    <div class="mx-auto flex h-16 max-w-5xl items-center justify-between px-4 sm:px-6">
        <a href="dashboard.php" class="flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-servora-700 text-lg font-black text-white shadow-sm">S</div>
            <div>
                <div class="text-lg font-bold tracking-tight text-slate-900 leading-tight">Subnext</div>
                <div class="text-[10px] font-medium text-slate-400 uppercase tracking-wider">Official Receipt</div>
            </div>
        </a>

        <div class="flex items-center gap-3">
            <a href="orders.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50 transition">
                ← My Orders
            </a>
            <a href="dashboard.php" class="rounded-xl bg-slate-50 px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-slate-100 transition border border-slate-200">
                Dashboard
            </a>
        </div>
    </div>
</header>

<main class="mx-auto w-full max-w-3xl px-4 py-8 pb-20 sm:px-6">

    <?php if (!$receipt): ?>
        <!-- NOT FOUND OR UNAUTHORIZED CARD -->
        <div class="rounded-3xl border border-slate-200 bg-white p-8 text-center shadow-sm">
            <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-rose-50 text-rose-600">
                <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
            </div>
            <h1 class="text-xl font-bold text-slate-900">Receipt Not Available</h1>
            <p class="mt-2 text-sm text-slate-500 max-w-md mx-auto">
                The requested transaction receipt could not be found or you do not have permission to view it. Only completed, successful transactions generate receipts.
            </p>
            <div class="mt-6 flex flex-wrap justify-center gap-3">
                <a href="orders.php" class="rounded-xl bg-servora-700 px-5 py-2.5 text-xs font-bold text-white shadow-sm hover:bg-servora-800 transition">
                    View Orders History
                </a>
                <a href="dashboard.php" class="rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-50 transition">
                    Return to Dashboard
                </a>
            </div>
        </div>
    <?php else: ?>

        <!-- ACTION CONTROLS (HIDDEN IN PRINT) -->
        <div class="no-print mb-6 flex flex-wrap items-center justify-between gap-3 action-bar">
            <a href="javascript:history.back()" class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-600 hover:text-servora-700 transition">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                Back
            </a>

            <div class="flex items-center gap-2">
                <button type="button" onclick="window.print()" class="inline-flex items-center gap-2 rounded-xl bg-servora-700 px-4 py-2.5 text-xs font-bold text-white shadow-md hover:bg-servora-800 active:scale-95 transition">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                    </svg>
                    Print Receipt
                </button>
                <button type="button" onclick="window.print()" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-50 transition shadow-xs" title="Save as PDF via printer dialog">
                    <svg class="h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                    </svg>
                    Download PDF
                </button>
            </div>
        </div>

        <!-- =========================================================
             OFFICIAL SUBNEXT RECEIPT CONTAINER (PRINTABLE)
        ========================================================= -->
        <article class="receipt-wrapper">
            <div class="receipt-card relative overflow-hidden rounded-3xl border border-slate-200/90 bg-white p-6 sm:p-10 shadow-xl">
                
                <!-- TOP HEADER BAND -->
                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-6 border-b border-slate-100 pb-8">
                    <!-- Brand Section -->
                    <div class="flex items-center gap-3.5">
                        <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-servora-700 text-2xl font-black text-white shadow-md">
                            S
                        </div>
                        <div>
                            <div class="text-2xl font-black tracking-tight text-slate-900 leading-tight">Subnext</div>
                            <p class="text-xs font-medium text-slate-400 uppercase tracking-widest mt-0.5">Digital Services, Simplified.</p>
                            <p class="text-[11px] text-slate-500 font-mono mt-0.5">support@subnext.com.ng • subnext.com.ng</p>
                        </div>
                    </div>

                    <!-- Receipt Metadata -->
                    <div class="sm:text-right">
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-black uppercase tracking-wider text-emerald-700 border border-emerald-200/80">
                            <svg class="h-3.5 w-3.5 text-emerald-600" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                            <?= htmlspecialchars($receipt['status']) ?>
                        </span>
                        <div class="mt-3">
                            <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-400">Receipt Reference</span>
                            <span class="font-mono text-sm font-black text-slate-900 select-all"><?= htmlspecialchars($receipt['reference']) ?></span>
                        </div>
                        <div class="mt-1 text-xs text-slate-500">
                            <?= htmlspecialchars($receipt['formatted_date']) ?>
                        </div>
                    </div>
                </div>

                <!-- AMOUNT HERO DISPLAY -->
                <div class="my-7 rounded-2xl bg-gradient-to-r from-slate-50 via-servora-50/40 to-slate-50 p-6 text-center border border-slate-200/70">
                    <span class="text-xs font-bold uppercase tracking-widest text-slate-400">Amount Paid</span>
                    <div class="mt-1 text-4xl sm:text-5xl font-black text-slate-900 tracking-tight font-mono">
                        <?= htmlspecialchars($receipt['formatted_amount']) ?>
                    </div>
                    <div class="mt-2 inline-flex items-center gap-2 text-xs font-semibold text-slate-600">
                        <span>Payment Method:</span>
                        <span class="rounded-md bg-white px-2.5 py-0.5 text-slate-800 font-bold border border-slate-200 shadow-2xs">
                            <?= htmlspecialchars($receipt['payment_method']) ?>
                        </span>
                    </div>
                </div>

                <!-- HIGHLIGHTED TOKEN / PIN / CODE BANNER (IF APPLICABLE) -->
                <?php if (!empty($receipt['token_highlight'])): ?>
                <div class="token-box mb-7 rounded-2xl bg-amber-50/70 border-2 border-dashed border-amber-300 p-5 text-center">
                    <span class="text-xs font-bold uppercase tracking-widest text-amber-800">
                        <?= htmlspecialchars($receipt['token_highlight']['label']) ?>
                    </span>
                    <div class="mt-2 font-mono text-xl sm:text-2xl font-black tracking-wider text-amber-950 select-all break-all">
                        <?= htmlspecialchars($receipt['token_highlight']['value']) ?>
                    </div>
                    <p class="mt-1.5 text-[11px] text-amber-700">
                        Keep this PIN / token secure. You can copy it directly to recharge your meter or verify your account.
                    </p>
                </div>
                <?php endif; ?>

                <!-- TRANSACTION & SERVICE DETAILS -->
                <div class="space-y-6">
                    <div>
                        <h2 class="text-xs font-bold uppercase tracking-wider text-slate-400 border-b border-slate-100 pb-2">
                            Transaction Summary
                        </h2>
                        <dl class="mt-3 divide-y divide-slate-100 text-xs sm:text-sm">
                            <div class="flex justify-between py-2.5">
                                <dt class="text-slate-500 font-medium">Service Category</dt>
                                <dd class="font-bold text-slate-900 text-right"><?= htmlspecialchars($receipt['service_category']) ?></dd>
                            </div>
                            <div class="flex justify-between py-2.5">
                                <dt class="text-slate-500 font-medium">Product / Item</dt>
                                <dd class="font-bold text-slate-900 text-right"><?= htmlspecialchars($receipt['product_name']) ?></dd>
                            </div>
                            <?php if (!empty($receipt['customer_name'])): ?>
                            <div class="flex justify-between py-2.5">
                                <dt class="text-slate-500 font-medium">Customer / Account Name</dt>
                                <dd class="font-bold text-slate-900 text-right"><?= htmlspecialchars($receipt['customer_name']) ?></dd>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($receipt['customer_identifier'])): ?>
                            <div class="flex justify-between py-2.5">
                                <dt class="text-slate-500 font-medium">Recipient / Account Identifier</dt>
                                <dd class="font-mono font-bold text-slate-900 text-right select-all"><?= htmlspecialchars($receipt['customer_identifier']) ?></dd>
                            </div>
                            <?php endif; ?>
                            <div class="flex justify-between py-2.5">
                                <dt class="text-slate-500 font-medium">Transaction Status</dt>
                                <dd class="font-bold text-emerald-700 text-right flex items-center justify-end gap-1">
                                    <svg class="h-4 w-4 text-emerald-600 inline" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                                    Successful / Completed
                                </dd>
                            </div>
                            <div class="flex justify-between py-2.5">
                                <dt class="text-slate-500 font-medium">Date & Time</dt>
                                <dd class="text-slate-700 font-mono text-right"><?= htmlspecialchars($receipt['formatted_date']) ?></dd>
                            </div>
                        </dl>
                    </div>

                    <!-- SERVICE-SPECIFIC METADATA DETAILS -->
                    <?php if (!empty($receipt['details'])): ?>
                    <div>
                        <h2 class="text-xs font-bold uppercase tracking-wider text-slate-400 border-b border-slate-100 pb-2">
                            Service Specifications
                        </h2>
                        <dl class="mt-3 divide-y divide-slate-100 text-xs sm:text-sm">
                            <?php foreach ($receipt['details'] as $key => $val): ?>
                            <div class="flex justify-between py-2.5">
                                <dt class="text-slate-500 font-medium"><?= htmlspecialchars($key) ?></dt>
                                <dd class="font-semibold text-slate-900 text-right select-all"><?= htmlspecialchars((string)$val) ?></dd>
                            </div>
                            <?php endforeach; ?>
                        </dl>
                    </div>
                    <?php endif; ?>

                    <!-- CLIENT ACCOUNT INFORMATION -->
                    <div>
                        <h2 class="text-xs font-bold uppercase tracking-wider text-slate-400 border-b border-slate-100 pb-2">
                            Billed Account
                        </h2>
                        <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs text-slate-600 bg-slate-50 p-4 rounded-xl border border-slate-100">
                            <div>
                                <span class="block text-[10px] font-bold uppercase text-slate-400">Account Holder</span>
                                <span class="font-bold text-slate-900"><?= htmlspecialchars($receipt['client']['name']) ?></span>
                            </div>
                            <div>
                                <span class="block text-[10px] font-bold uppercase text-slate-400">Email Address</span>
                                <span class="font-mono"><?= htmlspecialchars($receipt['client']['email'] ?: '—') ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- OFFICIAL FOOTER & SECURITY AUTHENTICATION -->
                <div class="mt-10 border-t border-slate-100 pt-6 text-center text-xs text-slate-400 space-y-2">
                    <div class="flex items-center justify-center gap-2 text-emerald-700 font-bold text-xs">
                        <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M6.267 3.455a3.066 3.066 0 001.745-.723 3.066 3.066 0 013.976 0 3.066 3.066 0 001.745.723 3.066 3.066 0 012.812 2.812c.051.643.304 1.254.723 1.745a3.066 3.066 0 010 3.976 3.066 3.066 0 00-.723 1.745 3.066 3.066 0 01-2.812 2.812 3.066 3.066 0 00-1.745.723 3.066 3.066 0 01-3.976 0 3.066 3.066 0 00-1.745-.723 3.066 3.066 0 01-2.812-2.812 3.066 3.066 0 00-.723-1.745 3.066 3.066 0 010-3.976 3.066 3.066 0 00.723-1.745 3.066 3.066 0 012.812-2.812zm7.44 5.252a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                        </svg>
                        <span>Authentic Subnext Verified Electronic Receipt</span>
                    </div>
                    <p class="text-[11px] leading-relaxed">
                        This is an official computer-generated receipt issued by Subnext. No physical signature is required.<br>
                        For transaction enquiries, contact <strong class="text-slate-600">support@subnext.com.ng</strong> quoting reference <span class="font-mono font-bold text-slate-700"><?= htmlspecialchars($receipt['reference']) ?></span>.
                    </p>
                    <p class="text-[10px] text-slate-400 font-mono">
                        Generated on <?= date('d M Y, h:i:s A') ?> • Subnext Platform v2.0
                    </p>
                </div>

            </div>
        </article>

    <?php endif; ?>

</main>

</body>
</html>
