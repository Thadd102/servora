<?php

require_once __DIR__ . '/CheapDataHubClient.php';

/**
 * UtilityProvider - Unified Provider Abstraction for Subnext VTU Services
 *
 * Covers:
 * 1. Airtime Top-Up (MTN, Airtel, Glo, 9mobile) via CheapDataHub
 * 2. Electricity Bills & Meter Validation (AEDC, EKEDC, IBEDC, IKEDC, KAEDCO, PHED, JEDPLC, EEDC, YEDC, BEDC) via CheapDataHub
 * 3. Cable TV Subscription & Smartcard Validation (DStv, GOtv, StarTimes) via CheapDataHub
 * 4. Examination PINs (WAEC, NECO, NABTEB) via CheapDataHub
 * 5. Bulk SMS via Termii
 *
 * Adheres strictly to Subnext PRD:
 * - Real API integration behind stable provider interface
 * - Confidential credentials & supplier cost never leaked to client
 * - Strict production-only live API execution. Simulated success responses prohibited.
 */
class UtilityProvider
{
    private CheapDataHubClient $cheapDataHubClient;
    private string $cheapDataHubApiKey;
    private string $cheapDataHubBaseUrl;
    private string $termiiApiKey;
    private string $termiiBaseUrl;
    private string $termiiSenderId;
    private string $vtuApiKey;
    private string $vtuBaseUrl;
    private string $smsApiKey;
    private string $smsBaseUrl;

    public function __construct(?string $cheapDataHubApiKey = null, ?string $cheapDataHubBaseUrl = null)
    {
        require_once __DIR__ . '/../config/env.php';

        $this->cheapDataHubApiKey = $cheapDataHubApiKey !== null
            ? trim($cheapDataHubApiKey)
            : trim((string)(getenv('CHEAPDATAHUB_API_KEY') ?: ($_ENV['CHEAPDATAHUB_API_KEY'] ?? '')));
        $this->cheapDataHubBaseUrl = $cheapDataHubBaseUrl !== null
            ? rtrim($cheapDataHubBaseUrl, '/')
            : rtrim((string)(getenv('CHEAPDATAHUB_BASE_URL') ?: ($_ENV['CHEAPDATAHUB_BASE_URL'] ?? 'https://www.cheapdatahub.ng/api/v1/resellers')), '/');

        $this->cheapDataHubClient = new CheapDataHubClient($this->cheapDataHubApiKey, $this->cheapDataHubBaseUrl);

        // Termii Live Credentials for Bulk SMS
        $this->termiiApiKey = trim((string)(getenv('TERMII_API_KEY') ?: ($_ENV['TERMII_API_KEY'] ?? '')));
        $this->termiiBaseUrl = rtrim((string)(getenv('TERMII_BASE_URL') ?: ($_ENV['TERMII_BASE_URL'] ?? 'https://api.ng.termii.com/api')), '/');
        $this->termiiSenderId = trim((string)(getenv('TERMII_SENDER_ID') ?: ($_ENV['TERMII_SENDER_ID'] ?? 'Subnext')));

        $this->vtuApiKey = trim((string)(getenv('VTU_PROVIDER_API_KEY') ?: ($_ENV['VTU_PROVIDER_API_KEY'] ?? '')));
        $this->vtuBaseUrl = rtrim((string)(getenv('VTU_PROVIDER_BASE_URL') ?: ($_ENV['VTU_PROVIDER_BASE_URL'] ?? '')), '/');

        $this->smsApiKey = trim((string)(getenv('SMS_PROVIDER_API_KEY') ?: ($_ENV['SMS_PROVIDER_API_KEY'] ?? '')));
        $this->smsBaseUrl = rtrim((string)(getenv('SMS_PROVIDER_BASE_URL') ?: ($_ENV['SMS_PROVIDER_BASE_URL'] ?? '')), '/');
    }

