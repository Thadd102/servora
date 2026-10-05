<?php

require_once "../config/database.php";
require_once "../includes/client_auth.php";
require_once "../config/payment.php";
require_once "../config/flutterwave.php";

$userId = currentUserId();
$message = "";


/* =========================================================
   GET CURRENT CLIENT
========================================================= */

$stmt = $pdo->prepare("
    SELECT full_name, email, phone
    FROM users
    WHERE id = ?
      AND role = 'client'
      AND status = 'active'
    LIMIT 1
");

$stmt->execute([$userId]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    exit("User account not found.");
}


/* =========================================================
   PROCESS FUNDING REQUEST
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    verifyCsrfToken();

    $amount = filter_input(
        INPUT_POST,
        "amount",
        FILTER_VALIDATE_FLOAT
    );

    $gateway = strtolower(
        trim($_POST["gateway"] ?? "")
    );


    /* -------------------------
       VALIDATE AMOUNT
    ------------------------- */

    if ($amount === false || $amount === null || $amount < 100) {

        $message = "Minimum wallet funding amount is ₦100.";

    } elseif ($amount > 1000000) {

        $message = "Maximum wallet funding amount is ₦1,000,000.";

    } elseif (!in_array($gateway, ["paystack", "flutterwave"], true)) {

        $message = "Please choose Paystack or Flutterwave.";

    } else {


        /* =====================================================
           CREATE PAYMENT REFERENCE
        ===================================================== */

        $prefix = $gateway === "paystack"
            ? "WALLET"
            : "SUBNEXT";

        $reference =
            $prefix .
            "-" .
            $userId .
            "-" .
            strtoupper(bin2hex(random_bytes(8)));


        /* =====================================================
           GET CURRENT WALLET
        ===================================================== */

        $stmt = $pdo->prepare("
            SELECT balance
            FROM wallets
            WHERE user_id = ?
            LIMIT 1
        ");

        $stmt->execute([$userId]);

        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$wallet) {

            $message = "Wallet not found.";

            goto payment_form;
        }


        $balanceBefore = (float) $wallet["balance"];


        /* =====================================================
           CREATE PENDING TRANSACTION
        ===================================================== */

        $stmt = $pdo->prepare("
            INSERT INTO wallet_transactions
            (
                user_id,
                type,
                amount,
                balance_before,
                balance_after,
                reference,
                description,
                status
            )
            VALUES
            (
                ?,
                'funding',
                ?,
                ?,
                ?,
                ?,
                ?,
                'pending'
            )
        ");

        $stmt->execute([
            $userId,
            $amount,
            $balanceBefore,
            $balanceBefore,
            $reference,
            "Wallet funding via " . ucfirst($gateway)
        ]);


        /* =====================================================
           PAYSTACK
        ===================================================== */

        if ($gateway === "paystack") {

            if (empty($paystackSecretKey)) {

                $pdo->prepare("
                    UPDATE wallet_transactions
                    SET status = 'failed'
                    WHERE reference = ?
                      AND user_id = ?
                ")->execute([
                    $reference,
                    $userId
                ]);

                $message = "Paystack is not configured yet.";

                goto payment_form;
            }


            $data = [

                "email" => $user["email"],

                // Paystack expects amount in kobo.
                "amount" => (int) round($amount * 100),

                "reference" => $reference,

                "callback_url" =>
                    $appUrl .
                    "/client/verify_payment.php",

                "metadata" => json_encode([

                    "cancel_action" =>
                        $appUrl .
                        "/client/payment_cancelled.php"
                        . "?gateway=paystack"
                        . "&reference="
                        . rawurlencode($reference),

                    "purpose" => "wallet_funding",

                    "user_id" => $userId

                ])
            ];


            $ch = curl_init(
                $paystackBaseUrl .
                "/transaction/initialize"
            );


            curl_setopt_array($ch, [

                CURLOPT_POST => true,

                CURLOPT_POSTFIELDS =>
                    json_encode($data),

                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer " .
                    $paystackSecretKey,

                    "Content-Type: application/json"
                ],

                CURLOPT_RETURNTRANSFER => true,

                CURLOPT_CONNECTTIMEOUT => 10,

                CURLOPT_TIMEOUT => 30

            ]);


        /* =====================================================
           FLUTTERWAVE
        ===================================================== */

        } else {

            if (
                !defined("FLW_SECRET_KEY") ||
                empty(FLW_SECRET_KEY)
            ) {

                $pdo->prepare("
                    UPDATE wallet_transactions
                    SET status = 'failed'
                    WHERE reference = ?
                      AND user_id = ?
                ")->execute([
                    $reference,
                    $userId
                ]);

                $message = "Flutterwave is not configured yet.";

                goto payment_form;
            }


            $data = [

                "tx_ref" => $reference,

                "amount" => number_format(
                    $amount,
                    2,
                    ".",
                    ""
                ),

                "currency" => "NGN",

                "redirect_url" =>
                    $appUrl .
                    "/client/flutterwave_callback.php",

                "payment_options" =>
                    "card,banktransfer,ussd,account",

                "customer" => [

                    "email" =>
                        $user["email"],

                    "name" =>
                        $user["full_name"],

                    "phonenumber" =>
                        $user["phone"] ?? ""
                ],

                "customizations" => [

                    "title" =>
                        "Subnext Wallet",

                    "description" =>
                        "Fund your Subnext wallet"
                ],

                "meta" => [

                    "user_id" =>
                        $userId,

                    "purpose" =>
                        "wallet_funding"
                ]
            ];


            $ch = curl_init(
                FLW_BASE_URL .
                "/payments"
            );


            curl_setopt_array($ch, [

                CURLOPT_POST => true,

                CURLOPT_POSTFIELDS =>
                    json_encode($data),

                CURLOPT_HTTPHEADER => [

                    "Authorization: Bearer " .
                    FLW_SECRET_KEY,

                    "Content-Type: application/json"
                ],

                CURLOPT_RETURNTRANSFER => true,

                CURLOPT_CONNECTTIMEOUT => 10,

                CURLOPT_TIMEOUT => 30
            ]);
        }


        /* =====================================================
           SEND REQUEST TO PAYMENT PROVIDER
        ===================================================== */

        $response = curl_exec($ch);

        $httpCode = (int) curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

        $curlError = curl_error($ch);

        curl_close($ch);


        $result = json_decode(
            (string) $response,
            true
        );


        if (!is_array($result)) {

            $result = [];
        }


        /* =====================================================
           GET CHECKOUT URL
        ===================================================== */

        if ($gateway === "paystack") {

            $paymentUrl =
                $result["data"]["authorization_url"]
                ?? "";

            $requestSuccessful =
                ($result["status"] ?? false) === true;

        } else {

            $paymentUrl =
                $result["data"]["link"]
                ?? "";

            $requestSuccessful =
                ($result["status"] ?? "") === "success";
        }


        /* =====================================================
           REDIRECT TO PAYMENT GATEWAY
        ===================================================== */

        if (
            $curlError === "" &&
            $httpCode >= 200 &&
            $httpCode < 300 &&
            $requestSuccessful &&
            filter_var(
                $paymentUrl,
                FILTER_VALIDATE_URL
            )
        ) {

            header(
                "Location: " . $paymentUrl
            );

            exit;
        }


        /* =====================================================
           INITIALIZATION FAILED
        ===================================================== */

        $pdo->prepare("
            UPDATE wallet_transactions
            SET status = 'failed'
            WHERE reference = ?
              AND user_id = ?
        ")->execute([
            $reference,
            $userId
        ]);


        // TEMPORARY DEBUGGING:
