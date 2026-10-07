<?php

require_once "../config/database.php";
require_once "../includes/client_auth.php";
require_once "../includes/VirtualNumberProvider.php";
require_once "../includes/VirtualNumberPricing.php";

$userId = (int) ($_SESSION["user_id"] ?? 0);

if ($userId <= 0) {
    header("Location: ../login.php");
    exit;
}

/**
 * Render a professional Subnext error screen instead of plain unstyled text.
 */
function renderProcessError(string $title, string $message, array $options = []): void {
    $httpCode = (int) ($options['code'] ?? 400);
    http_response_code($httpCode);

    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'error',
            'title' => $title,
            'message' => $message
        ]);
        exit;
    }

    $fundWalletUrl = $options['fund_wallet_url'] ?? 'fund_wallet.php';
    $backUrl = $options['back_url'] ?? 'foreign_numbers.php';
    $backText = $options['back_text'] ?? 'Back to Foreign Numbers';
    $isBalanceError = !empty($options['is_balance_error']);
    $requiredAmount = isset($options['required_amount']) ? (float)$options['required_amount'] : null;
    $availableBalance = isset($options['available_balance']) ? (float)$options['available_balance'] : null;
    $serviceName = trim((string)($options['service_name'] ?? ''));
    $countryName = trim((string)($options['country_name'] ?? ''));
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> | Subnext</title>
    <!-- Precompiled Production Stylesheet -->
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="min-h-screen bg-[#F6F7FB] text-slate-900 antialiased flex flex-col justify-between selection:bg-[#3E37B7] selection:text-white pb-20 md:pb-0">

    <!-- TOPBAR -->
    <header class="w-full bg-white border-b border-slate-200/80 sticky top-0 z-40">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between">
            <a href="dashboard.php" class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-[#3E37B7] text-white flex items-center justify-center font-black text-lg shadow-sm">
                    S
                </div>
                <div>
                    <div class="font-extrabold text-slate-900 tracking-tight leading-tight">Subnext</div>
                    <div class="text-[10px] font-medium text-slate-400 uppercase tracking-wider">Virtual Number Service</div>
                </div>
            </a>
            <div class="flex items-center gap-2">
                <a href="dashboard.php" class="text-xs font-semibold text-slate-600 hover:text-slate-900 px-3 py-1.5 rounded-lg hover:bg-slate-100 transition">
                    Dashboard
                </a>
                <a href="wallet.php" class="text-xs font-semibold text-[#3E37B7] bg-indigo-50 hover:bg-indigo-100 px-3 py-1.5 rounded-lg transition">
                    My Wallet
                </a>
            </div>
        </div>
    </header>

    <!-- MAIN CONTAINER -->
    <main class="flex-1 flex items-center justify-center p-4 sm:p-6 my-auto">
        <div class="w-full max-w-md">
            <div class="rounded-3xl border border-slate-200/80 bg-white p-6 sm:p-8 shadow-xl shadow-slate-200/50 text-center">
                
                <!-- ICON BADGE -->
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl <?= $isBalanceError ? 'bg-amber-50 text-amber-600 ring-8 ring-amber-50/70' : 'bg-rose-50 text-rose-600 ring-8 ring-rose-50/70' ?> mb-4 shadow-sm">
                    <?php if ($isBalanceError): ?>
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                        </svg>
                    <?php else: ?>
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    <?php endif; ?>
                </div>

                <!-- TITLE & MESSAGE -->
                <h1 class="text-xl sm:text-2xl font-extrabold text-slate-900 tracking-tight">
                    <?= htmlspecialchars($title) ?>
                </h1>
                <p class="text-xs sm:text-sm text-slate-500 mt-2 leading-relaxed">
                    <?= htmlspecialchars($message) ?>
                </p>

                <?php if ($isBalanceError && $requiredAmount !== null && $availableBalance !== null): ?>
                    <!-- BALANCE BREAKDOWN -->
                    <div class="mt-5 mb-6 rounded-2xl bg-slate-50 border border-slate-100 p-4 space-y-2.5 text-xs text-left">
                        <?php if ($serviceName !== '' || $countryName !== ''): ?>
                            <div class="flex items-center justify-between pb-2 border-b border-slate-200/60">
                                <span class="text-slate-500 font-medium">Selected Service</span>
                                <span class="font-semibold text-slate-800"><?= htmlspecialchars(trim($serviceName . ' ' . ($countryName ? "($countryName)" : ''))) ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="flex items-center justify-between">
                            <span class="text-slate-500 font-medium">Order Cost</span>
                            <span class="font-bold text-slate-900">₦<?= number_format($requiredAmount, 2) ?></span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-slate-500 font-medium">Available Balance</span>
                            <span class="font-semibold text-rose-600">₦<?= number_format($availableBalance, 2) ?></span>
                        </div>
                        <?php $shortfall = max(0, $requiredAmount - $availableBalance); ?>
                        <?php if ($shortfall > 0): ?>
                            <div class="flex items-center justify-between pt-2 border-t border-slate-200/60 text-slate-700">
                                <span class="font-medium text-slate-600">Funding Needed</span>
                                <span class="font-bold text-amber-700">₦<?= number_format($shortfall, 2) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="my-4"></div>
                <?php endif; ?>

                <!-- ACTION BUTTONS -->
                <div class="space-y-3">
                    <?php if ($isBalanceError): ?>
                        <a href="<?= htmlspecialchars($fundWalletUrl) ?>" class="w-full h-12 sm:h-14 rounded-xl bg-[#3E37B7] hover:bg-[#312E81] text-white font-bold text-sm shadow-lg shadow-indigo-200 transition-all duration-200 flex items-center justify-center gap-2 active:scale-[0.98]">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                            </svg>
                            <span>Fund Wallet Now</span>
                        </a>
                    <?php endif; ?>

                    <a href="<?= htmlspecialchars($backUrl) ?>" class="w-full h-12 rounded-xl border border-slate-200 hover:bg-slate-50 text-slate-700 font-semibold text-xs sm:text-sm transition flex items-center justify-center gap-2">
                        <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                        </svg>
                        <span><?= htmlspecialchars($backText) ?></span>
                    </a>
                </div>

            </div>

            <!-- FOOTER -->
            <p class="text-center text-[11px] text-slate-400 mt-6">
                &copy; <?= date('Y') ?> Subnext. Digital Services, Simplified.
            </p>
        </div>
    </main>

    <?php 
    $clientBottomNav = __DIR__ . '/../includes/client_bottom_nav.php';
    if (file_exists($clientBottomNav)) {
        require_once $clientBottomNav;
    }
    ?>
</body>
</html>
    <?php
    exit;
}

