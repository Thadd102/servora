<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/CheapDataHubClient.php';

/**
 * CheapDataHubSyncService - Synchronizes official plans, packages & Disco IDs
 * from CheapDataHub API directly into Subnext's database.
 *
 * Covers:
 * 1. Mobile Data Bundles (MTN, Airtel, Glo, T2/9mobile)
 * 2. Cable TV Bouquets (DStv, GOtv, StarTimes)
 * 3. Electricity Discos (10 Discos across Nigeria)
 * 4. Airtime Top-ups (MTN, Airtel, Glo, 9mobile/T2)
 * 5. Exam PIN Products (WAEC, NECO, NABTEB)
 */
class CheapDataHubSyncService
{
    private PDO $pdo;
    private CheapDataHubClient $client;
    private string $baseUrl;
    private string $apiKey;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        require_once __DIR__ . '/../config/env.php';

        $this->apiKey = trim((string)(getenv('CHEAPDATAHUB_API_KEY') ?: ($_ENV['CHEAPDATAHUB_API_KEY'] ?? '')));
        $this->baseUrl = rtrim((string)(getenv('CHEAPDATAHUB_BASE_URL') ?: ($_ENV['CHEAPDATAHUB_BASE_URL'] ?? 'https://www.cheapdatahub.ng/api/v1/resellers')), '/');

