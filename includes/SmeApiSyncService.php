<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/SmeApiClient.php';

/**
 * SmeApiSyncService - Synchronizes official data plans from SMEAPI (smeapi.com.ng)
 * into Subnext's database.
 *
 * Scope: DATA SUBSCRIPTIONS ONLY (MTN, Airtel, Glo, 9mobile).
 * Does not import or touch airtime, electricity, cable TV, or other services.
 */
class SmeApiSyncService
{
    private PDO $pdo;
    private SmeApiClient $client;
    private int $smeProviderId = 0;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        require_once __DIR__ . '/../config/env.php';

        $apiKey = trim((string)(getenv('SMEAPI_API_KEY') ?: ($_ENV['SMEAPI_API_KEY'] ?? '')));
        if ($apiKey === '') {
            try {
                $stmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'smeapi_api_key' LIMIT 1");
                $stmt->execute();
                $val = $stmt->fetchColumn();
                if ($val !== false) {
                    $apiKey = trim((string)$val);
                }
            } catch (\Throwable) {}
        }

        $baseUrl = rtrim((string)(getenv('SMEAPI_BASE_URL') ?: ($_ENV['SMEAPI_BASE_URL'] ?? 'https://smeapi.com.ng')), '/');
        $this->client = new SmeApiClient($apiKey, $baseUrl);

        // Fetch or create SMEAPI in api_providers
        $stmtProv = $pdo->prepare("SELECT id FROM api_providers WHERE slug = 'smeapi' LIMIT 1");
        $stmtProv->execute();
        $provId = $stmtProv->fetchColumn();

        if ($provId) {
            $this->smeProviderId = (int)$provId;
        } else {
            $stmtInsert = $pdo->prepare("
                INSERT INTO api_providers (name, slug, base_url, status)
                VALUES ('SMEAPI', 'smeapi', 'https://smeapi.com.ng', 'active')
            ");
            $stmtInsert->execute();
            $this->smeProviderId = (int)$pdo->lastInsertId();
        }
    }

    /**
     * Get the SMEAPI client instance.
     */
    public function getClient(): SmeApiClient
    {
        return $this->client;
    }

