<?php
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/client_auth.php";
require_once __DIR__ . "/../includes/UtilityOrderProcessor.php";

$userId = currentUserId();

// Get wallet balance
$stmt = $pdo->prepare("SELECT balance FROM wallets WHERE user_id = ? LIMIT 1");
$stmt->execute([$userId]);
$wallet = $stmt->fetch(PDO::FETCH_ASSOC);
$balance = $wallet ? (float)$wallet["balance"] : 0.00;

$fullName = trim((string)($_SESSION["full_name"] ?? "Client"));
$profileInitial = strtoupper(substr($fullName, 0, 1));

// CSRF & Idempotency tokens
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION["csrf_token"];
$requestToken = bin2hex(random_bytes(32));

$successMessage = null;
$errorMessage = null;
$orderDetails = null;

// Fetch active exam pin products
$stmt = $pdo->query("
    SELECT product_code, name, category, selling_price
    FROM service_products
    WHERE service_slug = 'exam_pins' AND status = 'active'
    ORDER BY id ASC
");
$examProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $submittedCsrf = trim((string)($_POST["csrf_token"] ?? ""));
    if (!hash_equals($csrfToken, $submittedCsrf)) {
        $errorMessage = "Invalid security token. Please refresh the page.";
    } else {
        $examCode = trim((string)($_POST["exam_code"] ?? ""));
        $quantity = max(1, min(10, (int)($_POST["quantity"] ?? 1)));
        $phone = preg_replace('/\D+/', '', trim((string)($_POST["phone"] ?? "")));
        $reqToken = trim((string)($_POST["request_token"] ?? ""));

        if (empty($examCode)) {
            $errorMessage = "Please select an examination product.";
        } elseif (empty($phone)) {
            $errorMessage = "Please enter your contact phone number.";
        } else {
            $processor = new UtilityOrderProcessor($pdo);
            $result = $processor->processOrder([
                'user_id' => $userId,
                'service_slug' => 'exam_pins',
                'product_code' => $examCode,
                'customer_identifier' => $phone,
                'request_token' => $reqToken,
                'extra_payload' => [
                    'quantity' => $quantity
                ]
            ]);

            if ($result['ok']) {
                $successMessage = $result['message'];
                $orderDetails = $result;
                $stmtWallet = $pdo->prepare("SELECT balance FROM wallets WHERE user_id = ? LIMIT 1");
                $stmtWallet->execute([$userId]);
                $wallet = $stmtWallet->fetch(PDO::FETCH_ASSOC);
                $balance = $wallet ? (float)$wallet["balance"] : 0.00;
            } else {
                $errorMessage = $result['message'] ?? 'Unable to purchase exam PINs.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam PINs - Subnext</title>
    <!-- Precompiled Production Stylesheet -->
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="bg-slate-50 text-slate-900 min-h-screen">

<!-- Desktop Navigation Header -->
<header class="hidden md:block sticky top-0 z-40 border-b border-slate-200/80 bg-white/95 backdrop-blur">
    <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6">
        <a href="dashboard.php" class="flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-servora-700 text-lg font-black text-white shadow-sm">S</div>
            <div>
                <div class="text-lg font-bold tracking-tight text-slate-900 leading-tight">Subnext</div>
                <div class="text-[10px] font-medium text-slate-400 uppercase tracking-wider">Exam PINs</div>
            </div>
        </a>

        <!-- Desktop Navigation Links -->
        <nav class="flex items-center gap-1">
            <a href="dashboard.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">Dashboard</a>
            <a href="orders.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">My Orders</a>
            <a href="wallet.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">Wallet</a>
            <a href="profile.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">Profile</a>
        </nav>

        <div class="flex items-center gap-2.5">
            <a href="notifications.php" class="relative flex h-10 w-10 items-center justify-center rounded-xl text-slate-600 transition hover:bg-slate-100" aria-label="Notifications">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 1-5.714 0M18 8A6 6 0 0 0 6 8c0 7-3 7-3 9h18c0-2-3-2-3-9M13.73 21a2 2 0 0 1-3.46 0"/>
                </svg>
            </a>
            <a href="profile.php" class="flex h-10 w-10 items-center justify-center rounded-xl bg-servora-100 font-bold text-servora-700 text-sm" title="My Profile">
                <?= htmlspecialchars($profileInitial, ENT_QUOTES, "UTF-8") ?>
            </a>
            <a href="../logout.php" class="inline-flex items-center gap-1.5 rounded-xl border border-red-200 bg-red-50/80 px-3.5 py-2 text-xs font-bold text-red-600 transition hover:bg-red-100 hover:border-red-300" title="Logout">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                </svg>
                <span>Logout</span>
            </a>
        </div>
    </div>
</header>

<main class="mx-auto w-full max-w-5xl px-4 py-6 pb-28 md:pb-8 sm:px-6">

    <!-- HERO BANNER CARD -->
    <section class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-servora-800 via-servora-700 to-servora-500 p-6 sm:p-8 text-white shadow-xl">
        <a href="dashboard.php" class="text-sm font-semibold text-white/70 hover:text-white">
            ← Dashboard
        </a>

        <div class="mt-6">
            <p class="text-sm font-semibold text-white/70">
                Subnext Education
            </p>
            <h1 class="mt-1 text-3xl font-black">
                Examination PINs
            </h1>
            <p class="mt-2 max-w-xl text-sm text-white/70">
                Instant WAEC, NECO, JAMB, and NABTEB result checker PINs and tokens.
            </p>
        </div>
    </section>

    <!-- STANDARDIZED WALLET BALANCE CARD -->
    <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex items-center justify-between gap-4">
            <div>
                <p class="text-xs font-bold uppercase tracking-wide text-slate-400">
                    Wallet Balance
                </p>
                <p class="mt-1 text-2xl font-black text-slate-900">
                    ₦<?= number_format($balance, 2) ?>
                </p>
            </div>
            <a href="fund_wallet.php" class="rounded-xl bg-servora-50 px-4 py-2.5 text-sm font-bold text-servora-700 hover:bg-servora-100 transition">
                Fund Wallet
            </a>
        </div>
    </section>

    <?php if ($successMessage): ?>
    <div class="mt-6 rounded-2xl bg-emerald-50 border border-emerald-200 p-6 text-emerald-900 shadow-sm">
        <div class="flex items-center gap-3 mb-3">
            <svg class="h-7 w-7 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            <div>
                <h3 class="font-bold text-lg">PIN(s) Generated Successfully!</h3>
                <p class="text-xs text-emerald-700"><?= htmlspecialchars($successMessage) ?></p>
            </div>
        </div>

        <?php if (!empty($orderDetails['token_or_pin'])): ?>
        <div class="my-4 rounded-2xl bg-white p-5 border-2 border-emerald-300 shadow-inner">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Generated Exam PIN & Serial:</span>
                <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('pinsBlock').innerText); alert('PINs copied!');"
                    class="rounded-xl bg-emerald-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-emerald-700">Copy All</button>
            </div>
            <pre class="font-mono text-sm font-bold text-slate-900 whitespace-pre-wrap bg-slate-50 p-3 rounded-xl border border-slate-200" id="pinsBlock"><?= htmlspecialchars($orderDetails['token_or_pin']) ?></pre>
        </div>
        <?php endif; ?>

        <?php if ($orderDetails): ?>
        <div class="rounded-xl bg-white/80 p-3 text-xs space-y-1 text-slate-700 border border-emerald-100">
            <div><span class="text-slate-400">Reference:</span> <span class="font-mono font-bold"><?= htmlspecialchars($orderDetails['reference']) ?></span></div>
            <div><span class="text-slate-400">Product:</span> <span class="font-bold"><?= htmlspecialchars($orderDetails['product_name']) ?></span></div>
            <div><span class="text-slate-400">Total Charged:</span> <span class="font-bold text-slate-900">₦<?= number_format($orderDetails['amount'], 2) ?></span></div>
        </div>
        <?php endif; ?>
        <div class="mt-4 flex flex-wrap gap-3">
            <?php if (!empty($orderDetails['reference'])): ?>
            <a href="receipt.php?ref=<?= urlencode((string)$orderDetails['reference']) ?>" class="inline-flex items-center gap-1.5 rounded-xl bg-servora-700 px-4 py-2 text-xs font-bold text-white hover:bg-servora-800 transition">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                View &amp; Print Receipt
            </a>
            <?php endif; ?>
            <a href="exam_pins.php" class="rounded-xl bg-emerald-600 px-4 py-2 text-xs font-bold text-white hover:bg-emerald-700">Buy More PINs</a>
            <a href="dashboard.php" class="rounded-xl bg-white px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 border border-slate-200">Return to Home</a>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($errorMessage): ?>
    <div class="mt-6 rounded-2xl bg-rose-50 border border-rose-200 p-4 text-rose-800 text-sm shadow-sm">
        <div class="flex items-center gap-2 font-bold mb-1">
            <svg class="h-5 w-5 text-rose-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            Purchase Failed
        </div>
        <p><?= htmlspecialchars($errorMessage) ?></p>
        <?php if (str_contains(strtolower($errorMessage), 'insufficient')): ?>
        <a href="fund_wallet.php" class="inline-block mt-3 rounded-lg bg-servora-700 px-3 py-1.5 text-xs font-bold text-white">Fund Wallet Now</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <section class="mt-6 rounded-3xl border border-slate-200 bg-white p-5 sm:p-7 shadow-sm">
        <div>
            <p class="text-xs font-bold uppercase tracking-widest text-servora-600">Education Portal</p>
            <h2 class="mt-1 text-xl font-black">Purchase Result PINs</h2>
            <p class="mt-1 text-sm text-slate-500">Select exam body and quantity to generate instant tokens.</p>
        </div>

        <form method="POST" id="examForm" class="space-y-5">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="request_token" value="<?= htmlspecialchars($requestToken) ?>">

            <!-- Exam Product Selection -->
            <div>
                <label for="exam_code" class="block text-sm font-semibold text-slate-700 mb-1">1. Select Examination Product</label>
                <select id="exam_code" name="exam_code" required onchange="calculateTotal()"
                    class="w-full rounded-xl border border-slate-300 px-4 py-3 text-sm font-medium text-slate-900 focus:border-servora-600 focus:outline-none focus:ring-2 focus:ring-servora-200">
                    <option value="">-- Choose Exam Product --</option>
                    <?php foreach ($examProducts as $p): ?>
                    <option value="<?= htmlspecialchars($p['product_code']) ?>" data-price="<?= (float)$p['selling_price'] ?>">
                        <?= htmlspecialchars($p['name']) ?> — ₦<?= number_format((float)$p['selling_price'], 2) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Quantity Selection -->
            <div>
                <label for="quantity" class="block text-sm font-semibold text-slate-700 mb-1">2. Quantity</label>
                <select id="quantity" name="quantity" required onchange="calculateTotal()"
                    class="w-full rounded-xl border border-slate-300 px-4 py-3 text-sm font-medium text-slate-900 focus:border-servora-600 focus:outline-none focus:ring-2 focus:ring-servora-200">
                    <?php for ($i = 1; $i <= 10; $i++): ?>
                    <option value="<?= $i ?>"><?= $i ?> PIN<?= $i > 1 ? 's' : '' ?></option>
                    <?php endfor; ?>
                </select>
            </div>

            <!-- Contact Phone Number -->
            <div>
                <label for="phone" class="block text-sm font-semibold text-slate-700 mb-1">3. Contact Phone Number</label>
                <input type="tel" id="phone" name="phone" placeholder="e.g. 08012345678" required maxlength="11"
                    class="w-full rounded-xl border border-slate-300 px-4 py-3 text-base font-semibold text-slate-900 placeholder-slate-400 focus:border-servora-600 focus:outline-none focus:ring-2 focus:ring-servora-200">
            </div>

            <!-- Total Price Display Box -->
            <div class="rounded-2xl bg-servora-50 p-4 border border-servora-100 flex items-center justify-between">
                <div>
                    <span class="text-xs text-servora-600 font-semibold uppercase tracking-wider">Total Amount:</span>
                    <div class="text-2xl font-black text-servora-900" id="totalPrice">₦0.00</div>
                </div>
                <div class="text-right">
                    <span class="text-xs text-slate-500">Wallet Balance:</span>
                    <div class="text-sm font-bold text-slate-800">₦<?= number_format($balance, 2) ?></div>
                </div>
            </div>

            <!-- Submit Button -->
            <button type="submit" id="submitBtn" class="w-full rounded-2xl bg-servora-700 py-4 text-base font-bold text-white shadow-md transition hover:bg-servora-800 focus:outline-none focus:ring-4 focus:ring-servora-200">
                Generate PIN(s)
            </button>
        </form>
    </section>
</main>

<script>
function calculateTotal() {
    var select = document.getElementById('exam_code');
    var selectedOption = select.options[select.selectedIndex];
    var unitPrice = parseFloat(selectedOption.getAttribute('data-price') || 0);
    var qty = parseInt(document.getElementById('quantity').value || 1);
    var total = unitPrice * qty;

    document.getElementById('totalPrice').innerText = '₦' + total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

document.getElementById('examForm').addEventListener('submit', function() {
    var btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerText = 'Generating Result PINs...';
});
</script>

<?php require_once __DIR__ . "/../includes/client_bottom_nav.php"; ?>

</body>
</html>
