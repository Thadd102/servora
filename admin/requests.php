<?php

require_once "../config/database.php";
require_once "../includes/admin_auth.php";

$statusFilter = trim($_GET["status"] ?? "");
$search = trim($_GET["search"] ?? "");

/*
|--------------------------------------------------------------------------
| Allowed Statuses
|--------------------------------------------------------------------------
*/

$allowedStatuses = [
    "submitted",
    "payment_confirmed",
    "processing",
    "under_review",
    "awaiting_information",
    "ready_for_download",
    "completed",
    "cancelled"
];

/*
|--------------------------------------------------------------------------
| Build Query
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        sr.id,
        sr.request_code,
        sr.amount,
        sr.status,
        sr.submitted_at,
        sr.updated_at,
        u.full_name,
        u.email,
        u.phone,
        s.name AS service_name
    FROM service_requests sr

    INNER JOIN users u
        ON sr.user_id = u.id

    INNER JOIN services s
        ON sr.service_id = s.id

    WHERE 1 = 1
";

$params = [];

/*
|--------------------------------------------------------------------------
| Status Filter
|--------------------------------------------------------------------------
*/

if (
    $statusFilter !== "" &&
    in_array($statusFilter, $allowedStatuses, true)
) {

    $sql .= " AND sr.status = ? ";

    $params[] = $statusFilter;
}

/*
|--------------------------------------------------------------------------
| Search
|--------------------------------------------------------------------------
*/

if ($search !== "") {

    $sql .= "
        AND (
            sr.request_code LIKE ?
            OR u.full_name LIKE ?
            OR u.email LIKE ?
            OR u.phone LIKE ?
            OR s.name LIKE ?
        )
    ";

    $searchValue = "%" . $search . "%";

    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
}

$sql .= "
    ORDER BY sr.submitted_at DESC
";

/*
|--------------------------------------------------------------------------
| Execute
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$requests = $stmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Status Labels
|--------------------------------------------------------------------------
*/

$statusLabels = [

    "submitted" => "Submitted",

    "payment_confirmed" => "Payment Confirmed",

    "processing" => "Processing",

    "under_review" => "Under Review",

    "awaiting_information" => "Awaiting Information",

    "ready_for_download" => "Ready for Download",

    "completed" => "Completed",

    "cancelled" => "Cancelled"
];

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
        Service Requests | Subnext Admin
    </title>

    <!-- Precompiled Production Stylesheet -->
    <link rel="stylesheet" href="../assets/css/style.css">

</head>


<body class="min-h-screen bg-slate-50 text-slate-900">


