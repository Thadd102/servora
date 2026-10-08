<?php

require_once "../config/database.php";
require_once "../includes/client_auth.php";

/*
|--------------------------------------------------------------------------
| LOGGED-IN CLIENT
|--------------------------------------------------------------------------
*/

$userId = currentUserId();

/*
|--------------------------------------------------------------------------
| CREATE PURCHASE TOKEN
|--------------------------------------------------------------------------
|
| Helps prevent the same purchase from being charged twice.
|
*/

if (empty($_SESSION["data_purchase_token"])) {
    $_SESSION["data_purchase_token"] = bin2hex(random_bytes(32));
}

$purchaseToken = $_SESSION["data_purchase_token"];

/*
|--------------------------------------------------------------------------
| GET CUSTOMER
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT id, full_name
    FROM users
    WHERE id = ?
      AND role = 'client'
      AND status = 'active'
    LIMIT 1
");

$stmt->execute([$userId]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header("Location: ../logout.php");
    exit;
}

$fullName = trim((string)($user["full_name"] ?? $_SESSION["full_name"] ?? "Client"));
$profileInitial = strtoupper(substr($fullName, 0, 1) ?: "C");

/*
|--------------------------------------------------------------------------
| GET WALLET BALANCE
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT balance
    FROM wallets
    WHERE user_id = ?
    LIMIT 1
");

$stmt->execute([$userId]);

$wallet = $stmt->fetch(PDO::FETCH_ASSOC);

$walletBalance = $wallet
    ? (float) $wallet["balance"]
    : 0.00;

/*
|--------------------------------------------------------------------------
| GET ACTIVE NETWORKS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT DISTINCT
        n.id,
        n.name,
        n.slug
    FROM networks n
    INNER JOIN data_plans dp
        ON dp.network_id = n.id
    WHERE n.status = 'active'
      AND dp.status = 'active'
    ORDER BY n.name ASC
");

$networks = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| GET ACTIVE DATA PLANS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT
        dp.id,
        dp.network_id,
        dp.name,
        dp.data_amount,
        dp.validity,
        dp.selling_price,
        dc.name AS category_name
    FROM data_plans dp
    LEFT JOIN data_categories dc
        ON dp.category_id = dc.id
    INNER JOIN networks n
        ON dp.network_id = n.id
    WHERE dp.status = 'active'
      AND n.status = 'active'
    ORDER BY
        n.name ASC,
        dp.selling_price ASC
");

$plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| ERROR MESSAGES
|--------------------------------------------------------------------------
*/

$errorMessages = [
    "invalid"              => "Please complete all required fields.",
    "phone"                => "Please enter a valid Nigerian mobile number.",
    "network"              => "The phone number does not appear to match the selected network.",
    "network_unavailable"  => "The selected network is temporarily unavailable.",
    "plan"                 => "The selected data plan is unavailable.",
    "plan_changed"         => "This data plan was just updated. Please select the plan again.",
    "category"             => "The selected data category is temporarily unavailable.",
    "provider"             => "This data plan is temporarily unavailable.",
    "provider_plan"        => "This data plan is temporarily unavailable.",
    "provider_unavailable" => "Data service is temporarily unavailable. Please try again shortly.",
    "supplier_balance"     => "Data service is temporarily unavailable. Please try again later.",
    "account"              => "Your account cannot make this purchase at the moment.",
    "wallet"               => "Your wallet could not be found.",
    "balance"              => "Your wallet balance is insufficient for this purchase.",
    "processing"           => "We could not process the purchase. Please try again."
];

$errorCode = $_GET["error"] ?? "";
$errorMessage = $errorMessages[$errorCode] ?? "";

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Buy Data | Subnext</title>

    <!-- Precompiled Production Stylesheet -->
    <link rel="stylesheet" href="../assets/css/style.css">

</head>

<body class="min-h-screen bg-slate-50 text-slate-900">

