<?php
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/admin_auth.php";

$adminName = $_SESSION["full_name"] ?? "Admin";

// CSRF token
if (empty($_SESSION["admin_csrf"])) {
    $_SESSION["admin_csrf"] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION["admin_csrf"];

$message = null;
$messageType = 'success';

// Handle POST actions
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $submittedCsrf = trim((string)($_POST["csrf_token"] ?? ""));
    if (!hash_equals($csrfToken, $submittedCsrf)) {
        $message = "Invalid security token.";
        $messageType = "error";
    } else {
        $action = trim((string)($_POST["action"] ?? ""));

        // Toggle service status
        if ($action === 'toggle_service') {
            $serviceId = (int)($_POST["service_id"] ?? 0);
            $newStatus = trim((string)($_POST["status"] ?? "active")) === 'active' ? 'active' : 'inactive';

            $stmt = $pdo->prepare("UPDATE services SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$newStatus, $serviceId]);
            $message = "Service status updated successfully.";
        }

        // Toggle product status
        elseif ($action === 'toggle_product') {
            $productId = (int)($_POST["product_id"] ?? 0);
            $newStatus = trim((string)($_POST["status"] ?? "active")) === 'active' ? 'active' : 'inactive';

            $stmt = $pdo->prepare("UPDATE service_products SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$newStatus, $productId]);
            $message = "Product status updated successfully.";
        }

        // Update product pricing & markup
        elseif ($action === 'update_pricing') {
            $productId = (int)($_POST["product_id"] ?? 0);
            $sellingPrice = (float)($_POST["selling_price"] ?? 0);
            $pricingMode = trim((string)($_POST["pricing_mode"] ?? "fixed"));
            $fixedMarkup = (float)($_POST["fixed_markup"] ?? 0);
            $percentageMarkup = (float)($_POST["percentage_markup"] ?? 0);

            // Fetch provider cost
            $stmt = $pdo->prepare("SELECT provider_cost FROM service_products WHERE id = ?");
            $stmt->execute([$productId]);
            $cost = (float)$stmt->fetchColumn();

            // Calculate selling price if markup mode is chosen
            if ($pricingMode === 'fixed_markup') {
                $sellingPrice = $cost + $fixedMarkup;
            } elseif ($pricingMode === 'percentage_markup') {
                $sellingPrice = round($cost * (1 + ($percentageMarkup / 100)), 2);
            }

            $stmt = $pdo->prepare("
                UPDATE service_products
                SET selling_price = ?,
                    pricing_mode = ?,
                    fixed_markup = ?,
                    percentage_markup = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$sellingPrice, $pricingMode, $fixedMarkup, $percentageMarkup, $productId]);
            $message = "Pricing updated successfully.";
        }

        // Reset to provider default
        elseif ($action === 'reset_price') {
            $productId = (int)($_POST["product_id"] ?? 0);
            $stmt = $pdo->prepare("
                UPDATE service_products
                SET selling_price = provider_cost,
                    pricing_mode = 'fixed',
                    fixed_markup = 0,
                    percentage_markup = 0,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$productId]);
            $message = "Selling price restored to supplier default.";
        }
    }
}

