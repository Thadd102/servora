<?php

require_once "../config/database.php";
require_once "../includes/admin_auth.php";
require_once "../includes/BrandAssetHelper.php";

/*
|--------------------------------------------------------------------------
| ADMIN
|--------------------------------------------------------------------------
*/

$adminName = $_SESSION["full_name"] ?? "Admin";

/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/

$search = trim($_GET["search"] ?? "");
$status = strtolower(trim($_GET["status"] ?? ""));
$service = strtolower(trim($_GET["service"] ?? ""));

$allowedStatuses = [
    "processing",
    "waiting_sms",
    "completed",
    "cancelled",
    "expired",
    "failed",
    "unknown",
    "refunded"
];

if (
    $status !== ""
    && !in_array($status, $allowedStatuses, true)
) {
    $status = "";
}

/*
|--------------------------------------------------------------------------
| STATISTICS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT
        COUNT(*) AS total_orders,

        SUM(
            CASE
                WHEN status IN ('processing', 'waiting_sms', 'unknown')
                THEN 1
                ELSE 0
            END
        ) AS active_orders,

        SUM(
            CASE
                WHEN status = 'completed'
                THEN 1
                ELSE 0
            END
        ) AS completed_orders,

        SUM(
            CASE
                WHEN status IN ('failed', 'expired', 'cancelled')
                THEN 1
                ELSE 0
            END
        ) AS failed_orders,

        COALESCE(
            SUM(
                CASE
                    WHEN status = 'completed'
                    THEN selling_price
                    ELSE 0
                END
            ),
            0
        ) AS total_sales,

        COALESCE(
            SUM(
                CASE
                    WHEN status = 'completed'
                    THEN (selling_price - provider_cost)
                    ELSE 0
                END
            ),
            0
        ) AS total_profit

    FROM virtual_number_orders
");

$stats = $stmt->fetch(PDO::FETCH_ASSOC);

$totalOrders = (int) ($stats["total_orders"] ?? 0);
$activeOrders = (int) ($stats["active_orders"] ?? 0);
$completedOrders = (int) ($stats["completed_orders"] ?? 0);
$failedOrders = (int) ($stats["failed_orders"] ?? 0);

$totalSales = (float) ($stats["total_sales"] ?? 0);
$totalProfit = (float) ($stats["total_profit"] ?? 0);

/*
|--------------------------------------------------------------------------
| SERVICE FILTER OPTIONS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT DISTINCT
        service_code,
        service_name
    FROM virtual_number_orders
    WHERE service_code IS NOT NULL
      AND service_code <> ''
    ORDER BY service_name ASC
");

$serviceOptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| BUILD ORDER QUERY
|--------------------------------------------------------------------------
*/

$where = [];
$params = [];

if ($status !== "") {
    $where[] = "vno.status = ?";
    $params[] = $status;
}

if ($service !== "") {
    $where[] = "LOWER(vno.service_code) = ?";
    $params[] = $service;
}

if ($search !== "") {

    $where[] = "
        (
            vno.order_reference LIKE ?
            OR vno.provider_order_id LIKE ?
            OR vno.provider_phone_number LIKE ?
            OR vno.service_name LIKE ?
            OR vno.country_name LIKE ?
            OR vno.operator_name LIKE ?
            OR u.full_name LIKE ?
            OR u.email LIKE ?
            OR u.phone LIKE ?
        )
    ";

    $searchTerm = "%" . $search . "%";

    for ($i = 0; $i < 9; $i++) {
        $params[] = $searchTerm;
    }
}

$whereSql = "";

if (!empty($where)) {
    $whereSql =
        "WHERE " . implode(" AND ", $where);
}

