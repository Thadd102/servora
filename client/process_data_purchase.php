<?php
require_once "../config/database.php";
require_once "../config/env.php";
require_once "../includes/client_auth.php";
require_once "../includes/DataProviderProcessor.php";
require_once "../includes/VTPassDataClient.php";

$userId = currentUserId();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: buy_data.php");
    exit;
}

$planId = (int) ($_POST["plan_id"] ?? 0);
$phone = trim($_POST["phone"] ?? "");
$clientToken = trim($_POST["client_request_token"] ?? "");

if ($planId <= 0 || $phone === "" || $clientToken === "") {
    header("Location: buy_data.php?error=invalid");
    exit;
}

$sessionToken = $_SESSION["data_purchase_token"] ?? "";

if ($sessionToken === "" || !hash_equals($sessionToken, $clientToken)) {
    header("Location: buy_data.php?error=invalid");
    exit;
}

$phone = preg_replace('/\D+/', '', $phone);

if (str_starts_with($phone, "0")) {
    $phone = "234" . substr($phone, 1);
}

if (!preg_match('/^234\d{10}$/', $phone)) {
    header("Location: buy_data.php?error=phone");
    exit;
}

$localPhone = "0" . substr($phone, 3);

$networkPrefixes = [
    "airtel" => ["0701","0708","0802","0808","0812","0901","0902","0904","0907","0911","0912"],
    "mtn" => ["0703","0704","0706","0707","0803","0806","0810","0813","0814","0816","0903","0906","0913","0916"],
    "glo" => ["0705","0805","0807","0811","0815","0905","0915"],
    "t2" => ["0809","0817","0818","0908","0909"]
];

$phonePrefix = substr($localPhone, 0, 4);
$detectedNetwork = null;

foreach ($networkPrefixes as $networkSlug => $prefixes) {
    if (in_array($phonePrefix, $prefixes, true)) {
        $detectedNetwork = $networkSlug;
        break;
    }
}

if ($detectedNetwork === null) {
    header("Location: buy_data.php?error=phone");
    exit;
}

