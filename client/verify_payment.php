<?php
require_once "../config/database.php";
require_once "../includes/client_auth.php";
require_once "../includes/functions.php";
require_once "../config/payment.php";

$userId = currentUserId();
$reference = trim($_GET['reference'] ?? $_GET['trxref'] ?? '');

if ($reference === '' || !preg_match('/^[A-Za-z0-9.=-]{8,100}$/', $reference)) {
    header('Location: wallet.php?payment=invalid');
    exit;
}

// The reference must have been created for this logged-in user before we contact Paystack.
$stmt = $pdo->prepare("SELECT id FROM wallet_transactions WHERE reference = ? AND user_id = ? AND type = 'funding' LIMIT 1");
$stmt->execute([$reference, $userId]);
if (!$stmt->fetch()) {
    http_response_code(403);
    exit('Invalid payment reference.');
}

if ($paystackSecretKey === 'PASTE_YOUR_PAYSTACK_SECRET_KEY_HERE') {
    exit('Paystack is not configured yet.');
}

$ch = curl_init($paystackBaseUrl . '/transaction/verify/' . rawurlencode($reference));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $paystackSecretKey, 'Accept: application/json'],
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 20,
]);
$response = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false || $curlError !== '' || $httpCode !== 200) {
    header('Location: wallet.php?payment=verification_error');
    exit;
}

$result = json_decode($response, true);
$payment = is_array($result) ? ($result['data'] ?? []) : [];
$status = $payment['status'] ?? '';
$verifiedReference = (string) ($payment['reference'] ?? '');
$currency = (string) ($payment['currency'] ?? '');
$amountKobo = (int) ($payment['amount'] ?? 0);
$email = (string) ($payment['customer']['email'] ?? '');

if (($result['status'] ?? false) !== true || !hash_equals($reference, $verifiedReference)) {
    header('Location: wallet.php?payment=invalid');
    exit;
}

if ($status !== 'success') {
    header('Location: wallet.php?payment=' . rawurlencode($status ?: 'not_successful'));
    exit;
}

if ($currency !== 'NGN' || $amountKobo <= 0 || $email === '') {
    header('Location: wallet.php?payment=invalid');
    exit;
}

try {
    processWalletFunding($pdo, $reference, $amountKobo / 100, $email);
    header('Location: wallet.php?payment=success');
    exit;
} catch (Throwable $e) {
    error_log('Paystack callback processing failed: ' . $e->getMessage());
    header('Location: wallet.php?payment=processing_error');
    exit;
}
