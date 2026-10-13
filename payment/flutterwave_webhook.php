<?php

require_once "../config/database.php";
require_once "../includes/functions.php";
require_once "../config/flutterwave.php";

/*
|--------------------------------------------------------------------------
| Flutterwave Webhook
|--------------------------------------------------------------------------
|
| Handles asynchronous server-to-server notifications from Flutterwave.
| Ensures wallet funding is credited even if user closes browser window.
|
*/

if (!defined("FLW_SECRET_KEY") || empty(FLW_SECRET_KEY)) {
    http_response_code(503);
    exit("Payment provider is not configured.");
}

/*
|--------------------------------------------------------------------------
| 1. Read Raw Input
|--------------------------------------------------------------------------
*/

$input = file_get_contents("php://input");
if ($input === false || trim($input) === "") {
    http_response_code(400);
    exit("Invalid request.");
}

/*
|--------------------------------------------------------------------------
| 2. Verify Signature Header
|--------------------------------------------------------------------------
*/

$signature = $_SERVER["HTTP_VERIF_HASH"] ?? ($_SERVER["HTTP_X_FLUTTERWAVE_SIGNATURE"] ?? "");
$expectedHash = FLW_SECRET_HASH !== "" ? FLW_SECRET_HASH : FLW_SECRET_KEY;

if (!empty($signature) && !empty($expectedHash)) {
    if (!hash_equals($expectedHash, $signature) && !hash_equals(FLW_SECRET_KEY, $signature)) {
        http_response_code(401);
        exit("Invalid signature.");
    }
}

/*
|--------------------------------------------------------------------------
| 3. Parse JSON Body
|--------------------------------------------------------------------------
*/

$data = json_decode($input, true);
if (!is_array($data)) {
    http_response_code(400);
    exit("Invalid JSON.");
}

$event = $data["event"] ?? "";
$payment = $data["data"] ?? [];
$txRef = trim((string)($payment["tx_ref"] ?? ""));
$transactionId = (int)($payment["id"] ?? 0);
$status = strtolower(trim((string)($payment["status"] ?? "")));

if ($txRef === "") {
    http_response_code(200);
    exit("No reference provided.");
}

/*
|--------------------------------------------------------------------------
| 4. Verify Directly with Flutterwave API
|--------------------------------------------------------------------------
*/

if ($transactionId > 0) {
    $verificationUrl = FLW_BASE_URL . "/transactions/" . rawurlencode((string)$transactionId) . "/verify";

    $ch = curl_init($verificationUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer " . FLW_SECRET_KEY,
            "Accept: application/json"
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
        $result = json_decode($response, true);
        if (is_array($result) && isset($result["data"]) && is_array($result["data"])) {
            $payment = $result["data"];
            $status = strtolower(trim((string)($payment["status"] ?? "")));
            $txRef = trim((string)($payment["tx_ref"] ?? $txRef));
        }
    }
}

if ($status !== "successful") {
    http_response_code(200);
    exit("Payment not successful.");
}

$amount = (float)($payment["amount"] ?? 0);
$email  = (string)($payment["customer"]["email"] ?? "");

/*
|--------------------------------------------------------------------------
| 5. Process Wallet Funding Atomically (Exactly Once)
|--------------------------------------------------------------------------
*/

try {
    processWalletFunding(
        $pdo,
        $txRef,
        $amount,
        $email,
        "Flutterwave Webhook"
    );

    http_response_code(200);
    exit("Wallet funded successfully via Flutterwave Webhook.");

} catch (Throwable $e) {
    error_log("Flutterwave webhook error: " . $e->getMessage());
    http_response_code(500);
    exit("Webhook processing error: " . $e->getMessage());
}
