<?php

require_once "../config/database.php";
require_once "../includes/client_auth.php";
require_once "../includes/VirtualNumberProvider.php";
require_once "../includes/BrandAssetHelper.php";

$userId = (int) ($_SESSION["user_id"] ?? 0);

if ($userId <= 0) {
    header("Location: ../login.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| PROVIDER
|--------------------------------------------------------------------------
*/

$provider = new VirtualNumberProvider();

$services = $provider->getServices();

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
$profileInitial = strtoupper(substr($fullName, 0, 1));

/*
|--------------------------------------------------------------------------
| ICON
|--------------------------------------------------------------------------
|
| These are lightweight interface icons.
| Later, when the live provider returns hundreds of services, unknown
| services automatically receive the generic icon.
|
*/

function serviceIcon(string $serviceCode): string
{
    return BrandAssetHelper::renderServiceLogo($serviceCode, 'md', false);
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

<title>Foreign Numbers | Servora</title>

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
    max-width: 1100px;
    margin: 0 auto;

    display: flex;
    align-items: center;
    justify-content: space-between;

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
    max-width: 1100px;

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
    display: flex;
    justify-content: space-between;
    align-items: flex-end;

    gap: 25px;

    margin-bottom: 25px;
}

.hero h1 {
    font-size: 31px;
    margin-bottom: 8px;
}

.hero p {
    color: #747e91;
    line-height: 1.6;
}

.provider-badge {
    background: #fff9e8;

    border:
        1px solid #f2dfa5;

    color: #72591a;

    border-radius: 999px;

    padding:
        8px 12px;

    font-size: 11px;
    font-weight: 800;

    white-space: nowrap;
}

/*
|--------------------------------------------------------------------------
| SEARCH
|--------------------------------------------------------------------------
*/

.search-box {
    position: relative;

    margin-bottom: 24px;
}

.search-box input {
    width: 100%;

    background: #ffffff;

    border:
        1px solid #dfe4ec;

    border-radius: 13px;

    padding:
        14px 16px 14px 45px;

    outline: none;

    font-size: 14px;
}

.search-box input:focus {
    border-color: #144be6;

    box-shadow:
        0 0 0 3px
        rgba(20, 75, 230, 0.07);
}

.search-icon {
    position: absolute;

    left: 16px;
    top: 50%;

    transform:
        translateY(-50%);

    color: #7f8899;

    font-size: 18px;
}

/*
|--------------------------------------------------------------------------
| FEATURED
|--------------------------------------------------------------------------
*/

.section-heading {
    display: flex;

    justify-content:
        space-between;

    align-items: center;

    gap: 15px;

    margin-bottom: 14px;
}

.section-heading h2 {
    font-size: 16px;
}

.service-count {
    color: #818a9a;
    font-size: 12px;
}

/*
|--------------------------------------------------------------------------
| SERVICE GRID
|--------------------------------------------------------------------------
*/

.services-grid {
    display: grid;

    grid-template-columns:
        repeat(
            auto-fill,
            minmax(220px, 1fr)
        );

    gap: 14px;
}

.service-card {
    background: #ffffff;

    border:
        1px solid #e1e6ee;

    border-radius: 15px;

    padding: 17px;

    display: flex;

    align-items: center;

    gap: 14px;

    min-height: 83px;

    transition:
        border-color 0.15s ease,
        box-shadow 0.15s ease,
        transform 0.15s ease;
}

.service-card:hover {
    border-color: #bfc9db;

    box-shadow:
        0 8px 24px
        rgba(22, 32, 52, 0.07);

    transform:
        translateY(-1px);
}

.service-logo {
    width: 48px;
    height: 48px;

    flex: 0 0 48px;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 14px;

    background: #ffffff;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
}

.service-logo svg {
    width: 32px;
    height: 32px;
    display: block;
}

.service-info {
    min-width: 0;
    flex: 1;
}

.service-name {
    font-size: 14px;
    font-weight: 900;

    margin-bottom: 5px;

    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.service-description {
    color: #7c8597;

    font-size: 11px;

    line-height: 1.45;
}

.service-arrow {
    color: #9aa2b1;

    font-size: 18px;
}

/*
|--------------------------------------------------------------------------
| POPULAR MARKER
|--------------------------------------------------------------------------
*/

.service-card[data-service="whatsapp"] .service-logo {
    background: #ecfbf1;
}

.service-card[data-service="telegram"] .service-logo {
    background: #eef8ff;
}

.service-card[data-service="facebook"] .service-logo {
    background: #eef3ff;
}

.service-card[data-service="instagram"] .service-logo {
    background: #fff1f7;
}

.service-card[data-service="tiktok"] .service-logo {
    background: #f3f3f3;
}

.service-card[data-service="google"] .service-logo {
    background: #f5f7fb;
}

.service-card[data-service="microsoft"] .service-logo {
    background: #f5f7fb;
}

.service-card[data-service="amazon"] .service-logo {
    background: #fff7e9;
}

.service-card[data-service="apple"] .service-logo {
    background: #f3f3f3;
}

/*
|--------------------------------------------------------------------------
| EMPTY SEARCH
|--------------------------------------------------------------------------
*/

.no-results {
    display: none;

    background: #ffffff;

    border:
        1px solid #e1e6ee;

    border-radius: 14px;

    padding: 30px;

    text-align: center;

    color: #7c8597;
}

/*
|--------------------------------------------------------------------------
| INFORMATION
|--------------------------------------------------------------------------
*/

.info {
    margin-top: 26px;

    background: #f8faff;

    border:
        1px solid #e0e7fa;

    border-radius: 14px;

    padding: 18px;

    display: grid;

    grid-template-columns:
        repeat(3, 1fr);

    gap: 20px;
}

.info-item strong {
    display: block;

    font-size: 12px;

    margin-bottom: 5px;
}

.info-item span {
    color: #7b8496;

    font-size: 11px;

    line-height: 1.5;
}

/*
|--------------------------------------------------------------------------
| MOBILE
|--------------------------------------------------------------------------
*/

@media (max-width: 700px) {

    .hero {
        align-items: flex-start;
        flex-direction: column;
    }

    .hero h1 {
        font-size: 26px;
    }

    .services-grid {
        grid-template-columns: 1fr;
    }

    .info {
        grid-template-columns: 1fr;
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

    <!-- HERO BANNER CARD -->
    <section class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-servora-800 via-servora-700 to-servora-500 p-6 sm:p-8 text-white shadow-xl">
        <a href="dashboard.php" class="text-sm font-semibold text-white/70 hover:text-white">
            ← Dashboard
        </a>

        <div class="mt-6 flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div>
                <p class="text-sm font-semibold text-white/70">
                    Servora Verification
                </p>
                <h1 class="mt-1 text-3xl font-black">
                    Foreign Numbers
                </h1>
                <p class="mt-2 max-w-xl text-sm text-white/70">
                    Receive instant SMS verification codes for international platforms and applications.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <a href="foreign_number_history.php" class="rounded-xl bg-white/10 backdrop-blur border border-white/20 px-3.5 py-2 text-xs font-bold text-white hover:bg-white/20 transition">
                    Order History
                </a>
                <?php if (strtolower($provider->getProviderName()) === "mock"): ?>
                <span class="rounded-xl bg-amber-400/20 text-amber-200 border border-amber-400/30 px-3 py-1.5 text-xs font-bold">
                    Dev Mode
                </span>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- STANDARDIZED WALLET BALANCE CARD -->
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
            <a href="fund_wallet.php" class="rounded-xl bg-servora-50 px-4 py-2.5 text-sm font-bold text-servora-700 hover:bg-servora-100 transition">
                Fund Wallet
            </a>
        </div>
    </section>

    <!-- CONTENT CARD -->
    <section class="mt-6 rounded-3xl border border-slate-200 bg-white p-5 sm:p-7 shadow-sm">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
            <div>
                <p class="text-xs font-bold uppercase tracking-widest text-servora-600">Verification Hub</p>
                <h2 class="mt-1 text-xl font-black text-slate-900">Available Services</h2>
                <p class="mt-1 text-xs text-slate-500" id="serviceCount"><?= count($services) ?> services available</p>
            </div>

            <!-- Search Box -->
            <div class="relative w-full sm:w-72">
                <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-sm">⌕</span>
                <input
                    type="search"
                    id="serviceSearch"
                    placeholder="Search WhatsApp, Telegram..."
                    autocomplete="off"
                    class="w-full rounded-xl border border-slate-200 bg-slate-50 pl-9 pr-4 py-2 text-xs font-semibold text-slate-900 placeholder-slate-400 focus:bg-white focus:border-servora-600 focus:outline-none focus:ring-2 focus:ring-servora-100 transition"
                >
            </div>
        </div>

        <div
            class="services-grid"
            id="servicesGrid"
        >

<?php foreach ($services as $service): ?>

    <?php

    $serviceCode = strtolower(
        trim(
            (string) (
                $service["code"] ?? ""
            )
        )
    );

    $serviceName = trim(
        (string) (
            $service["name"] ?? ""
        )
    );

    if (
        $serviceCode === ""
        || $serviceName === ""
    ) {
        continue;
    }

    ?>

    <a
        href="foreign_number_options.php?service=<?= urlencode(
            $serviceCode
        ) ?>"

        class="service-card"

        data-service="<?= htmlspecialchars(
            $serviceCode,
            ENT_QUOTES,
            "UTF-8"
        ) ?>"

        data-name="<?= htmlspecialchars(
            strtolower($serviceName),
            ENT_QUOTES,
            "UTF-8"
        ) ?>"
    >

        <div class="service-logo">
            <?= serviceIcon(
                $serviceCode
            ) ?>
        </div>

        <div class="service-info">

            <div class="service-name">
                <?= htmlspecialchars(
                    $serviceName,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>
            </div>

            <div class="service-description">

                <?php if (
                    $serviceCode === "whatsapp"
                ): ?>
                    WhatsApp verification

                <?php elseif (
                    $serviceCode === "telegram"
                ): ?>
                    Telegram verification

                <?php elseif (
                    $serviceCode === "facebook"
                ): ?>
                    Facebook verification

                <?php elseif (
                    $serviceCode === "instagram"
                ): ?>
                    Instagram / Threads

                <?php elseif (
                    $serviceCode === "tiktok"
                ): ?>
                    TikTok verification

                <?php elseif (
                    $serviceCode === "google"
                ): ?>
                    Google / YouTube

                <?php elseif (
                    $serviceCode === "microsoft"
                ): ?>
                    Microsoft services

                <?php else: ?>
                    SMS verification service
                <?php endif; ?>

            </div>

        </div>

        <div class="service-arrow">
            ›
        </div>

    </a>

<?php endforeach; ?>

</div>

<div
    class="no-results"
    id="noResults"
>
    No matching service found.
</div>

<div class="info">

    <div class="info-item">

        <strong>
            1. Choose Service
        </strong>

        <span>
            Select the website or app you
            want to verify.
        </span>

    </div>

    <div class="info-item">

        <strong>
            2. Choose Country
        </strong>

        <span>
            Select from countries currently
            available for that service.
        </span>

    </div>

    <div class="info-item">

        <strong>
            3. Receive Message
        </strong>

        <span>
            Your order page will display the
            verification result when available.
        </span>

    </div>

    </div>
</section>
</main>

<script>

const searchInput =
    document.getElementById(
        "serviceSearch"
    );

const serviceCards =
    Array.from(
        document.querySelectorAll(
            ".service-card"
        )
    );

const noResults =
    document.getElementById(
        "noResults"
    );

const serviceCount =
    document.getElementById(
        "serviceCount"
    );

function filterServices() {

    const query =
        searchInput.value
        .trim()
        .toLowerCase();

    let visible = 0;

    serviceCards.forEach(
        function (card) {

            const name =
                card.dataset.name
                || "";

            const code =
                card.dataset.service
                || "";

            const matches =
                query === ""
                || name.includes(query)
                || code.includes(query);

            card.style.display =
                matches
                ? "flex"
                : "none";

            if (matches) {
                visible++;
            }
        }
    );

    serviceCount.textContent =
        visible
        + (
            visible === 1
            ? " service"
            : " services"
        );

    noResults.style.display =
        visible === 0
        ? "block"
        : "none";
}

searchInput.addEventListener(
    "input",
    filterServices
);

</script>

<?php require_once __DIR__ . "/../includes/client_bottom_nav.php"; ?>
</body>
</html>