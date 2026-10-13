<?php

require_once "../config/database.php";
require_once "../config/flutterwave.php";
require_once "../includes/client_auth.php";
require_once "../includes/functions.php";

$userId = currentUserId();

$status = strtolower(trim($_GET["status"] ?? ""));
$txRef = trim($_GET["tx_ref"] ?? ($_GET["txref"] ?? ""));

/* =========================================================
   HANDLE CANCELLED OR FAILED PAYMENT FIRST
========================================================= */

if (in_array($status, ["cancelled", "canceled", "failed"], true)) {
    if ($txRef !== "") {
        $stmt = $pdo->prepare("
            UPDATE wallet_transactions
            SET status = 'failed'
            WHERE reference = ?
              AND type = 'funding'
              AND status = 'pending'
        ");
        $stmt->execute([$txRef]);
    }

    if ($status === "failed") {
        header("Location: wallet.php?payment=failed");
    } else {
        header("Location: wallet.php?payment=cancelled");
    }
    exit;
}

/* =========================================================
   GET TRANSACTION INFORMATION
========================================================= */

$transactionId = filter_input(
    INPUT_GET,
    "transaction_id",
    FILTER_VALIDATE_INT
);

if ($txRef === "" || !preg_match('/^[A-Za-z0-9._=-]{8,100}$/', $txRef)) {
    header("Location: wallet.php?payment=invalid");
    exit;
}

/* =========================================================
   LOOKUP TRANSACTION IN DATABASE
========================================================= */

$stmt = $pdo->prepare("
    SELECT id, user_id, status, amount
    FROM wallet_transactions
    WHERE reference = ?
      AND type = 'funding'
    LIMIT 1
");
$stmt->execute([$txRef]);
$transaction = $stmt->fetch();

if (!$transaction) {
    http_response_code(403);
    exit("Invalid payment reference.");
}

// If already marked successful, return to wallet with success message immediately (Idempotent)
if (($transaction["status"] ?? "") === "successful") {
    header("Location: wallet.php?payment=success");
    exit;
}

/* =========================================================
   CHECK FLUTTERWAVE CONFIGURATION
========================================================= */

if (!defined("FLW_SECRET_KEY") || empty(FLW_SECRET_KEY)) {
    exit("Flutterwave is not configured yet.");
}

/* =========================================================
   VERIFY PAYMENT DIRECTLY WITH FLUTTERWAVE
========================================================= */

if ($transactionId && $transactionId > 0) {
    $verificationUrl = FLW_BASE_URL . "/transactions/" . rawurlencode((string)$transactionId) . "/verify";
} else {
    $verificationUrl = FLW_BASE_URL . "/transactions?tx_ref=" . rawurlencode($txRef);
}

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
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false || $curlError !== "" || $httpCode < 200 || $httpCode >= 300) {
    header("Location: wallet.php?payment=verification_error");
    exit;
}

/* =========================================================
   READ FLUTTERWAVE RESPONSE
========================================================= */

$result = json_decode($response, true);
$paymentData = is_array($result) ? ($result["data"] ?? []) : [];

// If response is a list array from tx_ref query
if (isset($paymentData[0]) && is_array($paymentData[0])) {
    $paymentData = $paymentData[0];
}

/* =========================================================
   VALIDATE PAYMENT
========================================================= */

$respStatus = strtolower((string)($result["status"] ?? ""));
$payStatus  = strtolower((string)($paymentData["status"] ?? ""));
$currency   = strtoupper((string)($paymentData["currency"] ?? "NGN"));
$returnedRef= (string)($paymentData["tx_ref"] ?? "");

if (
    $respStatus !== "success" ||
    $payStatus !== "successful" ||
    $currency !== "NGN" ||
    !hash_equals($txRef, $returnedRef)
) {
    header("Location: wallet.php?payment=invalid");
    exit;
}

$amount = (float)($paymentData["amount"] ?? $transaction["amount"]);
$email  = (string)($paymentData["customer"]["email"] ?? "");

if ($amount <= 0) {
    $amount = (float)$transaction["amount"];
}

/* =========================================================
   CREDIT WALLET
========================================================= */

try {
    processWalletFunding(
        $pdo,
        $txRef,
        $amount,
        $email,
        "Flutterwave"
    );

    header("Location: wallet.php?payment=success");
    exit;

} catch (Throwable $e) {
    error_log("Flutterwave callback error: " . $e->getMessage());
    header("Location: wallet.php?payment=processing_error");
    exit;
}