/*
|--------------------------------------------------------------------------
| POST ONLY
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: foreign_numbers.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

$csrfToken = trim(
    (string) ($_POST["csrf_token"] ?? "")
);

$sessionCsrfToken = trim(
    (string) ($_SESSION["csrf_token"] ?? "")
);

if (
    $csrfToken === ""
    || $sessionCsrfToken === ""
    || !hash_equals(
        $sessionCsrfToken,
        $csrfToken
    )
) {
    renderProcessError(
        "Invalid Request",
        "Your session or request security verification failed. Please try again.",
        [
            'code' => 403,
            'back_url' => 'foreign_numbers.php',
            'back_text' => 'Back to Foreign Numbers'
        ]
    );
}

/*
|--------------------------------------------------------------------------
| REQUEST TOKEN
|--------------------------------------------------------------------------
*/

$requestToken = trim(
    (string) ($_POST["request_token"] ?? "")
);

if (
    !preg_match(
        '/^[a-f0-9]{64}$/',
        $requestToken
    )
) {
    renderProcessError(
        "Invalid Request Token",
        "Invalid order security token. Please return to foreign numbers and try again.",
        [
            'code' => 400,
            'back_url' => 'foreign_numbers.php',
            'back_text' => 'Back to Foreign Numbers'
        ]
    );
}

$requestTokens =
    $_SESSION["virtual_number_request_tokens"]
    ?? [];

if (!isset($requestTokens[$requestToken])) {
    renderProcessError(
        "Order Session Expired",
        "This request has expired or has already been processed.",
        [
            'code' => 409,
            'back_url' => 'foreign_numbers.php',
            'back_text' => 'Back to Foreign Numbers'
        ]
    );
}

