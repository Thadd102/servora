<?php

require_once "../config/database.php";
require_once "../includes/functions.php";
require_once "../config/payment.php";


/*
|--------------------------------------------------------------------------
| Paystack Webhook
|--------------------------------------------------------------------------
|
| Paystack sends payment events to this file.
|
| The user does NOT need to be logged in because
| Paystack's server is calling this endpoint.
|
*/


/*
|--------------------------------------------------------------------------
| 1. Get the raw request body
|--------------------------------------------------------------------------
*/

if ($paystackSecretKey === 'PASTE_YOUR_PAYSTACK_SECRET_KEY_HERE') {
    http_response_code(503);
    exit('Payment provider is not configured.');
}

$input = file_get_contents("php://input");

if ($input === false || $input === "") {

    http_response_code(400);
    exit("Invalid request.");

}


/*
|--------------------------------------------------------------------------
| 2. Get Paystack signature
|--------------------------------------------------------------------------
*/

$signature = $_SERVER["HTTP_X_PAYSTACK_SIGNATURE"] ?? "";

if ($signature === "") {

    http_response_code(401);
    exit("Missing signature.");

}


/*
|--------------------------------------------------------------------------
| 3. Verify Paystack signature
|--------------------------------------------------------------------------
|
| This confirms that the request was signed using
| our Paystack secret key.
|
*/

$expectedSignature = hash_hmac(
    "sha512",
    $input,
    $paystackSecretKey
);


/*
|--------------------------------------------------------------------------
| 4. Compare signatures securely
|--------------------------------------------------------------------------
*/

if (!hash_equals($expectedSignature, $signature)) {

    http_response_code(401);
    exit("Invalid signature.");

}


/*
|--------------------------------------------------------------------------
| 5. Decode Paystack's JSON
|--------------------------------------------------------------------------
*/

$data = json_decode($input, true);

if (!is_array($data)) {

    http_response_code(400);
    exit("Invalid JSON.");

}


/*
|--------------------------------------------------------------------------
| 6. Get the event type
|--------------------------------------------------------------------------
*/

$event = $data["event"] ?? "";


/*
|--------------------------------------------------------------------------
| 7. We only process successful charges
|--------------------------------------------------------------------------
*/

if ($event !== "charge.success") {

    /*
     * Other events are not needed for wallet funding.
     */
    http_response_code(200);
    exit("Event ignored.");

}


/*
|--------------------------------------------------------------------------
| 8. Get payment information
|--------------------------------------------------------------------------
*/

$payment = $data["data"] ?? [];

$reference = trim(
    $payment["reference"] ?? ""
);

$paymentStatus = $payment["status"] ?? "";

$paidAmountInKobo = (int) (
    $payment["amount"] ?? 0
);

$paidEmail = $payment["customer"]["email"] ?? "";
$currency = $payment["currency"] ?? "";


/*
|--------------------------------------------------------------------------
| 9. Validate payment information
|--------------------------------------------------------------------------
*/

if (
    $reference === "" ||
    $paymentStatus !== "success" ||
    $paidAmountInKobo <= 0 ||
    $paidEmail === "" ||
    $currency !== "NGN"
) {

    http_response_code(400);
    exit("Invalid payment data.");

}


/*
|--------------------------------------------------------------------------
| 10. Convert kobo to naira
|--------------------------------------------------------------------------
*/

$paidAmount = $paidAmountInKobo / 100;


/*
|--------------------------------------------------------------------------
| 11. Process the wallet funding
|--------------------------------------------------------------------------
|
| This function is located in:
|
| includes/functions.php
|
| It handles:
|
|   - finding the transaction
|   - checking the amount
|   - checking the email
|   - locking the wallet
|   - adding the money
|   - marking the transaction successful
|   - preventing duplicate credits
|
*/

try {

    processWalletFunding(
        $pdo,
        $reference,
        $paidAmount,
        $paidEmail
    );


    /*
     * Tell Paystack that we successfully received
     * and processed the webhook.
     */

    http_response_code(200);

    exit("Payment processed successfully.");


} catch (Throwable $e) {

    /*
     * Do not expose internal database errors.
     */

    http_response_code(500);

    exit("Unable to process payment.");

}