<!-- Desktop Navigation Header -->
<header class="hidden md:block sticky top-0 z-40 border-b border-slate-200/80 bg-white/95 backdrop-blur">
    <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6">
        <a href="dashboard.php" class="flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-servora-700 text-lg font-black text-white shadow-sm">S</div>
            <div>
                <div class="text-lg font-bold tracking-tight text-slate-900 leading-tight">Subnext</div>
                <div class="text-[10px] font-medium text-slate-400 uppercase tracking-wider">Data Bundles</div>
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

    <section
        class="relative overflow-hidden rounded-3xl
               bg-gradient-to-br
               from-servora-800
               via-servora-700
               to-servora-500
               p-6 sm:p-8
               text-white shadow-xl"
    >

        <a
            href="dashboard.php"
            class="text-sm font-semibold text-white/70 hover:text-white"
        >
            ← Dashboard
        </a>

        <div class="mt-6">

            <p class="text-sm font-semibold text-white/70">
                Subnext Data
            </p>

            <h1 class="mt-1 text-3xl font-black">
                Buy Data
            </h1>

            <p class="mt-2 max-w-xl text-sm text-white/70">
                Select your network and preferred data plan.
            </p>

        </div>

    </section>

    <?php if ($errorMessage !== ""): ?>

        <div
            class="mt-6 rounded-2xl border border-red-200
                   bg-red-50 px-4 py-3
                   text-sm font-semibold text-red-700"
        >
            <?= htmlspecialchars(
                $errorMessage,
                ENT_QUOTES,
                "UTF-8"
            ) ?>
        </div>

    <?php endif; ?>

    <section
        class="mt-6 rounded-2xl border border-slate-200
               bg-white p-5 shadow-sm"
    >

        <div class="flex items-center justify-between gap-4">

            <div>

                <p
                    class="text-xs font-bold uppercase
                           tracking-wide text-slate-400"
                >
                    Wallet Balance
                </p>

                <p class="mt-1 text-2xl font-black text-slate-900">
                    ₦<?= number_format($walletBalance, 2) ?>
                </p>

            </div>

            <a
                href="fund_wallet.php"
                class="rounded-xl bg-servora-50
                       px-4 py-2.5 text-sm
                       font-bold text-servora-700"
            >
                Fund Wallet
            </a>

        </div>

    </section>

    <section
        class="mt-6 rounded-3xl border border-slate-200
               bg-white p-5 sm:p-7 shadow-sm"
    >

        <div>

            <p
                class="text-xs font-bold uppercase
                       tracking-widest text-servora-600"
            >
                Data Purchase
            </p>

            <h2 class="mt-1 text-xl font-black">
                Choose your plan
            </h2>

            <p class="mt-1 text-sm text-slate-500">
                Select a network to see available plans.
            </p>

        </div>

        <?php if (empty($plans)): ?>

            <div
                class="mt-6 rounded-2xl bg-amber-50
                       p-5 text-sm text-amber-700"
            >
                There are currently no active data plans.
            </div>

        <?php else: ?>

            <form
                id="dataForm"
                action="process_data_purchase.php"
                method="POST"
                class="mt-7 space-y-5"
            >

                <input
                    type="hidden"
                    name="client_request_token"
                    value="<?= htmlspecialchars(
                        $purchaseToken,
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
                >

                <div>

                    <label
                        for="network"
                        class="mb-2 block text-sm font-bold"
                    >
                        Network
                    </label>

                    <select
                        id="network"
                        required
                        class="w-full rounded-xl
                               border border-slate-300
                               px-4 py-3
                               outline-none
                               focus:border-servora-600"
                    >

                        <option value="">
                            Select network
                        </option>

                        <?php foreach ($networks as $network): ?>

                            <option
                                value="<?= (int) $network["id"] ?>"
                                data-slug="<?= htmlspecialchars(
                                    strtolower($network["slug"]),
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>"
                            >
                                <?= htmlspecialchars(
                                    $network["name"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div>

                    <label
                        for="plan"
                        class="mb-2 block text-sm font-bold"
                    >
                        Data Plan
                    </label>

                    <select
                        id="plan"
                        name="plan_id"
                        required
                        disabled
                        class="w-full rounded-xl
                               border border-slate-300
                               px-4 py-3
                               outline-none
                               disabled:bg-slate-100
                               focus:border-servora-600"
                    >

                        <option value="">
                            Select network first
                        </option>

                        <?php foreach ($plans as $plan): ?>

                            <option
                                value="<?= (int) $plan["id"] ?>"
                                data-network="<?= (int) $plan["network_id"] ?>"
                                data-price="<?= htmlspecialchars(
                                    $plan["selling_price"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>"
                                data-name="<?= htmlspecialchars(
                                    $plan["name"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>"
                                hidden
                            >

                                <?= htmlspecialchars(
                                    $plan["name"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>

                                — <?= htmlspecialchars(
                                    $plan["data_amount"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>

                                <?php if (!empty($plan["validity"])): ?>

                                    — <?= htmlspecialchars(
                                        $plan["validity"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>

                                <?php endif; ?>

                                — ₦<?= number_format(
                                    (float) $plan["selling_price"],
                                    2
                                ) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div>

                    <label
                        for="phone"
                        class="mb-2 block text-sm font-bold"
                    >
                        Phone Number
                    </label>

                    <input
                        type="tel"
                        id="phone"
                        name="phone"
                        required
                        maxlength="14"
                        inputmode="numeric"
                        autocomplete="tel"
                        placeholder="Example: 08012345678"
                        class="w-full rounded-xl
                               border border-slate-300
                               px-4 py-3
                               outline-none
                               transition
                               focus:border-servora-600"
                    >

                    <div
                        id="phoneStatus"
                        class="mt-2 hidden rounded-xl
                               px-3 py-2 text-xs font-semibold"
                    ></div>

                    <p class="mt-2 text-xs text-slate-400">
                        Subnext will check the phone number before purchase.
                    </p>

                </div>

                <div
                    id="summary"
                    class="hidden rounded-2xl bg-slate-50 p-5"
                >

                    <p
                        class="text-xs font-bold uppercase
                               tracking-wide text-slate-400"
                    >
                        Order Summary
                    </p>

                    <div
                        class="mt-4 flex justify-between gap-4"
                    >

                        <span class="text-sm text-slate-500">
                            Plan
                        </span>

                        <span
                            id="summaryPlan"
                            class="text-right text-sm font-bold"
                        >
                            —
                        </span>

                    </div>

                    <div
                        class="mt-3 flex justify-between gap-4"
                    >

                        <span class="text-sm text-slate-500">
                            Amount
                        </span>

                        <span
                            id="summaryPrice"
                            class="text-lg font-black text-servora-700"
                        >
                            ₦0.00
                        </span>

                    </div>

                </div>

                <button
                    id="purchaseButton"
                    type="submit"
                    class="w-full rounded-xl
                           bg-servora-700
                           px-5 py-3.5
                           font-bold text-white
                           transition
                           hover:bg-servora-800
                           disabled:cursor-not-allowed
                           disabled:opacity-60"
                >
                    Buy Data
                </button>

                <p class="text-center text-xs text-slate-400">
                    The amount will be deducted from your Subnext wallet.
                </p>

            </form>

        <?php endif; ?>

    </section>

</main>

<script>
const networkSelect = document.getElementById("network");
const planSelect = document.getElementById("plan");
const phoneInput = document.getElementById("phone");
const phoneStatus = document.getElementById("phoneStatus");
const form = document.getElementById("dataForm");
const summary = document.getElementById("summary");
const summaryPlan = document.getElementById("summaryPlan");
const summaryPrice = document.getElementById("summaryPrice");
const purchaseButton = document.getElementById("purchaseButton");

// Cache all initial plan options rendered by PHP (excluding placeholder options)
const masterPlanOptions = planSelect
    ? Array.from(planSelect.options).filter(function (opt) {
        return opt.dataset.network !== undefined && opt.dataset.network !== "";
    })
    : [];

function filterPlansByNetwork(networkId) {
    if (!planSelect) {
        return;
    }

    planSelect.innerHTML = "";

    const placeholderOpt = document.createElement("option");
    placeholderOpt.value = "";
    placeholderOpt.textContent = networkId ? "Select data plan" : "Select network first";
    planSelect.appendChild(placeholderOpt);

    if (networkId) {
        let matchCount = 0;
        masterPlanOptions.forEach(function (opt) {
            if (String(opt.dataset.network) === String(networkId)) {
                const clonedOpt = opt.cloneNode(true);
                clonedOpt.hidden = false;
                clonedOpt.removeAttribute("hidden");
                planSelect.appendChild(clonedOpt);
                matchCount++;
            }
        });
        planSelect.disabled = (matchCount === 0);
    } else {
        planSelect.disabled = true;
    }

    planSelect.value = "";

    if (summary) {
        summary.classList.add("hidden");
    }
}

const networkPrefixes = {
    airtel: ["0701","0708","0802","0808","0812","0901","0902","0904","0907","0911","0912"],
    mtn: ["0703","0704","0706","0707","0803","0806","0810","0813","0814","0816","0903","0906","0913","0916"],
    glo: ["0705","0805","0807","0811","0815","0905","0915"],
    t2: ["0809","0817","0818","0908","0909"]
};

const networkNames = {
    airtel: "Airtel",
    mtn: "MTN",
    glo: "Glo",
    t2: "T2"
};

function normalizePhone(phone)
{
    let cleaned = phone.replace(/\D/g, "");

    if (cleaned.startsWith("234") && cleaned.length === 13) {
        cleaned = "0" + cleaned.substring(3);
    }

    return cleaned;
}

function detectNetwork(phone)
{
    const localPhone = normalizePhone(phone);

    if (localPhone.length !== 11) {
        return null;
    }

    const prefix = localPhone.substring(0, 4);

    for (const network in networkPrefixes) {
        if (networkPrefixes[network].includes(prefix)) {
            return network;
        }
    }

    return null;
}

function showPhoneStatus(message, type)
{
    if (!phoneStatus) {
        return;
    }

    phoneStatus.classList.remove(
        "hidden",
        "bg-emerald-50",
        "text-emerald-700",
        "bg-amber-50",
        "text-amber-700",
        "bg-red-50",
        "text-red-700"
    );

    if (type === "success") {
        phoneStatus.classList.add("bg-emerald-50", "text-emerald-700");
    } else if (type === "warning") {
        phoneStatus.classList.add("bg-amber-50", "text-amber-700");
    } else {
        phoneStatus.classList.add("bg-red-50", "text-red-700");
    }

    phoneStatus.textContent = message;
}

function validatePhone()
{
    if (!phoneInput || !phoneStatus) {
        return false;
    }

    const rawPhone = phoneInput.value.trim();
    const localPhone = normalizePhone(rawPhone);

    if (localPhone === "") {
        phoneStatus.classList.add("hidden");
        return false;
    }

    if (!/^0\d{10}$/.test(localPhone)) {
        showPhoneStatus(
            "Enter a valid 11-digit Nigerian mobile number.",
            "error"
        );
        return false;
    }

    const detectedNetwork = detectNetwork(localPhone);

    if (!detectedNetwork) {
        showPhoneStatus(
            "This number has a valid length, but Subnext could not identify its mobile prefix.",
            "error"
        );
        return false;
    }

    let selectedNetwork = "";
    let selectedNetworkName = "";

    if (networkSelect && networkSelect.selectedIndex >= 0) {
        const selectedOption =
            networkSelect.options[networkSelect.selectedIndex];

        selectedNetwork = selectedOption.dataset.slug || "";
        selectedNetworkName = selectedOption.textContent.trim();
    }

    if (!selectedNetwork) {
        // Automatically pre-select detected network in dropdown
        for (let i = 0; i < networkSelect.options.length; i++) {
            if (networkSelect.options[i].dataset.slug === detectedNetwork) {
                networkSelect.selectedIndex = i;
                networkSelect.dispatchEvent(new Event("change"));
                selectedNetwork = detectedNetwork;
                selectedNetworkName = networkNames[detectedNetwork];
                break;
            }
        }
    }

    if (selectedNetwork !== detectedNetwork) {
        showPhoneStatus(
            "Likely network: " +
            networkNames[detectedNetwork] +
            ". You selected " +
            selectedNetworkName +
            ". If this number was ported, confirm its current network before purchasing.",
            "warning"
        );
        return true;
    }

    showPhoneStatus(
        "Likely network: " +
        networkNames[detectedNetwork] +
        " ✓",
        "success"
    );

    return true;
}

if (networkSelect && planSelect) {
    networkSelect.addEventListener("change", function () {
        filterPlansByNetwork(this.value);

        if (phoneInput && phoneInput.value.trim() !== "") {
            validatePhone();
        }
    });
}

if (phoneInput) {
    phoneInput.addEventListener("input", function () {
        this.value = this.value.replace(/[^\d+]/g, "");
        validatePhone();
    });
}

if (planSelect) {
    planSelect.addEventListener("change", function () {
        const selected = this.options[this.selectedIndex];

        if (!selected.value) {
            if (summary) {
                summary.classList.add("hidden");
            }
            return;
        }

        const planName = selected.dataset.name;
        const price = Number(selected.dataset.price);

        if (summaryPlan) {
            summaryPlan.textContent = planName;
        }

        if (summaryPrice) {
            summaryPrice.textContent =
                "₦" +
                price.toLocaleString(
                    "en-NG",
                    {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    }
                );
        }

        if (summary) {
            summary.classList.remove("hidden");
        }
    });
}

if (form && purchaseButton) {
    form.addEventListener("submit", function (event) {
        const phoneIsValid = validatePhone();

        if (!phoneIsValid) {
            event.preventDefault();

            if (phoneInput) {
                phoneInput.focus();
            }

            return;
        }

        if (!planSelect || !planSelect.value) {
            event.preventDefault();
            alert("Please select a data plan.");
            return;
        }

        purchaseButton.disabled = true;
        purchaseButton.textContent = "Processing...";
    });
}
</script>

<?php require_once __DIR__ . "/../includes/client_bottom_nav.php"; ?>

</body>
</html>
