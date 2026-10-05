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
    http_response_code(403);
    exit("Invalid request.");
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
    http_response_code(400);
    exit("Invalid request token.");
}

$requestTokens =
    $_SESSION["virtual_number_request_tokens"]
    ?? [];

if (!isset($requestTokens[$requestToken])) {
    http_response_code(409);

    exit(
        "This request has expired or has already been processed."
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
    http_response_code(422);
    exit("Invalid service.");
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

    http_response_code(503);

    exit(
        "Could not verify the selected country."
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
    http_response_code(422);

    exit(
        "The selected country is unavailable."
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

    http_response_code(503);

    exit(
        "Could not verify the selected number option."
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
    http_response_code(422);

    exit(
        "The selected number option is no longer available."
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
    http_response_code(503);

    exit(
        "The price for this order could not be calculated."
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
        http_response_code(400);
        exit("Insufficient wallet balance. Please fund your wallet to continue.");
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

    http_response_code(500);

    exit(
        "Could not create the virtual number order."
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