        $this->client = new CheapDataHubClient($this->apiKey, $this->baseUrl);
    }

    /**
     * Fetch raw plan rows from CheapDataHub's official plan-ids endpoint,
     * with an embedded verified snapshot as a resilient offline fallback.
     */
    public function fetchOfficialCatalog(): array
    {
        $url = 'https://www.cheapdatahub.ng/api/plan-ids/';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Subnext/2.0 (VTU Plan Sync)'
        ]);
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($html && $httpCode >= 200 && $httpCode < 400) {
            $parsed = $this->parsePlanIdsHtml($html);
            if (!empty($parsed['data']) || !empty($parsed['cable']) || !empty($parsed['electricity'])) {
                return $parsed;
            }
        }

        // Return verified static snapshot if live scrape has network issue
        return $this->getVerifiedCatalogSnapshot();
    }

    /**
     * Parse the HTML table of CheapDataHub /api/plan-ids/
     */
    private function parsePlanIdsHtml(string $html): array
    {
        $catalog = [
            'data' => [],
            'cable' => [],
            'electricity' => [],
            'airtime' => []
        ];

        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        $rows = $xpath->query('//table[@id="planIdTable"]/tbody/tr');
        if (!$rows || $rows->length === 0) {
            $rows = $xpath->query('//table//tbody/tr');
        }

        foreach ($rows as $row) {
            $cols = $xpath->query('./td', $row);
            if ($cols->length < 6) {
                continue;
            }

            $network = trim($cols->item(0)->textContent);
            $service = trim($cols->item(1)->textContent);
            $planName = trim($cols->item(2)->textContent);
            $field = trim($cols->item(3)->textContent);
            $id = (int)trim($cols->item(4)->textContent);
            $rawPrice = trim(str_replace(['—', ' ', ','], '', $cols->item(5)->textContent));
            $price = is_numeric($rawPrice) ? (float)$rawPrice : null;

            $item = [
                'network' => $network,
                'name' => $planName,
                'field' => $field,
                'id' => $id,
                'price' => $price
            ];

            if (strcasecmp($service, 'Data') === 0) {
                $catalog['data'][] = $item;
            } elseif (strcasecmp($service, 'Cable TV') === 0) {
                $catalog['cable'][] = $item;
            } elseif (strcasecmp($service, 'Electricity') === 0) {
                $catalog['electricity'][] = $item;
            } elseif (strcasecmp($service, 'Airtime') === 0) {
                $catalog['airtime'][] = $item;
            }
        }

        return $catalog;
    }

    /**
     * Synchronize all data plans into Subnext's data_plans table.
     */
    public function syncDataPlans(float $defaultMargin = 40.00): array
    {
        $catalog = $this->fetchOfficialCatalog();
        $dataItems = $catalog['data'] ?? [];

        // Ensure CheapDataHub provider exists in api_providers (id = 1)
        $stmtProv = $this->pdo->prepare("SELECT id FROM api_providers WHERE id = 1 OR slug = 'cheapdatahub' LIMIT 1");
        $stmtProv->execute();
        $providerId = (int)$stmtProv->fetchColumn();
        if ($providerId <= 0) {
            $stmtInsertProv = $this->pdo->prepare("
                INSERT INTO api_providers (name, slug, base_url, status, created_at, updated_at)
                VALUES ('CheapDataHub', 'cheapdatahub', ?, 'active', NOW(), NOW())
            ");
            $stmtInsertProv->execute([$this->baseUrl]);
            $providerId = (int)$this->pdo->lastInsertId();
        }

        // Network map: network name -> id in networks table
        $networkMap = [
            'MTN' => 1,
            'AIRTEL' => 2,
            'GLO' => 3,
            '9MOBILE' => 4,
            'T2' => 4,
            'ETISALAT' => 4
        ];

        // Ensure categories exist
        $categories = [
            'sme' => 1,
            'corporate' => 2,
            'gifting' => 3,
            'social' => 4
        ];

        // Check if category 'Awoof' or other custom exists or create if needed
        $stmtCat = $this->pdo->query("SELECT id, slug FROM data_categories");
        while ($row = $stmtCat->fetch(PDO::FETCH_ASSOC)) {
            $categories[$row['slug']] = (int)$row['id'];
        }

        $inserted = 0;
        $updated = 0;
        $total = 0;

        foreach ($dataItems as $item) {
            $bundleId = (int)$item['id'];
            $costPrice = (float)($item['price'] ?? 0);
            $rawName = trim($item['name']);
            $netUpper = strtoupper(trim($item['network']));
            $networkId = $networkMap[$netUpper] ?? 1;

            if ($bundleId <= 0 || $costPrice <= 0) {
                continue;
            }

            // Detect category
            $catId = $categories['gifting'] ?? 3;
            if (stripos($rawName, 'sme') !== false) {
                $catId = $categories['sme'] ?? 1;
            } elseif (stripos($rawName, 'corporate') !== false || stripos($rawName, 'corprate') !== false) {
                $catId = $categories['corporate'] ?? 2;
            } elseif (stripos($rawName, 'social') !== false) {
                $catId = $categories['social'] ?? 4;
            }

            // Extract data amount
            $dataAmount = '';
            if (preg_match('/(\d+(?:\.\d+)?\s*(?:MB|GB|TB))/i', $rawName, $m)) {
                $dataAmount = strtoupper(str_replace(' ', '', $m[1]));
            } else {
                $dataAmount = $rawName;
            }

            // Extract validity
            $validity = '30 Days';
            if (preg_match('/\((\d+\s*(?:Day|Days|Week|Weeks|Month|Months|Hours|Hrs))\)/i', $rawName, $vm)) {
                $validity = trim($vm[1]);
            } elseif (preg_match('/(\d+\s*(?:Day|Days|Week|Weeks|Month|Months))/i', $rawName, $vm)) {
                $validity = trim($vm[1]);
            }

            // Clean plan display name
            $cleanName = $netUpper . ' ' . $rawName;

            // Check if plan already exists for CheapDataHub by provider_plan_code
            $stmtCheck = $this->pdo->prepare("
                SELECT id, selling_price, cost_price, status
                FROM data_plans
                WHERE provider_id = ? AND provider_plan_code = ?
                LIMIT 1
            ");
            $stmtCheck->execute([$providerId, (string)$bundleId]);
            $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                // If selling price was higher than cost, preserve existing selling price or adjust
                $currentSelling = (float)$existing['selling_price'];
                $newSelling = ($currentSelling > $costPrice) ? $currentSelling : round($costPrice + $defaultMargin, 2);

                $stmtUpdate = $this->pdo->prepare("
                    UPDATE data_plans
                    SET network_id = ?,
                        category_id = ?,
                        name = ?,
                        data_amount = ?,
                        validity = ?,
                        cost_price = ?,
                        selling_price = ?,
                        status = 'active',
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmtUpdate->execute([
                    $networkId,
                    $catId,
                    $cleanName,
                    $dataAmount,
                    $validity,
                    $costPrice,
                    $newSelling,
                    $existing['id']
                ]);
                $updated++;
            } else {
                $sellingPrice = round($costPrice + $defaultMargin, 2);
                $stmtInsert = $this->pdo->prepare("
                    INSERT INTO data_plans
                    (network_id, category_id, provider_id, name, data_amount, validity, provider_plan_code, cost_price, selling_price, status, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW(), NOW())
                ");
                $stmtInsert->execute([
                    $networkId,
                    $catId,
                    $providerId,
                    $cleanName,
                    $dataAmount,
                    $validity,
                    (string)$bundleId,
                    $costPrice,
                    $sellingPrice
                ]);
                $inserted++;
            }
            $total++;
        }

        // Deactivate old VTpass data plans (provider_id = 8) to prevent purchases from defunct provider
        $stmtDeactivateVtpass = $this->pdo->prepare("
            UPDATE data_plans
            SET status = 'inactive', updated_at = NOW()
            WHERE provider_id = 8 AND status = 'active'
        ");
        $stmtDeactivateVtpass->execute();
        $deactivatedVtpass = $stmtDeactivateVtpass->rowCount();

        return [
            'ok' => true,
            'total' => $total,
            'inserted' => $inserted,
            'updated' => $updated,
            'deactivated_vtpass' => $deactivatedVtpass
        ];
    }

    /**
     * Synchronize all Cable TV subscription packages into service_products.
     */
    public function syncCablePackages(float $defaultMargin = 100.00): array
    {
        $catalog = $this->fetchOfficialCatalog();
        $cableItems = $catalog['cable'] ?? [];

        $inserted = 0;
        $updated = 0;

        foreach ($cableItems as $item) {
            $planId = (int)$item['id'];
            $rawName = trim($item['name']);
            $providerNet = strtoupper(trim($item['network']));
            $costPrice = (float)($item['price'] ?? 0);

            if ($planId <= 0 || $costPrice <= 0) {
                continue;
            }

            // Derive product_code slug
            $productCode = strtolower(preg_replace('/[^a-z0-9_]/i', '_', $rawName));
            $productCode = trim(preg_replace('/_+/', '_', $productCode), '_');

            // Category
            $category = match ($providerNet) {
                'DSTV' => 'DStv',
                'GOTV' => 'GOtv',
                'STARTIMES' => 'StarTimes',
                default => ucfirst(strtolower($providerNet))
            };

            // Check if product exists by service_slug & (product_code OR meta_data matching plan_id)
            $stmtCheck = $this->pdo->prepare("
                SELECT id, selling_price, pricing_mode, fixed_markup, percentage_markup
                FROM service_products
                WHERE service_slug = 'cable_tv'
                  AND (product_code = ? OR meta_data LIKE ?)
                LIMIT 1
            ");
            $metaSearch = '%"plan_id":' . $planId . '%';
            $stmtCheck->execute([$productCode, $metaSearch]);
            $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            $metaData = json_encode([
                'plan_id' => $planId,
                'cable_provider' => strtolower($providerNet),
                'provider' => 'cheapdatahub'
            ], JSON_UNESCAPED_SLASHES);

            if ($existing) {
                $curSelling = (float)$existing['selling_price'];
                $newSelling = ($curSelling >= $costPrice) ? $curSelling : ($costPrice + $defaultMargin);

                $stmtUpdate = $this->pdo->prepare("
                    UPDATE service_products
                    SET name = ?,
                        category = ?,
                        provider = 'cheapdatahub',
                        provider_cost = ?,
                        selling_price = ?,
                        status = 'active',
                        meta_data = ?,
                        last_price_sync_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmtUpdate->execute([
                    $rawName,
                    $category,
                    $costPrice,
                    $newSelling,
                    $metaData,
                    $existing['id']
                ]);
                $updated++;
            } else {
                $sellingPrice = $costPrice + $defaultMargin;
                $stmtInsert = $this->pdo->prepare("
                    INSERT INTO service_products
                    (service_slug, product_code, name, category, provider, provider_cost, selling_price, pricing_mode, fixed_markup, percentage_markup, status, meta_data, last_price_sync_at, created_at, updated_at)
                    VALUES ('cable_tv', ?, ?, ?, 'cheapdatahub', ?, ?, 'fixed', 0, 0, 'active', ?, NOW(), NOW(), NOW())
                ");
                $stmtInsert->execute([
                    $productCode,
                    $rawName,
                    $category,
                    $costPrice,
                    $sellingPrice,
                    $metaData
                ]);
                $inserted++;
            }
        }

        return [
            'ok' => true,
            'total' => count($cableItems),
            'inserted' => $inserted,
            'updated' => $updated
        ];
    }

    /**
     * Synchronize all official Electricity Discos into service_products.
     */
    public function syncElectricityDiscos(): array
    {
        $catalog = $this->fetchOfficialCatalog();
        $discos = $catalog['electricity'] ?? [];

        $discoSlugMap = [
            1 => ['code' => 'abuja_electric', 'name' => 'Abuja Electric (AEDC)'],
            2 => ['code' => 'eko_electric', 'name' => 'Eko Electric (EKEDC)'],
            3 => ['code' => 'ibadan_electric', 'name' => 'Ibadan Electric (IBEDC)'],
            4 => ['code' => 'ikeja_electric', 'name' => 'Ikeja Electric (IKEDC)'],
            5 => ['code' => 'kaduna_electric', 'name' => 'Kaduna Electric (KAEDCO)'],
            6 => ['code' => 'portharcourt_electric', 'name' => 'Port Harcourt Electric (PHED)'],
            7 => ['code' => 'jos_electric', 'name' => 'Jos Electricity Distribution PLC (JEDPLC)'],
            8 => ['code' => 'enugu_electric', 'name' => 'Enugu Electric (EEDC)'],
            9 => ['code' => 'yola_electric', 'name' => 'Yola Electric (YEDC)'],
            10 => ['code' => 'benin_electric', 'name' => 'Benin Electric (BEDC)']
        ];

        $synced = 0;

        foreach ($discos as $d) {
            $discoId = (int)$d['id'];
            if (!isset($discoSlugMap[$discoId])) {
                continue;
            }

            $info = $discoSlugMap[$discoId];
            $code = $info['code'];
            $name = $info['name'];

            $metaData = json_encode([
                'disco_id' => $discoId,
                'provider' => 'cheapdatahub'
            ], JSON_UNESCAPED_SLASHES);

            $stmtCheck = $this->pdo->prepare("
                SELECT id FROM service_products
                WHERE service_slug = 'electricity' AND product_code = ?
                LIMIT 1
            ");
            $stmtCheck->execute([$code]);
            $existingId = $stmtCheck->fetchColumn();

            if ($existingId) {
                $stmtUpdate = $this->pdo->prepare("
                    UPDATE service_products
                    SET name = ?,
                        category = 'Electricity',
                        provider = 'cheapdatahub',
                        provider_cost = 0.00,
                        selling_price = 0.00,
                        status = 'active',
                        meta_data = ?,
                        last_price_sync_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmtUpdate->execute([$name, $metaData, $existingId]);
            } else {
                $stmtInsert = $this->pdo->prepare("
                    INSERT INTO service_products
                    (service_slug, product_code, name, category, provider, provider_cost, selling_price, pricing_mode, fixed_markup, percentage_markup, status, meta_data, last_price_sync_at, created_at, updated_at)
                    VALUES ('electricity', ?, ?, 'Electricity', 'cheapdatahub', 0.00, 0.00, 'fixed', 0, 0, 'active', ?, NOW(), NOW(), NOW())
                ");
                $stmtInsert->execute([$code, $name, $metaData]);
            }
            $synced++;
        }

        // Deactivate any disco not in CheapDataHub's 10 supported Discos
        $supportedCodes = array_column($discoSlugMap, 'code');
        $inClause = "'" . implode("','", $supportedCodes) . "'";
        $this->pdo->query("
            UPDATE service_products
            SET status = 'inactive', updated_at = NOW()
            WHERE service_slug = 'electricity'
              AND product_code NOT IN ($inClause)
        ");

        return [
            'ok' => true,
            'synced' => $synced
        ];
    }

    /**
     * Synchronize Airtime products in service_products.
     */
    public function syncAirtimeProducts(): array
    {
        $networks = [
            ['code' => 'mtn_vtu', 'name' => 'MTN Airtime VTU', 'category' => 'MTN', 'provider_id' => 1],
            ['code' => 'glo_vtu', 'name' => 'Glo Airtime VTU', 'category' => 'Glo', 'provider_id' => 2],
            ['code' => 'airtel_vtu', 'name' => 'Airtel Airtime VTU', 'category' => 'Airtel', 'provider_id' => 3],
            ['code' => '9mobile_vtu', 'name' => '9mobile (T2) Airtime VTU', 'category' => '9mobile', 'provider_id' => 4],
        ];

        $synced = 0;
        foreach ($networks as $net) {
            $metaData = json_encode([
                'provider_id' => $net['provider_id'],
                'provider' => 'cheapdatahub'
            ], JSON_UNESCAPED_SLASHES);

            $stmtCheck = $this->pdo->prepare("
                SELECT id FROM service_products
                WHERE service_slug = 'airtime' AND product_code = ?
                LIMIT 1
            ");
            $stmtCheck->execute([$net['code']]);
            $existingId = $stmtCheck->fetchColumn();

            if ($existingId) {
                $stmtUpdate = $this->pdo->prepare("
                    UPDATE service_products
                    SET name = ?,
                        category = ?,
                        provider = 'cheapdatahub',
                        status = 'active',
                        meta_data = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmtUpdate->execute([$net['name'], $net['category'], $metaData, $existingId]);
            } else {
                $stmtInsert = $this->pdo->prepare("
                    INSERT INTO service_products
                    (service_slug, product_code, name, category, provider, provider_cost, selling_price, pricing_mode, fixed_markup, percentage_markup, status, meta_data, created_at, updated_at)
                    VALUES ('airtime', ?, ?, ?, 'cheapdatahub', 98.00, 100.00, 'fixed', 0, 2.0, 'active', ?, NOW(), NOW())
                ");
                $stmtInsert->execute([$net['code'], $net['name'], $net['category'], $metaData]);
            }
            $synced++;
        }

        return ['ok' => true, 'synced' => $synced];
    }

    /**
     * Synchronize Exam PINs from CheapDataHub API
     */
    public function syncExamPins(float $defaultMargin = 200.00): array
    {
        $res = $this->client->getExamPinProducts();
        $items = null;
        if ($res['ok'] && isset($res['data'])) {
            if (isset($res['data']['data']) && is_array($res['data']['data'])) {
                $items = $res['data']['data'];
            } elseif (is_array($res['data']) && isset($res['data'][0])) {
                $items = $res['data'];
            }
        }

        if (!is_array($items) || empty($items)) {
            // Default verified products
            $items = [
                ['id' => 1, 'exam_name' => 'WAEC', 'price' => 6000],
                ['id' => 2, 'exam_name' => 'NECO', 'price' => 2500],
                ['id' => 3, 'exam_name' => 'NABTEB', 'price' => 1200]
            ];
        }

        $synced = 0;
        foreach ($items as $item) {
            $prodId = (int)($item['id'] ?? 0);
            $examName = strtoupper(trim((string)($item['exam_name'] ?? '')));
            $costPrice = (float)($item['price'] ?? ($item['reseller_price'] ?? 0));

            if ($prodId <= 0 || empty($examName)) {
                continue;
            }

            $productCode = strtolower($examName);
            $fullName = $examName . ' Result Checker PIN';

            $metaData = json_encode([
                'product_id' => $prodId,
                'exam_name' => $examName,
                'provider' => 'cheapdatahub'
            ], JSON_UNESCAPED_SLASHES);

            $stmtCheck = $this->pdo->prepare("
                SELECT id, selling_price FROM service_products
                WHERE service_slug = 'exam_pins'
                  AND (product_code = ? OR product_code = ? OR meta_data LIKE ?)
                LIMIT 1
            ");
            $metaSearch = '%"product_id":' . $prodId . '%';
            $stmtCheck->execute([$productCode, $productCode . '_result', $metaSearch]);
            $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $curSelling = (float)$existing['selling_price'];
                $newSelling = ($curSelling >= $costPrice) ? $curSelling : ($costPrice + $defaultMargin);

                $stmtUpdate = $this->pdo->prepare("
                    UPDATE service_products
                    SET name = ?,
                        category = ?,
                        provider = 'cheapdatahub',
                        provider_cost = ?,
                        selling_price = ?,
                        status = 'active',
                        meta_data = ?,
                        last_price_sync_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmtUpdate->execute([$fullName, $examName, $costPrice, $newSelling, $metaData, $existing['id']]);
            } else {
                $sellingPrice = $costPrice + $defaultMargin;
                $stmtInsert = $this->pdo->prepare("
                    INSERT INTO service_products
                    (service_slug, product_code, name, category, provider, provider_cost, selling_price, pricing_mode, fixed_markup, percentage_markup, status, meta_data, last_price_sync_at, created_at, updated_at)
                    VALUES ('exam_pins', ?, ?, ?, 'cheapdatahub', ?, ?, 'fixed', 0, 0, 'active', ?, NOW(), NOW(), NOW())
                ");
                $stmtInsert->execute([$productCode, $fullName, $examName, $costPrice, $sellingPrice, $metaData]);
            }
            $synced++;
        }

        // Deactivate exams not provided by CheapDataHub (e.g. JAMB)
        $this->pdo->query("
            UPDATE service_products
            SET status = 'inactive', updated_at = NOW()
            WHERE service_slug = 'exam_pins'
              AND product_code NOT IN ('waec', 'waec_result', 'neco', 'neco_result', 'nabteb', 'nabteb_result')
        ");

        return ['ok' => true, 'synced' => $synced];
    }

    /**
     * Run all synchronization tasks in one master operation.
     */
    public function syncAllServices(float $dataMargin = 40.00, float $cableMargin = 100.00): array
    {
        $dataResult = $this->syncDataPlans($dataMargin);
        $cableResult = $this->syncCablePackages($cableMargin);
        $elecResult = $this->syncElectricityDiscos();
        $airtimeResult = $this->syncAirtimeProducts();
        $examResult = $this->syncExamPins();

        return [
            'ok' => true,
            'data' => $dataResult,
            'cable' => $cableResult,
            'electricity' => $elecResult,
            'airtime' => $airtimeResult,
            'exam_pins' => $examResult,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Fallback verified catalog directly from CheapDataHub's current documentation
     * in case of external network interruption.
     */
    private function getVerifiedCatalogSnapshot(): array
    {
        return [
            'airtime' => [
                ['network' => '9MOBILE', 'name' => '9mobile Airtime', 'field' => 'provider_id', 'id' => 4, 'price' => null],
                ['network' => 'AIRTEL', 'name' => 'airtel Airtime', 'field' => 'provider_id', 'id' => 3, 'price' => null],
                ['network' => 'GLO', 'name' => 'glo Airtime', 'field' => 'provider_id', 'id' => 2, 'price' => null],
                ['network' => 'MTN', 'name' => 'mtn Airtime', 'field' => 'provider_id', 'id' => 1, 'price' => null],
            ],
            'data' => [
                // Airtel (12 plans)
                ['network' => 'AIRTEL', 'name' => '1GB (Social Bundle) Gifting (3 Days)', 'field' => 'bundle_id', 'id' => 70, 'price' => 295.0],
                ['network' => 'AIRTEL', 'name' => '500MB Gifting (7 days)', 'field' => 'bundle_id', 'id' => 13, 'price' => 490.0],
                ['network' => 'AIRTEL', 'name' => '1.5GB Gifting (1 Day)', 'field' => 'bundle_id', 'id' => 69, 'price' => 500.0],
                ['network' => 'AIRTEL', 'name' => '1.5GB Gifting (2 Days)', 'field' => 'bundle_id', 'id' => 66, 'price' => 599.0],
                ['network' => 'AIRTEL', 'name' => '1GB Gifting (7 Days)', 'field' => 'bundle_id', 'id' => 15, 'price' => 800.0],
                ['network' => 'AIRTEL', 'name' => '2GB Gifting (30 Days)', 'field' => 'bundle_id', 'id' => 17, 'price' => 1490.0],
                ['network' => 'AIRTEL', 'name' => '5GB Gifting (7 Days)', 'field' => 'bundle_id', 'id' => 52, 'price' => 1570.0],
                ['network' => 'AIRTEL', 'name' => '3GB Gifting (30 Days)', 'field' => 'bundle_id', 'id' => 18, 'price' => 1960.0],
                ['network' => 'AIRTEL', 'name' => '6GB SME (7 Days)', 'field' => 'bundle_id', 'id' => 22, 'price' => 2455.0],
                ['network' => 'AIRTEL', 'name' => '4GB Gifting (30 Days)', 'field' => 'bundle_id', 'id' => 19, 'price' => 2570.0],
                ['network' => 'AIRTEL', 'name' => '8GB Gifting (30 Days)', 'field' => 'bundle_id', 'id' => 20, 'price' => 2999.0],
                ['network' => 'AIRTEL', 'name' => '10GB Gifting (30 Days)', 'field' => 'bundle_id', 'id' => 21, 'price' => 4070.0],

                // Glo (13 plans)
                ['network' => 'GLO', 'name' => '200 MB corprate gifting (1 Day)', 'field' => 'bundle_id', 'id' => 42, 'price' => 92.0],
                ['network' => 'GLO', 'name' => '500MB corprate gifting (30 Days)', 'field' => 'bundle_id', 'id' => 35, 'price' => 225.0],
                ['network' => 'GLO', 'name' => '1GB Awoof (1 Day)', 'field' => 'bundle_id', 'id' => 84, 'price' => 250.0],
                ['network' => 'GLO', 'name' => '1GB corprate gifting (3 Days)', 'field' => 'bundle_id', 'id' => 68, 'price' => 300.0],
                ['network' => 'GLO', 'name' => '1GB corprate gifting (30 Days)', 'field' => 'bundle_id', 'id' => 36, 'price' => 425.0],
                ['network' => 'GLO', 'name' => '1GB Gifting (14 Days)', 'field' => 'bundle_id', 'id' => 41, 'price' => 485.0],
                ['network' => 'GLO', 'name' => '2GB corprate gifting (30 Days)', 'field' => 'bundle_id', 'id' => 40, 'price' => 850.0],
                ['network' => 'GLO', 'name' => '3GB corprate gifting (30 Days)', 'field' => 'bundle_id', 'id' => 37, 'price' => 1300.0],
                ['network' => 'GLO', 'name' => '5GB corprate gifting (7 Days)', 'field' => 'bundle_id', 'id' => 54, 'price' => 1699.0],
                ['network' => 'GLO', 'name' => '5GB corprate gifting (30 Days)', 'field' => 'bundle_id', 'id' => 38, 'price' => 2250.0],
                ['network' => 'GLO', 'name' => '10GB corprate gifting (30 Days)', 'field' => 'bundle_id', 'id' => 39, 'price' => 4390.0],
                ['network' => 'GLO', 'name' => '20.5GB Gifting (30 Days)', 'field' => 'bundle_id', 'id' => 59, 'price' => 5300.0],
                ['network' => 'GLO', 'name' => '107GB Gifting (30 Days)', 'field' => 'bundle_id', 'id' => 58, 'price' => 19300.0],

                // MTN (24 plans)
                ['network' => 'MTN', 'name' => '110MB Gifting (1 Day)', 'field' => 'bundle_id', 'id' => 43, 'price' => 99.0],
                ['network' => 'MTN', 'name' => '230MB Gifting (1 Day)', 'field' => 'bundle_id', 'id' => 74, 'price' => 200.0],
                ['network' => 'MTN', 'name' => '500MB SME (2 Days)', 'field' => 'bundle_id', 'id' => 76, 'price' => 250.0],
                ['network' => 'MTN', 'name' => '500MB data share (30 Days)', 'field' => 'bundle_id', 'id' => 44, 'price' => 300.0],
                ['network' => 'MTN', 'name' => '1GB SME (2 Days)', 'field' => 'bundle_id', 'id' => 77, 'price' => 399.0],
                ['network' => 'MTN', 'name' => '1GB Awoof (30 Days)', 'field' => 'bundle_id', 'id' => 78, 'price' => 430.0],
                ['network' => 'MTN', 'name' => '1GB SME (7 Days)', 'field' => 'bundle_id', 'id' => 45, 'price' => 450.0],
                ['network' => 'MTN', 'name' => '1GB SME (30 Days)', 'field' => 'bundle_id', 'id' => 46, 'price' => 570.0],
                ['network' => 'MTN', 'name' => '2GB Gifting (7 Days)', 'field' => 'bundle_id', 'id' => 71, 'price' => 900.0],
                ['network' => 'MTN', 'name' => '2.5GB Gifting (2 Days)', 'field' => 'bundle_id', 'id' => 27, 'price' => 900.0],
                ['network' => 'MTN', 'name' => '2GB SME (7 Days)', 'field' => 'bundle_id', 'id' => 47, 'price' => 930.0],
                ['network' => 'MTN', 'name' => '3.5GB Gifting (1 Day)', 'field' => 'bundle_id', 'id' => 60, 'price' => 1050.0],
                ['network' => 'MTN', 'name' => '2GB SME (30 Days)', 'field' => 'bundle_id', 'id' => 48, 'price' => 1150.0],
                ['network' => 'MTN', 'name' => '4GB Gifting (2 Days)', 'field' => 'bundle_id', 'id' => 61, 'price' => 1175.0],
                ['network' => 'MTN', 'name' => '3GB SME (30 Days)', 'field' => 'bundle_id', 'id' => 49, 'price' => 1370.0],
                ['network' => 'MTN', 'name' => '5GB SME (30 Days)', 'field' => 'bundle_id', 'id' => 50, 'price' => 2050.0],
                ['network' => 'MTN', 'name' => '6GB Gifting (7 Days)', 'field' => 'bundle_id', 'id' => 53, 'price' => 2495.0],
                ['network' => 'MTN', 'name' => '11GB Gifting (7 Days)', 'field' => 'bundle_id', 'id' => 55, 'price' => 3550.0],
                ['network' => 'MTN', 'name' => '7GB Gifting (30 Days)', 'field' => 'bundle_id', 'id' => 33, 'price' => 3600.0],
                ['network' => 'MTN', 'name' => '10GB Gifting (30 Days)', 'field' => 'bundle_id', 'id' => 67, 'price' => 4800.0],
                ['network' => 'MTN', 'name' => '36GB Gifting (30 Days)', 'field' => 'bundle_id', 'id' => 57, 'price' => 10900.0],
                ['network' => 'MTN', 'name' => '75GB SME (30 Days)', 'field' => 'bundle_id', 'id' => 51, 'price' => 17990.0]
            ],
            'electricity' => [
                ['network' => 'ABUJA ELECTRIC AEDC', 'name' => 'Abuja Electric AEDC', 'field' => 'disco_id', 'id' => 1, 'price' => null],
                ['network' => 'BENIN ELECTRIC', 'name' => 'Benin Electric', 'field' => 'disco_id', 'id' => 10, 'price' => null],
                ['network' => 'EKO ELECTRIC (EKEDC)', 'name' => 'Eko Electric (EKEDC)', 'field' => 'disco_id', 'id' => 2, 'price' => null],
                ['network' => 'ENUGU ELECTRIC', 'name' => 'Enugu Electric', 'field' => 'disco_id', 'id' => 8, 'price' => null],
                ['network' => 'IBADAN ELECTRIC (IBEDC)', 'name' => 'Ibadan Electric (IBEDC)', 'field' => 'disco_id', 'id' => 3, 'price' => null],
                ['network' => 'IKEJA ELECTRIC (IKEDC)', 'name' => 'Ikeja Electric (IKEDC)', 'field' => 'disco_id', 'id' => 4, 'price' => null],
                ['network' => 'JOS ELECTRICITY DISTRIBUTION PLC (JEDPLC)', 'name' => 'Jos Electricity Distribution PLC (JEDplc)', 'field' => 'disco_id', 'id' => 7, 'price' => null],
                ['network' => 'KADUNA ELECTRIC', 'name' => 'Kaduna Electric', 'field' => 'disco_id', 'id' => 5, 'price' => null],
                ['network' => 'PORT HARCOURT ELECTRIC', 'name' => 'Port Harcourt Electric', 'field' => 'disco_id', 'id' => 6, 'price' => null],
                ['network' => 'YOLA ELECTRIC', 'name' => 'Yola Electric', 'field' => 'disco_id', 'id' => 9, 'price' => null]
            ],
            'cable' => [
                // DStv
                ['network' => 'DSTV', 'name' => 'DStv Padi', 'field' => 'plan_id', 'id' => 3, 'price' => 4400.0],
                ['network' => 'DSTV', 'name' => 'DStv Yanga', 'field' => 'plan_id', 'id' => 6, 'price' => 6000.0],
                ['network' => 'DSTV', 'name' => 'DStv Confam', 'field' => 'plan_id', 'id' => 7, 'price' => 11000.0],
                ['network' => 'DSTV', 'name' => 'DStv Compact', 'field' => 'plan_id', 'id' => 8, 'price' => 19000.0],
                ['network' => 'DSTV', 'name' => 'DStv Compact Plus', 'field' => 'plan_id', 'id' => 9, 'price' => 30000.0],
                ['network' => 'DSTV', 'name' => 'DStv Premium', 'field' => 'plan_id', 'id' => 10, 'price' => 44500.0],

                // GOtv
                ['network' => 'GOTV', 'name' => 'GOtv Smallie-monthly', 'field' => 'plan_id', 'id' => 4, 'price' => 1900.0],
                ['network' => 'GOTV', 'name' => 'GOtv Jinja', 'field' => 'plan_id', 'id' => 11, 'price' => 3900.0],
                ['network' => 'GOTV', 'name' => 'Gotv Jolli', 'field' => 'plan_id', 'id' => 12, 'price' => 5800.0],
                ['network' => 'GOTV', 'name' => 'GOtv Max', 'field' => 'plan_id', 'id' => 13, 'price' => 8500.0],
                ['network' => 'GOTV', 'name' => 'GOtv Supa', 'field' => 'plan_id', 'id' => 14, 'price' => 11400.0],
                ['network' => 'GOTV', 'name' => 'GOtv Supa Plus', 'field' => 'plan_id', 'id' => 15, 'price' => 16800.0],

                // StarTimes
                ['network' => 'STARTIMES', 'name' => 'Nova (antenna) -1 week', 'field' => 'plan_id', 'id' => 5, 'price' => 700.0],
                ['network' => 'STARTIMES', 'name' => 'Nova (Dish) - 1 Week', 'field' => 'plan_id', 'id' => 16, 'price' => 700.0],
                ['network' => 'STARTIMES', 'name' => 'Nova (Antenna) - 1 Month', 'field' => 'plan_id', 'id' => 17, 'price' => 2100.0],
                ['network' => 'STARTIMES', 'name' => 'Basic (Antenna) -1 Week', 'field' => 'plan_id', 'id' => 18, 'price' => 1400.0],
                ['network' => 'STARTIMES', 'name' => 'Basic (Dish) - 1 week', 'field' => 'plan_id', 'id' => 19, 'price' => 1700.0],
                ['network' => 'STARTIMES', 'name' => 'Basic (Antenna)- 1 month', 'field' => 'plan_id', 'id' => 20, 'price' => 4000.0],
                ['network' => 'STARTIMES', 'name' => 'Basic (dish) - 1Month', 'field' => 'plan_id', 'id' => 21, 'price' => 5100.0],
                ['network' => 'STARTIMES', 'name' => 'Classic (Dish) - 1 Week', 'field' => 'plan_id', 'id' => 22, 'price' => 2500.0],
                ['network' => 'STARTIMES', 'name' => 'Classic (Dish) -1 Month', 'field' => 'plan_id', 'id' => 23, 'price' => 7400.0],
                ['network' => 'STARTIMES', 'name' => 'Super (Dish) - 1 Week', 'field' => 'plan_id', 'id' => 24, 'price' => 3300.0],
                ['network' => 'STARTIMES', 'name' => 'Super (Antenna) - 1 week', 'field' => 'plan_id', 'id' => 25, 'price' => 3200.0],
                ['network' => 'STARTIMES', 'name' => 'Super (Antenna) -1 Month', 'field' => 'plan_id', 'id' => 26, 'price' => 9500.0]
            ]
        ];
    }
}
