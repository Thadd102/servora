<?php

require_once "../config/database.php";
require_once "../includes/admin_auth.php";
require_once "../includes/VirtualNumberPricing.php";

/*
|--------------------------------------------------------------------------
| ADMIN
|--------------------------------------------------------------------------
*/

$adminName = $_SESSION["full_name"] ?? "Admin";

$success = "";
$error = "";

/*
|--------------------------------------------------------------------------
| CSRF TOKEN
|--------------------------------------------------------------------------
*/

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(
        random_bytes(32)
    );
}

$csrfToken = $_SESSION["csrf_token"];

/*
|--------------------------------------------------------------------------
| LOAD SETTINGS
|--------------------------------------------------------------------------
|
| The shared pricing helper is now the single source of truth for
| foreign-number pricing settings.
|
*/

try {

    $settings =
        getVirtualNumberPricingSettings(
            $pdo
        );

} catch (Throwable $exception) {

    error_log(
        "Foreign number settings load failed: "
        . $exception->getMessage()
    );

    http_response_code(500);

    exit(
        "Foreign number settings could not be loaded."
    );
}

/*
|--------------------------------------------------------------------------
| UPDATE SETTINGS
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $submittedToken =
        $_POST["csrf_token"] ?? "";

    if (
        !is_string($submittedToken)
        || $submittedToken === ""
        || !hash_equals(
            (string) $_SESSION["csrf_token"],
            $submittedToken
        )
    ) {

        $error =
            "Your session token is invalid. Please refresh the page and try again.";

    } else {

        $profitType = strtolower(
            trim(
                (string) (
                    $_POST["profit_type"]
                    ?? ""
                )
            )
        );

        $profitValueRaw = trim(
            (string) (
                $_POST["profit_value"]
                ?? ""
            )
        );

        $minimumProfitRaw = trim(
            (string) (
                $_POST["minimum_profit"]
                ?? ""
            )
        );

        $status = strtolower(
            trim(
                (string) (
                    $_POST["status"]
                    ?? ""
                )
            )
        );

        /*
        |--------------------------------------------------------------------------
        | VALIDATION
        |--------------------------------------------------------------------------
        */

        if (
            !in_array(
                $profitType,
                [
                    "fixed",
                    "percentage"
                ],
                true
            )
        ) {

            $error =
                "Please select a valid profit type.";

        } elseif (
            $profitValueRaw === ""
            || !is_numeric(
                $profitValueRaw
            )
        ) {

            $error =
                "Please enter a valid profit value.";

        } elseif (
            $minimumProfitRaw === ""
            || !is_numeric(
                $minimumProfitRaw
            )
        ) {

            $error =
                "Please enter a valid minimum profit.";

        } elseif (
            !in_array(
                $status,
                [
                    "active",
                    "inactive"
                ],
                true
            )
        ) {

            $error =
                "Please select a valid module status.";

        } else {

            $profitValue = round(
                (float) $profitValueRaw,
                2
            );

            $minimumProfit = round(
                (float) $minimumProfitRaw,
                2
            );

            if ($profitValue < 0) {

                $error =
                    "Profit value cannot be negative.";

            } elseif ($minimumProfit < 0) {

                $error =
                    "Minimum profit cannot be negative.";

            } elseif (
                $profitType === "percentage"
                && $profitValue > 1000
            ) {

                $error =
                    "Percentage profit is too high.";

            } else {

                try {

                    $stmt = $pdo->prepare("
                        UPDATE virtual_number_settings

                        SET
                            profit_type = ?,
                            profit_value = ?,
                            minimum_profit = ?,
                            status = ?,
                            updated_at = CURRENT_TIMESTAMP

                        WHERE id = ?

                        LIMIT 1
                    ");

                    $stmt->execute([
                        $profitType,
                        $profitValue,
                        $minimumProfit,
                        $status,
                        $settings["id"]
                    ]);

                    /*
                    |--------------------------------------------------------------------------
                    | RELOAD THROUGH SHARED HELPER
                    |--------------------------------------------------------------------------
                    */

                    $settings =
                        getVirtualNumberPricingSettings(
                            $pdo
                        );

                    $success =
                        "Foreign number settings updated successfully.";

                } catch (Throwable $exception) {

                    error_log(
                        "Foreign number settings update failed: "
                        . $exception->getMessage()
                    );

                    $error =
                        "Settings could not be updated. Please try again.";
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function e($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        "UTF-8"
    );
}

function money($amount): string
{
    return "₦" . number_format(
        (float) $amount,
        2
    );
}

function formatDate(?string $date): string
{
    if (!$date) {
        return "—";
    }

    $timestamp = strtotime($date);

    if ($timestamp === false) {
        return "—";
    }

    return date(
        "d M Y, h:i A",
        $timestamp
    );
}

/*
|--------------------------------------------------------------------------
| NORMALIZED VALUES
|--------------------------------------------------------------------------
*/

$providerName = strtolower(
    trim(
        (string) (
            $settings["provider"]
            ?? "mock"
        )
    )
);

$moduleActive =
    isVirtualNumberModuleActive(
        $settings
    );

$profitType = strtolower(
    trim(
        (string) (
            $settings["profit_type"]
            ?? "percentage"
        )
    )
);

/*
|--------------------------------------------------------------------------
| EXAMPLE CALCULATION
|--------------------------------------------------------------------------
|
| IMPORTANT:
|
| This example now uses the exact same pricing function used by:
|
| foreign_number_options.php
| process_foreign_number.php
|
*/

$exampleProviderCost = 500.00;

$examplePricing =
    calculateVirtualNumberPrice(
        $exampleProviderCost,
        $settings
    );

$exampleProfit = (float) (
    $examplePricing["profit"]
    ?? 0
);

$exampleSellingPrice = (float) (
    $examplePricing["selling_price"]
    ?? 0
);

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
        Foreign Number Settings | Servora
    </title>

    <script src="https://cdn.tailwindcss.com"></script>

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        servora: {
                            50: '#F5F3FF',
                            100: '#EDE9FE',
                            200: '#DDD6FE',
                            500: '#635BDB',
                            600: '#5146C7',
                            700: '#3E37B7',
                            800: '#312E81',
                            900: '#1E1B4B'
                        }
                    }
                }
            }
        }
    </script>

