
<?php

$paystackSecretKey = getenv('PAYSTACK_SECRET_KEY') ?: '';
$paystackPublicKey = getenv('PAYSTACK_PUBLIC_KEY') ?: '';

$paystackBaseUrl = 'https://api.paystack.co';

$appUrl = rtrim(
    getenv('APP_URL') ?: 'http://localhost/service-platform',
    '/'
);