    /**
     * Identify Nigerian mobile network from phone number prefix
     */
    public static function detectNetwork(string $phoneNumber): ?string
    {
        $cleanPhone = preg_replace('/\D+/', '', $phoneNumber);
        if (str_starts_with($cleanPhone, '234')) {
            $cleanPhone = '0' . substr($cleanPhone, 3);
        }
        if (strlen($cleanPhone) < 4) {
            return null;
        }
        $prefix = substr($cleanPhone, 0, 4);

        $networkPrefixes = [
            'mtn' => ['0703','0704','0706','0707','0803','0806','0810','0813','0814','0816','0903','0906','0913','0916'],
            'airtel' => ['0701','0708','0802','0808','0812','0901','0902','0904','0907','0911','0912'],
            'glo' => ['0705','0805','0807','0811','0815','0905','0915'],
            '9mobile' => ['0809','0817','0818','0908','0909']
        ];

        foreach ($networkPrefixes as $net => $prefixes) {
            if (in_array($prefix, $prefixes, true)) {
                return $net;
            }
        }
        return null;
    }

    // =========================================================================
    // AIRTIME TOP-UP (CheapDataHub)
    // =========================================================================

    public function purchaseAirtime(string $network, string $phoneNumber, float $amount, string $reference): array
    {
        $networkMap = [
            'mtn' => 1,
            'glo' => 2,
            'airtel' => 3,
            '9mobile' => 4,
            'etisalat' => 4,
            't2' => 4
        ];

        // Clean phone number
        $cleanPhone = preg_replace('/\D+/', '', $phoneNumber);
        if (str_starts_with($cleanPhone, '234')) {
            $cleanPhone = '0' . substr($cleanPhone, 3);
        }

        $networkSlug = strtolower(trim($network));
        $detected = self::detectNetwork($cleanPhone);
        if (empty($networkSlug) && $detected) {
            $networkSlug = $detected;
        }
        $networkId = $networkMap[$networkSlug] ?? ($detected ? ($networkMap[$detected] ?? 1) : 1);

        if (empty($this->cheapDataHubApiKey)) {
            return [
                'ok' => false,
                'status' => 'failed',
                'message' => 'Airtime supplier gateway is not configured.'
            ];
        }

        // Live CheapDataHub call
        $res = $this->cheapDataHubClient->purchaseAirtime($networkId, $cleanPhone, (int)$amount);

        if ($res['ok'] && is_array($res['data'])) {
            $status = strtolower((string)($res['data']['status'] ?? ''));
            $isSuccess = ($status === 'true' || $status === 'success' || $status === 'successful' || ($res['data']['status'] ?? false) === true);
            if ($isSuccess) {
                $orderId = (string)($res['data']['details']['ident'] ?? ($res['data']['details']['id'] ?? ($res['data']['transaction_id'] ?? ($res['data']['reference'] ?? $reference))));
                return [
                    'ok' => true,
                    'status' => 'successful',
                    'provider_order_id' => $orderId,
                    'message' => (string)($res['data']['message'] ?? 'Airtime delivered successfully.')
                ];
            }
            return [
                'ok' => false,
                'status' => 'failed',
                'message' => (string)($res['data']['message'] ?? 'Airtime top-up could not be completed by provider.')
            ];
        }

        $errMsg = is_array($res['data'] ?? null)
            ? (string)($res['data']['message'] ?? ($res['data']['detail'] ?? 'Provider rejected the airtime purchase.'))
            : (string)($res['message'] ?? 'Airtime gateway is temporarily unavailable. Please try again shortly.');

        return [
            'ok' => false,
            'status' => 'failed',
            'message' => $errMsg
        ];
    }

    // =========================================================================
    // ELECTRICITY (CheapDataHub Discos & Meter Validation)
    // =========================================================================

    /**
     * Map disco code or product slug to CheapDataHub disco_id (1-10)
     */
    public static function resolveDiscoId(string $discoCode): int
    {
        $code = strtolower(trim($discoCode));
        if (is_numeric($code) && (int)$code >= 1 && (int)$code <= 10) {
            return (int)$code;
        }

        $discoMap = [
            'abuja_electric' => 1,
            'aedc' => 1,
            'abuja' => 1,
            'eko_electric' => 2,
            'ekedc' => 2,
            'eko' => 2,
            'ibadan_electric' => 3,
            'ibedc' => 3,
            'ibadan' => 3,
            'ikeja_electric' => 4,
            'ikedc' => 4,
            'ikeja' => 4,
            'kaduna_electric' => 5,
            'kaedco' => 5,
            'kaduna' => 5,
            'portharcourt_electric' => 6,
            'phed' => 6,
            'phedc' => 6,
            'port_harcourt' => 6,
            'jos_electric' => 7,
            'jed' => 7,
            'jedplc' => 7,
            'jos' => 7,
            'enugu_electric' => 8,
            'eedc' => 8,
            'enugu' => 8,
            'yola_electric' => 9,
            'yedc' => 9,
            'yola' => 9,
            'benin_electric' => 10,
            'bedc' => 10,
            'benin' => 10
        ];

        return $discoMap[$code] ?? 1;
    }

