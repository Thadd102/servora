<?php

/**
 * ProviderBalanceService - Resilient Multi-Provider Wallet & Balance Query Engine
 *
 * Responsibilities:
 * - Queries real-time supplier account balances for:
 *   1. CheapDataHub (Airtime, Data, Cable & Electricity)
 *   2. 5SIM (Foreign Virtual Phone Numbers)
 *   3. Termii (Bulk SMS Gateway)
 *
 * Security & Reliability Directives:
 * - Provider credentials and internal keys are processed STRICTLY server-side.
 *   They are never returned to client JavaScript or exposed in DOM markup.
 * - Complete Provider Fault Isolation: A failure, timeout, or rate-limit from
 *   one provider will NEVER block or degrade the retrieval of other provider balances.
 * - Extensibility: New providers can be added seamlessly by implementing a dedicated
 *   method and registering it in the $providers manifest.
 */
class ProviderBalanceService
{
    private string $cheapDataHubApiKey;
    private string $cheapDataHubBaseUrl;
    private string $smeApiKey;
    private string $smeApiBaseUrl;
    private string $fiveSimApiKey;
    private string $fiveSimBaseUrl;
    private string $termiiApiKey;
    private string $termiiBaseUrl;

    public function __construct()
    {
        require_once __DIR__ . '/../config/env.php';

        // CheapDataHub
        $this->cheapDataHubApiKey = trim((string)(subnextEnv('CHEAPDATAHUB_API_KEY') ?: getenv('CHEAPDATAHUB_API_KEY') ?: ($_ENV['CHEAPDATAHUB_API_KEY'] ?? '')));
        $this->cheapDataHubBaseUrl = rtrim((string)(subnextEnv('CHEAPDATAHUB_BASE_URL') ?: getenv('CHEAPDATAHUB_BASE_URL') ?: ($_ENV['CHEAPDATAHUB_BASE_URL'] ?? 'https://www.cheapdatahub.ng/api/v1/resellers')), '/');

        // SMEAPI (Data Subscription Provider Only)
        $this->smeApiKey = trim((string)(subnextEnv('SMEAPI_API_KEY') ?: getenv('SMEAPI_API_KEY') ?: ($_ENV['SMEAPI_API_KEY'] ?? '')));
        if ($this->smeApiKey === '') {
            try {
                if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
                    $stmt = $GLOBALS['pdo']->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'smeapi_api_key' LIMIT 1");
                    $stmt->execute();
                    $val = $stmt->fetchColumn();
                    if ($val !== false) {
                        $this->smeApiKey = trim((string)$val);
                    }
                }
            } catch (\Throwable) {}
        } else {
            // Sync to app_settings if database value is currently empty
            try {
                if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
                    $checkStmt = $GLOBALS['pdo']->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'smeapi_api_key' LIMIT 1");
                    $checkStmt->execute();
                    $dbVal = $checkStmt->fetchColumn();
                    if ($dbVal === false) {
                        $insStmt = $GLOBALS['pdo']->prepare("INSERT INTO app_settings (setting_key, setting_value, setting_group, description) VALUES ('smeapi_api_key', ?, 'api', 'SMEAPI API Key for Data Subscriptions')");
                        $insStmt->execute([$this->smeApiKey]);
                    } elseif (trim((string)$dbVal) === '') {
                        $upStmt = $GLOBALS['pdo']->prepare("UPDATE app_settings SET setting_value = ? WHERE setting_key = 'smeapi_api_key'");
                        $upStmt->execute([$this->smeApiKey]);
                    }
                }
            } catch (\Throwable) {}
        }
        $this->smeApiBaseUrl = rtrim((string)(subnextEnv('SMEAPI_BASE_URL') ?: getenv('SMEAPI_BASE_URL') ?: ($_ENV['SMEAPI_BASE_URL'] ?? 'https://smeapi.com.ng')), '/');

        // 5SIM
        $this->fiveSimApiKey = trim((string)(subnextEnv('FIVESIM_API_KEY') ?: getenv('FIVESIM_API_KEY') ?: ($_ENV['FIVESIM_API_KEY'] ?? '')));
        $this->fiveSimBaseUrl = rtrim((string)(subnextEnv('FIVESIM_BASE_URL') ?: getenv('FIVESIM_BASE_URL') ?: ($_ENV['FIVESIM_BASE_URL'] ?? 'https://5sim.net/v1')), '/');

        // Termii
        $this->termiiApiKey = trim((string)(subnextEnv('TERMII_API_KEY') ?: getenv('TERMII_API_KEY') ?: ($_ENV['TERMII_API_KEY'] ?? '')));
        $this->termiiBaseUrl = rtrim((string)(subnextEnv('TERMII_BASE_URL') ?: getenv('TERMII_BASE_URL') ?: ($_ENV['TERMII_BASE_URL'] ?? 'https://api.ng.termii.com/api')), '/');
    }

    /**
     * Return instantly cached balances if available, or lightweight placeholders
     * to eliminate render-blocking HTTP latency on initial dashboard load.
     */
    public function getCachedOrPlaceholderBalances(): array
    {
        $cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'subnext_provider_balances.json';
        if (file_exists($cacheFile)) {
            $cached = @json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($cached) && !empty($cached)) {
                return $cached;
            }
        }

        return [
            'cheapdatahub' => $this->buildPlaceholderPayload('cheapdatahub', 'CheapDataHub', 'Airtime, Data, Cable & Electricity', '₦', 'NGN'),
            'smeapi' => $this->buildPlaceholderPayload('smeapi', 'SMEAPI', 'Data Subscriptions Only', '₦', 'NGN'),
            'fivesim' => $this->buildPlaceholderPayload('fivesim', '5SIM', 'Foreign Virtual Numbers', '$', 'USD'),
            'termii' => $this->buildPlaceholderPayload('termii', 'Termii', 'Bulk SMS Gateway', '₦', 'NGN'),
        ];
    }

    /**
     * Query a single provider balance independently.
     * Allows asynchronous per-provider AJAX without blocking other providers.
     */
    public function getBalanceForProvider(string $providerKey, bool $forceRefresh = false): ?array
    {
        $providerKey = strtolower(trim($providerKey));
        $cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'subnext_balance_' . $providerKey . '.json';
        $cacheTtl = 120; // 2 minutes

        if (!$forceRefresh && file_exists($cacheFile)) {
            $mtime = @filemtime($cacheFile);
            if ($mtime !== false && (time() - $mtime) < $cacheTtl) {
                $cached = @json_decode((string)@file_get_contents($cacheFile), true);
                if (is_array($cached) && !empty($cached) && ($cached['status'] ?? '') !== 'unconfigured') {
                    return $cached;
                }
            }
        }

        $result = match ($providerKey) {
            'cheapdatahub' => $this->getCheapDataHubBalance(),
            'smeapi' => $this->getSmeApiBalance(),
            'fivesim' => $this->getFiveSimBalance(),
            'termii' => $this->getTermiiBalance(),
            default => null,
        };

        if ($result !== null && ($result['status'] ?? '') !== 'unconfigured') {
            @file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_SLASHES));
        } elseif (file_exists($cacheFile) && ($result['status'] ?? '') === 'unconfigured') {
            @unlink($cacheFile);
        }

        return $result;
    }

    /**
     * Retrieve live balances across all registered upstream providers.
     *
     * Every provider is queried independently inside isolated error handlers.
     * Failure of one API will not prevent subsequent providers from loading.
     *
     * @return array<string, array> Keyed list of normalized provider balance payloads.
     */
    public function getAllBalances(bool $forceRefresh = false): array
    {
        $balances = [];
        $providers = ['cheapdatahub', 'smeapi', 'fivesim', 'termii'];

        foreach ($providers as $pKey) {
            try {
                $balances[$pKey] = $this->getBalanceForProvider($pKey, $forceRefresh);
            } catch (\Throwable $e) {
                $balances[$pKey] = $this->buildErrorPayload(
                    $pKey,
                    ucfirst($pKey),
                    'Service',
                    '₦',
                    'NGN',
                    'Exception: ' . $e->getMessage()
                );
            }
        }

        return $balances;
    }

    /**
     * Query CheapDataHub reseller wallet balance
     */
    public function getCheapDataHubBalance(): array
    {
        $providerKey = 'cheapdatahub';
        $providerName = 'CheapDataHub';
        $serviceName = 'Airtime, Data, Cable & Electricity';
        $currencySymbol = '₦';
        $currencyCode = 'NGN';

        if (empty($this->cheapDataHubApiKey)) {
            return $this->buildUnconfiguredPayload($providerKey, $providerName, $serviceName, $currencySymbol, $currencyCode);
        }

        $res = $this->executeHttpRequest(
            $this->cheapDataHubBaseUrl . '/wallet/balance/',
            'GET',
            [
                "Authorization: Bearer " . $this->cheapDataHubApiKey,
                "Accept: application/json"
            ]
        );

        if (!$res['ok'] || !is_array($res['data'])) {
            return $this->buildErrorPayload(
                $providerKey,
                $providerName,
                $serviceName,
                $currencySymbol,
                $currencyCode,
                $res['message'] ?: 'Failed to connect to CheapDataHub'
            );
        }

        // Response format: {"status":"true","data":{"balance":101}} or {"balance":101}
        $rawBalance = $res['data']['data']['balance'] ?? ($res['data']['balance'] ?? null);

        if ($rawBalance === null) {
            return $this->buildErrorPayload(
                $providerKey,
                $providerName,
                $serviceName,
                $currencySymbol,
                $currencyCode,
                'Unexpected response format from supplier'
            );
        }

        $balanceFloat = (float)$rawBalance;

        return [
            'provider' => $providerKey,
            'name' => $providerName,
            'service' => $serviceName,
            'status' => 'connected',
            'is_configured' => true,
            'balance' => $balanceFloat,
            'currency' => $currencyCode,
            'symbol' => $currencySymbol,
            'formatted' => $currencySymbol . number_format($balanceFloat, 2),
            'environment' => 'Live',
            'message' => 'Wallet balance retrieved successfully',
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Query SMEAPI user wallet balance (Strictly Data Subscriptions Only)
     * Endpoint: GET https://smeapi.com.ng/api/user/
     */
    public function getSmeApiBalance(): array
    {
        $providerKey = 'smeapi';
        $providerName = 'SMEAPI';
        $serviceName = 'Data Subscriptions Only';
        $currencySymbol = '₦';
        $currencyCode = 'NGN';

        if (empty($this->smeApiKey)) {
            return $this->buildUnconfiguredPayload($providerKey, $providerName, $serviceName, $currencySymbol, $currencyCode);
        }

        require_once __DIR__ . '/SmeApiClient.php';
        $client = new SmeApiClient($this->smeApiKey, $this->smeApiBaseUrl);
        $res = $client->getBalance();

        if (!$res['ok'] || $res['balance'] === null) {
            return $this->buildErrorPayload(
                $providerKey,
                $providerName,
                $serviceName,
                $currencySymbol,
                $currencyCode,
                $res['message'] ?: 'Failed to retrieve balance from SMEAPI'
            );
        }

        $balanceFloat = (float)$res['balance'];
        $extraInfo = $res['username'] ? 'Account: ' . $res['username'] : 'Data Subscriptions';

        return [
            'provider' => $providerKey,
            'name' => $providerName,
            'service' => $serviceName,
            'status' => 'connected',
            'is_configured' => true,
            'balance' => $balanceFloat,
            'currency' => $currencyCode,
            'symbol' => $currencySymbol,
            'formatted' => $currencySymbol . number_format($balanceFloat, 2),
            'environment' => 'Live',
            'extra_info' => $extraInfo,
            'message' => 'SMEAPI balance retrieved successfully',
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Query 5SIM profile balance (Foreign Virtual Numbers)
     */
    public function getFiveSimBalance(): array
    {
        $providerKey = 'fivesim';
        $providerName = '5SIM';
        $serviceName = 'Foreign Virtual Numbers';
        $currencySymbol = '$';
        $currencyCode = 'USD';

        if (empty($this->fiveSimApiKey)) {
            return $this->buildUnconfiguredPayload($providerKey, $providerName, $serviceName, $currencySymbol, $currencyCode);
        }

        $res = $this->executeHttpRequest(
            $this->fiveSimBaseUrl . '/user/profile',
            'GET',
            [
                "Authorization: Bearer " . $this->fiveSimApiKey,
                "Accept: application/json"
            ]
        );

        if (!$res['ok'] || !is_array($res['data'])) {
            return $this->buildErrorPayload(
                $providerKey,
                $providerName,
                $serviceName,
                $currencySymbol,
                $currencyCode,
                $res['message'] ?: 'Failed to connect to 5SIM API'
            );
        }

        // Response format: {"id":4437245,"email":"...","balance":0.5304,"rating":96}
        $rawBalance = $res['data']['balance'] ?? null;

        if ($rawBalance === null) {
            return $this->buildErrorPayload(
                $providerKey,
                $providerName,
                $serviceName,
                $currencySymbol,
                $currencyCode,
                'Unexpected response payload from 5SIM'
            );
        }

        $balanceFloat = (float)$rawBalance;
        // Approximate NGN conversion for admin quick assessment (~1,600 NGN per USD)
        $ngnEstimate = number_format($balanceFloat * 1600.00, 2);

        return [
            'provider' => $providerKey,
            'name' => $providerName,
            'service' => $serviceName,
            'status' => 'connected',
            'is_configured' => true,
            'balance' => $balanceFloat,
            'currency' => $currencyCode,
            'symbol' => $currencySymbol,
            'formatted' => $currencySymbol . number_format($balanceFloat, 4) . " (~₦{$ngnEstimate})",
            'environment' => 'Live',
            'extra_info' => 'Email: ' . ($res['data']['email'] ?? 'Active Account') . ' | Rating: ' . ($res['data']['rating'] ?? 'N/A'),
            'message' => '5SIM profile balance retrieved successfully',
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Query Termii bulk SMS account balance & credits
     */
    public function getTermiiBalance(): array
    {
        $providerKey = 'termii';
        $providerName = 'Termii';
        $serviceName = 'Bulk SMS Gateway';
        $currencySymbol = '₦';
        $currencyCode = 'NGN';

        if (empty($this->termiiApiKey)) {
            return $this->buildUnconfiguredPayload($providerKey, $providerName, $serviceName, $currencySymbol, $currencyCode);
        }

        $url = $this->termiiBaseUrl . '/get-balance?api_key=' . urlencode($this->termiiApiKey);
        $res = $this->executeHttpRequest($url, 'GET', ["Accept: application/json"], 8);

        if (!$res['ok'] || !is_array($res['data'])) {
            return $this->buildErrorPayload(
                $providerKey,
                $providerName,
                $serviceName,
                $currencySymbol,
                $currencyCode,
                $res['message'] ?: 'Failed to retrieve balance from Termii'
            );
        }

        // Response format: {"balance":30.0000,"currency":"NGN","application":"servora","user":"servora"}
        $rawBalance = $res['data']['balance'] ?? null;

        if ($rawBalance === null) {
            return $this->buildErrorPayload(
                $providerKey,
                $providerName,
                $serviceName,
                $currencySymbol,
                $currencyCode,
                'Unexpected response structure from Termii'
            );
        }

        $balanceFloat = (float)$rawBalance;
        $curr = $res['data']['currency'] ?? 'NGN';

        return [
            'provider' => $providerKey,
            'name' => $providerName,
            'service' => $serviceName,
            'status' => 'connected',
            'is_configured' => true,
            'balance' => $balanceFloat,
            'currency' => $curr,
            'symbol' => $curr === 'NGN' ? '₦' : $curr,
            'formatted' => ($curr === 'NGN' ? '₦' : '') . number_format($balanceFloat, 2) . ($curr !== 'NGN' ? " $curr" : ''),
            'environment' => 'Live',
            'extra_info' => 'Application: ' . ($res['data']['application'] ?? 'Subnext'),
            'message' => 'Termii balance retrieved successfully',
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Standardized placeholder payload while async fetch loads
     */
    private function buildPlaceholderPayload(string $key, string $name, string $service, string $symbol, string $currency): array
    {
        return [
            'provider' => $key,
            'name' => $name,
            'service' => $service,
            'status' => 'loading',
            'is_configured' => true,
            'balance' => 0.00,
            'currency' => $currency,
            'symbol' => $symbol,
            'formatted' => 'Loading...',
            'environment' => 'Syncing',
            'message' => 'Syncing live balance in background...',
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Standardized payload for unconfigured provider
     */
    private function buildUnconfiguredPayload(string $key, string $name, string $service, string $symbol, string $currency): array
    {
        return [
            'provider' => $key,
            'name' => $name,
            'service' => $service,
            'status' => 'unconfigured',
            'is_configured' => false,
            'balance' => 0.00,
            'currency' => $currency,
            'symbol' => $symbol,
            'formatted' => 'Not configured',
            'environment' => 'Not configured',
            'message' => 'API credentials are empty in .env',
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Standardized payload for an error from a provider without failing other providers
     */
    private function buildErrorPayload(string $key, string $name, string $service, string $symbol, string $currency, string $errorMsg): array
    {
        return [
            'provider' => $key,
            'name' => $name,
            'service' => $service,
            'status' => 'error',
            'is_configured' => true,
            'balance' => 0.00,
            'currency' => $currency,
            'symbol' => $symbol,
            'formatted' => 'Unable to fetch',
            'environment' => 'Unable to fetch',
            'message' => $errorMsg,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Safe internal HTTP requester with strict timeouts
     * Never leaks Authorization headers or credentials to exception messages.
     */
    private function executeHttpRequest(string $url, string $method = 'GET', array $headers = [], int $timeout = 5): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($res === false) {
            return [
                'ok' => false,
                'http_status' => $code,
                'message' => $err ?: 'Network timeout connecting to provider',
                'data' => null
            ];
        }

        $decoded = json_decode($res, true);
        return [
            'ok' => ($code >= 200 && $code < 300),
            'http_status' => $code,
            'data' => is_array($decoded) ? $decoded : $res,
            'message' => $code >= 200 && $code < 300 ? 'Success' : 'HTTP ' . $code
        ];
    }
}