<div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8 lg:py-10">


    <!-- ==================================================
         HEADER
    =================================================== -->

    <div class="mb-8">

        <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">

            <div>

                <div class="mb-3 flex items-center gap-3">

                    <a
                        href="dashboard.php"
                        class="flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 shadow-sm transition hover:border-servora-200 hover:bg-servora-50 hover:text-servora-700"
                        aria-label="Back to dashboard"
                    >

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
                                d="M15 19l-7-7 7-7"
                            />
                        </svg>

                    </a>

                    <span class="text-sm font-semibold text-servora-700">
                        Request Management
                    </span>

                </div>


                <h1 class="text-2xl font-black tracking-tight text-slate-900 sm:text-3xl">
                    Service Requests
                </h1>

                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-500 sm:text-base">
                    View, search and manage service requests submitted by clients.
                </p>

            </div>


            <!-- REQUEST COUNT -->

            <div class="w-fit rounded-2xl border border-servora-100 bg-servora-50 px-4 py-3">

                <p class="text-[11px] font-bold uppercase tracking-wider text-servora-500">
                    Results
                </p>

                <p class="mt-1 text-xl font-black text-servora-800">
                    <?= count($requests) ?>
                </p>

            </div>

        </div>

    </div>


    <!-- ==================================================
         FILTER CARD
    =================================================== -->

    <div class="mb-8 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">

        <div class="border-b border-slate-100 bg-gradient-to-r from-servora-50 to-white px-5 py-5 sm:px-7">

            <div class="flex items-center gap-3">

                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-servora-700 text-white">

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
                            d="M21 21l-4.35-4.35m2.35-5.65a8 8 0 11-16 0 8 8 0 0116 0z"
                        />
                    </svg>

                </div>


                <div>

                    <h2 class="font-black text-slate-900">
                        Find Requests
                    </h2>

                    <p class="text-xs text-slate-500">
                        Search by client, service or request information.
                    </p>

                </div>

            </div>

        </div>


        <form
            method="GET"
            class="p-5 sm:p-7"
        >

            <div class="grid gap-4 lg:grid-cols-[1fr_240px_auto]">


                <!-- SEARCH -->

                <div>

                    <label
                        for="search"
                        class="mb-2 block text-sm font-bold text-slate-700"
                    >
                        Search
                    </label>

                    <div class="relative">

                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4 text-slate-400">

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
                                    d="M21 21l-4.35-4.35m2.35-5.65a8 8 0 11-16 0 8 8 0 0116 0z"
                                />
                            </svg>

                        </div>


                        <input
                            type="text"
                            id="search"
                            name="search"
                            value="<?= htmlspecialchars($search) ?>"
                            placeholder="Request code, client, email or phone"
                            class="w-full rounded-xl border border-slate-200 bg-white py-3 pl-11 pr-4 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-servora-500 focus:ring-4 focus:ring-servora-100"
                        >

                    </div>

                </div>


                <!-- STATUS -->

                <div>

                    <label
                        for="status"
                        class="mb-2 block text-sm font-bold text-slate-700"
                    >
                        Status
                    </label>

                    <select
                        name="status"
                        id="status"
                        class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-servora-500 focus:ring-4 focus:ring-servora-100"
                    >

                        <option value="">
                            All Statuses
                        </option>

                        <?php foreach ($statusLabels as $value => $label): ?>

                            <option
                                value="<?= htmlspecialchars($value) ?>"
                                <?= $statusFilter === $value ? "selected" : "" ?>
                            >
                                <?= htmlspecialchars($label) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <!-- SEARCH BUTTON -->

                <div class="flex items-end">

                    <button
                        type="submit"
                        class="min-h-12 w-full rounded-xl bg-servora-700 px-6 text-sm font-bold text-white shadow-sm transition hover:bg-servora-800 focus:outline-none focus:ring-4 focus:ring-servora-100 lg:w-auto"
                    >
                        Search Requests
                    </button>

                </div>

            </div>


            <?php if ($search !== "" || $statusFilter !== ""): ?>

                <div class="mt-4">

                    <a
                        href="requests.php"
                        class="inline-flex items-center gap-2 rounded-xl bg-slate-100 px-4 py-2.5 text-xs font-bold text-slate-600 transition hover:bg-slate-200"
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
                                d="M6 18L18 6M6 6l12 12"
                            />
                        </svg>

                        Clear Filters

                    </a>

                </div>

            <?php endif; ?>

        </form>

    </div>


    <!-- ==================================================
         REQUESTS
    =================================================== -->

    <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">


        <!-- SECTION HEADER -->

        <div class="border-b border-slate-100 px-5 py-5 sm:px-7">

            <div class="flex items-center justify-between gap-4">

                <div>

                    <h2 class="text-lg font-black text-slate-900">
                        All Requests
                    </h2>

                    <p class="mt-1 text-sm text-slate-500">
                        Latest service activity appears first.
                    </p>

                </div>

            </div>

        </div>


        <?php if (empty($requests)): ?>


            <!-- ==================================================
                 EMPTY STATE
            =================================================== -->

            <div class="px-5 py-16 text-center sm:px-7">

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
                            d="M9 12h6m-6 4h6M7 4h10a2 2 0 012 2v12a2 2 0 01-2 2H7a2 2 0 01-2-2V6a2 2 0 012-2z"
                        />
                    </svg>

                </div>


                <h3 class="mt-5 text-base font-bold text-slate-900">
                    No service requests found
                </h3>


                <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500">

                    <?php if ($search !== "" || $statusFilter !== ""): ?>

                        Try changing your search or status filter.

                    <?php else: ?>

                        Client service requests will appear here when they are submitted.

                    <?php endif; ?>

                </p>


                <?php if ($search !== "" || $statusFilter !== ""): ?>

                    <a
                        href="requests.php"
                        class="mt-5 inline-flex min-h-11 items-center justify-center rounded-xl bg-servora-700 px-5 text-sm font-bold text-white transition hover:bg-servora-800"
                    >
                        Clear Filters
                    </a>

                <?php endif; ?>

            </div>


        <?php else: ?>


            <!-- ==================================================
                 MOBILE REQUEST CARDS
            =================================================== -->

            <div class="space-y-3 p-4 lg:hidden">

                <?php foreach ($requests as $request): ?>

                    <?php

                    $status = $request["status"];

                    $statusClasses = match ($status) {

                        "submitted" =>
                            "bg-blue-50 text-blue-700",

                        "payment_confirmed" =>
                            "bg-servora-50 text-servora-700",

                        "processing" =>
                            "bg-amber-50 text-amber-700",

                        "under_review" =>
                            "bg-orange-50 text-orange-700",

                        "awaiting_information" =>
                            "bg-purple-50 text-purple-700",

                        "ready_for_download" =>
                            "bg-emerald-50 text-emerald-700",

                        "completed" =>
                            "bg-green-50 text-green-700",

                        "cancelled" =>
                            "bg-red-50 text-red-700",

                        default =>
                            "bg-slate-100 text-slate-600"

                    };

                    ?>


                    <div class="rounded-2xl border border-slate-200 bg-white p-4">


                        <!-- TOP -->

                        <div class="flex items-start justify-between gap-3">

                            <div class="min-w-0">

                                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                                    Request
                                </p>

                                <p class="mt-1 truncate font-mono text-sm font-bold text-servora-700">
                                    <?= htmlspecialchars($request["request_code"]) ?>
                                </p>

                            </div>


                            <span class="shrink-0 rounded-full px-3 py-1.5 text-[10px] font-bold <?= $statusClasses ?>">

                                <?= htmlspecialchars(
                                    $statusLabels[$status] ?? $status
                                ) ?>

                            </span>

                        </div>


                        <!-- CLIENT -->

                        <div class="mt-5">

                            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                                Client
                            </p>

                            <p class="mt-1 font-bold text-slate-800">
                                <?= htmlspecialchars($request["full_name"]) ?>
                            </p>

                            <p class="mt-0.5 truncate text-xs text-slate-400">
                                <?= htmlspecialchars($request["email"]) ?>
                            </p>

                        </div>


                        <!-- SERVICE + AMOUNT -->

                        <div class="mt-4 grid grid-cols-2 gap-3">

                            <div class="rounded-xl bg-slate-50 p-3">

                                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                                    Service
                                </p>

                                <p class="mt-1 line-clamp-2 text-sm font-semibold text-slate-700">
                                    <?= htmlspecialchars($request["service_name"]) ?>
                                </p>

                            </div>


                            <div class="rounded-xl bg-slate-50 p-3">

                                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                                    Amount
                                </p>

                                <p class="mt-1 text-sm font-black text-slate-800">
                                    ₦<?= number_format(
                                        (float) $request["amount"],
                                        2
                                    ) ?>
                                </p>

                            </div>

                        </div>


                        <!-- DATE -->

                        <div class="mt-4 flex items-center justify-between text-xs">

                            <span class="text-slate-400">
                                Submitted
                            </span>

                            <span class="font-medium text-slate-600">
                                <?= htmlspecialchars($request["submitted_at"]) ?>
                            </span>

                        </div>


                        <!-- ACTION -->

                        <a
                            href="request_details.php?id=<?= (int) $request["id"] ?>"
                            class="mt-4 flex min-h-11 w-full items-center justify-center rounded-xl bg-servora-700 px-4 text-sm font-bold text-white transition hover:bg-servora-800"
                        >
                            View Request
                        </a>

                    </div>

                <?php endforeach; ?>

            </div>


            <!-- ==================================================
                 DESKTOP TABLE
            =================================================== -->

            <div class="hidden overflow-x-auto lg:block">

                <table class="w-full text-left">

                    <thead>

                        <tr class="border-b border-slate-100 bg-slate-50/70">

                            <th class="px-6 py-4 text-xs font-bold uppercase tracking-wider text-slate-400">
                                Request
                            </th>

                            <th class="px-6 py-4 text-xs font-bold uppercase tracking-wider text-slate-400">
                                Client
                            </th>

                            <th class="px-6 py-4 text-xs font-bold uppercase tracking-wider text-slate-400">
                                Service
                            </th>

                            <th class="px-6 py-4 text-xs font-bold uppercase tracking-wider text-slate-400">
                                Amount
                            </th>

                            <th class="px-6 py-4 text-xs font-bold uppercase tracking-wider text-slate-400">
                                Status
                            </th>

                            <th class="px-6 py-4 text-xs font-bold uppercase tracking-wider text-slate-400">
                                Submitted
                            </th>

                            <th class="px-6 py-4 text-right text-xs font-bold uppercase tracking-wider text-slate-400">
                                Action
                            </th>

                        </tr>

                    </thead>


                    <tbody class="divide-y divide-slate-100">

                    <?php foreach ($requests as $request): ?>

                        <?php

                        $status = $request["status"];

                        $statusClasses = match ($status) {

                            "submitted" =>
                                "bg-blue-50 text-blue-700",

                            "payment_confirmed" =>
                                "bg-servora-50 text-servora-700",

                            "processing" =>
                                "bg-amber-50 text-amber-700",

                            "under_review" =>
                                "bg-orange-50 text-orange-700",

                            "awaiting_information" =>
                                "bg-purple-50 text-purple-700",

                            "ready_for_download" =>
                                "bg-emerald-50 text-emerald-700",

                            "completed" =>
                                "bg-green-50 text-green-700",

                            "cancelled" =>
                                "bg-red-50 text-red-700",

                            default =>
                                "bg-slate-100 text-slate-600"

                        };

                        ?>


                        <tr class="transition hover:bg-slate-50/70">


                            <!-- REQUEST -->

                            <td class="px-6 py-5">

                                <p class="font-mono text-sm font-bold text-servora-700">

                                    <?= htmlspecialchars(
                                        $request["request_code"]
                                    ) ?>

                                </p>

                                <p class="mt-1 text-xs text-slate-400">

                                    ID #<?= (int) $request["id"] ?>

                                </p>

                            </td>


                            <!-- CLIENT -->

                            <td class="px-6 py-5">

                                <p class="font-bold text-slate-800">

                                    <?= htmlspecialchars(
                                        $request["full_name"]
                                    ) ?>

                                </p>

                                <p class="mt-1 text-xs text-slate-400">

                                    <?= htmlspecialchars(
                                        $request["email"]
                                    ) ?>

                                </p>

                                <?php if (!empty($request["phone"])): ?>

                                    <p class="mt-0.5 text-xs text-slate-400">

                                        <?= htmlspecialchars(
                                            $request["phone"]
                                        ) ?>

                                    </p>

                                <?php endif; ?>

                            </td>


                            <!-- SERVICE -->

                            <td class="px-6 py-5">

                                <p class="max-w-[200px] font-medium text-slate-700">

                                    <?= htmlspecialchars(
                                        $request["service_name"]
                                    ) ?>

                                </p>

                            </td>


                            <!-- AMOUNT -->

                            <td class="px-6 py-5">

                                <p class="font-black text-slate-800">

                                    ₦<?= number_format(
                                        (float) $request["amount"],
                                        2
                                    ) ?>

                                </p>

                            </td>


                            <!-- STATUS -->

                            <td class="px-6 py-5">

                                <span class="inline-flex rounded-full px-3 py-1.5 text-[11px] font-bold <?= $statusClasses ?>">

                                    <?= htmlspecialchars(
                                        $statusLabels[$status] ?? $status
                                    ) ?>

                                </span>

                            </td>


                            <!-- DATE -->

                            <td class="px-6 py-5">

                                <p class="whitespace-nowrap text-sm text-slate-600">

                                    <?= htmlspecialchars(
                                        $request["submitted_at"]
                                    ) ?>

                                </p>

                            </td>


                            <!-- ACTION -->

                            <td class="px-6 py-5 text-right">

                                <a
                                    href="request_details.php?id=<?= (int) $request["id"] ?>"
                                    class="inline-flex min-h-10 items-center justify-center rounded-xl bg-servora-700 px-4 text-xs font-bold text-white transition hover:bg-servora-800"
                                >
                                    View
                                </a>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </div>


    <!-- FOOTER -->

    <div class="py-8 text-center">

        <p class="text-xs text-slate-400">

            Subnext Admin
            <span class="mx-1">•</span>
            Request Management

        </p>

    </div>

</div>

</body>

</html>