    public static function getDiscoName(int $discoId): string
    {
        $names = [
            1 => 'Abuja Electricity (AEDC)',
            2 => 'Eko Electricity (EKEDC)',
            3 => 'Ibadan Electricity (IBEDC)',
            4 => 'Ikeja Electric (IKEDC)',
            5 => 'Kaduna Electric (KAEDCO)',
            6 => 'Port Harcourt Electricity (PHED)',
            7 => 'Jos Electricity Distribution PLC (JEDPLC)',
            8 => 'Enugu Electricity (EEDC)',
            9 => 'Yola Electricity (YEDC)',
            10 => 'Benin Electricity (BEDC)'
        ];

        return $names[$discoId] ?? 'Electricity Distribution Company';
    }

    public function validateMeter(string $discoCode, string $meterNumber, string $meterType = 'prepaid'): array
    {
        $cleanMeter = trim($meterNumber);
        if (strlen($cleanMeter) < 6) {
            return [
                'ok' => false,
                'message' => 'Invalid meter number. Please check and try again.'
            ];
        }

        $discoId = self::resolveDiscoId($discoCode);
        $discoName = self::getDiscoName($discoId);
        $cleanType = strtolower(trim($meterType)) === 'postpaid' ? 'postpaid' : 'prepaid';

        if (empty($this->cheapDataHubApiKey)) {
            return [
                'ok' => false,
                'message' => 'Electricity verification provider is not configured.'
            ];
        }

        // Live CheapDataHub verification ONLY - NO MOCK / DEMO FALLBACK
        $res = $this->cheapDataHubClient->validateMeter($discoId, $cleanMeter, $cleanType);

        if ($res['ok'] && is_array($res['data'])) {
            $status = $res['data']['status'] ?? false;
            $isSuccess = ($status === true || $status === 1 || $status === '1' || strtolower((string)$status) === 'true' || strtolower((string)$status) === 'success' || strtolower((string)$status) === 'successful');

            if ($isSuccess) {
                $details = is_array($res['data']['details'] ?? null)
                    ? $res['data']['details']
                    : (is_array($res['data']['data'] ?? null) ? $res['data']['data'] : []);

                $name = $details['name'] ?? ($details['customer_name'] ?? ($details['Customer_Name'] ?? ($res['data']['name'] ?? '')));
                $address = $details['address'] ?? ($details['Address'] ?? ($details['meter_address'] ?? ($res['data']['address'] ?? '')));

                if (!empty($name)) {
                    return [
                        'ok' => true,
                        'customer_name' => trim((string)$name),
                        'meter_number' => $cleanMeter,
                        'meter_type' => ucfirst($cleanType),
                        'address' => trim((string)$address) ?: 'Verified Customer Address',
                        'disco_name' => $discoName,
                        'disco_id' => $discoId,
                        'message' => (string)($res['data']['message'] ?? 'Meter verified successfully.')
                    ];
                }
            }

            $msg = (string)($res['data']['message'] ?? ($res['data']['detail'] ?? ($res['data']['error'] ?? 'Could not verify meter number with provider.')));
            return [
                'ok' => false,
                'message' => $msg
            ];
        }

        // Provider rejected or returned error (e.g. HTTP 400 invalid meter)
        $msg = is_array($res['data'] ?? null)
            ? (string)($res['data']['message'] ?? ($res['data']['detail'] ?? ($res['data']['error'] ?? 'Customer details could not be validated.')))
            : (string)($res['message'] ?? 'Could not verify meter number. Please check and try again.');

        return [
            'ok' => false,
            'message' => $msg
        ];
    }