/*
|--------------------------------------------------------------------------
| INPUT
|--------------------------------------------------------------------------
*/

$serviceCode = strtolower(
    trim(
        (string) ($_POST["service_code"] ?? "")
    )
);

$countryCode = strtoupper(
    trim(
        (string) ($_POST["country_code"] ?? "")
    )
);

$operatorCode = strtolower(
    trim(
        (string) ($_POST["operator_code"] ?? "")
    )
);

$serviceCode = preg_replace(
    '/[^a-z0-9_-]/',
    '',
    $serviceCode
) ?? "";

$operatorCode = preg_replace(
    '/[^a-z0-9_-]/',
    '',
    $operatorCode
) ?? "";

if (
    $serviceCode === ""
    || !preg_match(
        '/^[A-Z]{2}$/',
        $countryCode
    )
    || $operatorCode === ""
) {
    http_response_code(422);

    exit(
        "Please select a valid service, country and option."
    );
}

/*
|--------------------------------------------------------------------------
| CENTRAL PRICING SETTINGS
|--------------------------------------------------------------------------
|
| This is checked on the backend.
|
| Even if someone manually sends a POST request, new orders cannot be
| created while the administrator has disabled the module.
|
*/

try {

    $pricingSettings =
        getVirtualNumberPricingSettings(
            $pdo
        );

} catch (Throwable $e) {

    error_log(
        "Virtual number pricing settings error: "
        . $e->getMessage()
    );

    http_response_code(503);

    exit(
        "Virtual number ordering is temporarily unavailable."
    );
}

if (
    !isVirtualNumberModuleActive(
        $pricingSettings
    )
) {
    http_response_code(503);

    exit(
        "Foreign number orders are temporarily unavailable."
    );
}

/*
|--------------------------------------------------------------------------
| PROVIDER
|--------------------------------------------------------------------------
*/

$provider =
    new VirtualNumberProvider();

/*
|--------------------------------------------------------------------------
| VERIFY SERVICE
|--------------------------------------------------------------------------
*/

try {

    $services =
        $provider->getServices();

} catch (Throwable $e) {

    error_log(
        "Virtual number service lookup failed: "
        . $e->getMessage()
    );

    http_response_code(503);

    exit(
        "Could not load the selected service."
    );
}

$selectedService = null;

foreach ($services as $service) {

    $code = strtolower(
        trim(
            (string) (
                $service["code"]
                ?? ""
            )
        )
    );

    if ($code !== $serviceCode) {
        continue;
    }

    $name = trim(
        (string) (
            $service["name"]
            ?? ""
        )
    );

    if ($name === "") {
        continue;
    }

    $selectedService = [
        "code" => $code,
        "name" => $name
    ];

    break;
}

if ($selectedService === null) {
    renderProcessError(
        "Invalid Service",
        "The requested virtual number service is invalid or unavailable.",
        [
            'code' => 422,
            'back_url' => 'foreign_numbers.php',
            'back_text' => 'Back to Foreign Numbers'
        ]
    );
}

/*
|--------------------------------------------------------------------------
| VERIFY COUNTRY
|--------------------------------------------------------------------------
*/

try {

    $countries =
        $provider->getCountries(
            $serviceCode
        );

} catch (Throwable $e) {

    error_log(
        "Virtual number country lookup failed: "
        . $e->getMessage()
    );

    renderProcessError(
        "Country Lookup Failed",
        "Could not verify the selected country. Please try again later.",
        [
            'code' => 503,
            'back_url' => 'foreign_numbers.php',
            'back_text' => 'Back to Foreign Numbers'
        ]
    );
}

$selectedCountry = null;

foreach ($countries as $country) {

    $code = strtoupper(
        trim(
            (string) (
                $country["code"]
                ?? ""
            )
        )
    );

    if ($code !== $countryCode) {
        continue;
    }

    $name = trim(
        (string) (
            $country["name"]
            ?? ""
        )
    );

    if ($name === "") {
        continue;
    }

    $selectedCountry = [
        "code" => $code,
        "name" => $name
    ];

    break;
}

