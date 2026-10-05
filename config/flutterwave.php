<?php

/**
 * Flutterwave configuration
 *
 * API credentials are loaded from the .env file.
 * Never place real secret keys directly in this file.
 */

define(
    'FLW_PUBLIC_KEY',
    getenv('FLW_PUBLIC_KEY') ?: ''
);

define(
    'FLW_SECRET_KEY',
    getenv('FLW_SECRET_KEY') ?: ''
);

define(
    'FLW_SECRET_HASH',
    getenv('FLW_SECRET_HASH') ?: ''
);

define(
    'FLW_BASE_URL',
    'https://api.flutterwave.com/v3'
);