    public function purchaseElectricity(string $discoCode, string $meterNumber, string $meterType, float $amount, string $customerPhone, string $reference): array
    {
        $cleanMeter = trim($meterNumber);
        $cleanPhone = preg_replace('/\D+/', '', $customerPhone);
        if (str_starts_with($cleanPhone, '234')) {
            $cleanPhone = '0' . substr($cleanPhone, 3);
        }

        $discoId = self::resolveDiscoId($discoCode);
        $cleanType = strtolower(trim($meterType)) === 'postpaid' ? 'postpaid' : 'prepaid';

        if (empty($this->cheapDataHubApiKey)) {
            return [
                'ok' => false,
                'status' => 'failed',
                'message' => 'Electricity supplier gateway is not configured.'
            ];
        }

        // Live CheapDataHub call
        $res = $this->cheapDataHubClient->purchaseElectricity($discoId, $cleanMeter, $cleanType, (float)$amount, $cleanPhone);

        if ($res['ok'] && is_array($res['data'])) {
            $status = $res['data']['status'] ?? false;
            $isSuccess = ($status === true || $status === 1 || $status === '1' || strtolower((string)$status) === 'true' || strtolower((string)$status) === 'success' || strtolower((string)$status) === 'successful');
            if ($isSuccess) {
                $details = is_array($res['data']['details'] ?? null) ? $res['data']['details'] : ($res['data']['data'] ?? []);
                $token = (string)($details['token'] ?? ($details['Token'] ?? ($details['token_code'] ?? ($res['data']['token'] ?? ''))));
                $units = (string)($details['units'] ?? ($details['Units'] ?? ($details['unit'] ?? '')));
                $orderId = (string)($details['ident'] ?? ($details['id'] ?? ($res['data']['transaction_id'] ?? $reference)));

                if (empty($token) && $cleanType === 'prepaid') {
                    $token = (string)($details['pin'] ?? ($details['meter_token'] ?? ''));
                }

                return [
                    'ok' => true,
                    'status' => 'successful',
                    'token' => $token ?: 'Token dispatched via SMS',
                    'units' => $units ?: 'Standard kWh',
                    'provider_order_id' => $orderId,
                    'message' => (string)($res['data']['message'] ?? 'Electricity payment completed successfully.')
                ];
            }

            return [
                'ok' => false,
                'status' => 'failed',
                'message' => (string)($res['data']['message'] ?? 'Electricity recharge was rejected by provider.')
            ];
        }

        $errMsg = is_array($res['data'] ?? null)
            ? (string)($res['data']['message'] ?? ($res['data']['detail'] ?? 'Provider electricity gateway error.'))
            : (string)($res['message'] ?? 'Electricity gateway is temporarily unavailable.');

        return [
            'ok' => false,
            'status' => 'failed',
            'message' => $errMsg
        ];
    }

    // =========================================================================
    // CABLE TV (CheapDataHub DStv, GOtv, StarTimes)
    // =========================================================================

    /**
     * Map package code or provider string to CheapDataHub cable plan_id
     */
    public static function resolveCablePlanId(string $packageOrProvider): int
    {
        $code = strtolower(trim($packageOrProvider));
        if (is_numeric($code) && (int)$code > 0) {
            return (int)$code;
        }

        $planMap = [
            // DStv
            'dstv' => 3, // Default DStv
            'dstv_padi' => 3,
            'dstv_yanga' => 6,
            'dstv_confam' => 7,
            'dstv_compact' => 8,
            'dstv_compact_plus' => 9,
            'dstv_premium' => 10,
            // GOtv
            'gotv' => 4, // Default GOtv
            'gotv_smallie_monthly' => 4,
            'gotv_smallie_quarterly' => 11,
            'gotv_smallie_yearly' => 12,
            'gotv_jinja' => 13,
            'gotv_jolli' => 14,
            'gotv_max' => 15,
            'gotv_supa' => 15,
            // StarTimes
            'startimes' => 20, // Default StarTimes
            'startimes_nova_1_month' => 5,
            'startimes_nova_1_day' => 16,
            'startimes_nova_1_week' => 17,
            'basic_antenna_1_week' => 18,
            'startimes_basic_antenna_1_week' => 18,
            'basic_dish_1_week' => 19,
            'startimes_basic_dish_1_week' => 19,
            'basic_antenna_1_month' => 20,
            'startimes_basic_antenna_1_month' => 20,
            'basic_dish_1month' => 21,
            'startimes_basic_dish_1month' => 21,
            'classic_dish_1_week' => 22,
            'startimes_classic_dish_1_week' => 22,
            'classic_dish_1_month' => 23,
            'startimes_classic_dish_1_month' => 23,
            'super_antenna_1_month' => 24,
            'startimes_super_antenna_1_month' => 24,
            'super_dish_1_month' => 25,
            'startimes_super_dish_1_month' => 25,
            'smart_dish_1_month' => 26,
            'startimes_smart_dish_1_month' => 26
        ];

        return $planMap[$code] ?? 3;
    }

