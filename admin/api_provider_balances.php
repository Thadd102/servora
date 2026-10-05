<?php

/**
 * api_provider_balances.php - Secure Server-Side Endpoint for Live Provider Balances
 *
 * Directives:
 * - Admin Authentication Required (HTTP 401 if unauthenticated)
 * - Returns clean JSON payload without leaking any API keys or private tokens
 * - Independent fault tolerance: Errors in one provider are returned within that
 *   specific provider object without failing the entire API response.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/ProviderBalanceService.php';

try {
    $forceRefresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';
    $balances = $service->getAllBalances($forceRefresh);

    echo json_encode([
        'ok' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'human_time' => date('h:i A'),
        'balances' => $balances
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'An error occurred while compiling provider balances.',
        'error' => $e->getMessage()
    ]);
}
