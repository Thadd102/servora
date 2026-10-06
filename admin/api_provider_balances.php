<?php

/**
 * api_provider_balances.php - Secure Server-Side Endpoint for Live Provider Balances
 *
 * Directives:
 * - Admin Authentication Required (HTTP 401/403 if unauthenticated)
 * - Returns clean JSON payload without leaking any API keys or private tokens
 * - Independent fault tolerance: Supports single provider queries via ?provider=
 *   or all providers simultaneously. Errors in one provider never fail another.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/ProviderBalanceService.php';

try {
    $service = new ProviderBalanceService();
    $forceRefresh = isset($_GET['refresh']) && ($_GET['refresh'] === '1' || $_GET['refresh'] === 'true');
    $providerKey = isset($_GET['provider']) ? strtolower(trim((string)$_GET['provider'])) : null;

    if ($providerKey !== null && $providerKey !== '') {
        $allowedProviders = ['cheapdatahub', 'fivesim', 'vtpass', 'termii'];
        if (!in_array($providerKey, $allowedProviders, true)) {
            http_response_code(400);
            echo json_encode([
                'ok' => false,
                'message' => 'Invalid provider specified.',
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }

        $balance = $service->getBalanceForProvider($providerKey, $forceRefresh);

        echo json_encode([
            'ok' => true,
            'provider' => $providerKey,
            'balance' => $balance,
            'timestamp' => date('Y-m-d H:i:s'),
            'human_time' => date('h:i A')
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $balances = $service->getAllBalances($forceRefresh);

    echo json_encode([
        'ok' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'human_time' => date('h:i A'),
        'balances' => $balances
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    http_response_code(500);
    error_log('API provider balances error: ' . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'message' => 'Unable to retrieve provider balances.',
        'error' => 'An error occurred while compiling provider balances.'
    ], JSON_UNESCAPED_SLASHES);
}
