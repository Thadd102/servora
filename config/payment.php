<?php

require_once __DIR__ . '/env.php';

$paystackSecretKey = getenv('PAYSTACK_SECRET_KEY') ?: ($_ENV['PAYSTACK_SECRET_KEY'] ?? ($_SERVER['PAYSTACK_SECRET_KEY'] ?? ''));
$paystackPublicKey = getenv('PAYSTACK_PUBLIC_KEY') ?: ($_ENV['PAYSTACK_PUBLIC_KEY'] ?? ($_SERVER['PAYSTACK_PUBLIC_KEY'] ?? ''));

$paystackBaseUrl = 'https://api.paystack.co';

// Determine default public base URL
$defaultAppUrl = 'https://subnext.com.ng';
if (!empty($_SERVER['HTTP_HOST'])) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    $scheme = $isHttps ? 'https' : 'http';
    $defaultAppUrl = $scheme . '://' . $_SERVER['HTTP_HOST'];
}

$appUrl = rtrim(
    getenv('APP_URL') ?: ($_ENV['APP_URL'] ?? ($_SERVER['APP_URL'] ?? $defaultAppUrl)),
    '/'
);