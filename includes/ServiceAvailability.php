<?php

/**
 * ServiceAvailability Helper
 * 
 * Centralized service status checking and professional inactive UI rendering.
 * Ensures consistent handling across Client Dashboard, Services Directory,
 * Service execution pages, and Admin controls.
 */

class ServiceAvailability
{
    private static ?array $cachedStatuses = null;

    /**
     * Map slug aliases to canonical database slugs in `services` table
     */
    public static function canonicalSlug(string $slug): string
    {
        $normalized = strtolower(trim($slug));
        $aliasMap = [
            'buy_data' => 'data',
            'data_bundles' => 'data',
            'airtime_recharge' => 'airtime',
            'cable' => 'cable_tv',
            'electricity_bills' => 'electricity',
            'virtual_numbers' => 'foreign_numbers',
            '5sim' => 'foreign_numbers'
        ];
        return $aliasMap[$normalized] ?? $normalized;
    }

    /**
     * Retrieve all service records keyed by canonical slug
     */
    public static function getAllServices(PDO $pdo, bool $fresh = false): array
    {
        if (self::$cachedStatuses !== null && !$fresh) {
            return self::$cachedStatuses;
        }

        try {
            $stmt = $pdo->query("SELECT id, name, slug, description, image, icon, route, status, sort_order FROM services ORDER BY sort_order ASC, id ASC");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $map = [];
            foreach ($rows as $row) {
                $map[$row['slug']] = $row;
            }
            self::$cachedStatuses = $map;
            return $map;
        } catch (Throwable $e) {
            error_log("ServiceAvailability::getAllServices error: " . $e->getMessage());
            return [];
        }
    }

    public static function clearCache(): void
    {
        self::$cachedStatuses = null;
    }

    /**
     * Check if a given service is currently active
     */
    public static function isServiceActive(PDO $pdo, string $slug, bool $fresh = false): bool
    {
        $services = self::getAllServices($pdo, $fresh);
        $canonical = self::canonicalSlug($slug);
        
        if (!isset($services[$canonical])) {
            return true; // Unknown services default to active if not in table
        }

        return strtolower((string)$services[$canonical]['status']) === 'active';
    }

    /**
     * Render professional "Service Temporarily Inactive" notice block
     */
    public static function renderInactiveNotice(string $serviceName, string $backUrl = 'dashboard.php'): void
    {
        $safeName = htmlspecialchars($serviceName, ENT_QUOTES, 'UTF-8');
        $safeUrl = htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8');

        echo <<<HTML
        <section class="mx-auto w-full max-w-3xl rounded-3xl border border-amber-200/90 bg-gradient-to-b from-amber-50/50 via-white to-white p-6 sm:p-10 shadow-sm text-center my-6 sm:my-8 animate-fade-in">
            <div class="mx-auto flex h-20 w-20 items-center justify-center rounded-3xl bg-amber-100 text-amber-700 shadow-inner">
                <svg class="h-10 w-10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
            </div>

            <div class="mt-5 inline-flex items-center gap-2 rounded-full border border-amber-300 bg-amber-50 px-3.5 py-1 text-xs font-bold text-amber-800">
                <span class="h-2 w-2 rounded-full bg-amber-500 animate-pulse"></span>
                Status: Not Active
            </div>

            <h1 class="mt-4 text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">
                {$safeName} is Temporarily Inactive
            </h1>

            <p class="mx-auto mt-3 max-w-lg text-sm sm:text-base leading-relaxed text-slate-600">
                This service has been temporarily paused for maintenance or administrative updates. Transactions for {$safeName} are paused right now to protect your orders.
            </p>

            <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
                <a href="{$safeUrl}" class="inline-flex items-center justify-center gap-2 rounded-2xl bg-slate-900 px-6 py-3.5 text-sm font-bold text-white shadow-sm transition hover:bg-slate-800 active:scale-[0.98]">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    Return to Dashboard
                </a>
                <a href="services.php" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-slate-200 bg-white px-5 py-3.5 text-sm font-bold text-slate-700 shadow-xs transition hover:bg-slate-50 active:scale-[0.98]">
                    Explore Available Services
                </a>
            </div>

            <div class="mt-8 border-t border-slate-100 pt-5 text-xs text-slate-400">
                Need urgent assistance? <a href="support.php" class="font-semibold text-servora-700 hover:underline">Contact Customer Support</a>
            </div>
        </section>
HTML;
    }
}