    /**
     * Fetch live data plans from SMEAPI and sync into `data_plans` table.
     *
     * @param float $defaultMargin Markup added to supplier cost price in Naira.
     * @return array Sync summary metrics.
     */
    public function syncDataPlans(float $defaultMargin = 35.00): array
    {
        $result = $this->client->fetchDataPlans();

        if (!$result['ok'] || empty($result['plans'])) {
            throw new RuntimeException($result['message'] ?: 'Failed to retrieve data plans from SMEAPI.');
        }

        $rawPlans = $result['plans'];

        // Map SMEAPI telecommunications networks to Subnext's `networks` table IDs
        // Subnext: 1 = MTN, 2 = Airtel, 3 = Glo, 4 = T2 (9mobile)
        $subnextNetworks = [];
        $stmtNets = $this->pdo->query("SELECT id, slug FROM networks");
        while ($row = $stmtNets->fetch(PDO::FETCH_ASSOC)) {
            $subnextNetworks[$row['slug']] = (int)$row['id'];
        }

        // Subnext data categories
        $categories = [
            'sme' => 1,
            'corporate' => 2,
            'gifting' => 3,
            'social' => 4
        ];
        $stmtCats = $this->pdo->query("SELECT id, slug FROM data_categories");
        while ($row = $stmtCats->fetch(PDO::FETCH_ASSOC)) {
            $categories[$row['slug']] = (int)$row['id'];
        }

        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $byNetwork = [
            'MTN' => 0,
            'AIRTEL' => 0,
            'GLO' => 0,
            '9MOBILE' => 0
        ];

        $this->pdo->beginTransaction();

        try {
            foreach ($rawPlans as $plan) {
                $planId = (int)($plan['id'] ?? 0);
                $netId = (int)($plan['network_id'] ?? 0);
                $netName = strtoupper(trim((string)($plan['network'] ?? '')));
                $costPrice = (float)($plan['price'] ?? 0);
                $rawSize = trim((string)($plan['name'] ?? ''));
                $planType = trim((string)($plan['type'] ?? 'Data'));
                $validityRaw = trim((string)($plan['days'] ?? '30 Days'));

                if ($planId <= 0 || $costPrice <= 0) {
                    $skipped++;
                    continue;
                }

                // Resolve Subnext network ID
                $subnextNetId = null;
                if ($netName === 'MTN' || $netId === 1) {
                    $subnextNetId = $subnextNetworks['mtn'] ?? 1;
                    $byNetwork['MTN']++;
                } elseif ($netName === 'AIRTEL' || $netId === 4) {
                    $subnextNetId = $subnextNetworks['airtel'] ?? 2;
                    $byNetwork['AIRTEL']++;
                } elseif ($netName === 'GLO' || $netId === 2) {
                    $subnextNetId = $subnextNetworks['glo'] ?? 3;
                    $byNetwork['GLO']++;
                } elseif (in_array($netName, ['9MOBILE', 'T2', 'ETISALAT'], true) || $netId === 3) {
                    $subnextNetId = $subnextNetworks['t2'] ?? 4;
                    $byNetwork['9MOBILE']++;
                } else {
                    $skipped++;
                    continue;
                }

                // Standardize validity display
                $validity = $validityRaw;
                if (preg_match('/^(\d+)\s*days?$/i', $validityRaw, $m)) {
                    $validity = $m[1] . ' Days';
                } elseif (preg_match('/^(\d+)\s*hours?$/i', $validityRaw, $m)) {
                    $validity = $m[1] . ' Hours';
                }

                // Standardize data amount
                $dataAmount = $rawSize;
                if (preg_match('/(\d+(?:\.\d+)?\s*(?:MB|GB|TB))/i', $rawSize, $dm)) {
                    $dataAmount = strtoupper(str_replace(' ', '', $dm[1]));
                }

                // Match Category
                $typeLower = strtolower($planType);
                $catId = $categories['gifting'] ?? 3;
                if (str_contains($typeLower, 'sme')) {
                    $catId = $categories['sme'] ?? 1;
                } elseif (str_contains($typeLower, 'corporate') || str_contains($typeLower, 'cg')) {
                    $catId = $categories['corporate'] ?? 2;
                } elseif (str_contains($typeLower, 'social')) {
                    $catId = $categories['social'] ?? 4;
                }

                // Descriptive Title
                $displayName = $netName . ' ' . $dataAmount . ' ' . $planType . ' (' . $validity . ')';

                // Check if already in data_plans for SMEAPI provider
                $stmtCheck = $this->pdo->prepare("
                    SELECT id, selling_price, cost_price, status
                    FROM data_plans
                    WHERE provider_id = ? AND provider_plan_code = ?
                    LIMIT 1
                ");
                $stmtCheck->execute([$this->smeProviderId, (string)$planId]);
                $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

                if ($existing) {
                    $currentSelling = (float)$existing['selling_price'];
                    // Maintain margin: if current selling price is higher than new cost, retain it; otherwise add margin
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
                        $subnextNetId,
                        $catId,
                        $displayName,
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
                        INSERT INTO data_plans (
                            network_id, category_id, provider_id, name,
                            data_amount, validity, provider_plan_code,
                            cost_price, selling_price, status, created_at, updated_at
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW(), NOW())
                    ");
                    $stmtInsert->execute([
                        $subnextNetId,
                        $catId,
                        $this->smeProviderId,
                        $displayName,
                        $dataAmount,
                        $validity,
                        (string)$planId,
                        $costPrice,
                        $sellingPrice
                    ]);
                    $inserted++;
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [
            'total_plans_fetched' => count($rawPlans),
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
            'by_network' => $byNetwork
        ];
    }
}

