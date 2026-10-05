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

<title>Foreign Numbers | Subnext</title>

<!-- Precompiled Production Stylesheet -->
<link rel="stylesheet" href="../assets/css/style.css">
<meta name="description" content="Subnext Foreign Virtual Numbers: Get instant international SMS verification codes for WhatsApp, Telegram, Google, and more.">
</head>

<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">

<!-- Desktop Navigation Header -->
<header class="hidden md:block sticky top-0 z-40 border-b border-slate-200/80 bg-white/95 backdrop-blur">
    <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6">
        <a href="dashboard.php" class="flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-servora-700 text-lg font-black text-white shadow-sm">S</div>
            <div>
                <div class="text-lg font-bold tracking-tight text-slate-900 leading-tight">Subnext</div>
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
            <a href="../logout.php" class="inline-flex items-center gap-1.5 rounded-xl border border-red-200 bg-red-50/80 px-3.5 py-2 text-xs font-bold text-red-600 transition hover:bg-red-100 hover:border-red-300" title="Logout">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                </svg>
                <span>Logout</span>
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
                    Subnext Verification
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

        <div id="servicesGrid" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3.5">
<?php foreach ($services as $service): ?>
    <?php
    $serviceCode = strtolower(trim((string) ($service["code"] ?? "")));
    $serviceName = trim((string) ($service["name"] ?? ""));
    if ($serviceCode === "" || $serviceName === "") {
        continue;
    }
    ?>
    <a
        href="foreign_number_options.php?service=<?= urlencode($serviceCode) ?>"
        class="service-card group flex items-center justify-between gap-3.5 rounded-2xl border border-slate-200/90 bg-white p-3.5 shadow-xs transition hover:border-servora-400 hover:bg-slate-50/70 hover:shadow-md hover:-translate-y-0.5"
        data-service="<?= htmlspecialchars($serviceCode, ENT_QUOTES, "UTF-8") ?>"
        data-name="<?= htmlspecialchars(strtolower($serviceName), ENT_QUOTES, "UTF-8") ?>"
    >
        <div class="flex items-center gap-3.5 min-w-0">
            <div class="service-logo flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-slate-50 border border-slate-100 p-2 shadow-xs group-hover:scale-105 transition-transform">
                <?= serviceIcon($serviceCode) ?>
            </div>
            <div class="service-info min-w-0">
                <div class="service-name text-sm font-bold text-slate-900 truncate group-hover:text-servora-700 transition-colors">
                    <?= htmlspecialchars($serviceName, ENT_QUOTES, "UTF-8") ?>
                </div>
                <div class="service-description text-xs text-slate-500 truncate">
                    <?php if ($serviceCode === "whatsapp"): ?>
                        WhatsApp verification
                    <?php elseif ($serviceCode === "telegram"): ?>
                        Telegram verification
                    <?php elseif ($serviceCode === "facebook"): ?>
                        Facebook verification
                    <?php elseif ($serviceCode === "instagram"): ?>
                        Instagram / Threads
                    <?php elseif ($serviceCode === "tiktok"): ?>
                        TikTok verification
                    <?php elseif ($serviceCode === "google"): ?>
                        Google / YouTube
                    <?php elseif ($serviceCode === "microsoft"): ?>
                        Microsoft services
                    <?php else: ?>
                        SMS verification service
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="service-arrow flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-400 font-bold text-sm transition group-hover:bg-servora-100 group-hover:text-servora-700">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
            </svg>
        </div>
    </a>
<?php endforeach; ?>
        </div>

        <div id="noResults" class="hidden py-12 text-center text-sm font-medium text-slate-400">
            No matching service found.
        </div>

        <!-- How It Works Steps -->
        <div class="mt-8 grid grid-cols-1 sm:grid-cols-3 gap-4 pt-6 border-t border-slate-100">
            <div class="flex items-start gap-3 rounded-2xl bg-slate-50 p-4 border border-slate-100/80">
                <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-servora-600 text-xs font-black text-white">1</div>
                <div>
                    <strong class="block text-xs font-bold text-slate-900">Choose Service</strong>
                    <span class="mt-0.5 block text-xs text-slate-500">Select the website or app you want to verify.</span>
                </div>
            </div>
            <div class="flex items-start gap-3 rounded-2xl bg-slate-50 p-4 border border-slate-100/80">
                <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-servora-600 text-xs font-black text-white">2</div>
                <div>
                    <strong class="block text-xs font-bold text-slate-900">Choose Country</strong>
                    <span class="mt-0.5 block text-xs text-slate-500">Select from available countries with live pricing.</span>
                </div>
            </div>
            <div class="flex items-start gap-3 rounded-2xl bg-slate-50 p-4 border border-slate-100/80">
                <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-servora-600 text-xs font-black text-white">3</div>
                <div>
                    <strong class="block text-xs font-bold text-slate-900">Receive Code</strong>
                    <span class="mt-0.5 block text-xs text-slate-500">Your order page displays the incoming SMS code in real time.</span>
                </div>
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