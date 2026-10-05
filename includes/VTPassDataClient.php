<?php

/**
 * VTPassDataClient - Official VTPass API Client for Data Bundles & Wallet Operations
 *
 * Implements the official VTPass REST API specification for:
 * 1. Data Bundle Variations Query (GET /service-variations?serviceID={serviceID})
 * 2. Data Bundle Purchase (POST /pay)
 * 3. Transaction Requery & Status Verification (POST /requery)
 * 4. Reseller Balance Inquiry (GET /balance)
 *
 * Security & Reliability Directives:
 * - Credentials (API Key, Secret Key, Public Key) are NEVER logged or exposed to the client.
 * - All responses are parsed safely, catching HTML error pages and non-JSON payloads.
 * - Idempotent request IDs formatted strictly according to VTPass specification.
 */
class VTPassDataClient
{
    private string $apiKey;
    private string $secretKey;
    private string $publicKey;
    private string $baseUrl;

    /**
     * Map network slugs to official VTPass Data Service IDs
     */
    public const SERVICE_IDS = [
        'mtn'     => 'mtn-data',
        'airtel'  => 'airtel-data',
        'glo'     => 'glo-data',
        '9mobile' => '9mobile-data',
        't2'      => '9mobile-data'
    ];

    /**
     * Initialize VTPass Data Client
     *
     * @param string $apiKey     VTPass API Key
     * @param string $secretKey  VTPass Secret Key
     * @param string $publicKey  VTPass Public Key (optional)
     * @param string $baseUrl    VTPass Base URL (Live: https://api-service.vtpass.com/api, Sandbox: https://sandbox.vtpass.com/api)
     */
    public function __construct(
        ?string $apiKey = null,
        ?string $secretKey = null,
        ?string $publicKey = null,
        ?string $baseUrl = null
    ) {
        $this->apiKey    = trim((string)($apiKey ?? (getenv('VTPASS_API_KEY') ?: ($_ENV['VTPASS_API_KEY'] ?? ''))));
        $this->secretKey = trim((string)($secretKey ?? (getenv('VTPASS_SECRET_KEY') ?: ($_ENV['VTPASS_SECRET_KEY'] ?? ''))));
        $this->publicKey = trim((string)($publicKey ?? (getenv('VTPASS_PUBLIC_KEY') ?: ($_ENV['VTPASS_PUBLIC_KEY'] ?? ''))));
        $this->baseUrl   = rtrim(trim((string)($baseUrl ?? (getenv('VTPASS_BASE_URL') ?: ($_ENV['VTPASS_BASE_URL'] ?? 'https://api-service.vtpass.com/api')))), '/');
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Generate official VTPass Request ID: YYYYMMDDHHII + unique alphanumeric suffix
     *
     * VTPass documentation requires the request_id to begin with the current date/time
     * in the Africa/Lagos timezone (GMT+1) to ensure unique tracking.
     *
     * @param string $suffix Optional unique identifier or order reference
     * @return string Valid VTPass request ID
     */
    public static function generateRequestId(string $suffix = ''): string
    {
        $timezone = new DateTimeZone('Africa/Lagos');
        $now = new DateTime('now', $timezone);
        $datePrefix = $now->format('YmdHi');

        $cleanSuffix = preg_replace('/[^A-Za-z0-9]/', '', $suffix);
        if ($cleanSuffix === '') {
            $cleanSuffix = bin2hex(random_bytes(5));
        }

        return $datePrefix . substr($cleanSuffix, 0, 12);
    }

    /**
     * Send authenticated HTTP request to VTPass
     *
     * @param string     $method   HTTP Method (GET or POST)
     * @param string     $endpoint API Endpoint path
     * @param array|null $payload  Optional request parameters/body
     * @return array Normalized response array: ['ok' => bool, 'code' => string, 'state' => string, 'http_status' => int, 'message' => string, 'data' => mixed]
     */
    private function request(string $method, string $endpoint, ?array $payload = null): array
    {
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');

        $headers = [
            'api-key: ' . $this->apiKey,
            'secret-key: ' . $this->secretKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ];

        if ($this->publicKey !== '') {
            $headers[] = 'public-key: ' . $this->publicKey;
        }

        $ch = curl_init();
        if ($ch === false) {
            return [
                'ok'          => false,
                'code'        => 'CURL_INIT_FAIL',
                'state'       => 'unknown',
                'http_status' => 0,
                'message'     => 'Could not initialize connection to VTPass.',
                'data'        => null
            ];
        }

        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_SSL_VERIFYPEER => true
        ];

        if (strtoupper($method) === 'POST') {
            $options[CURLOPT_POST] = true;
            $jsonPayload = json_encode($payload ?? [], JSON_UNESCAPED_SLASHES);
            if ($jsonPayload === false) {
                curl_close($ch);
                return [
                    'ok'          => false,
                    'code'        => 'JSON_ENCODE_FAIL',
                    'state'       => 'failed',
                    'http_status' => 0,
                    'message'     => 'Could not prepare request payload for VTPass.',
                    'data'        => null
                ];
            }
            $options[CURLOPT_POSTFIELDS] = $jsonPayload;
        } elseif (strtoupper($method) === 'GET' && !empty($payload)) {
            $url .= '?' . http_build_query($payload);
            $options[CURLOPT_URL] = $url;
        }

        curl_setopt_array($ch, $options);

        $response   = curl_exec($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);
        curl_close($ch);

        // Handle low-level transport errors (timeouts, network drops)
        if ($response === false) {
            error_log("VTPass connection error: Endpoint {$endpoint} | Error: {$curlError}");
            return [
                'ok'          => false,
                'code'        => 'NETWORK_TIMEOUT',
                'state'       => 'unknown',
                'http_status' => $httpStatus,
                'message'     => 'Connection to VTPass timed out or failed to connect: ' . $curlError,
                'data'        => null
            ];
        }

        $decoded = json_decode($response, true);

        // Non-JSON response (e.g. upstream 502/503 HTML gateway error)
        if (!is_array($decoded)) {
            $safeSnippet = substr(strip_tags($response), 0, 150);
            error_log("VTPass non-JSON response: HTTP {$httpStatus} | Endpoint {$endpoint} | Body: {$safeSnippet}");
            return [
                'ok'          => false,
                'code'        => 'NON_JSON_RESPONSE',
                'state'       => 'unknown',
                'http_status' => $httpStatus,
                'message'     => "VTPass returned non-JSON response (HTTP {$httpStatus}): " . $safeSnippet,
                'data'        => null
            ];
        }

        $responseCode = (string) ($decoded['code'] ?? ($decoded['response_description'] ?? ''));
        $responseDesc = (string) ($decoded['response_description'] ?? ($decoded['message'] ?? ''));

        // VTPass official code "000" signifies success for purchases; "1" signifies success for balance/queries.
        // Also verify variations or transaction delivery status in content payload.
        $hasVariations = !empty($decoded['content']['varations']) || !empty($decoded['content']['variations']);
        $hasBalance = isset($decoded['contents']['balance']) || isset($decoded['balance']);
        $txnStatus = strtolower(trim((string)($decoded['content']['transactions']['status'] ?? '')));

        $isSuccess = in_array($responseCode, ['000', '1'], true)
            || $responseDesc === '000'
            || stripos($responseDesc, 'successful') !== false
            || in_array($txnStatus, ['delivered', 'successful', 'success'], true)
            || $hasVariations
            || $hasBalance;

        // Codes "099" or "016" signify pending/processing
        $isPending = in_array($responseCode, ['099', '016'], true)
            || in_array($txnStatus, ['pending', 'processing', 'initiated'], true);

        $state = 'failed';
        if ($isSuccess) {
            $state = 'successful';
        } elseif ($isPending) {
            $state = 'processing';
        }

        return [
            'ok'          => $isSuccess || $isPending,
            'code'        => $responseCode ?: ($isSuccess ? '000' : 'UNKNOWN'),
            'state'       => $state,
            'http_status' => $httpStatus,
            'message'     => ($responseDesc && $responseDesc !== '000') ? $responseDesc : ($isSuccess ? 'Transaction successful' : 'VTPass transaction failed'),
            'data'        => $decoded
        ];
    }