// Fetch all services
$stmt = $pdo->query("SELECT * FROM services ORDER BY sort_order ASC");
$servicesList = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch products with filter
$selectedService = trim((string)($_GET['service'] ?? 'all'));
$params = [];
$prodSql = "SELECT * FROM service_products";
if ($selectedService !== 'all' && $selectedService !== '') {
    $prodSql .= " WHERE service_slug = ?";
    $params[] = $selectedService;
}
$prodSql .= " ORDER BY service_slug ASC, category ASC, selling_price ASC";
$stmt = $pdo->prepare($prodSql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service & Pricing Management - Servora Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        servora: {
                            50: '#F5F3FF', 100: '#EDE9FE', 200: '#DDD6FE',
                            500: '#635BDB', 600: '#5146C7', 700: '#3E37B7',
                            800: '#312E81', 900: '#1E1B4B'
                        }
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-slate-50 text-slate-900 min-h-screen">

<!-- Admin Navigation Bar -->
<header class="sticky top-0 z-40 border-b border-slate-200 bg-white/95 backdrop-blur">
    <div class="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
        <div class="flex items-center gap-4">
            <a href="dashboard.php" class="flex items-center gap-2">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-servora-700 text-lg font-black text-white">S</div>
                <div>
                    <span class="font-bold text-slate-900 block leading-tight">Servora</span>
                    <span class="text-[10px] uppercase font-bold text-servora-700">Admin Panel</span>
                </div>
            </a>
            <nav class="hidden md:flex items-center gap-1 ml-6 text-xs font-semibold text-slate-600">
                <a href="dashboard.php" class="px-3 py-2 rounded-lg hover:bg-slate-100">Dashboard</a>
                <a href="service_pricing.php" class="px-3 py-2 rounded-lg bg-servora-50 text-servora-700">Services & Pricing</a>
                <a href="utility_orders.php" class="px-3 py-2 rounded-lg hover:bg-slate-100">Utility Orders</a>
                <a href="data_orders.php" class="px-3 py-2 rounded-lg hover:bg-slate-100">Data Orders</a>
                <a href="foreign_number_orders.php" class="px-3 py-2 rounded-lg hover:bg-slate-100">Foreign Numbers</a>
                <a href="clients.php" class="px-3 py-2 rounded-lg hover:bg-slate-100">Clients</a>
            </nav>
        </div>
        <div class="flex items-center gap-3">
            <a href="../logout.php" class="rounded-xl border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-bold text-red-600 hover:bg-red-100">Logout</a>
        </div>
    </div>
</header>

<main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-slate-900">Service Catalog & Price Controls</h1>
            <p class="text-sm text-slate-500">Manage availability, supplier costs, markups, and customer selling prices across all services.</p>
        </div>
        <a href="dashboard.php" class="inline-flex items-center gap-2 text-xs font-bold text-slate-600 hover:text-servora-700 bg-white border border-slate-200 px-3 py-2 rounded-xl shadow-sm">
            ← Back to Admin Dashboard
        </a>
    </div>

    <?php if ($message): ?>
    <div class="mb-6 rounded-2xl <?= $messageType === 'success' ? 'bg-emerald-50 text-emerald-900 border-emerald-200' : 'bg-rose-50 text-rose-900 border-rose-200' ?> border p-4 text-sm font-semibold">
        <?= htmlspecialchars($message) ?>
    </div>
    <?php endif; ?>

    <!-- 1. SERVICE AVAILABILITY CONTROLS -->
    <section class="mb-10">
        <h2 class="text-base font-bold text-slate-900 mb-3">Service Availability Toggles</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <?php foreach ($servicesList as $svc):
                $isActive = ($svc['status'] === 'active');
            ?>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm flex items-center justify-between">
                <div>
                    <h3 class="font-bold text-sm text-slate-900"><?= htmlspecialchars($svc['name']) ?></h3>
                    <span class="inline-block mt-1 text-[10px] uppercase font-bold rounded-full px-2 py-0.5 <?= $isActive ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' ?>">
                        <?= htmlspecialchars($svc['status']) ?>
                    </span>
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="toggle_service">
                    <input type="hidden" name="service_id" value="<?= $svc['id'] ?>">
                    <input type="hidden" name="status" value="<?= $isActive ? 'inactive' : 'active' ?>">
                    <button type="submit" class="rounded-xl px-3 py-1.5 text-xs font-bold transition <?= $isActive ? 'bg-rose-50 text-rose-600 hover:bg-rose-100 border border-rose-200' : 'bg-emerald-600 text-white hover:bg-emerald-700' ?>">
                        <?= $isActive ? 'Disable' : 'Enable' ?>
                    </button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- 2. PRODUCT PRICING & MARKUP TABLE -->
    <section>
        <div class="mb-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <h2 class="text-base font-bold text-slate-900">Products & Markup Configuration</h2>
            <!-- Filter by service -->
            <div class="flex flex-wrap gap-1.5">
                <a href="service_pricing.php?service=all" class="px-3 py-1.5 text-xs font-bold rounded-xl <?= $selectedService === 'all' ? 'bg-servora-700 text-white' : 'bg-white text-slate-600 border border-slate-200' ?>">All</a>
                <a href="service_pricing.php?service=exam_pins" class="px-3 py-1.5 text-xs font-bold rounded-xl <?= $selectedService === 'exam_pins' ? 'bg-servora-700 text-white' : 'bg-white text-slate-600 border border-slate-200' ?>">Exam PINs</a>
                <a href="service_pricing.php?service=electricity" class="px-3 py-1.5 text-xs font-bold rounded-xl <?= $selectedService === 'electricity' ? 'bg-servora-700 text-white' : 'bg-white text-slate-600 border border-slate-200' ?>">Electricity</a>
                <a href="service_pricing.php?service=cable_tv" class="px-3 py-1.5 text-xs font-bold rounded-xl <?= $selectedService === 'cable_tv' ? 'bg-servora-700 text-white' : 'bg-white text-slate-600 border border-slate-200' ?>">Cable TV</a>
                <a href="service_pricing.php?service=airtime" class="px-3 py-1.5 text-xs font-bold rounded-xl <?= $selectedService === 'airtime' ? 'bg-servora-700 text-white' : 'bg-white text-slate-600 border border-slate-200' ?>">Airtime</a>
                <a href="service_pricing.php?service=bulk_sms" class="px-3 py-1.5 text-xs font-bold rounded-xl <?= $selectedService === 'bulk_sms' ? 'bg-servora-700 text-white' : 'bg-white text-slate-600 border border-slate-200' ?>">Bulk SMS</a>
            </div>
        </div>

        <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">

            <!-- MOBILE CARDS (Phones < 768px) -->
            <div class="block md:hidden divide-y divide-slate-100">
                <?php foreach ($products as $p):
                    $cost = (float)$p['provider_cost'];
                    $selling = (float)$p['selling_price'];
                    $margin = max(0.0, $selling - $cost);
                    $isProdActive = ($p['status'] === 'active');
                ?>
                <div class="p-4 space-y-3">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400"><?= htmlspecialchars($p['service_slug']) ?> • <?= htmlspecialchars($p['category'] ?? 'General') ?></span>
                            <h4 class="text-sm font-bold text-slate-900"><?= htmlspecialchars($p['name']) ?></h4>
                        </div>
                        <form method="POST" class="inline">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="toggle_product">
                            <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                            <input type="hidden" name="status" value="<?= $isProdActive ? 'inactive' : 'active' ?>">
                            <button type="submit" class="rounded-full px-2.5 py-0.5 text-[10px] font-bold transition <?= $isProdActive ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' ?>">
                                <?= $isProdActive ? 'Active' : 'Disabled' ?>
                            </button>
                        </form>
                    </div>

                    <div class="grid grid-cols-3 gap-2 rounded-xl bg-slate-50 p-2.5 text-center text-xs">
                        <div>
                            <span class="text-[10px] text-slate-400 block">Cost</span>
                            <span class="font-mono font-bold text-slate-700">₦<?= number_format($cost, 2) ?></span>
                        </div>
                        <div>
                            <span class="text-[10px] text-slate-400 block">Selling</span>
                            <span class="font-mono font-bold text-slate-900">₦<?= number_format($selling, 2) ?></span>
                        </div>
                        <div>
                            <span class="text-[10px] text-slate-400 block">Margin</span>
                            <span class="font-mono font-bold text-emerald-600">+₦<?= number_format($margin, 2) ?></span>
                        </div>
                    </div>

                    <div class="flex items-center justify-between pt-1">
                        <span class="text-[10px] text-slate-400 capitalize">Mode: <?= htmlspecialchars($p['pricing_mode']) ?></span>
                        <button type="button" onclick="openPriceModal(<?= htmlspecialchars(json_encode($p)) ?>)"
                            class="rounded-xl bg-servora-700 px-3 py-1.5 text-xs font-bold text-white shadow-xs hover:bg-servora-800 transition">
                            Edit Pricing
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- DESKTOP TABLE VIEW (Screens >= 768px) -->
            <div class="hidden md:block overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-600">
                    <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wider text-slate-500 border-b border-slate-200">
                        <tr>
                            <th class="px-6 py-4">Product / Service</th>
                            <th class="px-6 py-4">Supplier Cost</th>
                            <th class="px-6 py-4">Current Selling Price</th>
                            <th class="px-6 py-4">Estimated Margin</th>
                            <th class="px-6 py-4">Status</th>
                            <th class="px-6 py-4 text-right">Quick Price Edit</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($products as $p):
                            $cost = (float)$p['provider_cost'];
                            $selling = (float)$p['selling_price'];
                            $margin = max(0.0, $selling - $cost);
                            $isProdActive = ($p['status'] === 'active');
                        ?>
                        <tr class="hover:bg-slate-50/80 transition">
                            <td class="px-6 py-4">
                                <span class="font-bold text-slate-900 block"><?= htmlspecialchars($p['name']) ?></span>
                                <span class="text-xs uppercase text-slate-400"><?= htmlspecialchars($p['service_slug']) ?> • <?= htmlspecialchars($p['category'] ?? 'General') ?></span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap font-mono text-slate-500 font-semibold">
                                ₦<?= number_format($cost, 2) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="font-mono font-bold text-base text-slate-900">₦<?= number_format($selling, 2) ?></span>
                                <span class="block text-[10px] text-slate-400 capitalize">Mode: <?= htmlspecialchars($p['pricing_mode']) ?></span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="font-bold text-emerald-600">+₦<?= number_format($margin, 2) ?></span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <form method="POST" class="inline">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="action" value="toggle_product">
                                    <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                                    <input type="hidden" name="status" value="<?= $isProdActive ? 'inactive' : 'active' ?>">
                                    <button type="submit" class="rounded-full px-2.5 py-0.5 text-xs font-bold transition <?= $isProdActive ? 'bg-emerald-100 text-emerald-800 hover:bg-emerald-200' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                                        <?= $isProdActive ? 'Active' : 'Disabled' ?>
                                    </button>
                                </form>
                            </td>
                            <td class="px-6 py-4 text-right whitespace-nowrap">
                                <button type="button" onclick="openPriceModal(<?= htmlspecialchars(json_encode($p)) ?>)"
                                    class="rounded-xl bg-servora-50 px-3 py-1.5 text-xs font-bold text-servora-700 hover:bg-servora-100 border border-servora-200">
                                    Edit Pricing
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</main>

<!-- Price Edit Modal -->
<div id="priceModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4">
    <div class="w-full max-w-md rounded-3xl bg-white p-6 shadow-2xl">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-bold text-lg text-slate-900" id="modalProductName">Edit Pricing</h3>
            <button type="button" onclick="closePriceModal()" class="text-slate-400 hover:text-slate-600 font-bold text-xl">&times;</button>
        </div>

        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="update_pricing">
            <input type="hidden" name="product_id" id="modalProductId" value="">

            <div>
                <span class="text-xs text-slate-400 block mb-1">Supplier / Provider Cost:</span>
                <span class="text-base font-bold font-mono text-slate-700" id="modalProviderCost">₦0.00</span>
            </div>

            <div>
                <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Pricing Mode</label>
                <select name="pricing_mode" id="modalPricingMode" onchange="togglePricingInputs()"
                    class="w-full rounded-xl border border-slate-300 p-2.5 text-sm font-semibold">
                    <option value="fixed">Fixed Selling Price</option>
                    <option value="fixed_markup">Fixed Markup on Cost (+₦)</option>
                    <option value="percentage_markup">Percentage Markup on Cost (+%)</option>
                </select>
            </div>

            <div id="fixedPriceGroup">
                <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Servora Selling Price (₦)</label>
                <input type="number" step="0.01" name="selling_price" id="modalSellingPrice"
                    class="w-full rounded-xl border border-slate-300 p-2.5 font-bold font-mono text-base text-slate-900">
            </div>

            <div id="fixedMarkupGroup" class="hidden">
                <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Fixed Markup Amount (₦)</label>
                <input type="number" step="0.01" name="fixed_markup" id="modalFixedMarkup"
                    class="w-full rounded-xl border border-slate-300 p-2.5 font-bold font-mono text-base text-slate-900">
            </div>

            <div id="pctMarkupGroup" class="hidden">
                <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Percentage Markup (%)</label>
                <input type="number" step="0.1" name="percentage_markup" id="modalPctMarkup"
                    class="w-full rounded-xl border border-slate-300 p-2.5 font-bold font-mono text-base text-slate-900">
            </div>

            <div class="flex gap-2 pt-2">
                <button type="submit" class="flex-1 rounded-xl bg-servora-700 py-3 text-xs font-bold text-white hover:bg-servora-800">
                    Save Changes
                </button>
                <button type="button" onclick="closePriceModal()" class="rounded-xl border border-slate-200 px-4 py-3 text-xs font-bold text-slate-600 hover:bg-slate-50">
                    Cancel
                </button>
            </div>
        </form>

        <form method="POST" class="mt-3 pt-3 border-t border-slate-100">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="reset_price">
            <input type="hidden" name="product_id" id="modalResetProductId" value="">
            <button type="submit" class="w-full rounded-xl bg-slate-100 py-2 text-xs font-bold text-slate-600 hover:bg-slate-200">
                ↺ Restore Supplier Default Cost
            </button>
        </form>
    </div>
</div>

<script>
function openPriceModal(prod) {
    document.getElementById('modalProductId').value = prod.id;
    document.getElementById('modalResetProductId').value = prod.id;
    document.getElementById('modalProductName').innerText = prod.name;
    document.getElementById('modalProviderCost').innerText = '₦' + parseFloat(prod.provider_cost).toFixed(2);
    document.getElementById('modalSellingPrice').value = parseFloat(prod.selling_price).toFixed(2);
    document.getElementById('modalPricingMode').value = prod.pricing_mode || 'fixed';
    document.getElementById('modalFixedMarkup').value = parseFloat(prod.fixed_markup || 0).toFixed(2);
    document.getElementById('modalPctMarkup').value = parseFloat(prod.percentage_markup || 0).toFixed(2);

    togglePricingInputs();
    document.getElementById('priceModal').classList.remove('hidden');
}

function closePriceModal() {
    document.getElementById('priceModal').classList.add('hidden');
}

function togglePricingInputs() {
    var mode = document.getElementById('modalPricingMode').value;
    document.getElementById('fixedPriceGroup').classList.add('hidden');
    document.getElementById('fixedMarkupGroup').classList.add('hidden');
    document.getElementById('pctMarkupGroup').classList.add('hidden');

    if (mode === 'fixed') {
        document.getElementById('fixedPriceGroup').classList.remove('hidden');
    } else if (mode === 'fixed_markup') {
        document.getElementById('fixedMarkupGroup').classList.remove('hidden');
    } else if (mode === 'percentage_markup') {
        document.getElementById('pctMarkupGroup').classList.remove('hidden');
    }
}
</script>

</body>
</html>
