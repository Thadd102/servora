<?php

require_once "../config/database.php";
require_once "../includes/client_auth.php";
require_once "../includes/VirtualNumberProvider.php";
require_once "../includes/VirtualNumberPricing.php";
require_once "../includes/BrandAssetHelper.php";

$userId = (int) ($_SESSION["user_id"] ?? 0);

if ($userId <= 0) {
    header("Location: ../login.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION["csrf_token"];

/*
|--------------------------------------------------------------------------
| REQUEST TOKEN
|--------------------------------------------------------------------------
*/

$requestToken = bin2hex(random_bytes(32));

$_SESSION["virtual_number_request_tokens"] ??= [];

$_SESSION["virtual_number_request_tokens"][$requestToken] = [
    "created_at" => time()
];

/*
|--------------------------------------------------------------------------
| CLEAN OLD REQUEST TOKENS
|--------------------------------------------------------------------------
*/

foreach (
    $_SESSION["virtual_number_request_tokens"]
    as $token => $tokenData
) {
    $createdAt = (int) (
        $tokenData["created_at"] ?? 0
    );

    if (
        $createdAt <= 0
        || (time() - $createdAt) > 1800
    ) {
        unset(
            $_SESSION["virtual_number_request_tokens"][$token]
        );
    }
}

/*
|--------------------------------------------------------------------------
| SERVICE
|--------------------------------------------------------------------------
*/

$serviceCode = strtolower(
    trim(
        (string) ($_GET["service"] ?? "")
    )
);

$serviceCode = preg_replace(
    '/[^a-z0-9_-]/',
    '',
    $serviceCode
) ?? "";

if ($serviceCode === "") {
    header("Location: foreign_numbers.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| PROVIDER
|--------------------------------------------------------------------------
*/

$provider = new VirtualNumberProvider();

$services = $provider->getServices();

$selectedService = null;

foreach ($services as $service) {

    $code = strtolower(
        trim(
            (string) ($service["code"] ?? "")
        )
    );

    if ($code !== $serviceCode) {
        continue;
    }

    $selectedService = [
        "code" => $code,

        "name" => trim(
            (string) ($service["name"] ?? "")
        ),

        "icon" => trim(
            (string) ($service["icon"] ?? "other")
        )
    ];

    break;
}

if (
    $selectedService === null
    || $selectedService["name"] === ""
) {
    header("Location: foreign_numbers.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| WALLET
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT balance
    FROM wallets
    WHERE user_id = ?
    LIMIT 1
");

$stmt->execute([$userId]);

$walletBalance = (float) (
    $stmt->fetchColumn() ?: 0
);

$fullName = trim((string)($_SESSION["full_name"] ?? "Client"));
$profileInitial = strtoupper(substr($fullName, 0, 1) ?: "C");

/*
|--------------------------------------------------------------------------
| CENTRAL PRICING SETTINGS
|--------------------------------------------------------------------------
*/

$pricingSettings =
    getVirtualNumberPricingSettings(
        $pdo
    );

$moduleActive =
    isVirtualNumberModuleActive(
        $pricingSettings
    );

/*
|--------------------------------------------------------------------------
| COUNTRY FLAG
|--------------------------------------------------------------------------
*/

function countryFlag(
    string $countryCode
): string {

    $countryCode = strtoupper(
        trim($countryCode)
    );

    if (
        !preg_match(
            '/^[A-Z]{2}$/',
            $countryCode
        )
    ) {
        return "🌐";
    }

    $flag = "";

    foreach (
        str_split($countryCode)
        as $letter
    ) {

        if (function_exists("mb_chr")) {

            $flag .= mb_chr(
                127397 + ord($letter),
                "UTF-8"
            );

        } else {

            $flag .= $letter;
        }
    }

    return $flag;
}

/*
|--------------------------------------------------------------------------
| BUILD INVENTORY
|--------------------------------------------------------------------------
*/

$countryOptions = [];

if ($moduleActive) {

    $countries = $provider->getCountries(
        $serviceCode
    );

    foreach ($countries as $country) {

        $countryCode = strtoupper(
            trim(
                (string) ($country["code"] ?? "")
            )
        );

        $countryName = trim(
            (string) ($country["name"] ?? "")
        );

        if (
            !preg_match(
                '/^[A-Z]{2}$/',
                $countryCode
            )
            || $countryName === ""
        ) {
            continue;
        }

        try {

            $options = $provider->getOptions(
                $serviceCode,
                $countryCode
            );

        } catch (Throwable $exception) {

            error_log(
                "Virtual number inventory error: "
                . $exception->getMessage()
            );

            $options = [];
        }

        $availableOptions = [];

        foreach ($options as $option) {

            $operatorCode = strtolower(
                trim(
                    (string) (
                        $option["operator_code"]
                        ?? ""
                    )
                )
            );

            $operatorName = trim(
                (string) (
                    $option["operator_name"]
                    ?? "Available Number"
                )
            );

            $providerCost = round(
                (float) (
                    $option["provider_cost"]
                    ?? 0
                ),
                2
            );

            $stock = (int) (
                $option["stock"]
                ?? 0
            );

            if (
                $operatorCode === ""
                || $providerCost <= 0
                || $stock <= 0
            ) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | CENTRAL PRICE CALCULATION
            |--------------------------------------------------------------------------
            */

            $pricing =
                calculateVirtualNumberPrice(
                    $providerCost,
                    $pricingSettings
                );

            $availableOptions[] = [
                "operator_code" =>
                    $operatorCode,

                "operator_name" =>
                    $operatorName,

                "provider_cost" =>
                    $pricing["provider_cost"],

                "selling_price" =>
                    $pricing["selling_price"],

                "stock" =>
                    $stock
            ];
        }

        if ($availableOptions === []) {
            continue;
        }

        $countryLower = strtolower($countryCode);
        $dialCode = BrandAssetHelper::getCountryDialCode($countryCode);

        $countryOptions[] = [
            "code" => $countryCode,
            "name" => $countryName,
            "dial_code" => $dialCode,
            "flag" => countryFlag($countryCode),
            "flag_url" => "https://flagcdn.com/w40/{$countryLower}.png",
            "flag_url_2x" => "https://flagcdn.com/w80/{$countryLower}.png",
            "flag_html" => BrandAssetHelper::renderCountryFlag($countryCode, $countryName, 'sm', true),
            "options" => $availableOptions
        ];
    }
}

/*
|--------------------------------------------------------------------------
| SORT COUNTRIES
|--------------------------------------------------------------------------
*/

usort(
    $countryOptions,

    function (
        array $a,
        array $b
    ): int {

        return strcasecmp(
            $a["name"],
            $b["name"]
        );
    }
);

/*
|--------------------------------------------------------------------------
| JSON INVENTORY
|--------------------------------------------------------------------------
*/

$countryOptionsJson = json_encode(
    $countryOptions,
    JSON_UNESCAPED_SLASHES
    | JSON_UNESCAPED_UNICODE
);

if ($countryOptionsJson === false) {
    $countryOptionsJson = "[]";
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<meta
    name="robots"
    content="noindex,nofollow"
>

<title>
    <?= htmlspecialchars(
        $selectedService["name"],
        ENT_QUOTES,
        "UTF-8"
    ) ?>
    Verification Number | Servora
</title>

<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = {
        theme: {
            extend: {
                colors: {
                    servora: {
                        50: '#F5F3FF', 100: '#EDE9FE', 200: '#DDD6FE',
                        500: '#635BDB', 600: '#5146C7', 700: '#3E37B7',
                        800: '#312E81', 900: '#1E1B4B'
                    }
                }
            }
        }
    }
</script>

<style>

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family:
        Inter,
        Arial,
        Helvetica,
        sans-serif;

    background: #f5f7fb;
    color: #172033;
}

a {
    text-decoration: none;
    color: inherit;
}

button,
input {
    font: inherit;
}

.topbar {
    background: #ffffff;

    border-bottom:
        1px solid #e5e9f0;

    padding: 18px 24px;
}

.topbar-inner {
    max-width: 1000px;

    margin: 0 auto;

    display: flex;

    justify-content:
        space-between;

    align-items: center;

    gap: 20px;
}

.brand {
    color: #144be6;

    font-size: 23px;
    font-weight: 900;
}

.wallet {
    background: #f5f7ff;

    border:
        1px solid #dfe5ff;

    border-radius: 11px;

    padding: 10px 14px;
}

.wallet-label {
    color: #737c90;

    font-size: 11px;

    margin-bottom: 3px;
}

.wallet-amount {
    font-weight: 900;
}

.container {
    max-width: 1000px;

    margin: 0 auto;

    padding:
        35px 24px 70px;
}

.back {
    display: inline-block;

    color: #687286;

    font-size: 14px;
    font-weight: 700;

    margin-bottom: 24px;
}

.hero {
    margin-bottom: 27px;
}

.hero h1 {
    font-size: 30px;

    margin-bottom: 9px;
}

.hero p {
    color: #747e91;

    line-height: 1.6;
}

.card {
    background: #ffffff;

    border:
        1px solid #e2e7ef;

    border-radius: 18px;

    padding: 27px;

    box-shadow:
        0 8px 28px
        rgba(23, 32, 51, 0.05);
}

.service-summary {
    display: flex;

    align-items: center;

    justify-content:
        space-between;

    gap: 15px;

    background: #f5f8ff;

    border:
        1px solid #dfe7ff;

    border-radius: 13px;

    padding: 16px;

    margin-bottom: 26px;
}

.service-left {
    display: flex;

    align-items: center;

    gap: 12px;
}

.service-icon {
    width: 43px;
    height: 43px;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 12px;

    background: #144be6;

    color: #ffffff;

    font-weight: 900;

    font-size: 12px;
}

.summary-label {
    color: #7a8395;

    font-size: 11px;

    margin-bottom: 4px;
}

.summary-name {
    font-weight: 900;
}

.change-link {
    color: #144be6;

    font-size: 12px;

    font-weight: 800;
}

.form-group {
    margin-bottom: 21px;
}

label {
    display: block;

    font-size: 13px;

    font-weight: 800;

    margin-bottom: 8px;
}

/*
|--------------------------------------------------------------------------
| COUNTRY SELECTOR
|--------------------------------------------------------------------------
*/

.country-selector {
    position: relative;
}

.country-button {
    width: 100%;

    display: flex;

    align-items: center;

    justify-content:
        space-between;

    gap: 15px;

    background: #ffffff;

    border:
        1px solid #dce2ec;

    border-radius: 11px;

    padding:
        12px 14px;

    cursor: pointer;

    text-align: left;
}

.country-button:hover {
    border-color: #b9c4d6;
}

.country-selected {
    display: flex;

    align-items: center;

    gap: 10px;
}

.country-flag {
    font-size: 23px;

    line-height: 1;
}

.country-placeholder {
    color: #8b94a5;
}

.arrow {
    color: #737c90;
}

.country-dropdown {
    display: none;

    position: absolute;

    top: calc(100% + 7px);

    left: 0;
    right: 0;

    z-index: 50;

    background: #ffffff;

    border:
        1px solid #dce2ec;

    border-radius: 12px;

    overflow: hidden;

    box-shadow:
        0 16px 40px
        rgba(20, 30, 50, 0.13);
}

.country-dropdown.show {
    display: block;
}

.country-search-area {
    padding: 11px;

    border-bottom:
        1px solid #edf0f4;
}

.country-search {
    width: 100%;

    border:
        1px solid #dfe4ec;

    border-radius: 9px;

    padding:
        10px 12px;

    outline: none;
}

.country-search:focus {
    border-color: #144be6;

    box-shadow:
        0 0 0 3px
        rgba(20, 75, 230, 0.07);
}

.country-list {
    max-height: 300px;

    overflow-y: auto;
}

.country-item {
    width: 100%;

    border: 0;

    background: #ffffff;

    display: flex;

    align-items: center;

    gap: 11px;

    padding:
        11px 13px;

    cursor: pointer;

    text-align: left;
}

.country-item:hover {
    background: #f5f7fb;
}

.country-item-flag {
    font-size: 23px;

    width: 31px;

    text-align: center;
}

.country-item-name {
    flex: 1;

    font-size: 13px;

    font-weight: 700;
}

.country-code {
    color: #939baa;

    font-size: 11px;

    font-weight: 700;
}

.no-country {
    display: none;

    padding: 18px;

    color: #7d8697;

    text-align: center;

    font-size: 13px;
}

/*
|--------------------------------------------------------------------------
| OPTIONS
|--------------------------------------------------------------------------
*/

.option-list {
    display: grid;

    gap: 11px;
}

.option-placeholder {
    background: #f6f7f9;

    border:
        1px solid #e4e7ec;

    border-radius: 11px;

    padding: 14px;

    color: #8b94a5;

    font-size: 13px;
}

.option-button {
    width: 100%;

    display: flex;

    justify-content:
        space-between;

    align-items: center;

    gap: 20px;

    border:
        1px solid #dfe4ec;

    background: #ffffff;

    border-radius: 12px;

    padding: 15px;

    cursor: pointer;

    text-align: left;
}

.option-button:hover {
    border-color: #144be6;
}

.option-button.active {
    border-color: #144be6;

    background: #f5f8ff;

    box-shadow:
        0 0 0 2px
        rgba(20, 75, 230, 0.07);
}

.option-name {
    font-size: 14px;

    font-weight: 800;
}

.option-stock {
    color: #7e8798;

    font-size: 11px;

    margin-top: 4px;
}

.option-price {
    color: #144be6;

    font-size: 16px;

    font-weight: 900;

    white-space: nowrap;
}

/*
|--------------------------------------------------------------------------
| AUTO SELECTED
|--------------------------------------------------------------------------
*/

.auto-selected {
    display: inline-flex;

    align-items: center;

    gap: 5px;

    margin-top: 6px;

    color: #17753a;

    font-size: 11px;

    font-weight: 700;
}

/*
|--------------------------------------------------------------------------
| SUMMARY
|--------------------------------------------------------------------------
*/

.order-summary {
    display: none;

    margin-top: 24px;

    background: #f8fafc;

    border:
        1px solid #e1e6ed;

    border-radius: 13px;

    padding: 18px;
}

.order-summary.show {
    display: block;
}

.summary-row {
    display: flex;

    justify-content:
        space-between;

    align-items: center;

    gap: 20px;

    padding: 10px 0;

    border-bottom:
        1px solid #e8ebf0;
}

.summary-row:last-child {
    border-bottom: 0;
}

.summary-key {
    color: #747d8e;

    font-size: 13px;
}

.summary-value {
    font-size: 13px;

    font-weight: 800;

    text-align: right;
}

.summary-country {
    display: flex;

    align-items: center;

    justify-content: flex-end;

    gap: 7px;
}

.final-price {
    color: #144be6;

    font-size: 20px;
}

.notice {
    margin-top: 20px;

    background: #fff9eb;

    border:
        1px solid #f4e0a8;

    border-radius: 11px;

    padding:
        13px 14px;

    color: #70591c;

    font-size: 12px;

    line-height: 1.55;
}

.continue {
    width: 100%;

    border: 0;

    border-radius: 11px;

    background: #144be6;

    color: #ffffff;

    padding: 14px;

    font-weight: 900;

    cursor: pointer;

    margin-top: 20px;

    transition:
        opacity 0.15s ease,
        transform 0.15s ease;
}

.continue:not(:disabled):hover {
    transform:
        translateY(-1px);
}

.continue:disabled {
    opacity: 0.45;

    cursor: not-allowed;
}

.empty {
    background: #fff6f6;

    border:
        1px solid #f0d4d4;

    border-radius: 11px;

    padding: 15px;

    color: #8b3d3d;
}

@media (max-width: 650px) {

    .hero h1 {
        font-size: 25px;
    }

    .card {
        padding: 20px;
    }

    .summary-row {
        align-items: flex-start;
    }

    .option-button {
        align-items: flex-start;
    }

}

</style>

</head>

<body>

<!-- Desktop Navigation Header -->
<header class="hidden md:block sticky top-0 z-40 border-b border-slate-200/80 bg-white/95 backdrop-blur">
    <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6">
        <a href="dashboard.php" class="flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-servora-700 text-lg font-black text-white shadow-sm">S</div>
            <div>
                <div class="text-lg font-bold tracking-tight text-slate-900 leading-tight">Servora</div>
                <div class="text-[10px] font-medium text-slate-400 uppercase tracking-wider">Virtual Numbers</div>
            </div>
        </a>

        <!-- Desktop Navigation Links -->
        <nav class="flex items-center gap-1">
            <a href="dashboard.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">Dashboard</a>
            <a href="orders.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">My Orders</a>
            <a href="wallet.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">Wallet</a>
            <a href="profile.php" class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-servora-700 hover:bg-slate-50">Profile</a>
        </nav>

        <div class="flex items-center gap-2.5">
            <a href="notifications.php" class="relative flex h-10 w-10 items-center justify-center rounded-xl text-slate-600 transition hover:bg-slate-100" aria-label="Notifications">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 1-5.714 0M18 8A6 6 0 0 0 6 8c0 7-3 7-3 9h18c0-2-3-2-3-9M13.73 21a2 2 0 0 1-3.46 0"/>
                </svg>
            </a>
            <a href="profile.php" class="flex h-10 w-10 items-center justify-center rounded-xl bg-servora-100 font-bold text-servora-700 text-sm" title="My Profile">
                <?= htmlspecialchars($profileInitial, ENT_QUOTES, "UTF-8") ?>
            </a>
        </div>
    </div>
</header>

<main class="mx-auto w-full max-w-5xl px-4 py-6 pb-28 md:pb-8 sm:px-6">

    <!-- Hero Card -->
    <section class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-servora-800 via-servora-700 to-servora-500 p-6 sm:p-8 text-white shadow-xl">
        <a href="foreign_numbers.php" class="text-sm font-semibold text-white/70 hover:text-white transition">
            ← Foreign Numbers
        </a>
        <div class="mt-6">
            <p class="text-sm font-semibold text-white/70">
                Servora Virtual Number
            </p>
            <h1 class="mt-1 text-3xl font-black">
                <?= htmlspecialchars($selectedService["name"], ENT_QUOTES, "UTF-8") ?> Verification
            </h1>
            <p class="mt-2 max-w-xl text-sm text-white/70">
                Choose a country and continue with an available verification number.
            </p>
        </div>
    </section>

    <!-- Wallet Balance Card -->
    <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex items-center justify-between gap-4">
            <div>
                <p class="text-xs font-bold uppercase tracking-wide text-slate-400">
                    Wallet Balance
                </p>
                <p class="mt-1 text-2xl font-black text-slate-900">
                    ₦<?= number_format($walletBalance, 2) ?>
                </p>
            </div>
            <a href="fund_wallet.php" class="inline-flex items-center justify-center rounded-xl bg-servora-50 px-4 py-2 text-xs font-bold text-servora-700 transition hover:bg-servora-100">
                Fund Wallet
            </a>
        </div>
    </section>

    <div class="mt-6">

<section class="card">

<div class="service-summary">

    <div class="service-left">

        <div class="service-icon">
            <?= BrandAssetHelper::renderServiceLogo(
                $selectedService["code"],
                "lg",
                false
            ) ?>
        </div>

        <div>

            <div class="summary-label">
                Selected Service
            </div>

            <div class="summary-name">

                <?= htmlspecialchars(
                    $selectedService["name"],
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>

            </div>

        </div>

    </div>

    <a
        href="foreign_numbers.php"
        class="change-link"
    >
        Change
    </a>

</div>

<?php if (!$moduleActive): ?>

<div class="empty">
    Foreign number orders are temporarily
    unavailable. Please try again later.
</div>

<?php elseif ($countryOptions === []): ?>

<div class="empty">
    There are currently no available
    countries for this service.
</div>

<?php else: ?>

<form
    method="POST"
    action="process_foreign_number.php"
    id="orderForm"
>

<input
    type="hidden"
    name="csrf_token"
    value="<?= htmlspecialchars(
        $csrfToken,
        ENT_QUOTES,
        "UTF-8"
    ) ?>"
>

<input
    type="hidden"
    name="request_token"
    value="<?= htmlspecialchars(
        $requestToken,
        ENT_QUOTES,
        "UTF-8"
    ) ?>"
>

<input
    type="hidden"
    name="service_code"
    value="<?= htmlspecialchars(
        $selectedService["code"],
        ENT_QUOTES,
        "UTF-8"
    ) ?>"
>

<input
    type="hidden"
    name="country_code"
    id="countryCode"
    value=""
>

<input
    type="hidden"
    name="operator_code"
    id="operatorCode"
    value=""
>

<div class="form-group">

<label>
    Country
</label>

<div class="country-selector">

<button
    type="button"
    class="country-button"
    id="countryButton"
>

<span
    class="country-selected"
    id="countrySelected"
>

<span class="country-placeholder">
    Select country
</span>

</span>

<span class="arrow">
    ▾
</span>

</button>

<div
    class="country-dropdown"
    id="countryDropdown"
>

<div class="country-search-area">

<input
    type="text"
    class="country-search"
    id="countrySearch"
    placeholder="Search country..."
    autocomplete="off"
>

</div>

<div
    class="country-list"
    id="countryList"
>

<?php foreach (
    $countryOptions
    as $country
): ?>

<button
    type="button"

    class="country-item"

    data-country-code="<?= htmlspecialchars(
        $country["code"],
        ENT_QUOTES,
        "UTF-8"
    ) ?>"

    data-country-name="<?= htmlspecialchars(
        $country["name"],
        ENT_QUOTES,
        "UTF-8"
    ) ?>"
>

<span class="country-item-flag">
    <?= BrandAssetHelper::renderCountryFlag($country["code"], $country["name"], "sm") ?>
</span>

<span class="country-item-name">
    <?= htmlspecialchars(
        $country["name"],
        ENT_QUOTES,
        "UTF-8"
    ) ?>
</span>

<span class="country-code">
    <?= htmlspecialchars(
        $country["dial_code"] ?: $country["code"],
        ENT_QUOTES,
        "UTF-8"
    ) ?>
</span>

</button>

<?php endforeach; ?>

<div
    class="no-country"
    id="noCountry"
>
    No country found.
</div>

</div>

</div>

</div>

</div>

<div class="form-group">

<label>
    Available Options
</label>

<div
    class="option-list"
    id="optionList"
>

<div class="option-placeholder">
    Select a country first.
</div>

</div>

</div>

<div
    class="order-summary"
    id="orderSummary"
>

<div class="summary-row">

<div class="summary-key">
    Service
</div>

<div class="summary-value">

<?= htmlspecialchars(
    $selectedService["name"],
    ENT_QUOTES,
    "UTF-8"
) ?>

</div>

</div>

<div class="summary-row">

<div class="summary-key">
    Country
</div>

<div
    class="summary-value summary-country"
    id="summaryCountry"
>
    —
</div>

</div>

<div class="summary-row">

<div class="summary-key">
    Option
</div>

<div
    class="summary-value"
    id="summaryOperator"
>
    —
</div>

</div>

<div class="summary-row">

<div class="summary-key">
    Availability
</div>

<div
    class="summary-value"
    id="summaryStock"
>
    —
</div>

</div>

<div class="summary-row">

<div class="summary-key">
    Servora Price
</div>

<div
    class="summary-value final-price"
    id="summaryPrice"
>
    ₦0.00
</div>

</div>

</div>

<div class="notice">
    Availability and pricing may vary by
    service and country. No wallet deduction
    happens until the order is processed.
</div>

<button
    type="submit"
    class="continue"
    id="continueButton"
    disabled
>
    Continue
</button>

</form>

<?php endif; ?>

</section>

</div>

</main>

<script>

const inventory =
    <?= $countryOptionsJson ?>;

const countryButton =
    document.getElementById(
        "countryButton"
    );

const countryDropdown =
    document.getElementById(
        "countryDropdown"
    );

const countrySearch =
    document.getElementById(
        "countrySearch"
    );

const countrySelected =
    document.getElementById(
        "countrySelected"
    );

const countryCode =
    document.getElementById(
        "countryCode"
    );

const operatorCode =
    document.getElementById(
        "operatorCode"
    );

const optionList =
    document.getElementById(
        "optionList"
    );

const orderSummary =
    document.getElementById(
        "orderSummary"
    );

const continueButton =
    document.getElementById(
        "continueButton"
    );

const summaryCountry =
    document.getElementById(
        "summaryCountry"
    );

const summaryOperator =
    document.getElementById(
        "summaryOperator"
    );

const summaryStock =
    document.getElementById(
        "summaryStock"
    );

const summaryPrice =
    document.getElementById(
        "summaryPrice"
    );

const noCountry =
    document.getElementById(
        "noCountry"
    );

/*
|--------------------------------------------------------------------------
| MONEY
|--------------------------------------------------------------------------
*/

function money(value) {

    return "₦"
        + Number(value || 0)
        .toLocaleString(
            "en-NG",
            {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }
        );
}

/*
|--------------------------------------------------------------------------
| FIND COUNTRY
|--------------------------------------------------------------------------
*/

function findCountry(code) {

    return inventory.find(
        function (country) {

            return (
                country.code === code
            );
        }
    ) || null;
}

/*
|--------------------------------------------------------------------------
| RESET OPTION
|--------------------------------------------------------------------------
*/

function resetOrderOption() {

    if (operatorCode) {
        operatorCode.value = "";
    }

    if (orderSummary) {
        orderSummary.classList.remove(
            "show"
        );
    }

    if (continueButton) {
        continueButton.disabled = true;
    }
}

/*
|--------------------------------------------------------------------------
| APPLY OPTION
|--------------------------------------------------------------------------
*/

function applyOption(
    selectedCountry,
    option,
    button
) {

    document
        .querySelectorAll(
            ".option-button"
        )
        .forEach(
            function (item) {

                item.classList.remove(
                    "active"
                );
            }
        );

    if (button) {

        button.classList.add(
            "active"
        );
    }

    operatorCode.value =
        option.operator_code;

    summaryCountry.innerHTML = "";

    const summaryFlag = document.createElement("span");
    summaryFlag.className = "country-flag";

    const sumImg = document.createElement("img");
    sumImg.src = selectedCountry.flag_url;
    sumImg.srcset = selectedCountry.flag_url_2x + " 2x";
    sumImg.width = 24;
    sumImg.height = 16;
    sumImg.alt = selectedCountry.name;
    sumImg.style.borderRadius = "3px";
    sumImg.style.verticalAlign = "middle";
    sumImg.style.marginRight = "8px";
    sumImg.style.boxShadow = "0 1px 3px rgba(0,0,0,0.1)";
    sumImg.onerror = function() {
        this.style.display = "none";
        summaryFlag.textContent = selectedCountry.flag || "🌐";
    };
    summaryFlag.appendChild(sumImg);

    const summaryName = document.createElement("span");
    summaryName.textContent = selectedCountry.name + (selectedCountry.dial_code ? " (" + selectedCountry.dial_code + ")" : "");

    summaryCountry.appendChild(summaryFlag);
    summaryCountry.appendChild(summaryName);

    summaryOperator.textContent =
        option.operator_name;

    summaryStock.textContent =
        String(option.stock)
        + " available";

    summaryPrice.textContent =
        money(
            option.selling_price
        );

    orderSummary.classList.add(
        "show"
    );

    continueButton.disabled = false;
}

/*
|--------------------------------------------------------------------------
| SELECT COUNTRY
|--------------------------------------------------------------------------
*/

function selectCountry(
    selectedCountry
) {

    countryCode.value =
        selectedCountry.code;

    countrySelected.innerHTML = "";

    const flag = document.createElement("span");
    flag.className = "country-flag";

    const img = document.createElement("img");
    img.src = selectedCountry.flag_url;
    img.srcset = selectedCountry.flag_url_2x + " 2x";
    img.width = 26;
    img.height = 18;
    img.alt = selectedCountry.name;
    img.style.borderRadius = "3px";
    img.style.verticalAlign = "middle";
    img.style.marginRight = "8px";
    img.style.boxShadow = "0 1px 3px rgba(0,0,0,0.1)";
    img.onerror = function() {
        this.style.display = "none";
        flag.textContent = selectedCountry.flag || "🌐";
    };
    flag.appendChild(img);

    const name = document.createElement("span");
    name.textContent = selectedCountry.name;

    if (selectedCountry.dial_code) {
        const dial = document.createElement("span");
        dial.className = "country-code";
        dial.style.marginLeft = "6px";
        dial.style.color = "#718096";
        dial.textContent = "(" + selectedCountry.dial_code + ")";
        name.appendChild(dial);
    }

    countrySelected.appendChild(flag);
    countrySelected.appendChild(name);

    countryDropdown.classList.remove(
        "show"
    );

    resetOrderOption();

    renderOptions(
        selectedCountry
    );
}

/*
|--------------------------------------------------------------------------
| RENDER OPTIONS
|--------------------------------------------------------------------------
*/

function renderOptions(
    selectedCountry
) {

    optionList.innerHTML = "";

    if (
        !Array.isArray(
            selectedCountry.options
        )
        || selectedCountry.options.length === 0
    ) {

        optionList.innerHTML =
            '<div class="option-placeholder">'
            + 'No available number for this country.'
            + '</div>';

        return;
    }

    const optionButtons = [];

    selectedCountry.options.forEach(
        function (option) {

            const button =
                document.createElement(
                    "button"
                );

            button.type = "button";

            button.className =
                "option-button";

            const left =
                document.createElement(
                    "div"
                );

            const name =
                document.createElement(
                    "div"
                );

            name.className =
                "option-name";

            name.textContent =
                option.operator_name;

            const stock =
                document.createElement(
                    "div"
                );

            stock.className =
                "option-stock";

            stock.textContent =
                String(option.stock)
                + " available";

            left.appendChild(name);
            left.appendChild(stock);

            if (
                selectedCountry.options.length === 1
            ) {

                const auto =
                    document.createElement(
                        "div"
                    );

                auto.className =
                    "auto-selected";

                auto.textContent =
                    "✓ Automatically selected";

                left.appendChild(auto);
            }

            const price =
                document.createElement(
                    "div"
                );

            price.className =
                "option-price";

            price.textContent =
                money(
                    option.selling_price
                );

            button.appendChild(left);
            button.appendChild(price);

            button.addEventListener(
                "click",
                function () {

                    applyOption(
                        selectedCountry,
                        option,
                        button
                    );
                }
            );

            optionList.appendChild(
                button
            );

            optionButtons.push({
                button: button,
                option: option
            });
        }
    );

    /*
    |--------------------------------------------------------------------------
    | AUTOMATIC OPTION SELECTION
    |--------------------------------------------------------------------------
    */

    if (
        optionButtons.length === 1
    ) {

        applyOption(
            selectedCountry,
            optionButtons[0].option,
            optionButtons[0].button
        );
    }
}

/*
|--------------------------------------------------------------------------
| OPEN COUNTRY SELECTOR
|--------------------------------------------------------------------------
*/

countryButton?.addEventListener(
    "click",
    function () {

        countryDropdown.classList.toggle(
            "show"
        );

        if (
            countryDropdown.classList.contains(
                "show"
            )
        ) {

            setTimeout(
                function () {

                    countrySearch.focus();

                },
                50
            );
        }
    }
);

/*
|--------------------------------------------------------------------------
| COUNTRY SELECTION
|--------------------------------------------------------------------------
*/

document
    .querySelectorAll(
        ".country-item"
    )
    .forEach(
        function (button) {

            button.addEventListener(
                "click",
                function () {

                    const selectedCountry =
                        findCountry(
                            button.dataset.countryCode
                        );

                    if (!selectedCountry) {
                        return;
                    }

                    selectCountry(
                        selectedCountry
                    );
                }
            );
        }
    );

/*
|--------------------------------------------------------------------------
| SEARCH COUNTRIES
|--------------------------------------------------------------------------
*/

countrySearch?.addEventListener(
    "input",
    function () {

        const query =
            countrySearch.value
            .trim()
            .toLowerCase();

        let visible = 0;

        document
            .querySelectorAll(
                ".country-item"
            )
            .forEach(
                function (button) {

                    const countryName =
                        (
                            button.dataset.countryName
                            || ""
                        )
                        .toLowerCase();

                    const code =
                        (
                            button.dataset.countryCode
                            || ""
                        )
                        .toLowerCase();

                    const matches =
                        query === ""
                        || countryName.includes(
                            query
                        )
                        || code.includes(
                            query
                        );

                    button.style.display =
                        matches
                        ? "flex"
                        : "none";

                    if (matches) {
                        visible++;
                    }
                }
            );

        if (noCountry) {

            noCountry.style.display =
                visible === 0
                ? "block"
                : "none";
        }
    }
);

/*
|--------------------------------------------------------------------------
| CLOSE COUNTRY SELECTOR
|--------------------------------------------------------------------------
*/

document.addEventListener(
    "click",
    function (event) {

        if (
            !countryButton?.contains(
                event.target
            )
            && !countryDropdown?.contains(
                event.target
            )
        ) {

            countryDropdown?.classList.remove(
                "show"
            );
        }
    }
);

/*
|--------------------------------------------------------------------------
| FINAL FORM GUARD
|--------------------------------------------------------------------------
*/

document
    .getElementById(
        "orderForm"
    )
    ?.addEventListener(
        "submit",
        function (event) {

            if (
                !countryCode
                || !operatorCode
                || countryCode.value === ""
                || operatorCode.value === ""
            ) {

                event.preventDefault();

                if (continueButton) {
                    continueButton.disabled =
                        true;
                }
            }
        }
    );

</script>

<?php require_once __DIR__ . "/../includes/client_bottom_nav.php"; ?>
</body>
</html>