    public function validateSmartCard(string $cableProvider, string $smartCardNumber, ?int $planId = null): array
    {
        $cleanIuc = trim($smartCardNumber);
        if (strlen($cleanIuc) < 6) {
            return [
                'ok' => false,
                'message' => 'Invalid Smartcard / IUC number.'
            ];
        }

        $resolvedPlanId = ($planId !== null && $planId > 0) ? $planId : self::resolveCablePlanId($cableProvider);

        if (empty($this->cheapDataHubApiKey)) {
            return [
                'ok' => false,
                'message' => 'Cable TV verification provider is not configured.'
            ];
        }

        // Live CheapDataHub verification ONLY - NO MOCK / DEMO FALLBACK
        $res = $this->cheapDataHubClient->validateCable($resolvedPlanId, $cleanIuc);

        if ($res['ok'] && is_array($res['data'])) {
            $status = $res['data']['status'] ?? false;
            $isSuccess = ($status === true || $status === 1 || $status === '1' || strtolower((string)$status) === 'true' || strtolower((string)$status) === 'success' || strtolower((string)$status) === 'successful');
            if ($isSuccess) {
                $details = is_array($res['data']['details'] ?? null) ? $res['data']['details'] : ($res['data']['data'] ?? []);
                $name = $details['name'] ?? ($details['customer_name'] ?? ($details['Customer_Name'] ?? ($res['data']['name'] ?? '')));
                $planName = $details['current_plan'] ?? ($details['package'] ?? (strtoupper($cableProvider) . ' Active Bouquet'));
                $dueDate = $details['due_date'] ?? '';

                if (!empty($name)) {
                    return [
                        'ok' => true,
                        'customer_name' => trim((string)$name),
                        'smart_card_number' => $cleanIuc,
                        'current_plan' => (string)$planName,
                        'due_date' => (string)$dueDate,
                        'message' => (string)($res['data']['message'] ?? 'Smartcard / IUC verified successfully.')
                    ];
                }
            }

            $msg = (string)($res['data']['message'] ?? ($res['data']['detail'] ?? ($res['data']['error'] ?? 'Could not verify smartcard number with provider.')));
            return [
                'ok' => false,
                'message' => $msg
            ];
        }

        $msg = is_array($res['data'] ?? null)
            ? (string)($res['data']['message'] ?? ($res['data']['detail'] ?? ($res['data']['error'] ?? 'Smartcard validation could not be completed.')))
            : (string)($res['message'] ?? 'Could not verify smartcard number. Please check and try again.');

        return [
            'ok' => false,
            'message' => $msg
        ];
    }

