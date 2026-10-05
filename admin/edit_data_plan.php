<?php

require_once "../config/database.php";
require_once "../includes/admin_auth.php";

$error = "";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION["csrf_token"];


/*
|--------------------------------------------------------------------------
| GET PLAN ID
|--------------------------------------------------------------------------
*/

$planId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$planId) {
    header("Location: data_plans.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| GET NETWORKS, CATEGORIES AND PROVIDERS
|--------------------------------------------------------------------------
*/

$networks = $pdo->query("
    SELECT id, name
    FROM networks
    WHERE status = 'active'
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

$categories = $pdo->query("
    SELECT id, name
    FROM data_categories
    WHERE status = 'active'
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

$providers = $pdo->query("
    SELECT id, name
    FROM api_providers
    WHERE status = 'active'
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| GET CURRENT PLAN
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT *
    FROM data_plans
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$planId]);

$plan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$plan) {
    header("Location: data_plans.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| UPDATE PLAN
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (
        empty($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {

        $error = "Invalid request. Please refresh the page.";

    } else {

        $networkId = (int) ($_POST["network_id"] ?? 0);
        $categoryId = (int) ($_POST["category_id"] ?? 0);
        $providerId = (int) ($_POST["provider_id"] ?? 0);

        $name = trim($_POST["name"] ?? "");
        $dataAmount = trim($_POST["data_amount"] ?? "");
        $validity = trim($_POST["validity"] ?? "");
        $providerPlanCode = trim($_POST["provider_plan_code"] ?? "");

        $costPrice = (float) ($_POST["cost_price"] ?? 0);
        $sellingPrice = (float) ($_POST["selling_price"] ?? 0);

        $status = $_POST["status"] ?? "inactive";

        if (!in_array($status, ["active", "inactive"], true)) {
            $status = "inactive";
        }

        /*
        |--------------------------------------------------------------------------
        | VALIDATION
        |--------------------------------------------------------------------------
        */

        if ($networkId <= 0) {

            $error = "Please select a network.";

        } elseif ($name === "") {

            $error = "Please enter the plan name.";

        } elseif ($dataAmount === "") {

            $error = "Please enter the data amount.";

        } elseif ($sellingPrice <= 0) {

            $error = "Selling price must be greater than zero.";

        } elseif ($costPrice < 0) {

            $error = "Cost price cannot be negative.";

        } elseif ($sellingPrice < $costPrice) {

            $error = "Selling price cannot be lower than supplier cost.";

        } else {

            $stmt = $pdo->prepare("
                UPDATE data_plans

                SET
                    network_id = ?,
                    category_id = ?,
                    provider_id = ?,
                    provider_plan_code = ?,
                    name = ?,
                    data_amount = ?,
                    validity = ?,
                    cost_price = ?,
                    selling_price = ?,
                    status = ?

                WHERE id = ?
            ");

            $stmt->execute([
                $networkId,
                $categoryId > 0 ? $categoryId : null,
                $providerId > 0 ? $providerId : null,
                $providerPlanCode !== "" ? $providerPlanCode : null,
                $name,
                $dataAmount,
                $validity !== "" ? $validity : null,
                $costPrice,
                $sellingPrice,
                $status,
                $planId
            ]);

            /*
            |--------------------------------------------------------------------------
            | REDIRECT
            |--------------------------------------------------------------------------
            | Prevents accidental duplicate form submission after refresh.
            */

            header("Location: data_plans.php?updated=1");
            exit;
        }
    }
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

    <title>Edit Data Plan | Subnext</title>

    <!-- Precompiled Production Stylesheet -->
    <link rel="stylesheet" href="../assets/css/style.css">

</head>


<body class="min-h-screen bg-slate-50 text-slate-900">

<main class="mx-auto max-w-4xl px-4 py-6 sm:px-6">


    <!-- HEADER -->

    <section
        class="rounded-3xl bg-gradient-to-br from-servora-800 via-servora-700 to-servora-500 p-6 text-white shadow-xl sm:p-8"
    >

        <a
            href="data_plans.php"
            class="text-sm font-semibold text-white/70 hover:text-white"
        >
            ← Back to Data Plans
        </a>

        <h1 class="mt-5 text-3xl font-black">
            Edit Data Plan
        </h1>

        <p class="mt-2 text-sm text-white/70">
            Update pricing, supplier mapping and availability.
        </p>

    </section>


    <?php if ($error !== ""): ?>

        <div
            class="mt-6 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-700"
        >
            <?= htmlspecialchars($error) ?>
        </div>

    <?php endif; ?>


    <!-- FORM -->

    <section
        class="mt-6 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7"
    >

        <form method="POST" class="grid gap-5 md:grid-cols-2">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($csrfToken) ?>"
            >


            <!-- NETWORK -->

            <div>

                <label class="mb-2 block text-sm font-bold">
                    Network
                </label>

                <select
                    name="network_id"
                    required
                    class="w-full rounded-xl border border-slate-300 px-4 py-3"
                >

                    <?php foreach ($networks as $network): ?>

                        <option
                            value="<?= (int) $network["id"] ?>"
                            <?= (int) $plan["network_id"] === (int) $network["id"]
                                ? "selected"
                                : "" ?>
                        >
                            <?= htmlspecialchars($network["name"]) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <!-- CATEGORY -->

            <div>

                <label class="mb-2 block text-sm font-bold">
                    Category
                </label>

                <select
                    name="category_id"
                    class="w-full rounded-xl border border-slate-300 px-4 py-3"
                >

                    <option value="">No category</option>

                    <?php foreach ($categories as $category): ?>

                        <option
                            value="<?= (int) $category["id"] ?>"
                            <?= (int) $plan["category_id"] === (int) $category["id"]
                                ? "selected"
                                : "" ?>
                        >
                            <?= htmlspecialchars($category["name"]) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <!-- PLAN NAME -->

            <div>

                <label class="mb-2 block text-sm font-bold">
                    Plan Name
                </label>

                <input
                    type="text"
                    name="name"
                    required
                    value="<?= htmlspecialchars($plan["name"]) ?>"
                    class="w-full rounded-xl border border-slate-300 px-4 py-3"
                >

            </div>


            <!-- DATA AMOUNT -->

            <div>

                <label class="mb-2 block text-sm font-bold">
                    Data Amount
                </label>

                <input
                    type="text"
                    name="data_amount"
                    required
                    value="<?= htmlspecialchars($plan["data_amount"]) ?>"
                    class="w-full rounded-xl border border-slate-300 px-4 py-3"
                >

            </div>


            <!-- VALIDITY -->

            <div>

                <label class="mb-2 block text-sm font-bold">
                    Validity
                </label>

                <input
                    type="text"
                    name="validity"
                    value="<?= htmlspecialchars($plan["validity"] ?? "") ?>"
                    placeholder="Example: 30 Days"
                    class="w-full rounded-xl border border-slate-300 px-4 py-3"
                >

            </div>


            <!-- PROVIDER -->

            <div>

                <label class="mb-2 block text-sm font-bold">
                    API Provider
                </label>

                <select
                    name="provider_id"
                    class="w-full rounded-xl border border-slate-300 px-4 py-3"
                >

                    <option value="">Not connected</option>

                    <?php foreach ($providers as $provider): ?>

                        <option
                            value="<?= (int) $provider["id"] ?>"
                            <?= (int) $plan["provider_id"] === (int) $provider["id"]
                                ? "selected"
                                : "" ?>
                        >
                            <?= htmlspecialchars($provider["name"]) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <!-- PROVIDER CODE -->

            <div>

                <label class="mb-2 block text-sm font-bold">
                    Provider Plan Code
                </label>

                <input
                    type="text"
                    name="provider_plan_code"
                    value="<?= htmlspecialchars(
                        $plan["provider_plan_code"] ?? ""
                    ) ?>"
                    placeholder="Supplier plan ID/code"
                    class="w-full rounded-xl border border-slate-300 px-4 py-3"
                >

            </div>


            <!-- COST PRICE -->
            <div>
                <label class="mb-2 block text-sm font-bold text-slate-800">
                    Supplier Cost (₦)
                </label>
                <input
                    type="number"
                    id="edit_cost_price"
                    name="cost_price"
                    min="0"
                    step="0.01"
                    required
                    value="<?= htmlspecialchars($plan["cost_price"]) ?>"
                    class="w-full rounded-xl border border-slate-300 px-4 py-3 font-mono font-medium text-slate-900"
                >
            </div>

            <!-- PROFIT MARKUP (INTERACTIVE) -->
            <?php
            $currentProfit = (float)$plan["selling_price"] - (float)$plan["cost_price"];
            ?>
            <div>
                <label class="mb-2 flex items-center justify-between text-sm font-bold text-slate-800">
                    <span>Profit Markup (₦)</span>
                    <span class="text-xs font-semibold text-emerald-600">Auto-calculates</span>
                </label>
                <input
                    type="number"
                    id="edit_profit_margin"
                    step="0.01"
                    value="<?= number_format($currentProfit, 2, '.', '') ?>"
                    class="w-full rounded-xl border border-emerald-300 bg-emerald-50/30 px-4 py-3 font-mono font-bold text-emerald-700 outline-none focus:border-emerald-600"
                >
            </div>

            <!-- SELLING PRICE -->
            <div>
                <label class="mb-2 block text-sm font-bold text-slate-800">
                    Subnext Selling Price (₦)
                </label>
                <input
                    type="number"
                    id="edit_selling_price"
                    name="selling_price"
                    min="0.01"
                    step="0.01"
                    required
                    value="<?= htmlspecialchars($plan["selling_price"]) ?>"
                    class="w-full rounded-xl border border-slate-300 px-4 py-3 font-mono font-black text-slate-900"
                >
            </div>

            <!-- STATUS -->
            <div>
                <label class="mb-2 block text-sm font-bold text-slate-800">
                    Availability
                </label>
                <select
                    name="status"
                    class="w-full rounded-xl border border-slate-300 px-4 py-3"
                >
                    <option
                        value="inactive"
                        <?= $plan["status"] === "inactive" ? "selected" : "" ?>
                    >
                        Inactive — hidden from customers
                    </option>
                    <option
                        value="active"
                        <?= $plan["status"] === "active" ? "selected" : "" ?>
                    >
                        Active — available for purchase
                    </option>
                </select>
            </div>

            <!-- SAVE -->
            <div class="md:col-span-2 pt-2">
                <button
                    type="submit"
                    class="w-full rounded-xl bg-servora-700 px-6 py-3.5 font-bold text-white transition hover:bg-servora-800 sm:w-auto shadow-md"
                >
                    Save Changes
                </button>
            </div>

        </form>

    </section>

</main>

<script>
    const costInput = document.getElementById('edit_cost_price');
    const profitInput = document.getElementById('edit_profit_margin');
    const sellingInput = document.getElementById('edit_selling_price');

    function updateFromProfit() {
        const cost = parseFloat(costInput.value) || 0;
        const profit = parseFloat(profitInput.value) || 0;
        const total = cost + profit;
        sellingInput.value = total > 0 ? total.toFixed(2) : '';
    }

    function updateFromSelling() {
        const cost = parseFloat(costInput.value) || 0;
        const selling = parseFloat(sellingInput.value) || 0;
        const profit = selling - cost;
        profitInput.value = profit >= 0 ? profit.toFixed(2) : '0.00';
    }

    if (costInput && profitInput && sellingInput) {
        costInput.addEventListener('input', updateFromProfit);
        profitInput.addEventListener('input', updateFromProfit);
        sellingInput.addEventListener('input', updateFromSelling);
    }
</script>

</body>
</html>