/*
|--------------------------------------------------------------------------
| SUPPLIER BALANCE PRE-CHECK
|--------------------------------------------------------------------------
| Nothing has been deducted from the customer's wallet at this point.
*/
try {
    $stmt = $pdo->prepare("
        SELECT
            dp.id, dp.network_id, dp.category_id, dp.provider_id,
            dp.provider_plan_code, dp.name, dp.data_amount, dp.validity,
            dp.cost_price, dp.selling_price, dp.status,
            n.name AS network_name, n.slug AS network_slug,
            n.status AS network_status,
            dc.name AS category_name, dc.slug AS category_slug,
            dc.status AS category_status,
            ap.name AS provider_name, ap.slug AS provider_slug,
            ap.base_url AS provider_base_url, ap.status AS provider_status
        FROM data_plans dp
        INNER JOIN networks n ON n.id = dp.network_id
        LEFT JOIN data_categories dc ON dc.id = dp.category_id
        LEFT JOIN api_providers ap ON ap.id = dp.provider_id
        WHERE dp.id = ?
        LIMIT 1
    ");

    $stmt->execute([$planId]);
    $precheckPlan = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$precheckPlan || $precheckPlan["status"] !== "active") {
        header("Location: buy_data.php?error=plan");
        exit;
    }

    if ($precheckPlan["network_status"] !== "active") {
        header("Location: buy_data.php?error=network_unavailable");
        exit;
    }

    if (
        $precheckPlan["category_id"] !== null &&
        $precheckPlan["category_status"] !== "active"
    ) {
        header("Location: buy_data.php?error=category");
        exit;
    }

    if (
        empty($precheckPlan["provider_id"]) ||
        empty($precheckPlan["provider_slug"]) ||
        $precheckPlan["provider_status"] !== "active"
    ) {
        header("Location: buy_data.php?error=provider");
        exit;
    }

    $providerPlanCode = trim((string) ($precheckPlan["provider_plan_code"] ?? ""));

    if ($providerPlanCode === "") {
        header("Location: buy_data.php?error=provider_plan");
        exit;
    }

    $selectedNetwork = strtolower(trim((string) $precheckPlan["network_slug"]));

    if ($selectedNetwork !== $detectedNetwork) {
        header("Location: buy_data.php?error=network");
        exit;
    }

    $precheckCostPrice = (float) $precheckPlan["cost_price"];
    $precheckSellingPrice = (float) $precheckPlan["selling_price"];

    if (
        !is_finite($precheckSellingPrice) ||
        !is_finite($precheckCostPrice) ||
        $precheckSellingPrice <= 0 ||
        $precheckCostPrice < 0 ||
        $precheckSellingPrice < $precheckCostPrice
    ) {
        header("Location: buy_data.php?error=processing");
        exit;
    }

    $providerSlug = strtolower(trim((string) $precheckPlan["provider_slug"]));
    $cheapDataHub = null;
    $vtpassClient = null;

    if ($providerSlug === "cheapdatahub") {
        $apiKey = $_ENV["CHEAPDATAHUB_API_KEY"] ?? getenv("CHEAPDATAHUB_API_KEY");
        $baseUrl =
            $_ENV["CHEAPDATAHUB_BASE_URL"]
            ?? getenv("CHEAPDATAHUB_BASE_URL")
            ?: "https://www.cheapdatahub.ng/api/v1/resellers/";

        if (!$apiKey) {
            error_log("CheapDataHub API key is not configured.");
            header("Location: buy_data.php?error=provider_unavailable");
            exit;
        }

        $cheapDataHub = new CheapDataHubClient($apiKey, $baseUrl);
        $balanceResult = $cheapDataHub->getBalance();

        if (($balanceResult["ok"] ?? false) !== true) {
            error_log("CheapDataHub balance pre-check failed.");
            header("Location: buy_data.php?error=provider_unavailable");
            exit;
        }

        $payload = $balanceResult["data"] ?? [];
        $supplierBalance = null;
        $candidates = [];

        if (is_array($payload)) {
            $candidates[] = $payload["balance"] ?? null;
            $candidates[] = $payload["wallet_balance"] ?? null;

            if (isset($payload["data"]) && is_array($payload["data"])) {
                $candidates[] = $payload["data"]["balance"] ?? null;
                $candidates[] = $payload["data"]["wallet_balance"] ?? null;
            }
        }

        foreach ($candidates as $candidate) {
            if (is_int($candidate) || is_float($candidate) || is_string($candidate)) {
                $normalized = is_string($candidate)
                    ? str_replace([",", "₦", "NGN", "ngn", " "], "", $candidate)
                    : $candidate;

                if (is_numeric($normalized)) {
                    $supplierBalance = (float) $normalized;
                    break;
                }
            }
        }

        if (
            $supplierBalance === null ||
            !is_finite($supplierBalance) ||
            $supplierBalance < 0
        ) {
            error_log("CheapDataHub balance response could not be interpreted safely.");
            header("Location: buy_data.php?error=provider_unavailable");
            exit;
        }

        if ($supplierBalance < $precheckCostPrice) {
            header("Location: buy_data.php?error=supplier_balance");
            exit;
        }
    } elseif ($providerSlug === "vtpass") {
        $vtpassApiKey = trim((string)(getenv('VTPASS_API_KEY') ?: ($_ENV['VTPASS_API_KEY'] ?? '')));
        $vtpassSecretKey = trim((string)(getenv('VTPASS_SECRET_KEY') ?: ($_ENV['VTPASS_SECRET_KEY'] ?? '')));

        if ($vtpassApiKey === '' || $vtpassSecretKey === '') {
            error_log("VTPass credentials are not configured.");
            header("Location: buy_data.php?error=provider_unavailable");
            exit;
        }

        $vtpassClient = new VTPassDataClient();
        $balanceResult = $vtpassClient->getBalance();

        if (($balanceResult["ok"] ?? false) !== true) {
            error_log("VTPass balance pre-check failed: " . ($balanceResult["message"] ?? ""));
            header("Location: buy_data.php?error=provider_unavailable");
            exit;
        }

        $payload = $balanceResult["data"] ?? [];
        $supplierBalance = null;

        $rawBal = $payload["contents"]["balance"] ?? ($payload["balance"] ?? null);
        if ($rawBal !== null && is_numeric($rawBal)) {
            $supplierBalance = (float)$rawBal;
        }

        if (
            $supplierBalance === null ||
            !is_finite($supplierBalance) ||
            $supplierBalance < 0
        ) {
            error_log("VTPass balance response could not be interpreted safely.");
            header("Location: buy_data.php?error=provider_unavailable");
            exit;
        }

        if ($supplierBalance < $precheckCostPrice) {
            header("Location: buy_data.php?error=supplier_balance");
            exit;
        }
    } else {
        header("Location: buy_data.php?error=provider");
        exit;
    }

} catch (Throwable $precheckError) {
    error_log("Data supplier pre-check failed.");
    header("Location: buy_data.php?error=provider_unavailable");
    exit;
}

