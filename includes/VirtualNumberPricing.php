<?php

/*
|--------------------------------------------------------------------------
| SUBNEXT - VIRTUAL NUMBER PRICING
|--------------------------------------------------------------------------
|
| Central pricing logic for the Foreign Number module.
|
| Formula:
|
| Percentage:
|   profit = provider_cost × percentage / 100
|
| Fixed:
|   profit = fixed amount
|
| The configured minimum profit is always respected.
|
| Final price:
|   provider_cost + profit
|
|--------------------------------------------------------------------------
*/

/**
 * Get the current virtual-number pricing settings.
 */
function getVirtualNumberPricingSettings(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT
            id,
            provider,
            profit_type,
            profit_value,
            minimum_profit,
            status,
            created_at,
            updated_at
        FROM virtual_number_settings
        ORDER BY id ASC
        LIMIT 1
    ");

    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    /*
    |--------------------------------------------------------------------------
    | CREATE DEFAULT SETTINGS IF NONE EXIST
    |--------------------------------------------------------------------------
    */

    if (!$settings) {

        $stmt = $pdo->prepare("
            INSERT INTO virtual_number_settings
            (
                provider,
                profit_type,
                profit_value,
                minimum_profit,
                status
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?
            )
        ");

        $stmt->execute([
            "mock",
            "percentage",
            20.00,
            100.00,
            "active"
        ]);

        $settingsId =
            (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare("
            SELECT
                id,
                provider,
                profit_type,
                profit_value,
                minimum_profit,
                status,
                created_at,
                updated_at
            FROM virtual_number_settings
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->execute([
            $settingsId
        ]);

        $settings =
            $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /*
    |--------------------------------------------------------------------------
    | NORMALIZE SETTINGS
    |--------------------------------------------------------------------------
    */

    $profitType =
        strtolower(
            trim(
                (string) (
                    $settings["profit_type"]
                    ?? "percentage"
                )
            )
        );

    if (
        !in_array(
            $profitType,
            [
                "percentage",
                "fixed"
            ],
            true
        )
    ) {
        $profitType = "percentage";
    }

    $status =
        strtolower(
            trim(
                (string) (
                    $settings["status"]
                    ?? "inactive"
                )
            )
        );

    if (
        !in_array(
            $status,
            [
                "active",
                "inactive"
            ],
            true
        )
    ) {
        $status = "inactive";
    }

    return [
        "id" =>
            (int) (
                $settings["id"]
                ?? 0
            ),

        "provider" =>
            strtolower(
                trim(
                    (string) (
                        $settings["provider"]
                        ?? "mock"
                    )
                )
            ),

        "profit_type" =>
            $profitType,

        "profit_value" =>
            max(
                0,
                round(
                    (float) (
                        $settings["profit_value"]
                        ?? 0
                    ),
                    2
                )
            ),

        "minimum_profit" =>
            max(
                0,
                round(
                    (float) (
                        $settings["minimum_profit"]
                        ?? 0
                    ),
                    2
                )
            ),

        "status" =>
            $status,

        "created_at" =>
            $settings["created_at"]
            ?? null,

        "updated_at" =>
            $settings["updated_at"]
            ?? null
    ];
}


/**
 * Calculate the selling price for a virtual number.
 */
function calculateVirtualNumberPrice(
    float $providerCost,
    array $settings
): array {

    /*
    |--------------------------------------------------------------------------
    | CLEAN PROVIDER COST
    |--------------------------------------------------------------------------
    */

    $providerCost =
        max(
            0,
            round(
                $providerCost,
                2
            )
        );

    /*
    |--------------------------------------------------------------------------
    | CLEAN SETTINGS
    |--------------------------------------------------------------------------
    */

    $profitType =
        strtolower(
            trim(
                (string) (
                    $settings["profit_type"]
                    ?? "percentage"
                )
            )
        );

    $profitValue =
        max(
            0,
            (float) (
                $settings["profit_value"]
                ?? 0
            )
        );

    $minimumProfit =
        max(
            0,
            (float) (
                $settings["minimum_profit"]
                ?? 0
            )
        );

    /*
    |--------------------------------------------------------------------------
    | CALCULATE PROFIT
    |--------------------------------------------------------------------------
    */

    if ($profitType === "fixed") {

        $calculatedProfit =
            $profitValue;

    } else {

        $calculatedProfit =
            $providerCost
            *
            (
                $profitValue
                / 100
            );
    }

    /*
    |--------------------------------------------------------------------------
    | ENFORCE MINIMUM PROFIT
    |--------------------------------------------------------------------------
    */

    $profit =
        max(
            $calculatedProfit,
            $minimumProfit
        );

    $profit =
        round(
            $profit,
            2
        );

    /*
    |--------------------------------------------------------------------------
    | FINAL SELLING PRICE
    |--------------------------------------------------------------------------
    */

    $sellingPrice =
        round(
            $providerCost
            + $profit,
            2
        );

    return [
        "provider_cost" =>
            $providerCost,

        "profit_type" =>
            $profitType,

        "profit_value" =>
            round(
                $profitValue,
                2
            ),

        "minimum_profit" =>
            round(
                $minimumProfit,
                2
            ),

        "profit" =>
            $profit,

        "selling_price" =>
            $sellingPrice
    ];
}


/**
 * Check whether new virtual-number orders are enabled.
 */
function isVirtualNumberModuleActive(
    array $settings
): bool {

    return (
        strtolower(
            trim(
                (string) (
                    $settings["status"]
                    ?? ""
                )
            )
        )
        === "active"
    );
}


/**
 * Get only the final customer selling price.
 */
function getVirtualNumberSellingPrice(
    float $providerCost,
    array $settings
): float {

    $pricing =
        calculateVirtualNumberPrice(
            $providerCost,
            $settings
        );

    return
        (float)
        $pricing["selling_price"];
}


/**
 * Get only the profit amount.
 */
function getVirtualNumberProfit(
    float $providerCost,
    array $settings
): float {

    $pricing =
        calculateVirtualNumberPrice(
            $providerCost,
            $settings
        );

    return
        (float)
        $pricing["profit"];
}