// Show the actual reason payment initialization failed.

if ($curlError !== "") {

    $message = "Connection error: " . $curlError;

} else {

    $providerMessage = $result["message"] ?? "Unknown error";

    $message =
        ucfirst($gateway) .
        " error (HTTP " .
        $httpCode .
        "): " .
        $providerMessage;
}
    }
}


payment_form:

$stmtWallet = $pdo->prepare("SELECT balance FROM wallets WHERE user_id = ? LIMIT 1");
$stmtWallet->execute([$userId]);
$walletRow = $stmtWallet->fetch(PDO::FETCH_ASSOC);
$walletBalance = $walletRow ? (float)$walletRow["balance"] : 0.00;
$profileInitial = strtoupper(substr($user["full_name"], 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fund Wallet - Subnext</title>
    <!-- Precompiled Production Stylesheet -->
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">

<!-- Desktop Navigation Header -->
<header class="hidden md:block sticky top-0 z-40 border-b border-slate-200/80 bg-white/95 backdrop-blur">
    <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6">
        <a href="dashboard.php" class="flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-servora-700 text-lg font-black text-white shadow-sm">S</div>
            <div>
                <div class="text-lg font-bold tracking-tight text-slate-900 leading-tight">Subnext</div>
                <div class="text-[10px] font-medium text-slate-400 uppercase tracking-wider">Add Funds</div>
            </div>
        </a>

        <!-- Desktop Navigation Links -->
        <nav class="flex items-center gap-1">
            <a href="dashboard.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">Dashboard</a>
            <a href="orders.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">My Orders</a>
            <a href="wallet.php" class="rounded-xl bg-servora-50 px-3.5 py-2 text-xs font-bold text-servora-700">Wallet</a>
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
        <a href="wallet.php" class="text-sm font-semibold text-white/70 hover:text-white">
            ← Wallet
        </a>

        <div class="mt-6">
            <p class="text-sm font-semibold text-white/70">
                Subnext Payments
            </p>
            <h1 class="mt-1 text-3xl font-black">
                Fund Wallet
            </h1>
            <p class="mt-2 max-w-xl text-sm text-white/70">
                Top-up your balance instantly using Paystack or Flutterwave.
            </p>
        </div>
    </section>

    <!-- STANDARDIZED WALLET BALANCE CARD -->
    <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex items-center justify-between gap-4">
            <div>
                <p class="text-xs font-bold uppercase tracking-wide text-slate-400">
                    Current Balance
                </p>
                <p class="mt-1 text-2xl font-black text-slate-900">
                    ₦<?= number_format($walletBalance, 2) ?>
                </p>
            </div>
            <a href="wallet.php" class="rounded-xl bg-slate-100 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-200 transition">
                Transaction History
            </a>
        </div>
    </section>

    <?php if ($message !== ""): ?>
    <div class="mt-6 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-700 shadow-sm flex items-start gap-2.5">
        <svg class="h-5 w-5 text-red-600 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
        <div><?= htmlspecialchars($message, ENT_QUOTES, "UTF-8") ?></div>
    </div>
    <?php endif; ?>

    <!-- CONTENT CARD -->
    <section class="mt-6 rounded-3xl border border-slate-200 bg-white p-5 sm:p-7 shadow-sm">
        <div>
            <p class="text-xs font-bold uppercase tracking-widest text-servora-600">Secure Deposit</p>
            <h2 class="mt-1 text-xl font-black text-slate-900">Enter Deposit Amount</h2>
            <p class="mt-1 text-sm text-slate-500">Choose how much you want to add to your wallet balance.</p>
        </div>

        <form method="POST" class="mt-6 space-y-6">
            <?= csrfField() ?>

            <!-- Amount Input -->
            <div>
                <label for="amount" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-2">
                    Deposit Amount (NGN) <span class="text-red-500">*</span>
                </label>
                <div class="relative">
                    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-lg font-black text-slate-400">₦</span>
                    <input
                        type="number"
                        id="amount"
                        name="amount"
                        min="100"
                        max="1000000"
                        step="100"
                        placeholder="e.g. 5,000"
                        required
                        class="w-full rounded-2xl border border-slate-300 py-3.5 pl-9 pr-4 text-lg font-black text-slate-900 placeholder-slate-400 focus:border-servora-600 focus:outline-none focus:ring-2 focus:ring-servora-200 transition"
                    >
                </div>
                <div class="mt-2.5 flex flex-wrap gap-2">
                    <button type="button" onclick="setQuickAmount(1000)" class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-100 transition">₦1,000</button>
                    <button type="button" onclick="setQuickAmount(2000)" class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-100 transition">₦2,000</button>
                    <button type="button" onclick="setQuickAmount(5000)" class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-100 transition">₦5,000</button>
                    <button type="button" onclick="setQuickAmount(10000)" class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-100 transition">₦10,000</button>
                    <button type="button" onclick="setQuickAmount(20000)" class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-100 transition">₦20,000</button>
                </div>
                <p class="mt-2 text-xs text-slate-400">Minimum funding: ₦100 • Maximum: ₦1,000,000</p>
            </div>

            <!-- Gateway Selection -->
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-2">
                    Select Payment Gateway <span class="text-red-500">*</span>
                </label>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <label class="cursor-pointer">
                        <input type="radio" name="gateway" value="paystack" checked class="peer sr-only">
                        <div class="h-full rounded-2xl border-2 border-slate-200 p-4 transition peer-checked:border-servora-600 peer-checked:bg-servora-50/50 hover:bg-slate-50">
                            <div class="flex items-center justify-between mb-1">
                                <span class="font-bold text-slate-900 text-sm">Paystack</span>
                                <span class="h-2.5 w-2.5 rounded-full bg-servora-600 ring-4 ring-servora-100"></span>
                            </div>
                            <p class="text-xs text-slate-500">Pay with Debit Card, Bank Transfer, USSD, or Apple Pay.</p>
                        </div>
                    </label>

                    <label class="cursor-pointer">
                        <input type="radio" name="gateway" value="flutterwave" class="peer sr-only">
                        <div class="h-full rounded-2xl border-2 border-slate-200 p-4 transition peer-checked:border-servora-600 peer-checked:bg-servora-50/50 hover:bg-slate-50">
                            <div class="flex items-center justify-between mb-1">
                                <span class="font-bold text-slate-900 text-sm">Flutterwave</span>
                                <span class="h-2.5 w-2.5 rounded-full bg-servora-600 ring-4 ring-servora-100"></span>
                            </div>
                            <p class="text-xs text-slate-500">Pay with Card, Bank Account, Mobile Money, or Barter.</p>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Submit -->
            <button
                type="submit"
                class="w-full rounded-2xl bg-servora-700 py-4 text-base font-bold text-white shadow-md transition hover:bg-servora-800 focus:outline-none focus:ring-4 focus:ring-servora-200"
            >
                Proceed to Payment →
            </button>

            <p class="text-center text-xs text-slate-400">
                🔒 All transactions are encrypted and processed by PCI-DSS compliant providers.
            </p>
        </form>
    </section>

</main>

<script>
function setQuickAmount(val) {
    var input = document.getElementById('amount');
    if (input) {
        input.value = val;
    }
}
</script>

<?php require_once __DIR__ . "/../includes/client_bottom_nav.php"; ?>

</body>
</html>