/*
|--------------------------------------------------------------------------
| LOAD ORDERS
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        vno.id,
        vno.order_reference,
        vno.user_id,
        vno.service_code,
        vno.service_name,
        vno.country_code,
        vno.country_name,
        vno.operator_code,
        vno.operator_name,
        vno.provider,
        vno.provider_order_id,
        vno.provider_phone_number,
        vno.provider_cost,
        vno.selling_price,
        vno.status,
        vno.sms_code,
        vno.provider_message,
        vno.created_at,
        vno.updated_at,
        vno.completed_at,

        u.full_name AS client_name,
        u.email AS client_email,
        u.phone AS client_phone

    FROM virtual_number_orders AS vno

    INNER JOIN users AS u
        ON u.id = vno.user_id

    $whereSql

    ORDER BY vno.id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES,
            "UTF-8"
        );
    }
}

if (!function_exists('money')) {
    function money($amount): string
    {
        return "₦" . number_format(
            (float) $amount,
            2
        );
    }
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

function statusConfig(string $status): array
{
    return match ($status) {

        "completed" => [
            "label" => "Completed",
            "class" =>
                "bg-emerald-50 text-emerald-700 ring-emerald-600/20"
        ],

        "waiting_sms" => [
            "label" => "Waiting for OTP",
            "class" =>
                "bg-amber-50 text-amber-700 ring-amber-600/20"
        ],

        "processing" => [
            "label" => "Processing",
            "class" =>
                "bg-blue-50 text-blue-700 ring-blue-600/20"
        ],

        "failed" => [
            "label" => "Failed",
            "class" =>
                "bg-red-50 text-red-700 ring-red-600/20"
        ],

        "expired" => [
            "label" => "Expired",
            "class" =>
                "bg-red-50 text-red-700 ring-red-600/20"
        ],

        "cancelled" => [
            "label" => "Cancelled",
            "class" =>
                "bg-red-50 text-red-700 ring-red-600/20"
        ],

        "refunded" => [
            "label" => "Refunded",
            "class" =>
                "bg-violet-50 text-violet-700 ring-violet-600/20"
        ],

        "unknown" => [
            "label" => "Unknown",
            "class" =>
                "bg-slate-100 text-slate-700 ring-slate-600/20"
        ],

        default => [
            "label" => ucfirst($status),
            "class" =>
                "bg-slate-100 text-slate-700 ring-slate-600/20"
        ]
    };
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

    <title>
        Foreign Number Orders | Servora
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
    class="mx-auto w-full max-w-7xl
    px-4 py-5 sm:px-6 sm:py-8 lg:px-8"
>

    <!-- =====================================================
         HEADER
    ====================================================== -->

    <section
        class="relative overflow-hidden rounded-3xl
        bg-gradient-to-br from-servora-800
        via-servora-700 to-servora-500
        p-6 text-white shadow-xl sm:p-8"
    >

        <div class="relative z-10">

            <div
                class="mb-6 flex flex-wrap
                items-center justify-between gap-3"
            >

                <div class="flex items-center gap-3">

                    <a
                        href="dashboard.php"
                        class="flex h-11 w-11 items-center
                        justify-center rounded-2xl
                        bg-white/15 text-lg font-black
                        backdrop-blur transition
                        hover:bg-white/20"
                    >
                        S
                    </a>

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
                    href="dashboard.php"
                    class="rounded-xl border
                    border-white/15 bg-white/10
                    px-4 py-2 text-xs font-bold
                    backdrop-blur transition
                    hover:bg-white/15"
                >
                    ← Dashboard
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
                Verification Number Orders
            </h1>

            <p
                class="mt-3 max-w-2xl text-sm
                leading-6 text-white/75 sm:text-base"
            >
                Monitor client verification number orders,
                provider activity, sales and order statuses.
            </p>

        </div>

        <div
            class="absolute -right-16 -top-20
            h-56 w-56 rounded-full bg-white/10"
        ></div>

        <div
            class="absolute -bottom-24 right-20
            h-64 w-64 rounded-full bg-white/5"
        ></div>

    </section>

    <!-- =====================================================
         STATISTICS
    ====================================================== -->

    <section
        class="mt-6 grid grid-cols-2
        gap-3 sm:gap-5 lg:grid-cols-4"
    >

        <!-- TOTAL -->

        <div
            class="rounded-2xl border border-slate-200
            bg-white p-4 shadow-sm sm:p-5"
        >

            <p
                class="text-xs font-semibold uppercase
                tracking-wide text-slate-400"
            >
                Total Orders
            </p>

            <p
                class="mt-2 text-2xl font-black
                text-slate-900 sm:text-3xl"
            >
                <?= $totalOrders ?>
            </p>

        </div>

        <!-- ACTIVE -->

        <div
            class="rounded-2xl border border-slate-200
            bg-white p-4 shadow-sm sm:p-5"
        >

            <p
                class="text-xs font-semibold uppercase
                tracking-wide text-slate-400"
            >
                Active / Waiting
            </p>

            <p
                class="mt-2 text-2xl font-black
                text-amber-600 sm:text-3xl"
            >
                <?= $activeOrders ?>
            </p>

        </div>

        <!-- COMPLETED -->

        <div
            class="rounded-2xl border border-slate-200
            bg-white p-4 shadow-sm sm:p-5"
        >

            <p
                class="text-xs font-semibold uppercase
                tracking-wide text-slate-400"
            >
                Completed
            </p>

            <p
                class="mt-2 text-2xl font-black
                text-emerald-600 sm:text-3xl"
            >
                <?= $completedOrders ?>
            </p>

        </div>

        <!-- FAILED -->

        <div
            class="rounded-2xl border border-slate-200
            bg-white p-4 shadow-sm sm:p-5"
        >

            <p
                class="text-xs font-semibold uppercase
                tracking-wide text-slate-400"
            >
                Failed / Expired
            </p>

            <p
                class="mt-2 text-2xl font-black
                text-red-600 sm:text-3xl"
            >
                <?= $failedOrders ?>
            </p>

        </div>

    </section>

    <!-- =====================================================
         BUSINESS SUMMARY
    ====================================================== -->

    <section
        class="mt-4 grid gap-3
        sm:grid-cols-2 sm:gap-5"
    >

        <div
            class="rounded-2xl border border-slate-200
            bg-white p-5 shadow-sm"
        >

            <p
                class="text-xs font-semibold uppercase
                tracking-wide text-slate-400"
            >
                Completed Sales
            </p>

            <p
                class="mt-2 text-2xl font-black
                text-slate-900"
            >
                <?= money($totalSales) ?>
            </p>

        </div>

        <div
            class="rounded-2xl border border-slate-200
            bg-white p-5 shadow-sm"
        >

            <p
                class="text-xs font-semibold uppercase
                tracking-wide text-slate-400"
            >
                Estimated Profit
            </p>

            <p
                class="mt-2 text-2xl font-black
                text-servora-700"
            >
                <?= money($totalProfit) ?>
            </p>

        </div>

    </section>

    <!-- =====================================================
         FILTERS
    ====================================================== -->

    <section
        class="mt-8 rounded-2xl border
        border-slate-200 bg-white p-4
        shadow-sm sm:p-5"
    >

        <form
            method="GET"
            action=""
            class="grid gap-3
            md:grid-cols-4"
        >

            <!-- SEARCH -->

            <div class="md:col-span-2">

                <label
                    for="search"
                    class="mb-1.5 block text-xs
                    font-bold text-slate-600"
                >
                    Search Orders
                </label>

                <input
                    type="text"
                    id="search"
                    name="search"
                    value="<?= e($search) ?>"
                    placeholder="Reference, client, email, number..."
                    class="w-full rounded-xl border
                    border-slate-200 bg-white px-4 py-3
                    text-sm outline-none transition
                    focus:border-servora-500
                    focus:ring-4 focus:ring-servora-100"
                >

            </div>

            <!-- STATUS -->

            <div>

                <label
                    for="status"
                    class="mb-1.5 block text-xs
                    font-bold text-slate-600"
                >
                    Status
                </label>

                <select
                    id="status"
                    name="status"
                    class="w-full rounded-xl border
                    border-slate-200 bg-white px-4 py-3
                    text-sm outline-none transition
                    focus:border-servora-500
                    focus:ring-4 focus:ring-servora-100"
                >

                    <option value="">
                        All Statuses
                    </option>

                    <?php foreach ($allowedStatuses as $statusOption): ?>

                        <?php
                        $config =
                            statusConfig($statusOption);
                        ?>

                        <option
                            value="<?= e($statusOption) ?>"
                            <?= $status === $statusOption
                                ? "selected"
                                : "" ?>
                        >
                            <?= e($config["label"]) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <!-- SERVICE -->

            <div>

                <label
                    for="service"
                    class="mb-1.5 block text-xs
                    font-bold text-slate-600"
                >
                    Service
                </label>

                <select
                    id="service"
                    name="service"
                    class="w-full rounded-xl border
                    border-slate-200 bg-white px-4 py-3
                    text-sm outline-none transition
                    focus:border-servora-500
                    focus:ring-4 focus:ring-servora-100"
                >

                    <option value="">
                        All Services
                    </option>

                    <?php foreach ($serviceOptions as $serviceOption): ?>

                        <?php
                        $serviceCode = strtolower(
                            trim(
                                (string)
                                $serviceOption["service_code"]
                            )
                        );
                        ?>

                        <option
                            value="<?= e($serviceCode) ?>"
                            <?= $service === $serviceCode
                                ? "selected"
                                : "" ?>
                        >
                            <?= e(
                                $serviceOption["service_name"]
                            ) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <!-- BUTTONS -->

            <div
                class="flex flex-wrap gap-2
                md:col-span-4"
            >

                <button
                    type="submit"
                    class="rounded-xl bg-servora-700
                    px-5 py-3 text-sm font-bold
                    text-white transition
                    hover:bg-servora-800"
                >
                    Apply Filters
                </button>

                <a
                    href="foreign_number_orders.php"
                    class="rounded-xl border
                    border-slate-200 bg-white
                    px-5 py-3 text-sm font-bold
                    text-slate-600 transition
                    hover:bg-slate-50"
                >
                    Reset
                </a>

            </div>

        </form>

    </section>

    <!-- =====================================================
         ORDERS
    ====================================================== -->

    <section class="mt-6">

        <div
            class="mb-4 flex flex-wrap
            items-end justify-between gap-3"
        >

            <div>

                <p
                    class="text-xs font-bold uppercase
                    tracking-widest text-servora-600"
                >
                    Orders
                </p>

                <h2
                    class="mt-1 text-xl font-black
                    text-slate-900 sm:text-2xl"
                >
                    Foreign Number Orders
                </h2>

            </div>

            <p
                class="text-sm font-semibold
                text-slate-500"
            >
                <?= count($orders) ?>
                result<?= count($orders) === 1 ? "" : "s" ?>
            </p>

        </div>

        <?php if (empty($orders)): ?>

            <!-- EMPTY STATE -->

            <div
                class="rounded-3xl border
                border-dashed border-slate-300
                bg-white px-6 py-16 text-center"
            >

                <div
                    class="mx-auto flex h-14 w-14
                    items-center justify-center
                    rounded-2xl bg-servora-50
                    text-servora-700"
                >

                    <svg
                        class="h-7 w-7"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="1.8"
                            d="M12 8v4l3 2m6-2
                            a9 9 0 11-18 0
                            9 9 0 0118 0z"
                        />
                    </svg>

                </div>

                <h3
                    class="mt-4 text-lg font-black
                    text-slate-900"
                >
                    No orders found
                </h3>

                <p
                    class="mx-auto mt-2 max-w-md
                    text-sm leading-6 text-slate-500"
                >
                    There are currently no foreign number
                    orders matching your filters.
                </p>

            </div>

        <?php else: ?>

            <!-- DESKTOP TABLE -->

            <div
                class="hidden overflow-hidden
                rounded-2xl border border-slate-200
                bg-white shadow-sm lg:block"
            >

                <div class="overflow-x-auto">

                    <table
                        class="min-w-full divide-y
                        divide-slate-200"
                    >

                        <thead class="bg-slate-50">

                        <tr>

                            <th
                                class="px-5 py-4 text-left
                                text-xs font-bold uppercase
                                tracking-wide text-slate-500"
                            >
                                Reference / Client
                            </th>

                            <th
                                class="px-5 py-4 text-left
                                text-xs font-bold uppercase
                                tracking-wide text-slate-500"
                            >
                                Service
                            </th>

                            <th
                                class="px-5 py-4 text-left
                                text-xs font-bold uppercase
                                tracking-wide text-slate-500"
                            >
                                Number
                            </th>

                            <th
                                class="px-5 py-4 text-left
                                text-xs font-bold uppercase
                                tracking-wide text-slate-500"
                            >
                                Price / Profit
                            </th>

                            <th
                                class="px-5 py-4 text-left
                                text-xs font-bold uppercase
                                tracking-wide text-slate-500"
                            >
                                Status
                            </th>

                            <th
                                class="px-5 py-4 text-left
                                text-xs font-bold uppercase
                                tracking-wide text-slate-500"
                            >
                                Date
                            </th>

                            <th
                                class="px-5 py-4 text-right
                                text-xs font-bold uppercase
                                tracking-wide text-slate-500"
                            >
                                Action
                            </th>

                        </tr>

                        </thead>

                        <tbody
                            class="divide-y divide-slate-100"
                        >

                        <?php foreach ($orders as $order): ?>

                            <?php
                            $orderStatus = strtolower(
                                (string) $order["status"]
                            );

                            $statusInfo =
                                statusConfig($orderStatus);

                            $profit =
                                (float) $order["selling_price"]
                                -
                                (float) $order["provider_cost"];
                            ?>

                            <tr
                                class="transition
                                hover:bg-slate-50"
                            >

                                <!-- REFERENCE -->

                                <td class="px-5 py-4">

                                    <p
                                        class="text-sm font-bold
                                        text-slate-900"
                                    >
                                        <?= e(
                                            $order["order_reference"]
                                        ) ?>
                                    </p>

                                    <p
                                        class="mt-1 text-xs
                                        font-semibold text-slate-600"
                                    >
                                        <?= e(
                                            $order["client_name"]
                                        ) ?>
                                    </p>

                                    <p
                                        class="mt-0.5 text-xs
                                        text-slate-400"
                                    >
                                        <?= e(
                                            $order["client_email"]
                                        ) ?>
                                    </p>

                                </td>

                                <!-- SERVICE -->

                                <td class="px-5 py-4">

                                    <div class="flex items-center gap-2.5">
                                        <?= BrandAssetHelper::renderServiceLogo($order["service_code"] ?? '', 'sm', true) ?>

                                        <div>
                                            <p
                                                class="text-sm font-bold
                                                text-slate-900"
                                            >
                                                <?= e(
                                                    $order["service_name"]
                                                ) ?>
                                            </p>

                                            <div
                                                class="mt-1 flex items-center gap-1.5 text-xs
                                                text-slate-500"
                                            >
                                                <?= BrandAssetHelper::renderCountryFlag($order["country_code"] ?? '', $order["country_name"] ?? '', 'xs') ?>

                                                <span><?= e(
                                                    $order["country_name"]
                                                ) ?></span>

                                                <?php if (!empty($order["country_code"])): ?>
                                                    <span class="text-slate-400 font-semibold">(<?= e(strtoupper($order["country_code"])) ?>)</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                </td>

                                <!-- NUMBER -->

                                <td class="px-5 py-4">

                                    <p
                                        class="text-sm font-bold
                                        text-slate-900"
                                    >
                                        <?= !empty(
                                            $order[
                                                "provider_phone_number"
                                            ]
                                        )
                                            ? e(
                                                $order[
                                                    "provider_phone_number"
                                                ]
                                            )
                                            : "—" ?>
                                    </p>

                                    <p
                                        class="mt-1 text-xs
                                        text-slate-400"
                                    >
                                        <?= e(
                                            ucfirst(
                                                $order["provider"]
                                            )
                                        ) ?>
                                    </p>

                                </td>

                                <!-- PRICE -->

                                <td class="px-5 py-4">

                                    <p
                                        class="text-sm font-black
                                        text-slate-900"
                                    >
                                        <?= money(
                                            $order["selling_price"]
                                        ) ?>
                                    </p>

                                    <p
                                        class="mt-1 text-xs
                                        font-semibold text-servora-700"
                                    >
                                        Profit:
                                        <?= money($profit) ?>
                                    </p>

                                </td>

                                <!-- STATUS -->

                                <td class="px-5 py-4">

                                    <span
                                        class="inline-flex rounded-full
                                        px-2.5 py-1 text-xs font-bold
                                        ring-1 ring-inset
                                        <?= e(
                                            $statusInfo["class"]
                                        ) ?>"
                                    >
                                        <?= e(
                                            $statusInfo["label"]
                                        ) ?>
                                    </span>

                                </td>

                                <!-- DATE -->

                                <td class="px-5 py-4">

                                    <p
                                        class="whitespace-nowrap
                                        text-xs font-medium
                                        text-slate-600"
                                    >
                                        <?= e(
                                            formatDate(
                                                $order["created_at"]
                                            )
                                        ) ?>
                                    </p>

                                </td>

                                <!-- ACTION -->

                                <td
                                    class="px-5 py-4 text-right"
                                >

                                    <a
                                        href="foreign_number_view.php?id=<?= (int) $order["id"] ?>"
                                        class="inline-flex rounded-xl
                                        bg-servora-50 px-3.5 py-2
                                        text-xs font-bold
                                        text-servora-700 transition
                                        hover:bg-servora-100"
                                    >
                                        View
                                    </a>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            </div>

            <!-- MOBILE / TABLET CARDS -->

            <div class="grid gap-3 lg:hidden">

                <?php foreach ($orders as $order): ?>

                    <?php
                    $orderStatus = strtolower(
                        (string) $order["status"]
                    );

                    $statusInfo =
                        statusConfig($orderStatus);

                    $profit =
                        (float) $order["selling_price"]
                        -
                        (float) $order["provider_cost"];
                    ?>

                    <article
                        class="rounded-2xl border
                        border-slate-200 bg-white
                        p-4 shadow-sm"
                    >

                        <div
                            class="flex items-start
                            justify-between gap-3"
                        >

                            <div class="flex items-center gap-2.5 min-w-0">
                                <?= BrandAssetHelper::renderServiceLogo($order["service_code"] ?? '', 'sm', true) ?>

                                <div class="min-w-0">
                                    <p
                                        class="truncate text-sm
                                        font-black text-slate-900"
                                    >
                                        <?= e(
                                            $order["service_name"]
                                        ) ?>
                                    </p>

                                    <p
                                        class="mt-0.5 truncate
                                        text-xs text-slate-500"
                                    >
                                        <?= e(
                                            $order["order_reference"]
                                        ) ?>
                                    </p>
                                </div>
                            </div>

                            <span
                                class="shrink-0 rounded-full
                                px-2.5 py-1 text-[11px]
                                font-bold ring-1 ring-inset
                                <?= e(
                                    $statusInfo["class"]
                                ) ?>"
                            >
                                <?= e(
                                    $statusInfo["label"]
                                ) ?>
                            </span>

                        </div>

                        <div
                            class="mt-4 grid
                            grid-cols-2 gap-3"
                        >

                            <div>

                                <p
                                    class="text-[10px]
                                    font-bold uppercase
                                    tracking-wide text-slate-400"
                                >
                                    Client
                                </p>

                                <p
                                    class="mt-1 truncate
                                    text-sm font-semibold
                                    text-slate-700"
                                >
                                    <?= e(
                                        $order["client_name"]
                                    ) ?>
                                </p>

                            </div>

                            <div>

                                <p
                                    class="text-[10px]
                                    font-bold uppercase
                                    tracking-wide text-slate-400"
                                >
                                    Country
                                </p>

                                <div
                                    class="mt-1 flex items-center gap-1.5 text-sm
                                    font-semibold text-slate-700"
                                >
                                    <?= BrandAssetHelper::renderCountryFlag($order["country_code"] ?? '', $order["country_name"] ?? '', 'xs') ?>
                                    <span><?= e(
                                        $order["country_name"]
                                    ) ?></span>
                                </div>

                            </div>

                            <div>

                                <p
                                    class="text-[10px]
                                    font-bold uppercase
                                    tracking-wide text-slate-400"
                                >
                                    Number
                                </p>

                                <p
                                    class="mt-1 text-sm
                                    font-semibold text-slate-700"
                                >
                                    <?= !empty(
                                        $order[
                                            "provider_phone_number"
                                        ]
                                    )
                                        ? e(
                                            $order[
                                                "provider_phone_number"
                                            ]
                                        )
                                        : "—" ?>
                                </p>

                            </div>

                            <div>

                                <p
                                    class="text-[10px]
                                    font-bold uppercase
                                    tracking-wide text-slate-400"
                                >
                                    Selling Price
                                </p>

                                <p
                                    class="mt-1 text-sm
                                    font-black text-slate-900"
                                >
                                    <?= money(
                                        $order["selling_price"]
                                    ) ?>
                                </p>

                            </div>

                        </div>

                        <div
                            class="mt-4 flex items-center
                            justify-between border-t
                            border-slate-100 pt-4"
                        >

                            <div>

                                <p
                                    class="text-xs
                                    text-slate-400"
                                >
                                    Profit
                                </p>

                                <p
                                    class="text-sm font-bold
                                    text-servora-700"
                                >
                                    <?= money($profit) ?>
                                </p>

                            </div>

                            <a
                                href="foreign_number_view.php?id=<?= (int) $order["id"] ?>"
                                class="rounded-xl
                                bg-servora-700 px-4 py-2.5
                                text-xs font-bold text-white
                                transition
                                hover:bg-servora-800"
                            >
                                View Order
                            </a>

                        </div>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </section>

    <!-- =====================================================
         FOOTER
    ====================================================== -->

    <footer
        class="py-8 text-center
        text-xs text-slate-400"
    >
        Servora Administration
        · <?= e($adminName) ?>
    </footer>

</main>

</body>
</html>