    public function purchaseCable(string $cableProvider, string $packageCode, string $smartCardNumber, string $customerPhone, string $reference, ?int $planId = null): array
    {
        $cleanCard = trim($smartCardNumber);
        $cleanPhone = preg_replace('/\D+/', '', $customerPhone);
        if (str_starts_with($cleanPhone, '234')) {
            $cleanPhone = '0' . substr($cleanPhone, 3);
        }

        $resolvedPlanId = ($planId !== null && $planId > 0) ? $planId : self::resolveCablePlanId($packageCode ?: $cableProvider);

        if (empty($this->cheapDataHubApiKey)) {
            return [
                'ok' => false,
                'status' => 'failed',
                'message' => 'Cable TV supplier gateway is not configured.'
            ];
        }

        // Live CheapDataHub call
        $res = $this->cheapDataHubClient->purchaseCable($resolvedPlanId, $cleanCard, $cleanPhone);

        if ($res['ok'] && is_array($res['data'])) {
            $status = $res['data']['status'] ?? false;
            $isSuccess = ($status === true || $status === 1 || $status === '1' || strtolower((string)$status) === 'true' || strtolower((string)$status) === 'success' || strtolower((string)$status) === 'successful');
            if ($isSuccess) {
                $details = is_array($res['data']['details'] ?? null) ? $res['data']['details'] : ($res['data']['data'] ?? []);
                $orderId = (string)($details['ident'] ?? ($details['id'] ?? ($res['data']['transaction_id'] ?? $reference)));

                return [
                    'ok' => true,
                    'status' => 'successful',
                    'provider_order_id' => $orderId,
                    'message' => (string)($res['data']['message'] ?? 'Cable TV subscription activated successfully.')
                ];
            }

            return [
                'ok' => false,
                'status' => 'failed',
                'message' => (string)($res['data']['message'] ?? 'Cable TV subscription could not be activated.')
            ];
        }

        $errMsg = is_array($res['data'] ?? null)
            ? (string)($res['data']['message'] ?? ($res['data']['detail'] ?? 'Provider cable TV gateway error.'))
            : (string)($res['message'] ?? 'Cable TV gateway is temporarily unavailable.');

        return [
            'ok' => false,
            'status' => 'failed',
            'message' => $errMsg
        ];
    }

    // =========================================================================
    // EXAM PINs (CheapDataHub: WAEC, NECO, NABTEB)
    // =========================================================================

    public static function resolveExamProductId(string $examCode): int
    {
        $code = strtolower(trim($examCode));
        if (is_numeric($code) && (int)$code > 0) {
            return (int)$code;
        }

        $examMap = [
            'waec' => 1,
            'waec_registration' => 1,
            'neco' => 2,
            'nabteb' => 3
        ];

        return $examMap[$code] ?? 1;
    }

    public function purchaseExamPin(string $examCode, int $quantity, string $customerPhone, string $reference): array
    {
        $quantity = max(1, min(10, $quantity));
        $productId = self::resolveExamProductId($examCode);

        if (empty($this->cheapDataHubApiKey)) {
            return [
                'ok' => false,
                'status' => 'failed',
                'message' => 'Exam PIN supplier gateway is not configured.'
            ];
        }

        // Live CheapDataHub call
        $res = $this->cheapDataHubClient->purchaseExamPin($productId, in_array($quantity, [1, 2, 5], true) ? $quantity : 1);

        if ($res['ok'] && is_array($res['data'])) {
            $status = $res['data']['status'] ?? false;
            $isSuccess = ($status === true || $status === 1 || $status === '1' || strtolower((string)$status) === 'true' || strtolower((string)$status) === 'success' || strtolower((string)$status) === 'successful');
            if ($isSuccess) {
                $details = is_array($res['data']['details'] ?? null) ? $res['data']['details'] : ($res['data']['data'] ?? []);
                $tokens = (array)($details['pins'] ?? ($details['tokens'] ?? ($details['cards'] ?? [])));
                $tokenParts = [];
                $pins = [];

                foreach ($tokens as $idx => $t) {
                    $p = is_array($t) ? ($t['pin'] ?? ($t['Pin'] ?? '')) : (string)$t;
                    $s = is_array($t) ? ($t['serial'] ?? ($t['Serial'] ?? '')) : '';
                    $pins[] = ['pin' => $p, 'serial' => $s];
                    $tokenParts[] = "PIN #" . ($idx + 1) . ": $p" . ($s ? " | Serial: $s" : '');
                }

                $singlePin = (string)($details['pin'] ?? ($details['token'] ?? ''));
                if (empty($tokenParts) && !empty($singlePin)) {
                    $pins[] = ['pin' => $singlePin, 'serial' => (string)($details['serial'] ?? '')];
                    $tokenParts[] = "PIN #1: $singlePin" . (!empty($details['serial']) ? " | Serial: {$details['serial']}" : '');
                }

                $tokenDisplay = !empty($tokenParts) ? implode("\n", $tokenParts) : (string)($res['data']['message'] ?? 'PIN generated');
                $orderId = (string)($details['ident'] ?? ($details['id'] ?? ($res['data']['transaction_id'] ?? $reference)));

                return [
                    'ok' => true,
                    'status' => 'successful',
                    'pins' => $pins,
                    'token_or_pin' => $tokenDisplay,
                    'provider_order_id' => $orderId,
                    'message' => "Successfully generated " . strtoupper(str_replace('_', ' ', $examCode)) . " PIN(s)."
                ];
            }

            return [
                'ok' => false,
                'status' => 'failed',
                'message' => (string)($res['data']['message'] ?? 'Exam PIN generation could not be completed.')
            ];
        }

        $errMsg = is_array($res['data'] ?? null)
            ? (string)($res['data']['message'] ?? ($res['data']['detail'] ?? 'Provider exam PIN gateway error.'))
            : (string)($res['message'] ?? 'Exam PIN gateway is temporarily unavailable.');

        return [
            'ok' => false,
            'status' => 'failed',
            'message' => $errMsg
        ];
    }

