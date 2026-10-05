<?php

/**
 * ProviderBalanceService - Resilient Multi-Provider Wallet & Balance Query Engine
 *
 * Responsibilities:
 * - Queries real-time supplier account balances for:
 *   1. CheapDataHub (Data & Airtime)
 *   2. 5SIM (Foreign Virtual Phone Numbers)
 *   3. VTpass (Electricity, Cable TV, Exam PINs)
 *   4. Termii (Bulk SMS Gateway)
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
    private string $fiveSimApiKey;
    private string $fiveSimBaseUrl;
    private string $vtpassApiKey;
    private string $vtpassPublicKey;
    private string $vtpassSecretKey;
    private string $vtpassBaseUrl;
    private string $termiiApiKey;
    private string $termiiBaseUrl;

    public function __construct()
    {
        require_once __DIR__ . '/../config/env.php';

        // CheapDataHub
        $this->cheapDataHubApiKey = trim((string)(getenv('CHEAPDATAHUB_API_KEY') ?: ($_ENV['CHEAPDATAHUB_API_KEY'] ?? '')));
        $this->cheapDataHubBaseUrl = rtrim((string)(getenv('CHEAPDATAHUB_BASE_URL') ?: ($_ENV['CHEAPDATAHUB_BASE_URL'] ?? 'https://www.cheapdatahub.ng/api/v1/resellers')), '/');

        // 5SIM
        $this->fiveSimApiKey = trim((string)(getenv('FIVESIM_API_KEY') ?: ($_ENV['FIVESIM_API_KEY'] ?? '')));
        $this->fiveSimBaseUrl = rtrim((string)(getenv('FIVESIM_BASE_URL') ?: ($_ENV['FIVESIM_BASE_URL'] ?? 'https://5sim.net/v1')), '/');

        // VTpass
        $this->vtpassApiKey = trim((string)(getenv('VTPASS_API_KEY') ?: ($_ENV['VTPASS_API_KEY'] ?? '')));
        $this->vtpassPublicKey = trim((string)(getenv('VTPASS_PUBLIC_KEY') ?: ($_ENV['VTPASS_PUBLIC_KEY'] ?? '')));
        $this->vtpassSecretKey = trim((string)(getenv('VTPASS_SECRET_KEY') ?: ($_ENV['VTPASS_SECRET_KEY'] ?? '')));
        $this->vtpassBaseUrl = rtrim((string)(getenv('VTPASS_BASE_URL') ?: ($_ENV['VTPASS_BASE_URL'] ?? 'https://api-service.vtpass.com/api')), '/');

        // Termii
        $this->termiiApiKey = trim((string)(getenv('TERMII_API_KEY') ?: ($_ENV['TERMII_API_KEY'] ?? '')));
        $this->termiiBaseUrl = rtrim((string)(getenv('TERMII_BASE_URL') ?: ($_ENV['TERMII_BASE_URL'] ?? 'https://api.ng.termii.com/api')), '/');
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
            'cheapdatahub' => $this->buildPlaceholderPayload('cheapdatahub', 'CheapDataHub', 'Data & Airtime', '₦', 'NGN'),
            'fivesim' => $this->buildPlaceholderPayload('fivesim', '5SIM', 'Foreign Virtual Numbers', '$', 'USD'),
            'vtpass' => $this->buildPlaceholderPayload('vtpass', 'VTpass', 'Data, Utilities & Exams', '₦', 'NGN'),
            'termii' => $this->buildPlaceholderPayload('termii', 'Termii', 'Bulk SMS Gateway', '₦', 'NGN'),
        ];
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
        $cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'subnext_provider_balances.json';
        $cacheTtl = 180; // 3 minutes

        if (!$forceRefresh && file_exists($cacheFile)) {
            $mtime = @filemtime($cacheFile);
            if ($mtime !== false && (time() - $mtime) < $cacheTtl) {
                $cached = @json_decode((string)@file_get_contents($cacheFile), true);
                if (is_array($cached) && !empty($cached)) {
                    return $cached;
                }
            }
        }

        $balances = [];

        // 1. CheapDataHub (Data & Airtime)
        try {
            $balances['cheapdatahub'] = $this->getCheapDataHubBalance();
        } catch (\Throwable $e) {
            $balances['cheapdatahub'] = $this->buildErrorPayload(
                'cheapdatahub',
                'CheapDataHub',
                'Data & Airtime',
                '₦',
                'NGN',
                'Exception: ' . $e->getMessage()
            );
        }

        // 2. 5SIM (Foreign Virtual Numbers)
        try {
            $balances['fivesim'] = $this->getFiveSimBalance();
        } catch (\Throwable $e) {
            $balances['fivesim'] = $this->buildErrorPayload(
                'fivesim',
                '5SIM',
                'Foreign Virtual Numbers',
                '$',
                'USD',
                'Exception: ' . $e->getMessage()
            );
        }

        // 3. VTpass (Data, Electricity, Cable TV, Exam PINs)
        try {
            $balances['vtpass'] = $this->getVtpassBalance();
        } catch (\Throwable $e) {
            $balances['vtpass'] = $this->buildErrorPayload(
                'vtpass',
                'VTpass',
                'Data, Utilities & Exams',
                '₦',
                'NGN',
                'Exception: ' . $e->getMessage()
            );
        }

        // 4. Termii (Bulk SMS Gateway)
        try {
            $balances['termii'] = $this->getTermiiBalance();
        } catch (\Throwable $e) {
            $balances['termii'] = $this->buildErrorPayload(
                'termii',
                'Termii',
                'Bulk SMS Gateway',
                '₦',
                'NGN',
                'Exception: ' . $e->getMessage()
            );
        }

        if (!empty($balances)) {
            @file_put_contents($cacheFile, json_encode($balances, JSON_UNESCAPED_SLASHES));
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
        $serviceName = 'Data & Airtime';
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
     * Query VTpass wallet balance (Electricity, Cable TV, Exam PINs)
     */
    public function getVtpassBalance(): array
    {
        $providerKey = 'vtpass';
        $providerName = 'VTpass';
        $serviceName = 'Data, Utilities & Exams';
        $currencySymbol = '₦';
        $currencyCode = 'NGN';

        if (empty($this->vtpassApiKey)) {
            return $this->buildUnconfiguredPayload($providerKey, $providerName, $serviceName, $currencySymbol, $currencyCode);
        }

        $headers = [
            "api-key: " . $this->vtpassApiKey,
            "secret-key: " . $this->vtpassSecretKey,
            "Accept: application/json"
        ];
        if (!empty($this->vtpassPublicKey)) {
            $headers[] = "public-key: " . $this->vtpassPublicKey;
        }

        // Try primary configured baseUrl first
        $endpoint = rtrim($this->vtpassBaseUrl, '/') . '/balance';
        $res = $this->executeHttpRequest($endpoint, 'GET', $headers, 8);

        // Resilient fallback: If live endpoint is down or user is using sandbox credentials (starts with PK_)
        if ((!$res['ok'] || empty($res['data'])) && !str_contains($this->vtpassBaseUrl, 'sandbox')) {
            $fallbackEndpoint = 'https://sandbox.vtpass.com/api/balance';
            $res = $this->executeHttpRequest($fallbackEndpoint, 'GET', $headers, 8);
        }

        if (!$res['ok'] || !is_array($res['data'])) {
            return $this->buildErrorPayload(
                $providerKey,
                $providerName,
                $serviceName,
                $currencySymbol,
                $currencyCode,
                $res['message'] ?: 'Failed to retrieve balance from VTpass'
            );
        }

        // Format: {"code":1,"contents":{"balance":"2000000.00"}} or {"balance": ...}
        $rawBalance = $res['data']['contents']['balance'] ?? ($res['data']['balance'] ?? null);

        if ($rawBalance === null) {
            return $this->buildErrorPayload(
                $providerKey,
                $providerName,
                $serviceName,
                $currencySymbol,
                $currencyCode,
                'Unexpected response format from VTpass'
            );
        }

        $balanceFloat = (float)$rawBalance;
        $isSandbox = str_contains($this->vtpassBaseUrl, 'sandbox') || str_starts_with($this->vtpassPublicKey, 'PK_');

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
            'environment' => $isSandbox ? 'Sandbox Test Wallet' : 'Live Production',
            'message' => 'VTpass wallet balance retrieved successfully',
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
            'formatted' => 'Not Configured',
            'environment' => 'Unconfigured',
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
            'formatted' => 'Unavailable',
            'environment' => 'Unknown',
            'message' => $errorMsg,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Safe internal HTTP requester with strict timeouts
     * Never leaks Authorization headers or credentials to exception messages.
     */
    private function executeHttpRequest(string $url, string $method = 'GET', array $headers = [], int $timeout = 8): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
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
