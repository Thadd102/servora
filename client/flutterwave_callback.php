<?php

require_once "../config/database.php";
require_once "../config/flutterwave.php";
require_once "../includes/client_auth.php";
require_once "../includes/functions.php";

$userId = currentUserId();

$status = strtolower(trim($_GET["status"] ?? ""));
$txRef = trim($_GET["tx_ref"] ?? "");


/* =========================================================
   HANDLE CANCELLED OR FAILED PAYMENT FIRST
========================================================= */

if (in_array($status, ["cancelled", "canceled", "failed"], true)) {

    if ($txRef !== "") {

        $stmt = $pdo->prepare("
            UPDATE wallet_transactions
            SET status = 'failed'
            WHERE reference = ?
              AND user_id = ?
              AND type = 'funding'
              AND status = 'pending'
        ");

        $stmt->execute([
            $txRef,
            $userId
        ]);
    }

    if ($status === "failed") {

        header("Location: wallet.php?payment=failed");

    } else {

        header("Location: wallet.php?payment=cancelled");
    }

    exit;
}


/* =========================================================
   ONLY CONTINUE FOR SUCCESSFUL PAYMENTS
========================================================= */

if ($status !== "successful") {

    header("Location: wallet.php?payment=invalid");
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

if (
    !$transactionId ||
    $txRef === "" ||
    !preg_match('/^[A-Za-z0-9._=-]{8,100}$/', $txRef)
) {

    header("Location: wallet.php?payment=invalid");
    exit;
}


/* =========================================================
   MAKE SURE THIS TRANSACTION BELONGS TO THE USER
========================================================= */

$stmt = $pdo->prepare("
    SELECT id
    FROM wallet_transactions
    WHERE reference = ?
      AND user_id = ?
      AND type = 'funding'
    LIMIT 1
");

$stmt->execute([
    $txRef,
    $userId
]);

$transaction = $stmt->fetch();

if (!$transaction) {

    http_response_code(403);

    exit("Invalid payment reference.");
}


/* =========================================================
   CHECK FLUTTERWAVE CONFIGURATION
========================================================= */

if (
    !defined("FLW_SECRET_KEY") ||
    empty(FLW_SECRET_KEY)
) {

    exit("Flutterwave is not configured yet.");
}


/* =========================================================
   VERIFY PAYMENT DIRECTLY WITH FLUTTERWAVE
========================================================= */

$verificationUrl =
    FLW_BASE_URL .
    "/transactions/" .
    rawurlencode((string) $transactionId) .
    "/verify";


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

$httpCode = (int) curl_getinfo(
    $ch,
    CURLINFO_HTTP_CODE
);

$curlError = curl_error($ch);

curl_close($ch);


/* =========================================================
   HANDLE VERIFICATION CONNECTION ERROR
========================================================= */

if (
    $response === false ||
    $curlError !== "" ||
    $httpCode < 200 ||
    $httpCode >= 300
) {

    header(
        "Location: wallet.php?payment=verification_error"
    );

    exit;
}


/* =========================================================
   READ FLUTTERWAVE RESPONSE
========================================================= */

$result = json_decode(
    $response,
    true
);

$payment = is_array($result)
    ? ($result["data"] ?? [])
    : [];


/* =========================================================
   VALIDATE PAYMENT
========================================================= */

if (
    ($result["status"] ?? "") !== "success" ||
    ($payment["status"] ?? "") !== "successful" ||
    ($payment["currency"] ?? "") !== "NGN" ||
    !hash_equals(
        $txRef,
        (string) ($payment["tx_ref"] ?? "")
    )
) {

    header(
        "Location: wallet.php?payment=invalid"
    );

    exit;
}


$amount = (float) ($payment["amount"] ?? 0);

$email = (string) (
    $payment["customer"]["email"] ?? ""
);


if ($amount <= 0 || $email === "") {

    header(
        "Location: wallet.php?payment=invalid"
    );

    exit;
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

    header(
        "Location: wallet.php?payment=success"
    );

    exit;

} catch (Throwable $e) {

    error_log(
        "Flutterwave callback error: " .
        $e->getMessage()
    );

    header(
        "Location: wallet.php?payment=processing_error"
    );

    exit;
}