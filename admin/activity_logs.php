<?php

require_once "../config/database.php";
require_once "../includes/admin_auth.php";

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$search = trim($_GET["search"] ?? "");
$action = trim($_GET["action"] ?? "");

/*
|--------------------------------------------------------------------------
| Build Query
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        al.id,
        al.action,
        al.description,
        al.created_at,

        u.full_name AS admin_name,
        u.email AS admin_email,

        sr.request_code

    FROM activity_logs al

    LEFT JOIN users u
        ON u.id = al.user_id

    LEFT JOIN service_requests sr
        ON sr.id = al.request_id

    WHERE 1 = 1
";

$params = [];

/*
|--------------------------------------------------------------------------
| Search
|--------------------------------------------------------------------------
*/

if ($search !== "") {

    $sql .= "
        AND (
            u.full_name LIKE ?
            OR u.email LIKE ?
            OR al.action LIKE ?
            OR al.description LIKE ?
            OR sr.request_code LIKE ?
        )
    ";

    $searchValue = "%{$search}%";

    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
}

/*
|--------------------------------------------------------------------------
| Action Filter
|--------------------------------------------------------------------------
*/

if ($action !== "") {

    $sql .= " AND al.action = ?";

    $params[] = $action;
}

/*
|--------------------------------------------------------------------------
| Latest Logs First
|--------------------------------------------------------------------------
*/

$sql .= " ORDER BY al.created_at DESC";

/*
|--------------------------------------------------------------------------
| Execute
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$logs = $stmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Get Available Actions
|--------------------------------------------------------------------------
*/