if ($selectedCountry === null) {
    renderProcessError(
        "Country Unavailable",
        "The selected country is unavailable for this service. Please choose another country.",
        [
            'code' => 422,
            'back_url' => 'foreign_numbers.php',
            'back_text' => 'Back to Foreign Numbers'
        ]
    );
}

/*
|--------------------------------------------------------------------------
| VERIFY OPTION
|--------------------------------------------------------------------------
|
| We load the option directly from the provider again.
|
| Nothing about provider cost, selling price or stock is trusted from
| the browser.
|
*/

try {

    $options =
        $provider->getOptions(
            $serviceCode,
            $countryCode
        );

} catch (Throwable $e) {

    error_log(
        "Virtual number option lookup failed: "
        . $e->getMessage()
    );

    renderProcessError(
        "Option Lookup Failed",
        "Could not verify the selected number option. Please try again later.",
        [
            'code' => 503,
            'back_url' => 'foreign_number_options.php?service=' . urlencode($serviceCode),
            'back_text' => 'Choose Another Option'
        ]
    );
}

$selectedOption = null;

foreach ($options as $option) {

    $code = strtolower(
        trim(
            (string) (
                $option["operator_code"]
                ?? ""
            )
        )
    );

    if ($code !== $operatorCode) {
        continue;
    }

    $operatorName = trim(
        (string) (
            $option["operator_name"]
            ?? "Available Number"
        )
    );

    $providerCost = round(
        (float) (
            $option["provider_cost"]
            ?? 0
        ),
        2
    );

    $stock = (int) (
        $option["stock"]
        ?? 0
    );

    if (
        $providerCost <= 0
        || $stock <= 0
    ) {
        continue;
    }

    $selectedOption = [
        "operator_code" =>
            $code,

        "operator_name" =>
            $operatorName !== ""
                ? $operatorName
                : "Available Number",

        "provider_cost" =>
            $providerCost,

        "stock" =>
            $stock
    ];

    break;
}

if ($selectedOption === null) {
    renderProcessError(
        "Option Unavailable",
        "The selected number option is no longer available in stock. Please select another option.",
        [
            'code' => 422,
            'back_url' => 'foreign_number_options.php?service=' . urlencode($serviceCode),
            'back_text' => 'Choose Another Option'
        ]
    );
}

/*
|--------------------------------------------------------------------------
| CENTRAL PRICE CALCULATION
|--------------------------------------------------------------------------
|
| Same pricing engine used by foreign_number_options.php.
|
| The customer cannot change the selling price from DevTools or by
| creating a custom POST request.
|
*/

$pricing =
    calculateVirtualNumberPrice(
        (float) $selectedOption["provider_cost"],
        $pricingSettings
    );

$providerCost = (float) (
    $pricing["provider_cost"]
    ?? 0
);

$profit = (float) (
    $pricing["profit"]
    ?? 0
);

$sellingPrice = (float) (
    $pricing["selling_price"]
    ?? 0
);

if (
    $providerCost <= 0
    || $sellingPrice <= 0
    || $sellingPrice < $providerCost
) {
    renderProcessError(
        "Pricing Error",
        "The price for this order could not be calculated. Please try again later.",
        [
            'code' => 503,
            'back_url' => 'foreign_numbers.php',
            'back_text' => 'Back to Foreign Numbers'
        ]
    );
}

/*
|--------------------------------------------------------------------------
| MOCK MODE SAFETY
|--------------------------------------------------------------------------
|
| Development mode only.
|
| No wallet debit.
| No real provider purchase.
| No real provider charge.
|
*/

$providerName = strtolower(
    trim(
        $provider->getProviderName()
    )
);

/*
|--------------------------------------------------------------------------
| CHECK EXISTING REQUEST
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT id
    FROM virtual_number_orders
    WHERE client_request_token = ?
      AND user_id = ?
    LIMIT 1
");

$stmt->execute([
    $requestToken,
    $userId
]);

$existingOrderId = (int) (
    $stmt->fetchColumn() ?: 0
);

if ($existingOrderId > 0) {

    unset(
        $_SESSION[
            "virtual_number_request_tokens"
        ][$requestToken]
    );

    header(
        "Location: foreign_number_result.php?id="
        . $existingOrderId
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| ORDER REFERENCE
|--------------------------------------------------------------------------
*/