    // =========================================================================
    // BULK SMS (Termii Gateway)
    // =========================================================================

    public function sendBulkSms(string $senderId, array $recipients, string $message, string $reference): array
    {
        $cleanRecipients = [];
        foreach ($recipients as $r) {
            $r = preg_replace('/\D+/', '', trim($r));
            if (strlen($r) >= 10) {
                if (str_starts_with($r, '0')) {
                    $r = '234' . substr($r, 1);
                }
                $cleanRecipients[] = $r;
            }
        }

        $cleanRecipients = array_unique($cleanRecipients);
        $count = count($cleanRecipients);

        if ($count === 0) {
            return [
                'ok' => false,
                'message' => 'No valid recipient phone numbers found.'
            ];
        }

        $sender = !empty($senderId) ? trim($senderId) : $this->termiiSenderId;

        if (empty($this->termiiApiKey)) {
            return [
                'ok' => false,
                'status' => 'failed',
                'message' => 'Bulk SMS provider (Termii) is not configured.'
            ];
        }

        // Live Termii SMS Gateway
        $payload = [
            'to' => $cleanRecipients,
            'from' => $sender,
            'sms' => $message,
            'type' => 'plain',
            'channel' => 'generic',
            'api_key' => $this->termiiApiKey
        ];

        if ($count === 1) {
            $payload['to'] = $cleanRecipients[0];
        }

        $res = $this->httpPost($this->termiiBaseUrl . '/sms/send', $payload, [
            "Content-Type: application/json",
            "Accept: application/json"
        ]);

        if ($res['ok'] && is_array($res['data'])) {
            $msgId = (string)($res['data']['message_id'] ?? bin2hex(random_bytes(5)));
            return [
                'ok' => true,
                'status' => 'successful',
                'batch_id' => $msgId,
                'recipient_count' => $count,
                'provider_order_id' => $msgId,
                'message' => (string)($res['data']['message'] ?? "Bulk SMS dispatched to $count recipient(s) successfully.")
            ];
        }

        $errorMsg = is_array($res['data'] ?? null)
            ? (string)($res['data']['message'] ?? ($res['data']['error'] ?? ''))
            : (string)($res['message'] ?? '');

        if (!empty($errorMsg) && (str_contains($errorMsg, 'SENDER_ID_NOT_APPROVED') || str_contains($errorMsg, 'Sender ID'))) {
            $errorMsg = "Termii Error: Sender ID '$sender' is not yet approved on your Termii account. Nigerian telecom regulations require custom Sender IDs to be registered and approved before SMS can be delivered.";
        }

        return [
            'ok' => false,
            'status' => 'failed',
            'message' => $errorMsg ?: 'SMS gateway is unavailable or delivery could not be completed.'
        ];
    }

    private function httpPost(string $url, array $payload, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($res === false) {
            return [
                'ok' => false,
                'http_status' => $code,
                'message' => $err ?: 'Network error connecting to supplier',
                'data' => null
            ];
        }

        $decoded = json_decode($res, true);
        return [
            'ok' => ($code >= 200 && $code < 300),
            'http_status' => $code,
            'data' => $decoded !== null ? $decoded : $res,
            'message' => $code >= 200 && $code < 300 ? 'Success' : 'HTTP ' . $code
        ];
    }
}
