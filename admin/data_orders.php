<?php

require_once "../config/database.php";
require_once "../includes/admin_auth.php";

$adminName = $_SESSION["full_name"] ?? "Admin";


/*
|--------------------------------------------------------------------------
| HELPER
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


/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/

$status = trim($_GET["status"] ?? "");
$provider = trim($_GET["provider"] ?? "");
$search = trim($_GET["search"] ?? "");

$allowedStatuses = [
    "pending",
    "processing",
    "successful",
    "failed",
    "refunded"
];

if (
    $status !== "" &&
    !in_array(
        $status,
        $allowedStatuses,
        true
    )
) {
    $status = "";
}


/*
|--------------------------------------------------------------------------
| BUILD QUERY
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        do.id,
        do.order_reference,
        do.phone_number,
        do.cost_price,
        do.selling_price,
        do.profit,
        do.provider_reference,
        do.provider_message,
        do.status,
        do.created_at,
        do.completed_at,
        do.wallet_transaction_id,
        do.refund_transaction_id,

        u.full_name,
        u.email,

        dp.name AS plan_name,
        dp.data_amount,
        dp.validity,

        n.name AS network_name,

        COALESCE(do.provider_name, ap.name) AS provider_name,
        ap.slug AS provider_slug,
        dp.provider_id,

        dpt.status AS provider_status,
        dpt.http_status,
        dpt.response_message AS provider_response

    FROM data_orders do

    INNER JOIN users u
        ON u.id = do.user_id

    INNER JOIN data_plans dp
        ON dp.id = do.plan_id

    INNER JOIN networks n
        ON n.id = dp.network_id

    LEFT JOIN api_providers ap
        ON ap.id = dp.provider_id

    LEFT JOIN data_provider_transactions dpt
        ON dpt.id = (
            SELECT dpt2.id
            FROM data_provider_transactions dpt2
            WHERE dpt2.order_id = do.id
              AND dpt2.action = 'purchase'
            ORDER BY dpt2.id DESC
            LIMIT 1
        )

    WHERE 1 = 1
";

$params = [];


/*
|--------------------------------------------------------------------------
| STATUS FILTER
|--------------------------------------------------------------------------
*/

if ($status !== "") {
    $sql .= "
        AND do.status = ?
    ";
    $params[] = $status;
}

if ($provider === "vtpass") {
    $sql .= " AND (do.provider_name LIKE '%vtpass%' OR ap.slug = 'vtpass' OR dp.provider_id = 8)";
} elseif ($provider === "cheapdatahub") {
    $sql .= " AND (do.provider_name LIKE '%cheapdatahub%' OR ap.slug = 'cheapdatahub' OR dp.provider_id = 1)";
}


/*
|--------------------------------------------------------------------------
| SEARCH FILTER
|--------------------------------------------------------------------------
*/

if ($search !== "") {

    $sql .= "
        AND (
            do.order_reference LIKE ?
            OR do.phone_number LIKE ?
            OR u.full_name LIKE ?
            OR u.email LIKE ?
            OR dp.name LIKE ?
            OR n.name LIKE ?
        )
    ";

    $searchValue =
        "%" . $search . "%";

    for ($i = 0; $i < 6; $i++) {
        $params[] = $searchValue;
    }
}


$sql .= "
    ORDER BY do.id DESC
    LIMIT 200
";


$stmt = $pdo->prepare($sql);

$stmt->execute($params);

$orders = $stmt->fetchAll(
    PDO::FETCH_ASSOC
);


/*
|--------------------------------------------------------------------------
| DASHBOARD COUNTS
|--------------------------------------------------------------------------
*/

