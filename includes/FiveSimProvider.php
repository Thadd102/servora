<?php

/**
 * FiveSimProvider - 5SIM Virtual Number API Adapter
 *
 * Implements server-side integration with 5SIM (https://5sim.net/v1)
 * Adheres to Servora provider safety rules:
 * - API secrets and supplier costs never exposed to client
 * - Safe response normalization
 * - Dynamic supplier country & stock discovery
 * - Strict unavailability handling (no fake numbers, no fake SMS)
 */
class FiveSimProvider
{
    private string $apiKey;
    private string $baseUrl;
    private float $usdToNgnRate = 1600.00; // Exchange rate (USD to NGN) for display and pricing
    private array $cachedProductPrices = [];

    /**
     * Map of ISO 2-letter country codes to 5SIM provider slugs
     */
    private static array $countryMap = [
        'AF' => 'afghanistan', 'AL' => 'albania', 'DZ' => 'algeria', 'AO' => 'angola',
        'AG' => 'antiguaandbarbuda', 'AR' => 'argentina', 'AM' => 'armenia', 'AW' => 'aruba',
        'AU' => 'australia', 'AT' => 'austria', 'AZ' => 'azerbaijan', 'BS' => 'bahamas',
        'BH' => 'bahrain', 'BD' => 'bangladesh', 'BB' => 'barbados', 'BY' => 'belarus',
        'BE' => 'belgium', 'BZ' => 'belize', 'BJ' => 'benin', 'BM' => 'bermuda',
        'BT' => 'bhutan', 'BO' => 'bolivia', 'BA' => 'bosnia', 'BW' => 'botswana',
        'BR' => 'brazil', 'BG' => 'bulgaria', 'BF' => 'burkinafaso', 'BI' => 'burundi',
        'KH' => 'cambodia', 'CM' => 'cameroon', 'CA' => 'canada', 'CF' => 'car',
        'TD' => 'chad', 'CL' => 'chile', 'CN' => 'china', 'CO' => 'colombia',
        'KM' => 'comoros', 'CG' => 'congo', 'CR' => 'costarica', 'HR' => 'croatia',
        'CY' => 'cyprus', 'CZ' => 'czech', 'CD' => 'democratickong', 'DK' => 'denmark',
        'DJ' => 'djibouti', 'DM' => 'dominica', 'DO' => 'dominicana', 'EC' => 'ecuador',
        'EG' => 'egypt', 'SV' => 'salvador', 'GQ' => 'equatorialguinea', 'EE' => 'estonia',
        'ET' => 'ethiopia', 'FI' => 'finland', 'FR' => 'france', 'GF' => 'frenchguiana',
        'GA' => 'gabon', 'GM' => 'gambia', 'GE' => 'georgia', 'DE' => 'germany',
        'GH' => 'ghana', 'GR' => 'greece', 'GD' => 'grenada', 'GP' => 'guadeloupe',
        'GT' => 'guatemala', 'GN' => 'guinea', 'GW' => 'guineabissau', 'GY' => 'guyana',
        'HT' => 'haiti', 'HN' => 'honduras', 'HK' => 'hongkong', 'HU' => 'hungary',
        'IS' => 'iceland', 'IN' => 'india', 'ID' => 'indonesia', 'IQ' => 'iraq',
        'IE' => 'ireland', 'IL' => 'israel', 'IT' => 'italy', 'CI' => 'ivorycoast',
        'JM' => 'jamaica', 'JP' => 'japan', 'JO' => 'jordan', 'KZ' => 'kazakhstan',
        'KE' => 'kenya', 'KW' => 'kuwait', 'KG' => 'kyrgyzstan', 'LA' => 'laos',
        'LV' => 'latvia', 'LB' => 'lebanon', 'LS' => 'lesotho', 'LR' => 'liberia',
        'LY' => 'libya', 'LT' => 'lithuania', 'LU' => 'luxembourg', 'MO' => 'macau',
        'MG' => 'madagascar', 'MW' => 'malawi', 'MY' => 'malaysia', 'MV' => 'maldives',
        'ML' => 'mali', 'MR' => 'mauritania', 'MU' => 'mauritius', 'MX' => 'mexico',
        'MD' => 'moldova', 'MN' => 'mongolia', 'ME' => 'montenegro', 'MS' => 'montserrat',
        'MA' => 'morocco', 'MZ' => 'mozambique', 'MM' => 'myanmar', 'NA' => 'namibia',
        'NP' => 'nepal', 'NL' => 'netherlands', 'NC' => 'newcaledonia', 'NZ' => 'newzealand',
        'NI' => 'nicaragua', 'NE' => 'niger', 'NG' => 'nigeria', 'MK' => 'northmacedonia',
        'NO' => 'norway', 'OM' => 'oman', 'PK' => 'pakistan', 'PA' => 'panama',
        'PG' => 'papuanewguinea', 'PY' => 'paraguay', 'PE' => 'peru', 'PH' => 'philippines',
        'PL' => 'poland', 'PT' => 'portugal', 'PR' => 'puertorico', 'RE' => 'reunion',
        'RO' => 'romania', 'RU' => 'russia', 'RW' => 'rwanda', 'KN' => 'saintkittsandnevis',
        'LC' => 'saintlucia', 'VC' => 'saintvincentandthegrenadines', 'WS' => 'samoa',
        'ST' => 'saotomeandprincipe', 'SA' => 'saudiarabia', 'SN' => 'senegal',
        'RS' => 'serbia', 'SC' => 'seychelles', 'SL' => 'sierraleone', 'SG' => 'singapore',
        'SK' => 'slovakia', 'SI' => 'slovenia', 'SO' => 'somalia', 'ZA' => 'southafrica',
        'SS' => 'southsudan', 'ES' => 'spain', 'LK' => 'srilanka', 'SR' => 'suriname',
        'SZ' => 'swaziland', 'SE' => 'sweden', 'CH' => 'switzerland', 'TW' => 'taiwan',
        'TJ' => 'tajikistan', 'TZ' => 'tanzania', 'TH' => 'thailand', 'TG' => 'togo',
        'TO' => 'tonga', 'TT' => 'trinidadandtobago', 'TN' => 'tunisia', 'TR' => 'turkey',
        'TM' => 'turkmenistan', 'TC' => 'turksandcaicos', 'UG' => 'uganda', 'UA' => 'ukraine',
        'GB' => 'england', 'US' => 'usa', 'UY' => 'uruguay', 'UZ' => 'uzbekistan',
        'VE' => 'venezuela', 'VN' => 'vietnam', 'YE' => 'yemen', 'ZM' => 'zambia', 'ZW' => 'zimbabwe'
    ];