/*
|--------------------------------------------------------------------------
| LOCAL PURCHASE TRANSACTION
|--------------------------------------------------------------------------
*/
try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT id, status, role
        FROM users
        WHERE id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$userId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer || $customer["role"] !== "client" || $customer["status"] !== "active") {
        $pdo->rollBack();
        header("Location: buy_data.php?error=account");
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT id, order_reference
        FROM data_orders
        WHERE client_request_token = ?
          AND user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$clientToken, $userId]);
    $existingOrder = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existingOrder) {
        $pdo->rollBack();
        header("Location: data_order.php?ref=" . urlencode($existingOrder["order_reference"]));
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT
            dp.id, dp.network_id, dp.category_id, dp.provider_id,
            dp.provider_plan_code, dp.name, dp.data_amount, dp.validity,
            dp.cost_price, dp.selling_price, dp.status,
            n.name AS network_name, n.slug AS network_slug,
            n.status AS network_status,
            dc.name AS category_name, dc.slug AS category_slug,
            dc.status AS category_status,
            ap.name AS provider_name, ap.slug AS provider_slug,
            ap.base_url AS provider_base_url, ap.status AS provider_status
        FROM data_plans dp
        INNER JOIN networks n ON n.id = dp.network_id
        LEFT JOIN data_categories dc ON dc.id = dp.category_id
        LEFT JOIN api_providers ap ON ap.id = dp.provider_id
        WHERE dp.id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$planId]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$plan || $plan["status"] !== "active") {
        $pdo->rollBack();
        header("Location: buy_data.php?error=plan");
        exit;
    }

    if ($plan["network_status"] !== "active") {
        $pdo->rollBack();
        header("Location: buy_data.php?error=network_unavailable");
        exit;
    }

    if ($plan["category_id"] !== null && $plan["category_status"] !== "active") {
        $pdo->rollBack();
        header("Location: buy_data.php?error=category");
        exit;
    }

    if (
        empty($plan["provider_id"]) ||
        empty($plan["provider_slug"]) ||
        $plan["provider_status"] !== "active"
    ) {
        $pdo->rollBack();
        header("Location: buy_data.php?error=provider");
        exit;
    }

    $providerPlanCode = trim((string) ($plan["provider_plan_code"] ?? ""));

    if ($providerPlanCode === "") {
        $pdo->rollBack();
        header("Location: buy_data.php?error=provider_plan");
        exit;
    }

    $selectedNetwork = strtolower(trim((string) $plan["network_slug"]));

    if ($selectedNetwork !== $detectedNetwork) {
        $pdo->rollBack();
        header("Location: buy_data.php?error=network");
        exit;
    }

    $sellingPrice = (float) $plan["selling_price"];
    $costPrice = (float) $plan["cost_price"];

    if (
        !is_finite($sellingPrice) ||
        !is_finite($costPrice) ||
        $sellingPrice <= 0 ||
        $costPrice < 0 ||
        $sellingPrice < $costPrice
    ) {
        throw new Exception("Invalid data plan pricing.");
    }

    /* Prevent a plan/provider change between balance check and debit. */
    if (
        (int) $plan["provider_id"] !== (int) $precheckPlan["provider_id"] ||
        $providerPlanCode !== (string) $precheckPlan["provider_plan_code"] ||
        abs($costPrice - $precheckCostPrice) > 0.00001
    ) {
        $pdo->rollBack();
        header("Location: buy_data.php?error=plan_changed");
        exit;
    }

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
        $pdo->rollBack();
        header("Location: buy_data.php?error=wallet");
        exit;
    }

    $balanceBefore = (float) $wallet["balance"];

    if ($balanceBefore < $sellingPrice) {
        $pdo->rollBack();
        header("Location: buy_data.php?error=balance");
        exit;
    }

    $balanceAfter = $balanceBefore - $sellingPrice;
    $profit = $sellingPrice - $costPrice;

    $orderReference =
        "DATA-" . date("YmdHis") . "-" .
        strtoupper(bin2hex(random_bytes(4)));

    $orderProviderName = trim((string)($plan["provider_name"] ?? ""));
    if ($orderProviderName === "") {
        $orderProviderName = ($providerSlug === "vtpass") ? "VTpass" : "CheapDataHub";
    }

    $stmt = $pdo->prepare("
        INSERT INTO data_orders (
            order_reference, client_request_token, user_id, plan_id, provider_name,
            phone_number, cost_price, selling_price, profit, status
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
    ");
    $stmt->execute([
        $orderReference, $clientToken, $userId, $planId, $orderProviderName,
        $phone, $costPrice, $sellingPrice, $profit
    ]);

    $orderId = (int) $pdo->lastInsertId();

    if ($orderId <= 0) {
        throw new Exception("Could not retrieve data order ID.");
    }

    $stmt = $pdo->prepare("
        UPDATE wallets
        SET balance = balance - ?
        WHERE user_id = ?
          AND balance >= ?
    ");
    $stmt->execute([$sellingPrice, $userId, $sellingPrice]);

    if ($stmt->rowCount() !== 1) {
        throw new Exception("Wallet deduction failed.");
    }

    $transactionReference = "DATA-DEBIT-" . $orderReference;

    $description =
        $plan["network_name"] . " " .
        $plan["data_amount"] .
        " data purchase for " . $phone;

    $stmt = $pdo->prepare("
        INSERT INTO wallet_transactions (
            user_id, type, amount, balance_before, balance_after,
            reference, description, status
        )
        VALUES (?, 'data_purchase', ?, ?, ?, ?, ?, 'successful')
    ");
    $stmt->execute([
        $userId, $sellingPrice, $balanceBefore, $balanceAfter,
        $transactionReference, $description
    ]);

    $walletTransactionId = (int) $pdo->lastInsertId();

    if ($walletTransactionId <= 0) {
        throw new Exception("Could not retrieve wallet transaction ID.");
    }

    $stmt = $pdo->prepare("
        UPDATE data_orders
        SET wallet_transaction_id = ?
        WHERE id = ?
          AND user_id = ?
          AND wallet_transaction_id IS NULL
    ");
    $stmt->execute([$walletTransactionId, $orderId, $userId]);

    if ($stmt->rowCount() !== 1) {
        throw new Exception("Could not link wallet transaction to data order.");
    }

    $pdo->commit();
    unset($_SESSION["data_purchase_token"]);

    /*
    |--------------------------------------------------------------------------
    | SEND ORDER TO PROVIDER OUTSIDE DB TRANSACTION
    |--------------------------------------------------------------------------
    */
    try {
        $providerSlug = strtolower(trim((string) $plan["provider_slug"]));

        if ($providerSlug === "cheapdatahub" || $providerSlug === "vtpass") {
            $processor = new DataProviderProcessor($pdo, $cheapDataHub, $vtpassClient);
            $providerResult = $processor->process($orderId);

            if (($providerResult["success"] ?? false) !== true) {
                error_log(
                    "Data provider returned a non-success result for order " .
                    $orderReference . "."
                );
            }
        } else {
            throw new Exception("No integration is configured for this provider.");
        }
    } catch (Throwable $providerError) {
        /*
        | Do not retry or refund blindly. The existing provider processor /
        | reconciliation flow handles confirmed vs uncertain supplier results.
        */
        error_log("Data provider processing failed for order " . $orderReference . ".");
    }

    header("Location: data_order.php?ref=" . urlencode($orderReference));
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("Servora data purchase failed.");
    header("Location: buy_data.php?error=processing");
    exit;
}