$countStmt = $pdo->query("
    SELECT
        COUNT(*) AS total,

        SUM(
            CASE
                WHEN status = 'pending'
                THEN 1
                ELSE 0
            END
        ) AS pending,

        SUM(
            CASE
                WHEN status = 'processing'
                THEN 1
                ELSE 0
            END
        ) AS processing,

        SUM(
            CASE
                WHEN status = 'successful'
                THEN 1
                ELSE 0
            END
        ) AS successful,

        SUM(
            CASE
                WHEN status = 'failed'
                THEN 1
                ELSE 0
            END
        ) AS failed,

        SUM(
            CASE
                WHEN status = 'refunded'
                THEN 1
                ELSE 0
            END
        ) AS refunded

    FROM data_orders
");

$counts = $countStmt->fetch(
    PDO::FETCH_ASSOC
);


/*
|--------------------------------------------------------------------------
| MONEY FORMATTER
|--------------------------------------------------------------------------
*/

function formatMoney($amount): string
{
    return "₦" .
        number_format(
            (float) $amount,
            2
        );
}


/*
|--------------------------------------------------------------------------
| STATUS CLASS
|--------------------------------------------------------------------------
*/

function orderStatusClass(
    string $status
): string {

    return match ($status) {

        "successful" =>
            "bg-emerald-100 text-emerald-700",

        "processing" =>
            "bg-blue-100 text-blue-700",

        "failed" =>
            "bg-red-100 text-red-700",

        "refunded" =>
            "bg-purple-100 text-purple-700",

        default =>
            "bg-amber-100 text-amber-700"
    };
}


/*
|--------------------------------------------------------------------------
| PROVIDER STATUS CLASS
|--------------------------------------------------------------------------
*/

function providerStatusClass(
    ?string $status
): string {

    return match ($status) {

        "successful" =>
            "bg-emerald-100 text-emerald-700",

        "failed" =>
            "bg-red-100 text-red-700",

        "unknown" =>
            "bg-amber-100 text-amber-700",

        "pending" =>
            "bg-blue-100 text-blue-700",

        default =>
            "bg-gray-100 text-gray-600"
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
        Data Orders | Subnext Admin
    </title>

    <!-- Precompiled Production Stylesheet -->
    <link rel="stylesheet" href="../assets/css/style.css">

</head>

<body class="bg-gray-50 text-gray-900">

<div class="min-h-screen">


    <!-- HEADER -->

    <header
        class="
            bg-white
            border-b
            border-gray-200
        "
    >

        <div
            class="
                max-w-7xl
                mx-auto
                px-4
                sm:px-6
                lg:px-8
                py-4
                flex
                items-center
                justify-between
                gap-4
            "
        >

            <div>

                <a
                    href="data_control.php"
                    class="
                        text-sm
                        text-servora-600
                        hover:underline
                    "
                >
                    ← Data Control
                </a>

                <h1
                    class="
                        text-2xl
                        font-bold
                        mt-1
                    "
                >
                    Data Orders
                </h1>

                <p
                    class="
                        text-sm
                        text-gray-500
                    "
                >
                    Monitor Subnext data purchases and supplier responses.
                </p>

            </div>


            <div
                class="
                    hidden
                    sm:block
                    text-right
                "
            >

                <p
                    class="
                        text-sm
                        text-gray-500
                    "
                >
                    Signed in as
                </p>

                <p class="font-semibold">
                    <?= e($adminName) ?>
                </p>

            </div>

        </div>

    </header>


    <main
        class="
            max-w-7xl
            mx-auto
            px-4
            sm:px-6
            lg:px-8
            py-8
        "
    >


        <!-- SUMMARY CARDS -->

        <div
            class="
                grid
                grid-cols-2
                md:grid-cols-3
                lg:grid-cols-6
                gap-4
                mb-8
            "
        >

            <div class="bg-white rounded-xl border p-4">

                <p class="text-sm text-gray-500">
                    Total
                </p>

                <p class="text-2xl font-bold">
                    <?= (int) ($counts["total"] ?? 0) ?>
                </p>

            </div>


            <div class="bg-white rounded-xl border p-4">

                <p class="text-sm text-gray-500">
                    Pending
                </p>

                <p
                    class="
                        text-2xl
                        font-bold
                        text-amber-600
                    "
                >
                    <?= (int) ($counts["pending"] ?? 0) ?>
                </p>

            </div>


            <div class="bg-white rounded-xl border p-4">

                <p class="text-sm text-gray-500">
                    Processing
                </p>

                <p
                    class="
                        text-2xl
                        font-bold
                        text-blue-600
                    "
                >
                    <?= (int) ($counts["processing"] ?? 0) ?>
                </p>

            </div>


            <div class="bg-white rounded-xl border p-4">

                <p class="text-sm text-gray-500">
                    Successful
                </p>

                <p
                    class="
                        text-2xl
                        font-bold
                        text-emerald-600
                    "
                >
                    <?= (int) ($counts["successful"] ?? 0) ?>
                </p>

            </div>


            <div class="bg-white rounded-xl border p-4">

                <p class="text-sm text-gray-500">
                    Failed
                </p>

                <p
                    class="
                        text-2xl
                        font-bold
                        text-red-600
                    "
                >
                    <?= (int) ($counts["failed"] ?? 0) ?>
                </p>

            </div>


            <div class="bg-white rounded-xl border p-4">

                <p class="text-sm text-gray-500">
                    Refunded
                </p>

                <p
                    class="
                        text-2xl
                        font-bold
                        text-purple-600
                    "
                >
                    <?= (int) ($counts["refunded"] ?? 0) ?>
                </p>

            </div>

        </div>


        <!-- SAFETY NOTICE -->

        <div
            class="
                mb-6
                rounded-xl
                border
                border-amber-200
                bg-amber-50
                px-4
                py-3
            "
        >

            <p
                class="
                    text-sm
                    font-semibold
                    text-amber-800
                "
            >
                Processing or unknown supplier transactions should be
                investigated before any retry or refund is attempted.
            </p>

        </div>


        <!-- FILTERS -->

        <div
            class="
                bg-white
                border
                rounded-xl
                p-4
                mb-6
            "
        >

            <form
                method="GET"
                class="
                    grid
                    md:grid-cols-4
                    gap-3
                "
            >

                <div class="md:col-span-2">

                    <input
                        type="text"
                        name="search"
                        value="<?= e($search) ?>"
                        placeholder="Search order, client, phone, plan..."
                        class="
                            w-full
                            border
                            border-gray-300
                            rounded-lg
                            px-4
                            py-2.5
                            focus:outline-none
                            focus:ring-2
                            focus:ring-servora-500
                        "
                    >

                </div>


                <div>
                    <select
                        name="status"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2.5 bg-white text-sm"
                    >
                        <option value="">All statuses</option>
                        <?php foreach ($allowedStatuses as $option): ?>
                            <option value="<?= e($option) ?>" <?= $status === $option ? "selected" : "" ?>>
                                <?= e(ucfirst($option)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <select
                        name="provider"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2.5 bg-white text-sm"
                    >
                        <option value="">All Providers</option>
                        <option value="cheapdatahub" <?= $provider === "cheapdatahub" ? "selected" : "" ?>>CheapDataHub</option>
                        <option value="vtpass" <?= $provider === "vtpass" ? "selected" : "" ?>>VTpass</option>
                    </select>
                </div>


                <div class="flex gap-2">

                    <button
                        type="submit"
                        class="
                            flex-1
                            bg-servora-600
                            text-white
                            rounded-lg
                            px-4
                            py-2.5
                            font-semibold
                            hover:bg-servora-700
                        "
                    >
                        Filter
                    </button>

                    <a
                        href="data_orders.php"
                        class="
                            border
                            border-gray-300
                            rounded-lg
                            px-4
                            py-2.5
                            hover:bg-gray-50
                        "
                    >
                        Reset
                    </a>

                </div>

            </form>

        </div>


        <!-- ORDERS -->

        <div
            class="
                bg-white
                border
                rounded-xl
                overflow-hidden
            "
        >

            <?php if (!$orders): ?>

                <div class="p-10 text-center">

                    <p class="text-lg font-semibold">
                        No data orders found
                    </p>

                    <p
                        class="
                            text-sm
                            text-gray-500
                            mt-1
                        "
                    >
                        Orders will appear here when clients purchase data.
                    </p>

                </div>

            <?php else: ?>


                <div class="overflow-x-auto">

                    <table
                        class="
                            min-w-full
                            text-sm
                        "
                    >

                        <thead
                            class="
                                bg-gray-50
                                border-b
                            "
                        >

                        <tr>

                            <th
                                class="
                                    text-left
                                    px-5
                                    py-3
                                    font-semibold
                                "
                            >
                                Order
                            </th>

                            <th
                                class="
                                    text-left
                                    px-5
                                    py-3
                                    font-semibold
                                "
                            >
                                Client
                            </th>

                            <th
                                class="
                                    text-left
                                    px-5
                                    py-3
                                    font-semibold
                                "
                            >
                                Data
                            </th>

                            <th
                                class="
                                    text-left
                                    px-5
                                    py-3
                                    font-semibold
                                "
                            >
                                Amount
                            </th>

                            <th
                                class="
                                    text-left
                                    px-5
                                    py-3
                                    font-semibold
                                "
                            >
                                Order Status
                            </th>

                            <th
                                class="
                                    text-left
                                    px-5
                                    py-3
                                    font-semibold
                                "
                            >
                                Provider
                            </th>

                            <th
                                class="
                                    text-left
                                    px-5
                                    py-3
                                    font-semibold
                                "
                            >
                                Date
                            </th>

                            <th
                                class="
                                    text-left
                                    px-5
                                    py-3
                                    font-semibold
                                "
                            >
                                Action
                            </th>

                        </tr>

                        </thead>


                        <tbody
                            class="
                                divide-y
                                divide-gray-100
                            "
                        >

                        <?php foreach ($orders as $order): ?>

                            <tr
                                class="
                                    hover:bg-gray-50
                                    align-top
                                "
                            >


                                <!-- ORDER -->

                                <td
                                    class="
                                        px-5
                                        py-4
                                        min-w-[220px]
                                    "
                                >

                                    <p
                                        class="
                                            font-semibold
                                            text-gray-900
                                        "
                                    >
                                        <?= e(
                                            $order[
                                                "order_reference"
                                            ]
                                        ) ?>
                                    </p>

                                    <p
                                        class="
                                            text-gray-500
                                            mt-1
                                        "
                                    >
                                        <?= e(
                                            $order[
                                                "phone_number"
                                            ]
                                        ) ?>
                                    </p>

                                    <?php
                                    if (
                                        !empty(
                                            $order[
                                                "provider_reference"
                                            ]
                                        )
                                    ):
                                    ?>

                                        <p
                                            class="
                                                text-xs
                                                text-gray-400
                                                mt-1
                                            "
                                        >
                                            Provider Ref:
                                            <?= e(
                                                $order[
                                                    "provider_reference"
                                                ]
                                            ) ?>
                                        </p>

                                    <?php endif; ?>

                                </td>


                                <!-- CLIENT -->

                                <td
                                    class="
                                        px-5
                                        py-4
                                        min-w-[180px]
                                    "
                                >

                                    <p class="font-medium">

                                        <?= e(
                                            $order[
                                                "full_name"
                                            ]
                                        ) ?>

                                    </p>

                                    <p
                                        class="
                                            text-xs
                                            text-gray-500
                                            mt-1
                                        "
                                    >

                                        <?= e(
                                            $order[
                                                "email"
                                            ]
                                        ) ?>

                                    </p>

                                </td>


                                <!-- DATA -->

                                <td
                                    class="
                                        px-5
                                        py-4
                                        min-w-[190px]
                                    "
                                >

                                    <p class="font-medium">

                                        <?= e(
                                            $order[
                                                "network_name"
                                            ]
                                        ) ?>

                                        —

                                        <?= e(
                                            $order[
                                                "data_amount"
                                            ]
                                        ) ?>

                                    </p>


                                    <p
                                        class="
                                            text-xs
                                            text-gray-500
                                            mt-1
                                        "
                                    >

                                        <?= e(
                                            $order[
                                                "plan_name"
                                            ]
                                        ) ?>

                                    </p>


                                    <?php
                                    if (
                                        !empty(
                                            $order[
                                                "validity"
                                            ]
                                        )
                                    ):
                                    ?>

                                        <p
                                            class="
                                                text-xs
                                                text-gray-400
                                                mt-1
                                            "
                                        >

                                            <?= e(
                                                $order[
                                                    "validity"
                                                ]
                                            ) ?>

                                        </p>

                                    <?php endif; ?>

                                </td>


                                <!-- MONEY -->

                                <td
                                    class="
                                        px-5
                                        py-4
                                        min-w-[140px]
                                    "
                                >

                                    <p class="font-semibold">
                                        <?= formatMoney(
                                            $order[
                                                "selling_price"
                                            ]
                                        ) ?>
                                    </p>

                                    <p
                                        class="
                                            text-xs
                                            text-gray-500
                                            mt-1
                                        "
                                    >
                                        Cost:
                                        <?= formatMoney(
                                            $order[
                                                "cost_price"
                                            ]
                                        ) ?>
                                    </p>

                                    <p
                                        class="
                                            text-xs
                                            text-emerald-600
                                            mt-1
                                        "
                                    >
                                        Profit:
                                        <?= formatMoney(
                                            $order[
                                                "profit"
                                            ]
                                        ) ?>
                                    </p>

                                </td>


                                <!-- ORDER STATUS -->

                                <td
                                    class="
                                        px-5
                                        py-4
                                        min-w-[160px]
                                    "
                                >

                                    <span
                                        class="
                                            inline-flex
                                            px-2.5
                                            py-1
                                            rounded-full
                                            text-xs
                                            font-semibold
                                            <?= orderStatusClass(
                                                $order[
                                                    "status"
                                                ]
                                            ) ?>
                                        "
                                    >

                                        <?= e(
                                            ucfirst(
                                                $order[
                                                    "status"
                                                ]
                                            )
                                        ) ?>

                                    </span>


                                    <?php
                                    if (
                                        $order["status"]
                                        === "processing"
                                    ):
                                    ?>

                                        <p
                                            class="
                                                text-xs
                                                text-amber-600
                                                mt-2
                                                max-w-[180px]
                                            "
                                        >
                                            Check supplier status before taking action.
                                        </p>

                                    <?php endif; ?>

                                </td>


                                <!-- PROVIDER -->

                                <td
                                    class="
                                        px-5
                                        py-4
                                        min-w-[220px]
                                    "
                                >

                                    <?php
                                    $provName = trim((string)($order["provider_name"] ?? ""));
                                    $provSlug = strtolower(trim((string)($order["provider_slug"] ?? "")));
                                    $isVtpass = ($provSlug === "vtpass" || stripos($provName, "vtpass") !== false || (int)($order["provider_id"] ?? 0) === 8);
                                    $isCdh = ($provSlug === "cheapdatahub" || stripos($provName, "cheapdatahub") !== false || (int)($order["provider_id"] ?? 0) === 1);
                                    ?>

                                    <?php if ($isVtpass): ?>
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-purple-50 px-2.5 py-1 text-xs font-bold text-purple-700 border border-purple-200">
                                            <span class="h-1.5 w-1.5 rounded-full bg-purple-600"></span>
                                            VTpass
                                        </span>
                                    <?php elseif ($isCdh): ?>
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-50 px-2.5 py-1 text-xs font-bold text-blue-700 border border-blue-200">
                                            <span class="h-1.5 w-1.5 rounded-full bg-blue-600"></span>
                                            CheapDataHub
                                        </span>
                                    <?php else: ?>
                                        <p class="font-medium text-slate-700">
                                            <?= e($provName ?: "Not configured") ?>
                                        </p>
                                    <?php endif; ?>


                                    <span
                                        class="
                                            inline-flex
                                            mt-2
                                            px-2
                                            py-1
                                            rounded-full
                                            text-xs
                                            font-medium
                                            <?= providerStatusClass(
                                                $order[
                                                    "provider_status"
                                                ]
                                            ) ?>
                                        "
                                    >

                                        <?=
                                            $order[
                                                "provider_status"
                                            ]
                                                ? e(
                                                    ucfirst(
                                                        $order[
                                                            "provider_status"
                                                        ]
                                                    )
                                                )
                                                : "No attempt"
                                        ?>

                                    </span>


                                    <?php

                                    $providerText =
                                        $order[
                                            "provider_response"
                                        ]
                                        ??
                                        $order[
                                            "provider_message"
                                        ];

                                    if (
                                        !empty(
                                            $providerText
                                        )
                                    ):
                                    ?>

                                        <p
                                            title="<?= e(
                                                $providerText
                                            ) ?>"
                                            class="
                                                text-xs
                                                text-gray-500
                                                mt-2
                                                max-w-[220px]
                                                truncate
                                            "
                                        >

                                            <?= e($providerText) ?>

                                        </p>

                                    <?php endif; ?>


                                    <?php
                                    if (
                                        !empty(
                                            $order[
                                                "http_status"
                                            ]
                                        )
                                    ):
                                    ?>

                                        <p
                                            class="
                                                text-xs
                                                text-gray-400
                                                mt-1
                                            "
                                        >
                                            HTTP:
                                            <?= (int)
                                                $order[
                                                    "http_status"
                                                ]
                                            ?>
                                        </p>

                                    <?php endif; ?>

                                </td>


                                <!-- DATE -->

                                <td
                                    class="
                                        px-5
                                        py-4
                                        min-w-[150px]
                                        text-gray-500
                                    "
                                >

                                    <?= e(
                                        date(
                                            "d M Y",
                                            strtotime(
                                                $order[
                                                    "created_at"
                                                ]
                                            )
                                        )
                                    ) ?>

                                    <br>

                                    <span class="text-xs">

                                        <?= e(
                                            date(
                                                "h:i A",
                                                strtotime(
                                                    $order[
                                                        "created_at"
                                                    ]
                                                )
                                            )
                                        ) ?>

                                    </span>

                                </td>


                                <!-- ACTION -->

                                <td
                                    class="
                                        px-5
                                        py-4
                                        min-w-[150px]
                                    "
                                >

                                    <a
                                        href="data_order_view.php?id=<?= (int) $order["id"] ?>"
                                        class="
                                            inline-flex
                                            items-center
                                            justify-center
                                            whitespace-nowrap
                                            rounded-lg
                                            bg-servora-600
                                            px-4
                                            py-2.5
                                            text-xs
                                            font-semibold
                                            text-white
                                            shadow-sm
                                            transition
                                            hover:bg-servora-700
                                            focus:outline-none
                                            focus:ring-2
                                            focus:ring-servora-500
                                            focus:ring-offset-2
                                        "
                                    >
                                        View Details
                                    </a>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        </div>


        <div
            class="
                mt-4
                flex
                flex-col
                gap-3
                sm:flex-row
                sm:items-center
                sm:justify-between
            "
        >

            <p class="text-xs text-gray-400">
                Showing up to the latest 200 data orders.
            </p>

            <a
                href="provider_transactions.php"
                class="
                    text-sm
                    font-semibold
                    text-servora-600
                    hover:underline
                "
            >
                View Provider Transactions →
            </a>

        </div>

    </main>

</div>

</body>

</html>