</head>

<body class="min-h-screen bg-slate-50 text-slate-900">

<main
    class="mx-auto w-full max-w-5xl
    px-4 py-5 sm:px-6 sm:py-8 lg:px-8"
>

    <!-- HEADER -->

    <section
        class="relative overflow-hidden
        rounded-3xl bg-gradient-to-br
        from-servora-800 via-servora-700
        to-servora-500 p-6
        text-white shadow-xl sm:p-8"
    >

        <div class="relative z-10">

            <div
                class="mb-6 flex flex-wrap
                items-center justify-between gap-3"
            >

                <div class="flex items-center gap-3">

                    <div
                        class="flex h-11 w-11
                        items-center justify-center
                        rounded-2xl bg-white/15
                        text-lg font-black
                        backdrop-blur"
                    >
                        S
                    </div>

                    <div>

                        <p
                            class="text-sm font-semibold
                            text-white/70"
                        >
                            Servora
                        </p>

                        <p
                            class="text-xs text-white/50"
                        >
                            Administration
                        </p>

                    </div>

                </div>

                <a
                    href="foreign_number_orders.php"
                    class="rounded-xl border
                    border-white/15 bg-white/10
                    px-4 py-2 text-xs font-bold
                    backdrop-blur transition
                    hover:bg-white/15"
                >
                    ← Foreign Number Orders
                </a>

            </div>

            <p
                class="mb-2 text-sm font-medium
                text-white/70"
            >
                Foreign Number Management
            </p>

            <h1
                class="text-2xl font-black
                tracking-tight sm:text-4xl"
            >
                Pricing Settings
            </h1>

            <p
                class="mt-3 max-w-2xl
                text-sm leading-6
                text-white/75 sm:text-base"
            >
                Configure how Servora calculates
                the selling price of verification
                numbers.
            </p>

        </div>

        <div
            class="absolute -right-16 -top-20
            h-56 w-56 rounded-full
            bg-white/10"
        ></div>

        <div
            class="absolute -bottom-24 right-20
            h-64 w-64 rounded-full
            bg-white/5"
        ></div>

    </section>

    <!-- MESSAGES -->

    <?php if ($success !== ""): ?>

        <div
            class="mt-5 rounded-2xl
            border border-emerald-200
            bg-emerald-50 px-4 py-3
            text-sm font-semibold
            text-emerald-700"
        >
            <?= e($success) ?>
        </div>

    <?php endif; ?>

    <?php if ($error !== ""): ?>

        <div
            class="mt-5 rounded-2xl
            border border-red-200
            bg-red-50 px-4 py-3
            text-sm font-semibold
            text-red-700"
        >
            <?= e($error) ?>
        </div>

    <?php endif; ?>

    <!-- CURRENT PROVIDER -->

    <section
        class="mt-6 grid gap-4
        sm:grid-cols-2"
    >

        <div
            class="rounded-2xl border
            border-slate-200 bg-white
            p-5 shadow-sm"
        >

            <p
                class="text-xs font-bold uppercase
                tracking-wide text-slate-400"
            >
                Current Provider
            </p>

            <p
                class="mt-2 text-2xl
                font-black text-slate-900"
            >
                <?= e(
                    ucfirst(
                        $providerName
                    )
                ) ?>
            </p>

            <?php if (
                $providerName === "mock"
            ): ?>

                <span
                    class="mt-3 inline-flex
                    rounded-full bg-amber-50
                    px-2.5 py-1 text-xs
                    font-bold text-amber-700
                    ring-1 ring-inset
                    ring-amber-600/20"
                >
                    Development Mode
                </span>

            <?php endif; ?>

        </div>

        <div
            class="rounded-2xl border
            border-slate-200 bg-white
            p-5 shadow-sm"
        >

            <p
                class="text-xs font-bold uppercase
                tracking-wide text-slate-400"
            >
                Module Status
            </p>

            <p
                class="mt-2 text-2xl
                font-black
                <?= $moduleActive
                    ? "text-emerald-600"
                    : "text-red-600" ?>"
            >
                <?= $moduleActive
                    ? "Active"
                    : "Inactive" ?>
            </p>

            <p
                class="mt-2 text-xs
                text-slate-400"
            >
                Last updated:
                <?= e(
                    formatDate(
                        $settings["updated_at"]
                        ?? null
                    )
                ) ?>
            </p>

        </div>

    </section>

    <!-- SETTINGS FORM -->

    <section
        class="mt-5 rounded-2xl
        border border-slate-200
        bg-white p-5 shadow-sm sm:p-7"
    >

        <div class="mb-6">

            <p
                class="text-xs font-bold uppercase
                tracking-widest text-servora-600"
            >
                Configuration
            </p>

            <h2
                class="mt-1 text-xl
                font-black text-slate-900
                sm:text-2xl"
            >
                Profit Configuration
            </h2>

            <p
                class="mt-2 text-sm
                leading-6 text-slate-500"
            >
                The provider cost comes from the
                number supplier. Servora adds your
                configured profit before showing
                the final price to the customer.
            </p>

        </div>

        <form
            method="POST"
            action=""
            class="space-y-5"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($csrfToken) ?>"
            >

            <!-- PROFIT TYPE -->

            <div>

                <label
                    for="profit_type"
                    class="mb-2 block
                    text-sm font-bold
                    text-slate-700"
                >
                    Profit Type
                </label>

                <select
                    id="profit_type"
                    name="profit_type"
                    required
                    class="w-full rounded-xl
                    border border-slate-200
                    bg-white px-4 py-3
                    text-sm outline-none
                    transition
                    focus:border-servora-500
                    focus:ring-4
                    focus:ring-servora-100"
                >

                    <option
                        value="percentage"
                        <?= $profitType
                            === "percentage"
                            ? "selected"
                            : "" ?>
                    >
                        Percentage
                    </option>

                    <option
                        value="fixed"
                        <?= $profitType
                            === "fixed"
                            ? "selected"
                            : "" ?>
                    >
                        Fixed Amount
                    </option>

                </select>

                <p
                    id="profitTypeHelp"
                    class="mt-2 text-xs
                    leading-5 text-slate-400"
                ></p>

            </div>

            <!-- PROFIT VALUE -->

            <div>

                <label
                    for="profit_value"
                    class="mb-2 block
                    text-sm font-bold
                    text-slate-700"
                >
                    Profit Value
                </label>

                <div class="relative">

                    <span
                        id="profitPrefix"
                        class="absolute left-4
                        top-1/2 -translate-y-1/2
                        text-sm font-bold
                        text-slate-400"
                    >
                        %
                    </span>

                    <input
                        type="number"
                        id="profit_value"
                        name="profit_value"
                        min="0"
                        step="0.01"
                        required
                        value="<?= e(
                            number_format(
                                (float) (
                                    $settings["profit_value"]
                                    ?? 0
                                ),
                                2,
                                ".",
                                ""
                            )
                        ) ?>"
                        class="w-full rounded-xl
                        border border-slate-200
                        bg-white py-3 pl-10 pr-4
                        text-sm font-bold
                        outline-none transition
                        focus:border-servora-500
                        focus:ring-4
                        focus:ring-servora-100"
                    >

                </div>

            </div>

            <!-- MINIMUM PROFIT -->

            <div>

                <label
                    for="minimum_profit"
                    class="mb-2 block
                    text-sm font-bold
                    text-slate-700"
                >
                    Minimum Profit
                </label>

                <div class="relative">

                    <span
                        class="absolute left-4
                        top-1/2 -translate-y-1/2
                        text-sm font-bold
                        text-slate-400"
                    >
                        ₦
                    </span>

                    <input
                        type="number"
                        id="minimum_profit"
                        name="minimum_profit"
                        min="0"
                        step="0.01"
                        required
                        value="<?= e(
                            number_format(
                                (float) (
                                    $settings["minimum_profit"]
                                    ?? 0
                                ),
                                2,
                                ".",
                                ""
                            )
                        ) ?>"
                        class="w-full rounded-xl
                        border border-slate-200
                        bg-white py-3 pl-10 pr-4
                        text-sm font-bold
                        outline-none transition
                        focus:border-servora-500
                        focus:ring-4
                        focus:ring-servora-100"
                    >

                </div>

                <p
                    class="mt-2 text-xs
                    leading-5 text-slate-400"
                >
                    Servora will never make less
                    than this amount on an order,
                    even when the calculated
                    percentage is lower.
                </p>

            </div>

            <!-- STATUS -->

            <div>

                <label
                    for="status"
                    class="mb-2 block
                    text-sm font-bold
                    text-slate-700"
                >
                    Module Status
                </label>

                <select
                    id="status"
                    name="status"
                    required
                    class="w-full rounded-xl
                    border border-slate-200
                    bg-white px-4 py-3
                    text-sm outline-none
                    transition
                    focus:border-servora-500
                    focus:ring-4
                    focus:ring-servora-100"
                >

                    <option
                        value="active"
                        <?= $moduleActive
                            ? "selected"
                            : "" ?>
                    >
                        Active
                    </option>

                    <option
                        value="inactive"
                        <?= !$moduleActive
                            ? "selected"
                            : "" ?>
                    >
                        Inactive
                    </option>

                </select>

                <p
                    class="mt-2 text-xs
                    leading-5 text-slate-400"
                >
                    When inactive, Servora blocks
                    new foreign-number orders on
                    both the options page and the
                    server-side purchase processor.
                </p>

            </div>

            <!-- SAVE -->

            <div
                class="border-t
                border-slate-100 pt-5"
            >

                <button
                    type="submit"
                    class="inline-flex w-full
                    items-center justify-center
                    rounded-xl bg-servora-700
                    px-6 py-3.5
                    text-sm font-bold
                    text-white transition
                    hover:bg-servora-800
                    sm:w-auto"
                >
                    Save Settings
                </button>

            </div>

        </form>

    </section>

    <!-- EXAMPLE -->

    <section
        class="mt-5 rounded-2xl
        border border-servora-200
        bg-servora-50 p-5 sm:p-6"
    >

        <p
            class="text-xs font-bold
            uppercase tracking-widest
            text-servora-600"
        >
            Pricing Example
        </p>

        <h2
            class="mt-1 text-lg
            font-black text-servora-900"
        >
            How your current pricing works
        </h2>

        <div
            class="mt-5 grid gap-3
            sm:grid-cols-3"
        >

            <div
                class="rounded-xl bg-white
                p-4 shadow-sm"
            >

                <p
                    class="text-xs font-bold
                    uppercase text-slate-400"
                >
                    Supplier Cost
                </p>

                <p
                    class="mt-1 text-lg
                    font-black text-slate-900"
                >
                    <?= money(
                        $exampleProviderCost
                    ) ?>
                </p>

            </div>

            <div
                class="rounded-xl bg-white
                p-4 shadow-sm"
            >

                <p
                    class="text-xs font-bold
                    uppercase text-slate-400"
                >
                    Your Profit
                </p>

                <p
                    class="mt-1 text-lg
                    font-black text-servora-700"
                >
                    <?= money(
                        $exampleProfit
                    ) ?>
                </p>

            </div>

            <div
                class="rounded-xl bg-white
                p-4 shadow-sm"
            >

                <p
                    class="text-xs font-bold
                    uppercase text-slate-400"
                >
                    Customer Pays
                </p>

                <p
                    class="mt-1 text-lg
                    font-black text-emerald-600"
                >
                    <?= money(
                        $exampleSellingPrice
                    ) ?>
                </p>

            </div>

        </div>

        <div
            class="mt-4 rounded-xl
            bg-white/70 p-4"
        >

            <p
                class="text-sm leading-6
                text-servora-800"
            >

                <?php if (
                    $profitType === "percentage"
                ): ?>

                    Current rule:
                    <strong>
                        <?= e(
                            number_format(
                                (float) (
                                    $settings["profit_value"]
                                    ?? 0
                                ),
                                2
                            )
                        ) ?>%
                    </strong>
                    profit with a minimum profit of
                    <strong>
                        <?= money(
                            $settings[
                                "minimum_profit"
                            ]
                            ?? 0
                        ) ?>
                    </strong>.

                <?php else: ?>

                    Current rule:
                    <strong>
                        <?= money(
                            $settings[
                                "profit_value"
                            ]
                            ?? 0
                        ) ?>
                    </strong>
                    fixed profit with a minimum
                    profit of
                    <strong>
                        <?= money(
                            $settings[
                                "minimum_profit"
                            ]
                            ?? 0
                        ) ?>
                    </strong>.

                <?php endif; ?>

            </p>

        </div>

    </section>

    <!-- DEVELOPMENT NOTICE -->

    <section
        class="mt-5 rounded-2xl
        border border-amber-200
        bg-amber-50 p-5"
    >

        <p
            class="font-black text-amber-800"
        >
            Development Mode
        </p>

        <p
            class="mt-2 text-sm leading-6
            text-amber-700"
        >
            The provider is currently set to
            <strong><?= e(
                ucfirst($providerName)
            ) ?></strong>.
            Mock orders are development-only.
            No external provider credentials
            are managed from this page.
        </p>

    </section>

    <!-- NAVIGATION -->

    <section
        class="mt-8 flex flex-wrap
        gap-3 border-t
        border-slate-200 pt-6"
    >

        <a
            href="foreign_number_orders.php"
            class="rounded-xl bg-servora-700
            px-5 py-3 text-sm
            font-bold text-white
            transition hover:bg-servora-800"
        >
            Foreign Number Orders
        </a>

        <a
            href="dashboard.php"
            class="rounded-xl border
            border-slate-200 bg-white
            px-5 py-3 text-sm
            font-bold text-slate-700
            transition hover:bg-slate-50"
        >
            Admin Dashboard
        </a>

    </section>

    <footer
        class="py-8 text-center
        text-xs text-slate-400"
    >
        Servora Administration
        · <?= e($adminName) ?>
    </footer>

</main>

<script>

const profitType =
    document.getElementById(
        "profit_type"
    );

const profitPrefix =
    document.getElementById(
        "profitPrefix"
    );

const profitTypeHelp =
    document.getElementById(
        "profitTypeHelp"
    );

function updateProfitDisplay() {

    if (
        !profitType
        || !profitPrefix
        || !profitTypeHelp
    ) {
        return;
    }

    if (
        profitType.value ===
        "percentage"
    ) {

        profitPrefix.textContent =
            "%";

        profitTypeHelp.textContent =
            "Example: 20 means Servora adds 20% of the supplier cost.";

    } else {

        profitPrefix.textContent =
            "₦";

        profitTypeHelp.textContent =
            "Example: 200 means Servora adds ₦200 to the supplier cost.";
    }
}

if (profitType) {

    profitType.addEventListener(
        "change",
        updateProfitDisplay
    );
}

updateProfitDisplay();

</script>

</body>
</html>