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

// Handle submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $submittedCsrf = trim((string)($_POST["csrf_token"] ?? ""));
    if (!hash_equals($csrfToken, $submittedCsrf)) {
        $errorMessage = "Invalid security token. Please refresh the page.";
    } else {
        $phone = preg_replace('/\D+/', '', trim((string)($_POST["phone"] ?? "")));
        $network = strtolower(trim((string)($_POST["network"] ?? "")));
        $amount = (float)($_POST["amount"] ?? 0);
        $reqToken = trim((string)($_POST["request_token"] ?? ""));

        // Auto-detect network from phone number prefix if not selected or for verification
        require_once __DIR__ . "/../includes/UtilityProvider.php";
        $detectedNetwork = UtilityProvider::detectNetwork($phone);
        if (empty($network) && $detectedNetwork) {
            $network = $detectedNetwork;
        }

        $validNetworks = ['mtn', 'airtel', 'glo', '9mobile'];
        if (!in_array($network, $validNetworks)) {
            $errorMessage = "Please enter a valid Nigerian phone number or select a telecom network.";
        } elseif (strlen($phone) < 10 || strlen($phone) > 14) {
            $errorMessage = "Please enter a valid 11-digit phone number.";
        } elseif ($amount < 100) {
            $errorMessage = "Minimum airtime purchase is ₦100.";
        } elseif ($amount > 50000) {
            $errorMessage = "Maximum single airtime purchase is ₦50,000.";
        } else {
            $processor = new UtilityOrderProcessor($pdo);
            $productCode = $network . '_vtu';
            $result = $processor->processOrder([
                'user_id' => $userId,
                'service_slug' => 'airtime',
                'product_code' => $productCode,
                'customer_identifier' => $phone,
                'amount' => $amount,
                'request_token' => $reqToken,
                'extra_payload' => ['network' => $network]
            ]);

            if ($result['ok']) {
                $successMessage = $result['message'];
                $orderDetails = $result;
                // Refresh balance
                $stmtWallet = $pdo->prepare("SELECT balance FROM wallets WHERE user_id = ? LIMIT 1");
                $stmtWallet->execute([$userId]);
                $wallet = $stmtWallet->fetch(PDO::FETCH_ASSOC);
                $balance = $wallet ? (float)$wallet["balance"] : 0.00;
            } else {
                $errorMessage = $result['message'] ?? 'Unable to complete airtime recharge.';
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
    <title>Airtime Top-up - Servora</title>
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
    <style>
        body { font-family: Inter, ui-sans-serif, system-ui, -apple-system, sans-serif; }
    </style>
</head>
<body class="bg-slate-50 text-slate-900 min-h-screen">

<!-- Desktop Navigation Header -->
<header class="hidden md:block sticky top-0 z-40 border-b border-slate-200/80 bg-white/95 backdrop-blur">
    <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6">
        <a href="dashboard.php" class="flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-servora-700 text-lg font-black text-white shadow-sm">S</div>
            <div>
                <div class="text-lg font-bold tracking-tight text-slate-900 leading-tight">Servora</div>
                <div class="text-[10px] font-medium text-slate-400 uppercase tracking-wider">Airtime VTU</div>
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
                Servora Airtime
            </p>
            <h1 class="mt-1 text-3xl font-black">
                Buy Airtime
            </h1>
            <p class="mt-2 max-w-xl text-sm text-white/70">
                Instantly recharge airtime for MTN, Airtel, Glo, and 9mobile.
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
    <div class="mt-6 rounded-2xl bg-emerald-50 border border-emerald-200 p-5 text-emerald-900 shadow-sm">
        <div class="flex items-center gap-3 mb-2">
            <svg class="h-6 w-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            <h3 class="font-bold text-base">Recharge Successful!</h3>
        </div>
        <p class="text-sm leading-relaxed"><?= htmlspecialchars($successMessage) ?></p>
        <?php if ($orderDetails): ?>
        <div class="mt-4 rounded-xl bg-white/80 p-3 text-xs space-y-1 text-slate-700 border border-emerald-100">
            <div><span class="text-slate-400">Reference:</span> <span class="font-mono font-bold"><?= htmlspecialchars($orderDetails['reference']) ?></span></div>
            <div><span class="text-slate-400">Amount Charged:</span> <span class="font-bold text-slate-900">₦<?= number_format($orderDetails['amount'], 2) ?></span></div>
            <div><span class="text-slate-400">Recipient Phone:</span> <span class="font-bold"><?= htmlspecialchars($orderDetails['customer_identifier']) ?></span></div>
        </div>
        <?php endif; ?>
        <div class="mt-4 flex flex-wrap gap-3">
            <?php if (!empty($orderDetails['reference'])): ?>
            <a href="receipt.php?ref=<?= urlencode((string)$orderDetails['reference']) ?>" class="inline-flex items-center gap-1.5 rounded-xl bg-servora-700 px-4 py-2 text-xs font-bold text-white hover:bg-servora-800 transition">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                View &amp; Print Receipt
            </a>
            <?php endif; ?>
            <a href="airtime.php" class="rounded-xl bg-emerald-600 px-4 py-2 text-xs font-bold text-white hover:bg-emerald-700">Buy More Airtime</a>
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
            <p class="text-xs font-bold uppercase tracking-widest text-servora-600">Recharge Order</p>
            <h2 class="mt-1 text-xl font-black">Choose Network & Amount</h2>
            <p class="mt-1 text-sm text-slate-500">Select your mobile network and enter phone number.</p>
        </div>

        <form method="POST" id="airtimeForm" class="space-y-6">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="request_token" value="<?= htmlspecialchars($requestToken) ?>">

            <!-- 1. Phone Number (With Instant Network Detection) -->
            <div>
                <div class="flex items-center justify-between mb-1">
                    <label for="phone" class="block text-sm font-semibold text-slate-700">1. Recipient Phone Number</label>
                    <span id="networkPill" class="hidden inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-bold transition-all shadow-sm"></span>
                </div>
                <div class="relative">
                    <input type="tel" id="phone" name="phone" placeholder="e.g. 09077879254" maxlength="11" required autocomplete="tel"
                        class="w-full rounded-xl border border-slate-300 px-4 py-3 text-base font-semibold text-slate-900 placeholder-slate-400 focus:border-servora-600 focus:outline-none focus:ring-2 focus:ring-servora-200 transition">
                    <div id="phoneCarrierIcon" class="absolute right-3.5 top-1/2 -translate-y-1/2 hidden"></div>
                </div>
                <div id="detectionNotice" class="mt-1.5 flex items-center justify-between text-xs text-slate-500">
                    <span>Enter an 11-digit mobile number. Network is auto-identified instantly.</span>
                    <span id="portedHint" class="hidden text-amber-600 font-medium">Ported number override active</span>
                </div>
            </div>

            <!-- 2. Telecom Network Selection (Auto-checked via detection) -->
            <div>
                <div class="flex items-center justify-between mb-2">
                    <label class="block text-sm font-semibold text-slate-700">2. Telecom Network</label>
                    <span class="text-[11px] text-slate-400">Auto-selected • Click to change if ported</span>
                </div>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4" id="networkCardsContainer">
                    <label class="cursor-pointer network-card" data-network="mtn">
                        <input type="radio" name="network" value="mtn" class="peer sr-only" required>
                        <div class="rounded-2xl border-2 border-slate-200 p-3 text-center transition peer-checked:border-yellow-500 peer-checked:bg-yellow-50 hover:bg-slate-50">
                            <div class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-yellow-400 font-black text-slate-900 text-xs shadow-sm mb-1.5">MTN</div>
                            <span class="text-xs font-bold text-slate-800">MTN</span>
                            <div class="text-[10px] text-slate-400 font-medium mt-0.5">0803, 0806...</div>
                        </div>
                    </label>

                    <label class="cursor-pointer network-card" data-network="airtel">
                        <input type="radio" name="network" value="airtel" class="peer sr-only">
                        <div class="rounded-2xl border-2 border-slate-200 p-3 text-center transition peer-checked:border-red-500 peer-checked:bg-red-50 hover:bg-slate-50">
                            <div class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-red-600 font-black text-white text-xs shadow-sm mb-1.5">AIR</div>
                            <span class="text-xs font-bold text-slate-800">Airtel</span>
                            <div class="text-[10px] text-slate-400 font-medium mt-0.5">0907, 0802...</div>
                        </div>
                    </label>

                    <label class="cursor-pointer network-card" data-network="glo">
                        <input type="radio" name="network" value="glo" class="peer sr-only">
                        <div class="rounded-2xl border-2 border-slate-200 p-3 text-center transition peer-checked:border-emerald-500 peer-checked:bg-emerald-50 hover:bg-slate-50">
                            <div class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-emerald-600 font-black text-white text-xs shadow-sm mb-1.5">GLO</div>
                            <span class="text-xs font-bold text-slate-800">Glo</span>
                            <div class="text-[10px] text-slate-400 font-medium mt-0.5">0805, 0807...</div>
                        </div>
                    </label>

                    <label class="cursor-pointer network-card" data-network="9mobile">
                        <input type="radio" name="network" value="9mobile" class="peer sr-only">
                        <div class="rounded-2xl border-2 border-slate-200 p-3 text-center transition peer-checked:border-lime-600 peer-checked:bg-lime-50 hover:bg-slate-50">
                            <div class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-lime-700 font-black text-white text-xs shadow-sm mb-1.5">9MOB</div>
                            <span class="text-xs font-bold text-slate-800">9mobile</span>
                            <div class="text-[10px] text-slate-400 font-medium mt-0.5">0809, 0818...</div>
                        </div>
                    </label>
                </div>
            </div>

            <!-- 3. Amount Presets & Custom -->
            <div>
                <label for="amount" class="block text-sm font-semibold text-slate-700 mb-1">3. Amount (₦)</label>
                <div class="grid grid-cols-3 gap-2 mb-2 sm:grid-cols-6">
                    <button type="button" onclick="setAmount(100)" class="rounded-xl border border-slate-200 py-2 text-xs font-bold text-slate-700 hover:bg-servora-50 hover:border-servora-500 transition">₦100</button>
                    <button type="button" onclick="setAmount(200)" class="rounded-xl border border-slate-200 py-2 text-xs font-bold text-slate-700 hover:bg-servora-50 hover:border-servora-500 transition">₦200</button>
                    <button type="button" onclick="setAmount(500)" class="rounded-xl border border-slate-200 py-2 text-xs font-bold text-slate-700 hover:bg-servora-50 hover:border-servora-500 transition">₦500</button>
                    <button type="button" onclick="setAmount(1000)" class="rounded-xl border border-slate-200 py-2 text-xs font-bold text-slate-700 hover:bg-servora-50 hover:border-servora-500 transition">₦1,000</button>
                    <button type="button" onclick="setAmount(2000)" class="rounded-xl border border-slate-200 py-2 text-xs font-bold text-slate-700 hover:bg-servora-50 hover:border-servora-500 transition">₦2,000</button>
                    <button type="button" onclick="setAmount(5000)" class="rounded-xl border border-slate-200 py-2 text-xs font-bold text-slate-700 hover:bg-servora-50 hover:border-servora-500 transition">₦5,000</button>
                </div>
                <input type="number" id="amount" name="amount" min="100" max="50000" step="1" placeholder="Enter amount (min ₦100)" required
                    class="w-full rounded-xl border border-slate-300 px-4 py-3 text-base font-semibold text-slate-900 placeholder-slate-400 focus:border-servora-600 focus:outline-none focus:ring-2 focus:ring-servora-200 transition">
            </div>

            <!-- Submit Button -->
            <button type="submit" id="submitBtn" class="w-full rounded-2xl bg-servora-700 py-4 text-base font-bold text-white shadow-md transition hover:bg-servora-800 focus:outline-none focus:ring-4 focus:ring-servora-200">
                Purchase Airtime
            </button>
        </form>
    </section>
</main>

<script>
// Nigerian Telecom Network Prefixes
var telecomConfig = {
    mtn: {
        name: 'MTN Nigeria',
        bg: 'bg-yellow-100',
        text: 'text-yellow-900',
        border: 'border-yellow-300',
        iconBg: 'bg-yellow-400 text-slate-900',
        label: 'MTN',
        prefixes: ['0703','0704','0706','0707','0803','0806','0810','0813','0814','0816','0903','0906','0913','0916']
    },
    airtel: {
        name: 'Airtel Nigeria',
        bg: 'bg-red-100',
        text: 'text-red-900',
        border: 'border-red-300',
        iconBg: 'bg-red-600 text-white',
        label: 'AIRTEL',
        prefixes: ['0701','0708','0802','0808','0812','0901','0902','0904','0907','0911','0912']
    },
    glo: {
        name: 'Glo Nigeria',
        bg: 'bg-emerald-100',
        text: 'text-emerald-900',
        border: 'border-emerald-300',
        iconBg: 'bg-emerald-600 text-white',
        label: 'GLO',
        prefixes: ['0705','0805','0807','0811','0815','0905','0915']
    },
    '9mobile': {
        name: '9mobile (T2)',
        bg: 'bg-lime-100',
        text: 'text-lime-900',
        border: 'border-lime-300',
        iconBg: 'bg-lime-700 text-white',
        label: '9MOBILE',
        prefixes: ['0809','0817','0818','0908','0909']
    }
};

function normalizePhone(val) {
    var clean = val.replace(/\D+/g, '');
    if (clean.startsWith('234') && clean.length >= 12) {
        clean = '0' + clean.substring(3);
    }
    return clean;
}

function detectNetwork(phone) {
    var clean = normalizePhone(phone);
    if (clean.length < 4) return null;
    var prefix = clean.substring(0, 4);
    for (var net in telecomConfig) {
        if (telecomConfig[net].prefixes.indexOf(prefix) !== -1) {
            return net;
        }
    }
    return null;
}

var lastAutoDetected = null;
var userOverridden = false;

function applyDetection() {
    var phoneInput = document.getElementById('phone');
    var pill = document.getElementById('networkPill');
    var icon = document.getElementById('phoneCarrierIcon');
    var portedHint = document.getElementById('portedHint');
    var clean = normalizePhone(phoneInput.value);

    var detected = detectNetwork(clean);

    if (detected) {
        var conf = telecomConfig[detected];
        pill.className = 'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-bold transition-all border shadow-sm ' + conf.bg + ' ' + conf.text + ' ' + conf.border;
        pill.innerHTML = '⚡ ' + conf.name + ' Identified';
        pill.classList.remove('hidden');

        icon.className = 'absolute right-3.5 top-1/2 -translate-y-1/2 flex h-6 px-2 items-center justify-center rounded-full text-[10px] font-black shadow-sm ' + conf.iconBg;
        icon.innerText = conf.label;
        icon.classList.remove('hidden');

        // Auto-select radio button if not manually overridden by user
        if (!userOverridden || lastAutoDetected !== detected) {
            var radio = document.querySelector('input[name="network"][value="' + detected + '"]');
            if (radio) {
                radio.checked = true;
            }
            lastAutoDetected = detected;
            userOverridden = false;
            portedHint.classList.add('hidden');
        }
    } else {
        pill.classList.add('hidden');
        icon.classList.add('hidden');
        portedHint.classList.add('hidden');
        lastAutoDetected = null;
    }
}

// Attach listener
var phoneInput = document.getElementById('phone');
phoneInput.addEventListener('input', applyDetection);
phoneInput.addEventListener('paste', function() {
    setTimeout(applyDetection, 50);
});

// Detect when user manually clicks a different network (ported number override)
document.querySelectorAll('input[name="network"]').forEach(function(radio) {
    radio.addEventListener('change', function() {
        var selected = this.value;
        var clean = normalizePhone(phoneInput.value);
        var detected = detectNetwork(clean);
        var portedHint = document.getElementById('portedHint');
        var pill = document.getElementById('networkPill');

        if (detected && selected !== detected) {
            userOverridden = true;
            portedHint.classList.remove('hidden');
            var selectedConf = telecomConfig[selected];
            var detectedConf = telecomConfig[detected];
            if (pill) {
                pill.className = 'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-bold transition-all border shadow-sm bg-slate-100 text-slate-800 border-slate-300';
                pill.innerHTML = 'Ported: ' + selectedConf.name + ' (Prefix: ' + detectedConf.name + ')';
            }
        } else if (detected && selected === detected) {
            userOverridden = false;
            applyDetection();
        }
    });
});

// Run on page load if phone already has value
if (phoneInput.value) {
    applyDetection();
}

function setAmount(val) {
    document.getElementById('amount').value = val;
}
document.getElementById('airtimeForm').addEventListener('submit', function(e) {
    var btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerText = 'Processing Recharge...';
});
</script>

<?php require_once __DIR__ . "/../includes/client_bottom_nav.php"; ?>

</body>
</html>