$orderReference =
    "VN-"
    . date("YmdHis")
    . "-"
    . strtoupper(
        bin2hex(
            random_bytes(4)
        )
    );

/*
|--------------------------------------------------------------------------
| CREATE ORDER
|--------------------------------------------------------------------------
*/

try {

    $pdo->beginTransaction();

    /*
    |--------------------------------------------------------------------------
    | LOCK CLIENT
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            id,
            status
        FROM users
        WHERE id = ?
          AND role = 'client'
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->execute([
        $userId
    ]);

    $client = $stmt->fetch(
        PDO::FETCH_ASSOC
    );

    if (
        !$client
        || strtolower(
            trim(
                (string) (
                    $client["status"]
                    ?? ""
                )
            )
        ) !== "active"
    ) {
        throw new RuntimeException(
            "Your account is not available for this request."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | CHECK SETTINGS AGAIN WHILE CREATING ORDER
    |--------------------------------------------------------------------------
    |
    | Prevent an order being created if an administrator disabled the
    | module between the first check and this transaction.
    |
    */

    $stmt = $pdo->query("
        SELECT status
        FROM virtual_number_settings
        ORDER BY id ASC
        LIMIT 1
        FOR UPDATE
    ");

    $currentModuleStatus = strtolower(
        trim(
            (string) (
                $stmt->fetchColumn()
                ?: ""
            )
        )
    );

    if ($currentModuleStatus !== "active") {
        throw new RuntimeException(
            "Foreign number orders are temporarily unavailable."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | DUPLICATE PROTECTION
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT id
        FROM virtual_number_orders
        WHERE client_request_token = ?
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->execute([
        $requestToken
    ]);

    $duplicateOrderId = (int) (
        $stmt->fetchColumn() ?: 0
    );

    if ($duplicateOrderId > 0) {

        $pdo->commit();

        unset(
            $_SESSION[
                "virtual_number_request_tokens"
            ][$requestToken]
        );

        header(
            "Location: foreign_number_result.php?id="
            . $duplicateOrderId
        );

        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | WALLET BALANCE CHECK & ATOMIC DEDUCTION
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT id, balance
        FROM wallets
        WHERE user_id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$userId]);
    $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$wallet) {
        throw new RuntimeException("Wallet not found. Please contact support.");
    }

    $currentBalance = (float)$wallet['balance'];
    if ($currentBalance < $sellingPrice) {
        $pdo->rollBack();
        renderProcessError(
            "Insufficient Wallet Balance",
            "Insufficient wallet balance. Please fund your wallet to continue.",
            [
                'code' => 400,
                'is_balance_error' => true,
                'required_amount' => $sellingPrice,
                'available_balance' => $currentBalance,
                'service_name' => $selectedService['name'] ?? '',
                'country_name' => $selectedCountry['name'] ?? '',
                'fund_wallet_url' => 'fund_wallet.php',
                'back_url' => 'foreign_number_options.php?service=' . urlencode($serviceCode),
                'back_text' => 'Choose Another Country'
            ]
        );
    }

    $newBalance = round($currentBalance - $sellingPrice, 2);

    // Debit wallet
    $stmt = $pdo->prepare("UPDATE wallets SET balance = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$newBalance, $wallet['id']]);

    // Record debit in wallet_transactions
    $txnDesc = "Foreign Number - {$selectedService['name']} ({$selectedCountry['name']})";
    $stmt = $pdo->prepare("
        INSERT INTO wallet_transactions (
            user_id, type, amount, balance_before, balance_after, reference, description, status, created_at, updated_at
        ) VALUES (
            ?, 'debit', ?, ?, ?, ?, ?, 'successful', NOW(), NOW()
        )
    ");
    $stmt->execute([
        $userId,
        $sellingPrice,
        $currentBalance,
        $newBalance,
        $orderReference,
        $txnDesc
    ]);
    $walletTxnId = (int)$pdo->lastInsertId();

    /*
    |--------------------------------------------------------------------------
    | INSERT ORDER
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        INSERT INTO virtual_number_orders (
            order_reference,
            client_request_token,
            user_id,
            service_code,
            service_name,
            country_code,
            country_name,
            operator_code,
            operator_name,
            provider,
            provider_cost,
            selling_price,
            wallet_transaction_id,
            status,
            provider_message
        )
        VALUES (
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            'processing',
            ?
        )
    ");

    $stmt->execute([
        $orderReference,
        $requestToken,
        $userId,

        $selectedService["code"],
        $selectedService["name"],

        $selectedCountry["code"],
        $selectedCountry["name"],

        $selectedOption["operator_code"],
        $selectedOption["operator_name"],

        $providerName,

        $providerCost,
        $sellingPrice,
        $walletTxnId,

        "Order initiated. Contacting supplier..."
    ]);

    $orderId = (int) (
        $pdo->lastInsertId()
    );

    if ($orderId <= 0) {
        throw new RuntimeException(
            "Order creation failed."
        );
    }

    $pdo->commit();

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        "Virtual number order creation failed: "
        . $e->getMessage()
    );

    renderProcessError(
        "Order Processing Failed",
        "Could not create the virtual number order. Please contact support or try again.",
        [
            'code' => 500,
            'back_url' => 'foreign_numbers.php',
            'back_text' => 'Back to Foreign Numbers'
        ]
    );
}

/*
|--------------------------------------------------------------------------
| CONSUME REQUEST TOKEN
|--------------------------------------------------------------------------
*/

unset(
    $_SESSION[
        "virtual_number_request_tokens"
    ][$requestToken]
);

/*
|--------------------------------------------------------------------------
| MOCK PURCHASE
|--------------------------------------------------------------------------
|
| Provider action remains outside the database transaction.
|
*/

try {

    $providerResult =
        $provider->purchase(
            $selectedService["code"],
            $selectedCountry["code"],
            $selectedOption["operator_code"]
        );

} catch (Throwable $e) {

    error_log(
        "Virtual number provider purchase error: "
        . $e->getMessage()
    );

    $providerResult = [
        "ok" => false,

        "state" =>
            "unknown",

        "message" =>
            "Provider outcome could not be confirmed."
    ];
}

/*
|--------------------------------------------------------------------------
| NORMALIZE RESULT
|--------------------------------------------------------------------------
*/

$providerState = strtolower(
    trim(
        (string) (
            $providerResult["state"]
            ?? "unknown"
        )
    )
);

$allowedProviderStates = [
    "waiting_sms",
    "processing",
    "successful",
    "completed",
    "failed",
    "expired",
    "cancelled",
    "unknown"
];

if (
    !in_array(
        $providerState,
        $allowedProviderStates,
        true
    )
) {
    $providerState = "unknown";
}

$providerMessage = substr(
    trim(
        (string) (
            $providerResult["message"]
            ?? ""
        )
    ),
    0,
    500
);

$providerOrderId = trim(
    (string) (
        $providerResult["provider_order_id"]
        ?? ""
    )
);

$providerPhoneNumber = trim(
    (string) (
        $providerResult["phone_number"]
        ?? ""
    )
);

/*
|--------------------------------------------------------------------------
| PROVIDER TRANSACTION OUTCOME
|--------------------------------------------------------------------------
*/

$transactionOutcome = match (
    $providerState
) {

    "waiting_sms",
    "successful",
    "completed" =>
        "successful",

    "failed",
    "expired",
    "cancelled" =>
        "failed",

    default =>
        "unknown"
};

/*
|--------------------------------------------------------------------------
| PROVIDER TRANSACTION LOG
|--------------------------------------------------------------------------
*/

try {

    $stmt = $pdo->prepare("
        INSERT INTO virtual_number_provider_transactions (
            virtual_number_order_id,
            provider,
            action,
            provider_order_id,
            http_status,
            provider_status,
            outcome,
            message
        )
        VALUES (
            ?,
            ?,
            'purchase',
            ?,
            NULL,
            ?,
            ?,
            ?
        )
    ");

    $stmt->execute([
        $orderId,

        $providerName,

        $providerOrderId !== ""
            ? $providerOrderId
            : null,

        $providerState,

        $transactionOutcome,

        $providerMessage !== ""
            ? $providerMessage
            : null
    ]);

} catch (Throwable $e) {

    error_log(
        "Virtual number provider log failed: "
        . $e->getMessage()
    );
}

/*
|--------------------------------------------------------------------------
| WAITING FOR SMS
|--------------------------------------------------------------------------
*/

if (
    ($providerResult["ok"] ?? false) === true
    && !empty($providerPhoneNumber)
    && in_array($providerState, ["waiting_sms", "successful", "completed"], true)
) {
    $newStatus = ($providerState === "completed") ? "completed" : "waiting_sms";

    $stmt = $pdo->prepare("
        UPDATE virtual_number_orders
        SET
            provider_order_id = ?,
            provider_phone_number = ?,
            status = ?,
            provider_message = ?,
            completed_at = " . ($newStatus === "completed" ? "CURRENT_TIMESTAMP" : "NULL") . ",
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
          AND user_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $providerOrderId !== ""
            ? $providerOrderId
            : null,

        $providerPhoneNumber,

        $newStatus,

        $providerMessage !== ""
            ? $providerMessage
            : "Virtual number allocated. Waiting for verification SMS code.",

        $orderId,
        $userId
    ]);

} else {
    /*
    |--------------------------------------------------------------------------
    | NUMBER UNAVAILABLE / FAILED -> AUTOMATIC EXACT REFUND
    |--------------------------------------------------------------------------
    |
    | Requirement:
    | - Never generate or display a fake number.
    | - Never mark an unavailable order as successful.
    | - Automatically refund the exact amount if wallet was charged.
    | - Record the refund properly in wallet_transactions.
    | - Prevent duplicate deductions/refunds.
    | - Log technical supplier errors for the admin.
    |
    */

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT id, status, selling_price, user_id
            FROM virtual_number_orders
            WHERE id = ?
            FOR UPDATE
        ");
        $stmt->execute([$orderId]);
        $checkOrder = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($checkOrder && $checkOrder['status'] !== 'refunded') {
            $stmt = $pdo->prepare("
                SELECT id, balance
                FROM wallets
                WHERE user_id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$userId]);
            $wal = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($wal) {
                $balBefore = (float)$wal['balance'];
                $balAfter = round($balBefore + $sellingPrice, 2);

                $stmt = $pdo->prepare("UPDATE wallets SET balance = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$balAfter, $wal['id']]);

                $refundRef = $orderReference . "-REF";
                $refundDesc = "Refund: Foreign Number ({$selectedService['name']} - {$selectedCountry['name']}) Unavailable";

                $stmt = $pdo->prepare("
                    INSERT INTO wallet_transactions (
                        user_id, type, amount, balance_before, balance_after, reference, description, status, created_at, updated_at
                    ) VALUES (
                        ?, 'refund', ?, ?, ?, ?, ?, 'successful', NOW(), NOW()
                    )
                ");
                $stmt->execute([
                    $userId,
                    $sellingPrice,
                    $balBefore,
                    $balAfter,
                    $refundRef,
                    $refundDesc
                ]);
            }

            $clientNotice = "Number Currently Unavailable: No number is currently available for the selected country/service. Please try again later or choose another option.";

            $stmt = $pdo->prepare("
                UPDATE virtual_number_orders
                SET
                    provider_order_id = ?,
                    provider_phone_number = NULL,
                    status = 'refunded',
                    provider_message = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
                  AND user_id = ?
                LIMIT 1
            ");
            $stmt->execute([
                $providerOrderId !== "" ? $providerOrderId : null,
                $clientNotice,
                $orderId,
                $userId
            ]);
        }

        $pdo->commit();
    } catch (Throwable $refErr) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Foreign number auto-refund error: " . $refErr->getMessage());
    }
}

/*
|--------------------------------------------------------------------------
| REDIRECT
|--------------------------------------------------------------------------
*/

header(
    "Location: foreign_number_result.php?id="
    . $orderId
);

exit;