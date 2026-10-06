<?php

/**
 * Flutterwave configuration
 *
 * API credentials are loaded from the .env file.
 * Never place real secret keys directly in this file.
 */

require_once __DIR__ . '/env.php';

$flwPublicKey = getenv('FLW_PUBLIC_KEY') ?: ($_ENV['FLW_PUBLIC_KEY'] ?? ($_SERVER['FLW_PUBLIC_KEY'] ?? (getenv('FLUTTERWAVE_PUBLIC_KEY') ?: ($_ENV['FLUTTERWAVE_PUBLIC_KEY'] ?? ''))));
$flwSecretKey = getenv('FLW_SECRET_KEY') ?: ($_ENV['FLW_SECRET_KEY'] ?? ($_SERVER['FLW_SECRET_KEY'] ?? ''));
$flwSecretHash = getenv('FLW_SECRET_HASH') ?: ($_ENV['FLW_SECRET_HASH'] ?? ($_SERVER['FLW_SECRET_HASH'] ?? ''));

define('FLW_PUBLIC_KEY', (string)$flwPublicKey);
define('FLW_SECRET_KEY', (string)$flwSecretKey);
define('FLW_SECRET_HASH', (string)$flwSecretHash);
define('FLW_BASE_URL', 'https://api.flutterwave.com/v3');