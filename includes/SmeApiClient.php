<?php

/**
 * SmeApiClient - Resilient HTTP Client for SMEAPI (https://smeapi.com.ng)
 *
 * Scope: STRICTLY FOR DATA SUBSCRIPTIONS ONLY.
 *
 * Endpoints:
 * - Profile / Live Wallet Balance: GET https://smeapi.com.ng/api/user/
 * - Live Data Plans Catalog:     GET https://smeapi.com.ng/api/dataplans/?format=json
 * - Data Subscription Purchase:   POST https://smeapi.com.ng/api/data/
 *
 * Authentication:
 * - Header: Authorization: Token {apiKey}
 * - Header: Content-Type: application/json
 */
class SmeApiClient
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeout;
    private int $connectTimeout;

    public function __construct(
        string $apiKey = '',
        string $baseUrl = 'https://smeapi.com.ng',
        int $timeout = 30,
        int $connectTimeout = 10
    ) {
        $this->apiKey = trim($apiKey);
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = max(5, $timeout);
        $this->connectTimeout = max(3, $connectTimeout);
    }

    /**
     * Determine if client has an API key configured.
     */
    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Query live SMEAPI user profile and wallet balance.
     * Endpoint: GET /api/user/
     *
     * Returns:
     * [
     *   'ok' => bool,
     *   'balance' => ?float,
     *   'username' => ?string,
     *   'http_status' => int,
     *   'message' => string,
     *   'data' => ?array
     * ]
     */
    public function getBalance(): array
    {
        if (!$this->isConfigured()) {
            return [
                'ok' => false,
                'balance' => null,
                'username' => null,
                'http_status' => 0,
                'message' => 'SMEAPI API key is not configured in environment or settings.',
                'data' => null
            ];
        }

        $url = $this->baseUrl . '/api/user/';
        $response = $this->sendRequest('GET', $url);

        if (!$response['ok']) {
            $errMsg = $response['message'];
            if (is_array($response['data'])) {
                $customMsg = $response['data']['msg'] ?? ($response['data']['message'] ?? ($response['data']['detail'] ?? ''));
                if ($customMsg !== '') {
                    $errMsg = (string)$customMsg;
                }
            }
            return [
                'ok' => false,
                'balance' => null,
                'username' => null,
                'http_status' => $response['http_status'],
                'message' => $errMsg,
                'data' => $response['data']
            ];
        }

        $payload = $response['data'];
        $balance = $this->extractBalanceFromPayload($payload);
        $username = $this->extractUsernameFromPayload($payload);

        if ($balance === null) {
            return [
                'ok' => false,
                'balance' => null,
                'username' => $username,
                'http_status' => $response['http_status'],
                'message' => 'Unable to parse wallet balance from SMEAPI user response.',
                'data' => $payload
            ];
        }

        return [
            'ok' => true,
            'balance' => $balance,
            'username' => $username,
            'http_status' => $response['http_status'],
            'message' => 'Balance retrieved successfully.',
            'data' => $payload
        ];
    }

    /**
     * Fetch the official SMEAPI live data plans catalog.
     * Endpoint: GET /api/dataplans/?format=json
     *
     * Returns:
     * [
     *   'ok' => bool,
     *   'http_status' => int,
     *   'message' => string,
     *   'plans' => array
     * ]
     */
    public function fetchDataPlans(): array
    {
        $url = $this->baseUrl . '/api/dataplans/?format=json';
        $response = $this->sendRequest('GET', $url, null, false); // Token header optional on public catalog

        if (!$response['ok'] && empty($response['data'])) {
            return [
                'ok' => false,
                'http_status' => $response['http_status'],
                'message' => $response['message'],
                'plans' => []
            ];
        }

        $payload = $response['data'];
        $plans = [];

        if (is_array($payload)) {
            if (isset($payload['data']) && is_array($payload['data'])) {
                $plans = $payload['data'];
            } elseif (isset($payload[0])) {
                $plans = $payload;
            }
        }

        return [
            'ok' => true,
            'http_status' => $response['http_status'],
            'message' => 'Data plans retrieved successfully.',
            'plans' => $plans
        ];
    }

    /**
     * Execute a data subscription purchase on SMEAPI.
     * Endpoint: POST /api/data/
     *
     * Parameters:
     * - $networkId: int (1 = MTN, 4 = Airtel, 2 = Glo, 3 = 9mobile)
     * - $planId: int (the specific SMEAPI plan ID from /api/dataplans/)
     * - $phone: string (11-digit local Nigerian phone e.g. 08012345678)
     * - $ref: string (unique request reference)
     * - $isPorted: bool (whether the phone number is ported)
     *
     * Returns normalized structure:
     * [
     *   'ok' => bool,
     *   'http_status' => int,
     *   'state' => 'successful'|'failed'|'processing'|'unknown',
     *   'message' => string,
     *   'reference' => string,
     *   'provider_reference' => ?string,
     *   'data' => ?array
     * ]
     */
    public function purchaseData(
        int $networkId,
        int $planId,
        string $phone,
        string $ref,
        bool $isPorted = false
    ): array {
        if (!$this->isConfigured()) {
            return [
                'ok' => false,
                'http_status' => 0,
                'state' => 'failed',
                'message' => 'SMEAPI API key is not configured.',
                'reference' => $ref,
                'provider_reference' => null,
                'data' => null
            ];
        }

        // Standardize phone to 11-digit local Nigerian format (e.g. 0803...)
        $cleanPhone = preg_replace('/\D+/', '', $phone);
        if (str_starts_with($cleanPhone, '234') && strlen($cleanPhone) === 13) {
            $cleanPhone = '0' . substr($cleanPhone, 3);
        }

        $body = [
            'network' => $networkId,
            'data_plan' => $planId,
            'phone' => $cleanPhone,
            'ref' => $ref,
            'ported_number' => $isPorted ? 'true' : 'false'
        ];

        $url = $this->baseUrl . '/api/data/';
        $response = $this->sendRequest('POST', $url, $body);

        $httpStatus = $response['http_status'];
        $rawPayload = $response['data'];
        $supplierMessage = $this->cleanResponseMessage($response['raw_body'] ?? $response['message'] ?? '');

        // 1. Connection or Transport Uncertainty
        // When cURL times out or network connection drops, the upstream supplier may have processed it!
        // DO NOT mark failed and DO NOT refund. Must remain processing/unknown.
        if ($response['transport_error']) {
            return [
                'ok' => false,
                'http_status' => $httpStatus,
                'state' => 'unknown',
                'message' => 'SMEAPI connection timed out or network error. Transaction status uncertain: ' . $response['message'],
                'reference' => $ref,
                'provider_reference' => null,
                'data' => $rawPayload
            ];
        }

        // 2. HTTP 5xx Server Error Uncertainty
        if ($httpStatus >= 500 && $httpStatus <= 599) {
            // Check if server returned a definitive failure JSON (e.g. invalid token)
            $isDefinitiveAuthError = is_array($rawPayload)
                && isset($rawPayload['status'])
                && in_array($rawPayload['status'], ['fail', 'error'], true)
                && isset($rawPayload['msg'])
                && str_contains(strtolower((string)$rawPayload['msg']), 'token');

            if (!$isDefinitiveAuthError) {
                return [
                    'ok' => false,
                    'http_status' => $httpStatus,
                    'state' => 'unknown',
                    'message' => 'SMEAPI server error (' . $httpStatus . '). Transaction status uncertain: ' . $supplierMessage,
                    'reference' => $ref,
                    'provider_reference' => null,
                    'data' => $rawPayload
                ];
            }
        }

        // 3. Inspect Payload for Definitive Status
        $statusStr = '';
        if (is_array($rawPayload)) {
            $rawStatus = $rawPayload['Status'] ?? ($rawPayload['status'] ?? ($rawPayload['state'] ?? ''));
            $statusStr = strtolower(trim((string)$rawStatus));
            $extractedMsg = $rawPayload['msg'] ?? ($rawPayload['message'] ?? ($rawPayload['desc'] ?? ''));
            if ($extractedMsg !== '') {
                $supplierMessage = trim((string)$extractedMsg);
            }
        }

        // Provider Reference Extraction
        $providerRef = null;
        if (is_array($rawPayload)) {
            $candidateRefs = [
                $rawPayload['ident'] ?? null,
                $rawPayload['id'] ?? null,
                $rawPayload['reference'] ?? null,
                $rawPayload['ref'] ?? null,
                $rawPayload['trans_id'] ?? null,
                $rawPayload['data']['id'] ?? null,
                $rawPayload['data']['reference'] ?? null
            ];
            foreach ($candidateRefs as $cRef) {
                if (is_scalar($cRef) && trim((string)$cRef) !== '') {
                    $providerRef = trim((string)$cRef);
                    break;
                }
            }
        }

        // 4. Confirmed Success
        if (in_array($statusStr, ['successful', 'success', 'true', '1'], true) || ($httpStatus === 200 && $statusStr === '')) {
            return [
                'ok' => true,
                'http_status' => $httpStatus,
                'state' => 'successful',
                'message' => $supplierMessage ?: 'Data purchase successful.',
                'reference' => $ref,
                'provider_reference' => $providerRef ?: $ref,
                'data' => $rawPayload
            ];
        }

        // 5. Pending / In Progress
        if (in_array($statusStr, ['pending', 'processing', 'in-progress', 'submitted'], true)) {
            return [
                'ok' => false,
                'http_status' => $httpStatus,
                'state' => 'processing',
                'message' => $supplierMessage ?: 'Data subscription is processing with supplier.',
                'reference' => $ref,
                'provider_reference' => $providerRef ?: $ref,
                'data' => $rawPayload
            ];
        }

        // 6. Confirmed Failure / Rejection
        return [
            'ok' => false,
            'http_status' => $httpStatus,
            'state' => 'failed',
            'message' => $supplierMessage ?: 'Data subscription failed with supplier.',
            'reference' => $ref,
            'provider_reference' => $providerRef,
            'data' => $rawPayload
        ];
    }

    /**
     * Send HTTP request via cURL with secure headers and fault tolerance.
     */
    private function sendRequest(
        string $method,
        string $url,
        ?array $body = null,
        bool $useAuthHeader = true
    ): array {
        $ch = curl_init();

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: Subnext-VTU/2.0 (+https://subnext.com.ng)'
        ];

        if ($useAuthHeader && $this->apiKey !== '') {
            $headers[] = 'Authorization: Token ' . $this->apiKey;
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ];

        if (strtoupper($method) === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body !== null ? json_encode($body) : '{}';
        }

        curl_setopt_array($ch, $options);

        $rawBody = curl_exec($ch);
        $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlErrno !== 0) {
            return [
                'ok' => false,
                'transport_error' => true,
                'http_status' => $httpStatus,
                'message' => 'cURL Error (' . $curlErrno . '): ' . $curlError,
                'raw_body' => '',
                'data' => null
            ];
        }

        $decoded = null;
        if (is_string($rawBody) && $rawBody !== '') {
            $decoded = json_decode($rawBody, true);
        }

        $isOk = ($httpStatus >= 200 && $httpStatus < 300);

        return [
            'ok' => $isOk,
            'transport_error' => false,
            'http_status' => $httpStatus,
            'message' => $isOk ? 'OK' : 'HTTP Error ' . $httpStatus,
            'raw_body' => (string)$rawBody,
            'data' => $decoded
        ];
    }

    /**
     * Parse numeric wallet balance from diverse SMEAPI response keys.
     */
    private function extractBalanceFromPayload(?array $payload): ?float
    {
        if (!is_array($payload)) {
            return null;
        }

        $candidates = [
            $payload['wallet_balance'] ?? null,
            $payload['balance'] ?? null,
            $payload['user']['wallet_balance'] ?? null,
            $payload['user']['balance'] ?? null,
            $payload['data']['wallet_balance'] ?? null,
            $payload['data']['balance'] ?? null
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== null) {
                if (is_int($candidate) || is_float($candidate)) {
                    return (float)$candidate;
                }
                if (is_string($candidate)) {
                    $cleaned = str_replace([',', '₦', 'NGN', 'ngn', ' '], '', trim($candidate));
                    if (is_numeric($cleaned)) {
                        return (float)$cleaned;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Parse username/identifier from user payload.
     */
    private function extractUsernameFromPayload(?array $payload): ?string
    {
        if (!is_array($payload)) {
            return null;
        }

        $candidates = [
            $payload['name'] ?? null,
            $payload['user']['username'] ?? null,
            $payload['username'] ?? null,
            $payload['data']['username'] ?? null,
            $payload['user']['name'] ?? null,
            $payload['user']['email'] ?? null
        ];

        foreach ($candidates as $c) {
            if (is_string($c) && trim($c) !== '') {
                return trim($c);
            }
        }

        return null;
    }

    /**
     * Clean and truncate supplier messages safely for DB storage.
     */
    private function cleanResponseMessage(string $raw): string
    {
        $stripped = strip_tags($raw);
        $decoded = html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $squashed = preg_replace('/\s+/', ' ', $decoded);
        $clean = trim((string)$squashed);

        if (mb_strlen($clean) > 220) {
            return mb_substr($clean, 0, 220) . '...';
        }

        return $clean;
    }
}