    /**
     * Map of 5SIM provider slugs back to ISO 2-letter codes
     */
    private static ?array $reverseCountryMap = null;

    public function __construct(?string $apiKey = null, ?string $baseUrl = null)
    {
        require_once __DIR__ . '/../config/env.php';

        $this->apiKey = trim((string)($apiKey ?? getenv('FIVESIM_API_KEY') ?: ($_ENV['FIVESIM_API_KEY'] ?? '')));
        $this->baseUrl = rtrim((string)($baseUrl ?? getenv('FIVESIM_BASE_URL') ?: ($_ENV['FIVESIM_BASE_URL'] ?? 'https://5sim.net/v1')), '/');
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Standard list of popular services supported by 5SIM
     */
    public function getServices(): array
    {
        return [
            ['code' => 'whatsapp', 'name' => 'WhatsApp', 'icon' => 'whatsapp'],
            ['code' => 'telegram', 'name' => 'Telegram', 'icon' => 'telegram'],
            ['code' => 'google', 'name' => 'Google / YouTube', 'icon' => 'google'],
            ['code' => 'facebook', 'name' => 'Facebook / Meta', 'icon' => 'facebook'],
            ['code' => 'instagram', 'name' => 'Instagram / Threads', 'icon' => 'instagram'],
            ['code' => 'tiktok', 'name' => 'TikTok', 'icon' => 'tiktok'],
            ['code' => 'twitter', 'name' => 'X (Twitter)', 'icon' => 'twitter'],
            ['code' => 'openai', 'name' => 'OpenAI / ChatGPT', 'icon' => 'openai'],
            ['code' => 'microsoft', 'name' => 'Microsoft / Outlook', 'icon' => 'microsoft'],
            ['code' => 'apple', 'name' => 'Apple', 'icon' => 'apple'],
            ['code' => 'amazon', 'name' => 'Amazon', 'icon' => 'amazon'],
            ['code' => 'netflix', 'name' => 'Netflix', 'icon' => 'netflix'],
            ['code' => 'steam', 'name' => 'Steam', 'icon' => 'steam'],
            ['code' => 'other', 'name' => 'Other Services', 'icon' => 'other']
        ];
    }

    /**
     * Translate ISO 2-letter code to 5SIM slug
     */
    public function getProviderCountry(string $countryCode): string
    {
        $code = strtoupper(trim($countryCode));
        return self::$countryMap[$code] ?? strtolower($countryCode);
    }

    /**
     * Translate 5SIM slug to ISO 2-letter code
     */
    public function getIsoCode(string $providerSlug): string
    {
        if (self::$reverseCountryMap === null) {
            self::$reverseCountryMap = array_flip(self::$countryMap);
        }
        $slug = strtolower(trim($providerSlug));
        return self::$reverseCountryMap[$slug] ?? strtoupper($slug);
    }

    /**
     * Dynamic country list supported by 5SIM.
     * When serviceCode is provided, queries supplier live prices/stock and
     * returns only countries that have active inventory for that service.
     */
    public function getCountries(?string $serviceCode = null): array
    {
        require_once __DIR__ . '/BrandAssetHelper.php';

        $serviceCode = strtolower(trim((string)$serviceCode));

        // If a service code is given and we are configured or online, fetch dynamic inventory
        if ($serviceCode !== '') {
            $prices = $this->loadServicePrices($serviceCode);
            if (!empty($prices)) {
                $countries = [];
                foreach ($prices as $providerCountry => $operators) {
                    // Check if country has at least one operator with stock or cost
                    $hasStock = false;
                    foreach ($operators as $opName => $opData) {
                        if ((int)($opData['count'] ?? 0) > 0 || (float)($opData['cost'] ?? 0) > 0) {
                            $hasStock = true;
                            break;
                        }
                    }

                    if (!$hasStock) {
                        continue;
                    }

                    $iso = $this->getIsoCode($providerCountry);
                    if (strlen($iso) !== 2) {
                        continue;
                    }

                    // Look up professional country name
                    $name = BrandAssetHelper::renderCountryFlag($iso, '', 'sm', false); // verifies code
                    $cleanName = ucwords(str_replace('_', ' ', $providerCountry));
                    if ($iso === 'US') $cleanName = 'United States';
                    elseif ($iso === 'GB') $cleanName = 'United Kingdom';
                    elseif ($iso === 'NG') $cleanName = 'Nigeria';
                    elseif ($iso === 'CA') $cleanName = 'Canada';
                    elseif ($iso === 'DE') $cleanName = 'Germany';
                    elseif ($iso === 'FR') $cleanName = 'France';
                    elseif ($iso === 'ZA') $cleanName = 'South Africa';
                    else {
                        // Check if BrandAssetHelper knows the formal name
                        $dial = BrandAssetHelper::getCountryDialCode($iso);
                        if ($dial !== '') {
                            // Friendly name formatting
                            $cleanName = ucwords(preg_replace('/(?<!\ )[A-Z]/', ' $0', $providerCountry));
                            $cleanName = ucwords(str_replace(['and', 'republic', 'democratic'], ['&', 'Rep.', 'Dem.'], strtolower($cleanName)));
                        }
                    }

                    $countries[] = [
                        'code' => $iso,
                        'provider_code' => $providerCountry,
                        'name' => $cleanName
                    ];
                }

                if (!empty($countries)) {
                    usort($countries, fn($a, $b) => strcasecmp($a['name'], $b['name']));
                    return $countries;
                }
            }
        }

        // Fallback default list of popular supported countries
        return $this->getDefaultCountries();
    }

    /**
     * Standard list of countries supported by 5SIM
     */
    public function getDefaultCountries(): array
    {
        return [
            ['code' => 'US', 'provider_code' => 'usa', 'name' => 'United States'],
            ['code' => 'GB', 'provider_code' => 'england', 'name' => 'United Kingdom'],
            ['code' => 'CA', 'provider_code' => 'canada', 'name' => 'Canada'],
            ['code' => 'NG', 'provider_code' => 'nigeria', 'name' => 'Nigeria'],
            ['code' => 'DE', 'provider_code' => 'germany', 'name' => 'Germany'],
            ['code' => 'FR', 'provider_code' => 'france', 'name' => 'France'],
            ['code' => 'NL', 'provider_code' => 'netherlands', 'name' => 'Netherlands'],
            ['code' => 'ZA', 'provider_code' => 'southafrica', 'name' => 'South Africa'],
            ['code' => 'GH', 'provider_code' => 'ghana', 'name' => 'Ghana'],
            ['code' => 'KE', 'provider_code' => 'kenya', 'name' => 'Kenya'],
            ['code' => 'IN', 'provider_code' => 'india', 'name' => 'India'],
            ['code' => 'BR', 'provider_code' => 'brazil', 'name' => 'Brazil'],
            ['code' => 'ES', 'provider_code' => 'spain', 'name' => 'Spain'],
            ['code' => 'PL', 'provider_code' => 'poland', 'name' => 'Poland'],
            ['code' => 'AU', 'provider_code' => 'australia', 'name' => 'Australia'],
            ['code' => 'AR', 'provider_code' => 'argentina', 'name' => 'Argentina'],
            ['code' => 'CO', 'provider_code' => 'colombia', 'name' => 'Colombia'],
            ['code' => 'EG', 'provider_code' => 'egypt', 'name' => 'Egypt'],
            ['code' => 'ID', 'provider_code' => 'indonesia', 'name' => 'Indonesia'],
            ['code' => 'IE', 'provider_code' => 'ireland', 'name' => 'Ireland'],
            ['code' => 'IT', 'provider_code' => 'italy', 'name' => 'Italy'],
            ['code' => 'JP', 'provider_code' => 'japan', 'name' => 'Japan'],
            ['code' => 'MY', 'provider_code' => 'malaysia', 'name' => 'Malaysia'],
            ['code' => 'MX', 'provider_code' => 'mexico', 'name' => 'Mexico'],
            ['code' => 'NZ', 'provider_code' => 'newzealand', 'name' => 'New Zealand'],
            ['code' => 'PK', 'provider_code' => 'pakistan', 'name' => 'Pakistan'],
            ['code' => 'PH', 'provider_code' => 'philippines', 'name' => 'Philippines'],
            ['code' => 'PT', 'provider_code' => 'portugal', 'name' => 'Portugal'],
            ['code' => 'RO', 'provider_code' => 'romania', 'name' => 'Romania'],
            ['code' => 'SE', 'provider_code' => 'sweden', 'name' => 'Sweden'],
            ['code' => 'TH', 'provider_code' => 'thailand', 'name' => 'Thailand'],
            ['code' => 'TR', 'provider_code' => 'turkey', 'name' => 'Turkey'],
            ['code' => 'UA', 'provider_code' => 'ukraine', 'name' => 'Ukraine'],
            ['code' => 'VN', 'provider_code' => 'vietnam', 'name' => 'Vietnam']
        ];
    }

    /**
     * Load all country prices for a service in ONE single HTTP request,
     * cached in memory so subsequent lookups are instant.
     */
    private function loadServicePrices(string $serviceCode): array
    {
        if (isset($this->cachedProductPrices[$serviceCode])) {
            return $this->cachedProductPrices[$serviceCode];
        }

        $url = $this->baseUrl . "/guest/prices?product=" . urlencode($serviceCode);
        $res = $this->httpGet($url);

        if ($res['ok'] && is_array($res['data'])) {
            $data = $res['data'];
            // 5SIM returns either [$serviceCode => [country => ...]] or [country => [$serviceCode => ...]]
            if (isset($data[$serviceCode]) && is_array($data[$serviceCode])) {
                $this->cachedProductPrices[$serviceCode] = $data[$serviceCode];
                return $data[$serviceCode];
            }

            // Invert if keyed by country
            $byCountry = [];
            foreach ($data as $cName => $cServices) {
                if (isset($cServices[$serviceCode])) {
                    $byCountry[$cName] = $cServices[$serviceCode];
                }
            }
            if (!empty($byCountry)) {
                $this->cachedProductPrices[$serviceCode] = $byCountry;
                return $byCountry;
            }
        }

        return [];
    }

    /**
     * Get price and stock options for a given service and country
     */
    public function getOptions(string $serviceCode, string $countryCode): array
    {
        $providerCountry = $this->getProviderCountry($countryCode);

        // First check in-memory cached prices for this service
        $cached = $this->loadServicePrices($serviceCode);
        if (isset($cached[$providerCountry])) {
            $operators = $cached[$providerCountry];
            $options = [];
            foreach ($operators as $opName => $opData) {
                $costUsd = (float)($opData['cost'] ?? 0);
                $count = (int)($opData['count'] ?? 0);
                if ($costUsd > 0 && $count > 0) {
                    $costNgn = max(100.00, round($costUsd * $this->usdToNgnRate, 2));
                    $options[] = [
                        'operator_code' => $opName,
                        'operator_name' => ucfirst($opName) . " ($count available)",
                        'provider_cost' => $costNgn,
                        'stock' => $count
                    ];
                }
            }
            if (!empty($options)) {
                return $options;
            }
        }

        // If not in cache, query 5SIM prices endpoint specifically
        if ($this->isConfigured()) {
            $url = $this->baseUrl . "/guest/prices?product=" . urlencode($serviceCode) . "&country=" . urlencode($providerCountry);
            $res = $this->httpGet($url);
            if ($res['ok'] && is_array($res['data'])) {
                $data = $res['data'];
                if (isset($data[$providerCountry][$serviceCode])) {
                    $operators = $data[$providerCountry][$serviceCode];
                    $options = [];
                    foreach ($operators as $opName => $opData) {
                        $costUsd = (float)($opData['cost'] ?? 0);
                        $count = (int)($opData['count'] ?? 0);
                        if ($costUsd > 0 && $count > 0) {
                            $costNgn = max(100.00, round($costUsd * $this->usdToNgnRate, 2));
                            $options[] = [
                                'operator_code' => $opName,
                                'operator_name' => ucfirst($opName) . " ($count available)",
                                'provider_cost' => $costNgn,
                                'stock' => $count
                            ];
                        }
                    }
                    if (!empty($options)) {
                        return $options;
                    }
                }
            }
        }

        // Return empty if no stock available (never display fake stock for non-existent numbers)
        return [];
    }

    /**
     * Purchase / Allocate a virtual number from 5SIM
     */
    public function purchase(string $serviceCode, string $countryCode, string $operatorCode): array
    {
        $providerCountry = $this->getProviderCountry($countryCode);
        $operator = empty($operatorCode) ? 'any' : strtolower($operatorCode);

        if ($this->isConfigured()) {
            $endpoint = "/user/buy/activation/" . urlencode($providerCountry) . "/" . urlencode($operator) . "/" . urlencode($serviceCode);
            $res = $this->httpGet($this->baseUrl . $endpoint, [
                "Authorization: Bearer " . $this->apiKey,
                "Accept: application/json"
            ]);

            if ($res['ok'] && is_array($res['data']) && isset($res['data']['id'], $res['data']['phone'])) {
                $order = $res['data'];
                $phone = trim((string)$order['phone']);
                if ($phone !== '') {
                    return [
                        'ok' => true,
                        'state' => 'waiting_sms',
                        'provider' => '5sim',
                        'provider_order_id' => (string)$order['id'],
                        'phone_number' => $phone,
                        'provider_cost' => (float)($order['price'] ?? 0) * $this->usdToNgnRate,
                        'message' => 'Virtual number allocated. Waiting for verification SMS code.'
                    ];
                }
            }

            // Interpret supplier error safely
            $technicalError = is_string($res['data'] ?? null) ? $res['data'] : ($res['message'] ?? 'Supplier reported allocation failure.');

            // Standard client message required by Servora specification:
            $clientMessage = "Number Currently Unavailable: No number is currently available for the selected country/service. Please try again later or choose another option.";

            return [
                'ok' => false,
                'state' => 'failed',
                'is_unavailable' => true,
                'message' => $clientMessage,
                'technical_error' => (string)$technicalError,
                'http_status' => $res['http_status'] ?? 0
            ];
        }

        // If not configured, strictly report unavailable (NEVER generate a fake number)
        return [
            'ok' => false,
            'state' => 'failed',
            'is_unavailable' => true,
            'message' => "Number Currently Unavailable: No number is currently available for the selected country/service. Please try again later or choose another option.",
            'technical_error' => '5SIM API key is not configured.'
        ];
    }

    /**
     * Check status of an activation order on 5SIM
     */
    public function checkStatus(string $providerOrderId): array
    {
        if ($this->isConfigured() && !empty($providerOrderId)) {
            $endpoint = "/user/check/" . urlencode($providerOrderId);
            $res = $this->httpGet($this->baseUrl . $endpoint, [
                "Authorization: Bearer " . $this->apiKey,
                "Accept: application/json"
            ]);

            if ($res['ok'] && is_array($res['data'])) {
                $order = $res['data'];
                $status = strtoupper((string)($order['status'] ?? ''));
                $smsList = $order['sms'] ?? [];
                $smsCode = null;

                if (!empty($smsList) && is_array($smsList)) {
                    $latestSms = end($smsList);
                    $smsCode = $latestSms['code'] ?? null;
                    if (!$smsCode && isset($latestSms['text'])) {
                        if (preg_match('/\b\d{4,8}\b/', $latestSms['text'], $matches)) {
                            $smsCode = $matches[0];
                        }
                    }
                }

                if ($status === 'RECEIVED' || ($status === 'FINISHED' && $smsCode)) {
                    return [
                        'ok' => true,
                        'state' => 'completed',
                        'provider_order_id' => $providerOrderId,
                        'sms_code' => $smsCode,
                        'message' => 'SMS code received successfully!'
                    ];
                }

                if ($status === 'CANCELED') {
                    return [
                        'ok' => true,
                        'state' => 'cancelled',
                        'provider_order_id' => $providerOrderId,
                        'sms_code' => null,
                        'message' => 'Order was cancelled.'
                    ];
                }

                if ($status === 'TIMEOUT') {
                    return [
                        'ok' => true,
                        'state' => 'expired',
                        'provider_order_id' => $providerOrderId,
                        'sms_code' => null,
                        'message' => 'Order timed out.'
                    ];
                }

                if ($status === 'BANNED') {
                    return [
                        'ok' => false,
                        'state' => 'failed',
                        'provider_order_id' => $providerOrderId,
                        'sms_code' => null,
                        'message' => 'Number was reported unavailable.'
                    ];
                }

                return [
                    'ok' => true,
                    'state' => 'waiting_sms',
                    'provider_order_id' => $providerOrderId,
                    'sms_code' => null,
                    'message' => 'Waiting for SMS code.'
                ];
            }
        }

        return [
            'ok' => false,
            'state' => 'failed',
            'provider_order_id' => $providerOrderId,
            'sms_code' => null,
            'message' => 'Provider status could not be confirmed.'
        ];
    }

    /**
     * Cancel an active activation order on 5SIM
     */
    public function cancelOrder(string $providerOrderId): array
    {
        if ($this->isConfigured() && !empty($providerOrderId)) {
            $endpoint = "/user/cancel/" . urlencode($providerOrderId);
            $res = $this->httpGet($this->baseUrl . $endpoint, [
                "Authorization: Bearer " . $this->apiKey,
                "Accept: application/json"
            ]);
            return [
                'ok' => $res['ok'],
                'state' => 'cancelled',
                'message' => $res['ok'] ? 'Order cancelled successfully.' : 'Unable to cancel order.'
            ];
        }

        return [
            'ok' => true,
            'state' => 'cancelled',
            'message' => 'Order cancelled.'
        ];
    }

    /**
     * Finish/Complete an active activation order on 5SIM
     */
    public function finishOrder(string $providerOrderId): array
    {
        if ($this->isConfigured() && !empty($providerOrderId)) {
            $endpoint = "/user/finish/" . urlencode($providerOrderId);
            $res = $this->httpGet($this->baseUrl . $endpoint, [
                "Authorization: Bearer " . $this->apiKey,
                "Accept: application/json"
            ]);
            return [
                'ok' => $res['ok'],
                'state' => 'completed',
                'message' => 'Order finished.'
            ];
        }

        return [
            'ok' => true,
            'state' => 'completed',
            'message' => 'Order finished.'
        ];
    }

    private function httpGet(string $url, array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
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
                'message' => $err ?: 'Network error connecting to 5SIM',
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
