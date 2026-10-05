<?php

require_once "../includes/admin_auth.php";
require_once "../config/database.php";

// --------------------------------------------------
// GET ALL REFUND TRANSACTIONS
// --------------------------------------------------

$stmt = $pdo->query("
    SELECT
        wt.id,
        wt.user_id,
        wt.amount,
        wt.balance_before,
        wt.balance_after,
        wt.reference,
        wt.description,
        wt.status,
        wt.created_at,

        u.full_name,
        u.email,

        sr.id AS request_id,
        sr.request_code,

        s.name AS service_name

    FROM wallet_transactions wt

    INNER JOIN users u
        ON u.id = wt.user_id

    LEFT JOIN service_requests sr
        ON wt.description LIKE CONCAT(
            '%',
            sr.request_code,
            '%'
        )

    LEFT JOIN services s
        ON s.id = sr.service_id

    WHERE wt.type = 'refund'

    ORDER BY wt.created_at DESC
");

$refunds = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Refund History | Subnext</title>

    <!-- Precompiled Production Stylesheet -->
    <link rel="stylesheet" href="../assets/css/style.css">

</head>


<body class="min-h-screen bg-slate-50 text-slate-800">

<div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8">

    <!-- HEADER -->

    <div class="mb-6">

        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">

            <div>

                <div class="mb-2 inline-flex items-center gap-2 rounded-full border border-servora-200 bg-servora-50 px-3 py-1 text-xs font-semibold text-servora-700">

                    <span class="h-2 w-2 rounded-full bg-servora-600"></span>

                    Financial Management

                </div>

                <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">
                    Refund History
                </h1>

                <p class="mt-1 text-sm text-slate-500">
                    View all refunds issued to clients.
                </p>

            </div>


            <a
                href="dashboard.php"
                class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-servora-700 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-servora-800 sm:w-auto"
            >

                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="h-4 w-4"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="2"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"
                    />
                </svg>

                Dashboard

            </a>

        </div>

    </div>


    <!-- SUMMARY -->

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">

        <!-- TOTAL REFUNDS -->

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">

            <div class="flex items-start justify-between">

                <div>

                    <p class="text-sm font-medium text-slate-500">
                        Total Refunds
                    </p>

                    <p class="mt-2 text-2xl font-bold text-slate-900">
                        <?= count($refunds) ?>
                    </p>

                </div>

                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-servora-50 text-servora-700">

                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-5 w-5"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="2"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M3 10h11a4 4 0 014 4v1m0 0l-3-3m3 3l3-3M21 14H10a4 4 0 00-4-4V9m0 0l3 3m-3-3L3 12"
                        />
                    </svg>

                </div>

            </div>

            <p class="mt-3 text-xs text-slate-400">
                Recorded refund transactions
            </p>

        </div>


        <!-- REFUNDED AMOUNT -->

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">

            <?php

            $totalRefundAmount = 0;

            foreach ($refunds as $refund) {

                if (($refund["status"] ?? "") === "successful") {
                    $totalRefundAmount += (float) $refund["amount"];
                }

            }

            ?>

            <div class="flex items-start justify-between">

                <div>

                    <p class="text-sm font-medium text-slate-500">
                        Refunded Amount
                    </p>

                    <p class="mt-2 text-2xl font-bold text-emerald-600">
                        ₦<?= number_format($totalRefundAmount, 2) ?>
                    </p>

                </div>

                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">

                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-5 w-5"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="2"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M12 8c-2.21 0-4 1.12-4 2.5S9.79 13 12 13s4 1.12 4 2.5S14.21 18 12 18m0-10V6m0 12v-2M19 12a7 7 0 11-14 0 7 7 0 0114 0z"
                        />

                    </svg>

                </div>

            </div>

            <p class="mt-3 text-xs text-slate-400">
                Successfully refunded to clients
            </p>

        </div>


        <!-- STATUS -->

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">

            <?php

            $successfulRefunds = 0;
            $pendingRefunds = 0;

            foreach ($refunds as $refund) {

                if (($refund["status"] ?? "") === "successful") {
                    $successfulRefunds++;
                }

                if (($refund["status"] ?? "") === "pending") {
                    $pendingRefunds++;
                }

            }

            ?>

            <div class="flex items-start justify-between">

                <div>

                    <p class="text-sm font-medium text-slate-500">
                        Refund Status
                    </p>

                    <p class="mt-2 text-2xl font-bold text-slate-900">
                        <?= $successfulRefunds ?>
                    </p>

                </div>

                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">

                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-5 w-5"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="2"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M5 13l4 4L19 7"
                        />
                    </svg>

                </div>

            </div>

            <p class="mt-3 text-xs text-slate-400">

                Successful refunds

                <?php if ($pendingRefunds > 0): ?>

                    · <?= $pendingRefunds ?> pending

                <?php endif; ?>

            </p>

        </div>

    </div>


    <!-- REFUND LIST -->

    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">

        <div class="border-b border-slate-200 px-5 py-4 sm:px-6">

            <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">

                <div>

                    <h2 class="font-semibold text-slate-900">
                        Refund Transactions
                    </h2>

                    <p class="text-xs text-slate-500">
                        <?= count($refunds) ?> refund<?= count($refunds) === 1 ? "" : "s" ?> recorded
                    </p>

                </div>

            </div>

        </div>


        <?php if (!empty($refunds)): ?>


            <!-- MOBILE -->

            <div class="divide-y divide-slate-100 lg:hidden">

                <?php foreach ($refunds as $refund): ?>

                    <?php

                    $status = strtolower($refund["status"] ?? "");

                    $statusClass = match ($status) {

                        "successful" =>
                            "bg-emerald-50 text-emerald-700 border-emerald-200",

                        "pending" =>
                            "bg-amber-50 text-amber-700 border-amber-200",

                        "failed", "reversed" =>
                            "bg-red-50 text-red-700 border-red-200",

                        default =>
                            "bg-slate-100 text-slate-600 border-slate-200"

                    };

                    ?>

                    <div class="p-5">

                        <!-- CLIENT -->

                        <div class="flex items-start justify-between gap-4">

                            <div class="min-w-0">

                                <div class="flex items-center gap-3">

                                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-servora-100 font-bold text-servora-700">

                                        <?= htmlspecialchars(
                                            strtoupper(
                                                substr(
                                                    $refund["full_name"] ?? "C",
                                                    0,
                                                    1
                                                )
                                            )
                                        ) ?>

                                    </div>

                                    <div class="min-w-0">

                                        <p class="truncate font-semibold text-slate-900">

                                            <?= htmlspecialchars(
                                                $refund["full_name"]
                                            ) ?>

                                        </p>

                                        <p class="truncate text-xs text-slate-500">

                                            <?= htmlspecialchars(
                                                $refund["email"]
                                            ) ?>

                                        </p>

                                    </div>

                                </div>

                            </div>


                            <span
                                class="shrink-0 rounded-full border px-2.5 py-1 text-xs font-semibold <?= $statusClass ?>"
                            >

                                <?= htmlspecialchars(
                                    ucfirst($status)
                                ) ?>

                            </span>

                        </div>


                        <!-- AMOUNT -->

                        <div class="mt-5 rounded-xl bg-emerald-50 p-4">

                            <p class="text-xs font-medium text-emerald-700">
                                Refunded Amount
                            </p>

                            <p class="mt-1 text-xl font-bold text-emerald-700">

                                ₦<?= number_format(
                                    (float) $refund["amount"],
                                    2
                                ) ?>

                            </p>

                        </div>


                        <!-- DETAILS -->

                        <div class="mt-4 grid grid-cols-2 gap-3">

                            <div class="rounded-xl bg-slate-50 p-3">

                                <p class="text-[11px] font-medium uppercase tracking-wide text-slate-400">
                                    Request
                                </p>

                                <?php if (!empty($refund["request_code"])): ?>

                                    <a
                                        href="request_details.php?id=<?= (int) ($refund["request_id"] ?? 0) ?>"
                                        class="mt-1 block truncate text-sm font-semibold text-servora-700 hover:underline"
                                    >

                                        <?= htmlspecialchars(
                                            $refund["request_code"]
                                        ) ?>

                                    </a>

                                <?php else: ?>

                                    <p class="mt-1 text-sm text-slate-500">
                                        —
                                    </p>

                                <?php endif; ?>

                            </div>


                            <div class="rounded-xl bg-slate-50 p-3">

                                <p class="text-[11px] font-medium uppercase tracking-wide text-slate-400">
                                    Service
                                </p>

                                <p class="mt-1 truncate text-sm font-semibold text-slate-800">

                                    <?= htmlspecialchars(
                                        $refund["service_name"] ?? "—"
                                    ) ?>

                                </p>

                            </div>


                            <div class="rounded-xl bg-slate-50 p-3">

                                <p class="text-[11px] font-medium uppercase tracking-wide text-slate-400">
                                    Before
                                </p>

                                <p class="mt-1 text-sm font-semibold text-slate-800">

                                    ₦<?= number_format(
                                        (float) $refund["balance_before"],
                                        2
                                    ) ?>

                                </p>

                            </div>


                            <div class="rounded-xl bg-slate-50 p-3">

                                <p class="text-[11px] font-medium uppercase tracking-wide text-slate-400">
                                    After
                                </p>

                                <p class="mt-1 text-sm font-semibold text-slate-800">

                                    ₦<?= number_format(
                                        (float) $refund["balance_after"],
                                        2
                                    ) ?>

                                </p>

                            </div>

                        </div>


                        <!-- REFERENCE + DATE -->

                        <div class="mt-4 flex flex-col gap-2 border-t border-slate-100 pt-4">

                            <div>

                                <p class="text-[11px] font-medium uppercase tracking-wide text-slate-400">
                                    Reference
                                </p>

                                <p class="mt-1 break-all text-xs text-slate-500">

                                    <?= htmlspecialchars(
                                        $refund["reference"] ?? "—"
                                    ) ?>

                                </p>

                            </div>

                            <div>

                                <p class="text-[11px] font-medium uppercase tracking-wide text-slate-400">
                                    Date
                                </p>

                                <p class="mt-1 text-xs text-slate-500">

                                    <?= htmlspecialchars(
                                        $refund["created_at"]
                                    ) ?>

                                </p>

                            </div>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>


            <!-- DESKTOP -->

            <div class="hidden overflow-x-auto lg:block">

                <table class="min-w-[1100px] w-full text-left">

                    <thead class="border-b border-slate-200 bg-slate-50">

                    <tr>

                        <th class="px-5 py-4 text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Request
                        </th>

                        <th class="px-5 py-4 text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Client
                        </th>

                        <th class="px-5 py-4 text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Service
                        </th>

                        <th class="px-5 py-4 text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Amount
                        </th>

                        <th class="px-5 py-4 text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Balance Before
                        </th>

                        <th class="px-5 py-4 text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Balance After
                        </th>

                        <th class="px-5 py-4 text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Status
                        </th>

                        <th class="px-5 py-4 text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Reference
                        </th>

                        <th class="px-5 py-4 text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Date
                        </th>

                    </tr>

                    </thead>


                    <tbody class="divide-y divide-slate-100">

                    <?php foreach ($refunds as $refund): ?>

                        <?php

                        $status = strtolower(
                            $refund["status"] ?? ""
                        );

                        $statusClass = match ($status) {

                            "successful" =>
                                "bg-emerald-50 text-emerald-700 border-emerald-200",

                            "pending" =>
                                "bg-amber-50 text-amber-700 border-amber-200",

                            "failed", "reversed" =>
                                "bg-red-50 text-red-700 border-red-200",

                            default =>
                                "bg-slate-100 text-slate-600 border-slate-200"

                        };

                        ?>

                        <tr class="transition hover:bg-slate-50">

                            <td class="px-5 py-4">

                                <?php if (!empty($refund["request_code"])): ?>

                                    <a
                                        href="request_details.php?id=<?= (int) ($refund["request_id"] ?? 0) ?>"
                                        class="font-semibold text-servora-700 hover:underline"
                                    >

                                        <?= htmlspecialchars(
                                            $refund["request_code"]
                                        ) ?>

                                    </a>

                                <?php else: ?>

                                    <span class="text-slate-400">
                                        —
                                    </span>

                                <?php endif; ?>

                            </td>


                            <td class="px-5 py-4">

                                <div class="flex items-center gap-3">

                                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-servora-100 text-sm font-bold text-servora-700">

                                        <?= htmlspecialchars(
                                            strtoupper(
                                                substr(
                                                    $refund["full_name"] ?? "C",
                                                    0,
                                                    1
                                                )
                                            )
                                        ) ?>

                                    </div>

                                    <div>

                                        <p class="font-semibold text-slate-900">

                                            <?= htmlspecialchars(
                                                $refund["full_name"]
                                            ) ?>

                                        </p>

                                        <p class="text-xs text-slate-500">

                                            <?= htmlspecialchars(
                                                $refund["email"]
                                            ) ?>

                                        </p>

                                    </div>

                                </div>

                            </td>


                            <td class="px-5 py-4">

                                <span class="text-sm text-slate-700">

                                    <?= htmlspecialchars(
                                        $refund["service_name"] ?? "—"
                                    ) ?>

                                </span>

                            </td>


                            <td class="px-5 py-4">

                                <span class="font-bold text-emerald-600">

                                    ₦<?= number_format(
                                        (float) $refund["amount"],
                                        2
                                    ) ?>

                                </span>

                            </td>


                            <td class="px-5 py-4">

                                <span class="text-sm text-slate-700">

                                    ₦<?= number_format(
                                        (float) $refund["balance_before"],
                                        2
                                    ) ?>

                                </span>

                            </td>


                            <td class="px-5 py-4">

                                <span class="text-sm font-semibold text-slate-800">

                                    ₦<?= number_format(
                                        (float) $refund["balance_after"],
                                        2
                                    ) ?>

                                </span>

                            </td>


                            <td class="px-5 py-4">

                                <span
                                    class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold <?= $statusClass ?>"
                                >

                                    <?= htmlspecialchars(
                                        ucfirst($status)
                                    ) ?>

                                </span>

                            </td>


                            <td class="max-w-[180px] px-5 py-4">

                                <span class="block truncate text-xs text-slate-500">

                                    <?= htmlspecialchars(
                                        $refund["reference"] ?? "—"
                                    ) ?>

                                </span>

                            </td>


                            <td class="whitespace-nowrap px-5 py-4">

                                <span class="text-xs text-slate-500">

                                    <?= htmlspecialchars(
                                        $refund["created_at"]
                                    ) ?>

                                </span>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>


        <?php else: ?>


            <!-- EMPTY STATE -->

            <div class="px-6 py-16 text-center">

                <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-servora-50 text-servora-700">

                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-7 w-7"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="1.8"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M3 10h11a4 4 0 014 4v1m0 0l-3-3m3 3l3-3M21 14H10a4 4 0 00-4-4V9m0 0l3 3m-3-3L3 12"
                        />
                    </svg>

                </div>

                <h3 class="mt-5 text-base font-semibold text-slate-900">
                    No refunds yet
                </h3>

                <p class="mx-auto mt-2 max-w-sm text-sm text-slate-500">
                    Refund transactions will appear here when a client receives a refund.
                </p>

            </div>


        <?php endif; ?>

    </div>


    <!-- FOOTER -->

    <div class="mt-6 text-center">

        <p class="text-xs text-slate-400">
            Subnext Financial Management
        </p>

    </div>

</div>

</body>

</html>