    /**
     * Query Reseller Wallet Balance from VTPass (GET /balance)
     *
     * @return array Normalized balance structure
     */
    public function getBalance(): array
    {
        return $this->request('GET', 'balance');
    }

    /**
     * Retrieve supported Data Bundle variation codes and pricing for a service
     *
     * @param string $serviceId VTPass Service ID (e.g. 'mtn-data', 'airtel-data', 'glo-data', '9mobile-data')
     * @return array Variation items list with codes, names, and prices
     */
    public function getVariations(string $serviceId): array
    {
        return $this->request('GET', 'service-variations', ['serviceID' => $serviceId]);
    }

    /**
     * Purchase Data Bundle from VTPass (POST /pay)
     *
     * @param string $serviceId     VTPass Service ID (e.g. 'mtn-data')
     * @param string $variationCode Official VTPass Variation Code (e.g. 'mtn-10mb-100')
     * @param float  $amount        Plan cost / selling amount in Naira
     * @param string $phone         Recipient 11-digit Nigerian phone number
     * @param string $requestId     Unique idempotent Request ID
     * @return array Result of purchase call
     */
    public function purchaseData(
        string $serviceId,
        string $variationCode,
        float $amount,
        string $phone,
        string $requestId
    ): array {
        // Clean and normalize phone number to local 11-digit format (080...)
        $cleanPhone = preg_replace('/\D+/', '', $phone);
        if (str_starts_with($cleanPhone, '234')) {
            $cleanPhone = '0' . substr($cleanPhone, 3);
        }

        $payload = [
            'request_id'     => $requestId,
            'serviceID'      => $serviceId,
            'billersCode'    => $cleanPhone,
            'variation_code' => $variationCode,
            'amount'         => (int) $amount,
            'phone'          => $cleanPhone
        ];

        return $this->request('POST', 'pay', $payload);
    }

    /**
     * Requery status of a previous transaction by Request ID (POST /requery)
     *
     * @param string $requestId The unique request ID generated during initial purchase
     * @return array Verification response
     */
    public function requeryTransaction(string $requestId): array
    {
        return $this->request('POST', 'requery', ['request_id' => $requestId]);
    }
}