$actionStmt = $pdo->query("
    SELECT DISTINCT action
    FROM activity_logs
    WHERE action IS NOT NULL
    AND action != ''
    ORDER BY action ASC
");

$actions = $actionStmt->fetchAll(PDO::FETCH_COLUMN);

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function actionBadgeClass(string $action): string
{
    $action = strtolower($action);

    return match (true) {

        str_contains($action, "login"),
        str_contains($action, "logout") =>
            "bg-blue-50 text-blue-700 border-blue-200",

        str_contains($action, "refund") =>
            "bg-rose-50 text-rose-700 border-rose-200",

        str_contains($action, "payment"),
        str_contains($action, "fund") =>
            "bg-emerald-50 text-emerald-700 border-emerald-200",

        str_contains($action, "request") =>
            "bg-servora-50 text-servora-700 border-servora-200",

        str_contains($action, "status"),
        str_contains($action, "update") =>
            "bg-amber-50 text-amber-700 border-amber-200",

        str_contains($action, "delete"),
        str_contains($action, "remove") =>
            "bg-red-50 text-red-700 border-red-200",

        str_contains($action, "create"),
        str_contains($action, "add") =>
            "bg-indigo-50 text-indigo-700 border-indigo-200",

        default =>
            "bg-slate-100 text-slate-600 border-slate-200"
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

    <title>Activity Logs | Subnext</title>

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

                    System Monitoring

                </div>


                <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">

                    Activity Logs

                </h1>


                <p class="mt-1 text-sm text-slate-500">

                    Monitor administrative activities and system actions.

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


        <!-- TOTAL LOGS -->

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">

            <div class="flex items-start justify-between">

                <div>

                    <p class="text-sm font-medium text-slate-500">

                        Total Logs

                    </p>


                    <p class="mt-2 text-2xl font-bold text-slate-900">

                        <?= count($logs) ?>

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
                            d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"
                        />

                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M9 5a3 3 0 016 0v1H9V5z"
                        />

                    </svg>

                </div>

            </div>


            <p class="mt-3 text-xs text-slate-400">

                Matching activity records

            </p>

        </div>



        <!-- ACTION TYPES -->

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">

            <div class="flex items-start justify-between">

                <div>

                    <p class="text-sm font-medium text-slate-500">

                        Action Types

                    </p>


                    <p class="mt-2 text-2xl font-bold text-slate-900">

                        <?= count($actions) ?>

                    </p>

                </div>


                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600">

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
                            d="M4 6h16M4 12h16M4 18h10"
                        />

                    </svg>

                </div>

            </div>


            <p class="mt-3 text-xs text-slate-400">

                Different actions recorded

            </p>

        </div>



        <!-- FILTER STATUS -->

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">

            <div class="flex items-start justify-between">

                <div>

                    <p class="text-sm font-medium text-slate-500">

                        Current Filter

                    </p>


                    <p class="mt-2 text-lg font-bold text-slate-900">

                        <?php if ($search !== "" && $action !== ""): ?>

                            Search + Action

                        <?php elseif ($search !== ""): ?>

                            Search

                        <?php elseif ($action !== ""): ?>

                            Action

                        <?php else: ?>

                            All Activity

                        <?php endif; ?>

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
                            d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414A1 1 0 0014 14.414V19l-4 2v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"
                        />

                    </svg>

                </div>

            </div>


            <p class="mt-3 text-xs text-slate-400">

                <?= count($logs) ?> record<?= count($logs) === 1 ? "" : "s" ?> displayed

            </p>

        </div>

    </div>



    <!-- FILTERS -->

    <div class="mb-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">

        <div class="mb-4">

            <h2 class="font-semibold text-slate-900">

                Filter Activity

            </h2>


            <p class="mt-1 text-xs text-slate-500">

                Search by administrator, action, description or request.

            </p>

        </div>


        <form
            method="GET"
            class="grid grid-cols-1 gap-4 lg:grid-cols-[1fr_260px_auto_auto]"
        >


            <!-- SEARCH -->

            <div>

                <label
                    for="search"
                    class="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-500"
                >

                    Search

                </label>


                <div class="relative">

                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"
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


                    <input
                        id="search"
                        type="text"
                        name="search"
                        placeholder="Search admin, action, request..."
                        value="<?= htmlspecialchars($search) ?>"
                        class="w-full rounded-xl border border-slate-200 bg-white py-3 pl-10 pr-4 text-sm text-slate-800 outline-none transition placeholder:text-slate-400 focus:border-servora-500 focus:ring-4 focus:ring-servora-100"
                    >

                </div>

            </div>



            <!-- ACTION -->

            <div>

                <label
                    for="action"
                    class="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-500"
                >

                    Action

                </label>


                <select
                    id="action"
                    name="action"
                    class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-800 outline-none transition focus:border-servora-500 focus:ring-4 focus:ring-servora-100"
                >

                    <option value="">

                        All Actions

                    </option>


                    <?php foreach ($actions as $item): ?>

                        <option
                            value="<?= htmlspecialchars($item) ?>"
                            <?= $action === $item ? "selected" : "" ?>
                        >

                            <?= htmlspecialchars($item) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>



            <!-- FILTER -->

            <div class="flex items-end">

                <button
                    type="submit"
                    class="flex w-full items-center justify-center gap-2 rounded-xl bg-servora-700 px-5 py-3 text-sm font-semibold text-white transition hover:bg-servora-800 lg:w-auto"
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
                            d="M3 4h18M6 9h12M10 14h4M11 19h2"
                        />

                    </svg>

                    Apply Filter

                </button>

            </div>



            <!-- CLEAR -->

            <?php if ($search !== "" || $action !== ""): ?>

                <div class="flex items-end">

                    <a
                        href="activity_logs.php"
                        class="flex w-full items-center justify-center rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-600 transition hover:bg-slate-50 lg:w-auto"
                    >

                        Clear

                    </a>

                </div>

            <?php endif; ?>


        </form>

    </div>



    <!-- ACTIVITY -->

    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">


        <!-- CARD HEADER -->

        <div class="border-b border-slate-200 px-5 py-4 sm:px-6">

            <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">

                <div>

                    <h2 class="font-semibold text-slate-900">

                        Activity History

                    </h2>


                    <p class="text-xs text-slate-500">

                        Latest activities appear first.

                    </p>

                </div>


                <div class="text-xs font-medium text-slate-400">

                    <?= count($logs) ?> record<?= count($logs) === 1 ? "" : "s" ?>

                </div>

            </div>

        </div>



        <?php if (!$logs): ?>


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
                            d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"
                        />

                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M9 5a3 3 0 016 0v1H9V5z"
                        />

                    </svg>

                </div>


                <h3 class="mt-5 text-base font-semibold text-slate-900">

                    No activity logs found

                </h3>


                <p class="mx-auto mt-2 max-w-sm text-sm text-slate-500">

                    Try changing your search or action filter to find other activity records.

                </p>


                <?php if ($search !== "" || $action !== ""): ?>

                    <a
                        href="activity_logs.php"
                        class="mt-5 inline-flex items-center justify-center rounded-xl bg-servora-700 px-5 py-3 text-sm font-semibold text-white transition hover:bg-servora-800"
                    >

                        Clear Filters

                    </a>

                <?php endif; ?>

            </div>


        <?php else: ?>


            <!-- MOBILE -->

            <div class="divide-y divide-slate-100 lg:hidden">

                <?php foreach ($logs as $log): ?>

                    <?php

                    $badgeClass = actionBadgeClass(
                        $log["action"] ?? ""
                    );

                    ?>

                    <div class="p-5">

                        <!-- ADMIN -->

                        <div class="flex items-start justify-between gap-4">

                            <div class="flex min-w-0 items-center gap-3">

                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-servora-100 font-bold text-servora-700">

                                    <?= htmlspecialchars(
                                        strtoupper(
                                            substr(
                                                $log["admin_name"] ?? "S",
                                                0,
                                                1
                                            )
                                        )
                                    ) ?>

                                </div>


                                <div class="min-w-0">

                                    <p class="truncate font-semibold text-slate-900">

                                        <?= htmlspecialchars(
                                            $log["admin_name"] ?? "System"
                                        ) ?>

                                    </p>


                                    <?php if (!empty($log["admin_email"])): ?>

                                        <p class="truncate text-xs text-slate-500">

                                            <?= htmlspecialchars(
                                                $log["admin_email"]
                                            ) ?>

                                        </p>

                                    <?php endif; ?>

                                </div>

                            </div>


                            <span
                                class="shrink-0 rounded-full border px-2.5 py-1 text-xs font-semibold <?= $badgeClass ?>"
                            >

                                <?= htmlspecialchars(
                                    $log["action"]
                                ) ?>

                            </span>

                        </div>



                        <!-- DESCRIPTION -->

                        <div class="mt-4 rounded-xl bg-slate-50 p-4">

                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">

                                Description

                            </p>


                            <p class="mt-2 text-sm leading-6 text-slate-700">

                                <?= htmlspecialchars(
                                    $log["description"]
                                ) ?>

                            </p>

                        </div>



                        <!-- REQUEST + DATE -->

                        <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">


                            <div class="rounded-xl border border-slate-100 bg-white p-3">

                                <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">

                                    Request

                                </p>


                                <?php if (!empty($log["request_code"])): ?>

                                    <p class="mt-1 font-semibold text-servora-700">

                                        <?= htmlspecialchars(
                                            $log["request_code"]
                                        ) ?>

                                    </p>

                                <?php else: ?>

                                    <p class="mt-1 text-sm text-slate-400">

                                        No request

                                    </p>

                                <?php endif; ?>

                            </div>


                            <div class="rounded-xl border border-slate-100 bg-white p-3">

                                <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">

                                    Date / Time

                                </p>


                                <p class="mt-1 text-sm text-slate-600">

                                    <?= htmlspecialchars(
                                        $log["created_at"]
                                    ) ?>

                                </p>

                            </div>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>



            <!-- DESKTOP -->

            <div class="hidden overflow-x-auto lg:block">

                <table class="min-w-[950px] w-full text-left">

                    <thead class="border-b border-slate-200 bg-slate-50">

                    <tr>

                        <th class="px-5 py-4 text-xs font-semibold uppercase tracking-wide text-slate-500">

                            Administrator

                        </th>


                        <th class="px-5 py-4 text-xs font-semibold uppercase tracking-wide text-slate-500">

                            Action

                        </th>


                        <th class="px-5 py-4 text-xs font-semibold uppercase tracking-wide text-slate-500">

                            Description

                        </th>


                        <th class="px-5 py-4 text-xs font-semibold uppercase tracking-wide text-slate-500">

                            Request

                        </th>


                        <th class="px-5 py-4 text-xs font-semibold uppercase tracking-wide text-slate-500">

                            Date / Time

                        </th>

                    </tr>

                    </thead>


                    <tbody class="divide-y divide-slate-100">

                    <?php foreach ($logs as $log): ?>

                        <?php

                        $badgeClass = actionBadgeClass(
                            $log["action"] ?? ""
                        );

                        ?>

                        <tr class="transition hover:bg-slate-50">


                            <!-- ADMIN -->

                            <td class="px-5 py-4">

                                <div class="flex items-center gap-3">

                                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-servora-100 text-sm font-bold text-servora-700">

                                        <?= htmlspecialchars(
                                            strtoupper(
                                                substr(
                                                    $log["admin_name"] ?? "S",
                                                    0,
                                                    1
                                                )
                                            )
                                        ) ?>

                                    </div>


                                    <div class="min-w-0">

                                        <p class="font-semibold text-slate-900">

                                            <?= htmlspecialchars(
                                                $log["admin_name"] ?? "System"
                                            ) ?>

                                        </p>


                                        <?php if (!empty($log["admin_email"])): ?>

                                            <p class="max-w-[190px] truncate text-xs text-slate-500">

                                                <?= htmlspecialchars(
                                                    $log["admin_email"]
                                                ) ?>

                                            </p>

                                        <?php endif; ?>

                                    </div>

                                </div>

                            </td>



                            <!-- ACTION -->

                            <td class="px-5 py-4">

                                <span
                                    class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold <?= $badgeClass ?>"
                                >

                                    <?= htmlspecialchars(
                                        $log["action"]
                                    ) ?>

                                </span>

                            </td>



                            <!-- DESCRIPTION -->

                            <td class="max-w-[350px] px-5 py-4">

                                <p class="text-sm leading-6 text-slate-700">

                                    <?= htmlspecialchars(
                                        $log["description"]
                                    ) ?>

                                </p>

                            </td>



                            <!-- REQUEST -->

                            <td class="px-5 py-4">

                                <?php if (!empty($log["request_code"])): ?>

                                    <span class="font-semibold text-servora-700">

                                        <?= htmlspecialchars(
                                            $log["request_code"]
                                        ) ?>

                                    </span>

                                <?php else: ?>

                                    <span class="text-slate-400">

                                        —

                                    </span>

                                <?php endif; ?>

                            </td>



                            <!-- DATE -->

                            <td class="whitespace-nowrap px-5 py-4">

                                <span class="text-xs text-slate-500">

                                    <?= htmlspecialchars(
                                        $log["created_at"]
                                    ) ?>

                                </span>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>


        <?php endif; ?>

    </div>



    <!-- FOOTER -->

    <div class="mt-6 text-center">

        <p class="text-xs text-slate-400">

            Subnext System Monitoring

        </p>

    </div>


</div>


</body>

</html>