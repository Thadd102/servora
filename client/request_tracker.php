<?php

require_once "../includes/client_auth.php";
require_once "../config/database.php";

/*
|--------------------------------------------------------------------------
| CSRF TOKEN
|--------------------------------------------------------------------------
*/

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

/*
|--------------------------------------------------------------------------
| REQUEST ID
|--------------------------------------------------------------------------
*/

$requestId = filter_input(
    INPUT_GET,
    "id",
    FILTER_VALIDATE_INT
);

if (!$requestId) {
    die("Invalid request.");
}

/*
|--------------------------------------------------------------------------
| CURRENT USER
|--------------------------------------------------------------------------
*/

$userId = currentUserId();

/*
|--------------------------------------------------------------------------
| FETCH REQUEST
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        sr.*,
        s.name AS service_name
    FROM service_requests sr
    INNER JOIN services s
        ON sr.service_id = s.id
    WHERE sr.id = ?
      AND sr.user_id = ?
    LIMIT 1
");

$stmt->execute([
    $requestId,
    $userId
]);

$request = $stmt->fetch();

if (!$request) {
    http_response_code(404);
    die("Request not found.");
}

$stmt = $pdo->prepare("SELECT balance FROM wallets WHERE user_id = ? LIMIT 1");
$stmt->execute([$userId]);
$walletBalance = (float)($stmt->fetchColumn() ?: 0.00);

$fullName = trim((string)($_SESSION["full_name"] ?? "Client"));
$profileInitial = strtoupper(substr($fullName, 0, 1) ?: "C");

/*
|--------------------------------------------------------------------------
| FETCH STATUS HISTORY
|--------------------------------------------------------------------------
*/

$historyStmt = $pdo->prepare("
    SELECT
        rsh.*,
        u.full_name AS changed_by_name
    FROM request_status_history rsh
    LEFT JOIN users u
        ON rsh.changed_by = u.id
    WHERE rsh.request_id = ?
    ORDER BY rsh.created_at ASC
");

$historyStmt->execute([
    $requestId
]);

$statusHistory = $historyStmt->fetchAll();

/*
|--------------------------------------------------------------------------
| GET COMPLETED STATUSES
|--------------------------------------------------------------------------
*/

$completedStatuses = [];

foreach ($statusHistory as $history) {
    $completedStatuses[] = $history["status"];
}

/*
|--------------------------------------------------------------------------
| FETCH RESULT FILES
|--------------------------------------------------------------------------
*/

$fileStmt = $pdo->prepare("
    SELECT
        id,
        original_name,
        mime_type,
        file_size,
        created_at
    FROM request_files
    WHERE request_id = ?
    ORDER BY created_at DESC
");

$fileStmt->execute([
    $requestId
]);

$resultFiles = $fileStmt->fetchAll();

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
| REQUEST STAGES
|--------------------------------------------------------------------------
*/

$stages = [

    "submitted" => "Request Submitted",

    "payment_confirmed" => "Payment Confirmed",

    "processing" => "Processing",

    "completed" => "Completed",

    "ready_for_download" => "Ready for Download"

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

<title>Request Tracker | Subnext</title>

<!-- Precompiled Production Stylesheet -->
    <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body class="min-h-screen bg-slate-50 text-slate-800">

<!-- Desktop Navigation Header -->
<header class="hidden md:block sticky top-0 z-40 border-b border-slate-200/80 bg-white/95 backdrop-blur">
    <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6">
        <a href="dashboard.php" class="flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-servora-700 text-lg font-black text-white shadow-sm">S</div>
            <div>
                <div class="text-lg font-bold tracking-tight text-slate-900 leading-tight">Subnext</div>
                <div class="text-[10px] font-medium text-slate-400 uppercase tracking-wider">Request Tracker</div>
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

    <!-- Hero Card -->
    <section class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-servora-800 via-servora-700 to-servora-500 p-6 sm:p-8 text-white shadow-xl">
        <a href="request.php" class="text-sm font-semibold text-white/70 hover:text-white transition">
            ← My Requests
        </a>
        <div class="mt-6">
            <p class="text-sm font-semibold text-white/70">
                Subnext Request Tracker
            </p>
            <h1 class="mt-1 text-3xl font-black">
                <?= htmlspecialchars($request["service_name"] ?? "Service Request", ENT_QUOTES, "UTF-8") ?>
            </h1>
            <p class="mt-2 max-w-xl text-sm text-white/70">
                Live tracking and status updates for your service request.
            </p>
        </div>
    </section>

    <!-- Wallet Balance Card -->
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
            <a href="fund_wallet.php" class="inline-flex items-center justify-center rounded-xl bg-servora-50 px-4 py-2 text-xs font-bold text-servora-700 transition hover:bg-servora-100">
                Fund Wallet
            </a>
        </div>
    </section>

    <div class="mt-6">


<!-- =====================================================
     REQUEST OVERVIEW
====================================================== -->

<section class="mb-6 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">


    <!-- TOP -->

    <div class="border-b border-slate-100 p-5 sm:p-7">

        <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">


            <div class="min-w-0">

                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">
                    Service
                </p>

                <h2 class="mt-1 break-words text-xl font-black text-slate-900 sm:text-2xl">
                    <?= e($request["service_name"]) ?>
                </h2>

                <div class="mt-3 flex flex-wrap items-center gap-2">

                    <span class="rounded-lg bg-servora-50 px-2.5 py-1 text-xs font-bold text-servora-700">

                        <?= e($request["request_code"]) ?>

                    </span>

                    <span class="text-xs text-slate-400">
                        Request ID
                    </span>

                </div>

            </div>


            <!-- STATUS -->

            <div class="shrink-0">

                <span class="inline-flex items-center gap-2 rounded-full bg-servora-50 px-3 py-1.5 text-xs font-bold text-servora-700">

                    <span class="h-1.5 w-1.5 rounded-full bg-servora-600"></span>

                    <?= e(
                        ucwords(
                            str_replace(
                                "_",
                                " ",
                                $request["status"]
                            )
                        )
                    ) ?>

                </span>

            </div>

        </div>

    </div>


    <!-- INFORMATION -->

    <div class="grid grid-cols-2 divide-x divide-y divide-slate-100 sm:grid-cols-4 sm:divide-y-0">

        <div class="p-4 sm:p-5">

            <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                Amount
            </p>

            <p class="mt-1 text-sm font-black text-slate-900">
                ₦<?= number_format(
                    (float) $request["amount"],
                    2
                ) ?>
            </p>

        </div>


        <div class="p-4 sm:p-5">

            <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                Submitted
            </p>

            <p class="mt-1 break-words text-sm font-semibold text-slate-700">
                <?= e($request["submitted_at"]) ?>
            </p>

        </div>


        <div class="p-4 sm:p-5">

            <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                Current Status
            </p>

            <p class="mt-1 text-sm font-bold text-servora-700">
                <?= e(
                    ucwords(
                        str_replace(
                            "_",
                            " ",
                            $request["status"]
                        )
                    )
                ) ?>
            </p>

        </div>


        <div class="p-4 sm:p-5">

            <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                Request
            </p>

            <p class="mt-1 text-sm font-semibold text-slate-700">
                Active
            </p>

        </div>

    </div>

</section>


<!-- =====================================================
     TRACKER CARD
====================================================== -->

<section class="mb-6 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">


    <!-- HEADING -->

    <div class="mb-7">

        <div class="flex items-center gap-3">

            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-servora-50 text-servora-700">

                <svg
                    class="h-5 w-5"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M12 8v4l3 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z"
                    />
                </svg>

            </div>


            <div>

                <p class="text-xs font-bold uppercase tracking-wider text-servora-600">
                    Progress
                </p>

                <h2 class="text-lg font-black text-slate-900 sm:text-xl">
                    Request timeline
                </h2>

            </div>

        </div>

    </div>


    <!-- CURRENT STATUS -->

    <div class="mb-8 rounded-2xl border border-servora-100 bg-servora-50 p-4 sm:p-5">

        <div class="flex items-start gap-3">


            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-servora-700 text-xs font-black text-white">
                S
            </div>


            <div class="min-w-0">

                <p class="text-xs font-bold text-servora-600">
                    Current Status
                </p>

                <p class="mt-1 text-sm font-black text-servora-900">
                    <?= e(
                        ucwords(
                            str_replace(
                                "_",
                                " ",
                                $request["status"]
                            )
                        )
                    ) ?>
                </p>

                <p class="mt-1 text-xs leading-5 text-servora-600">
                    Your request is being handled through the Subnext processing workflow.
                </p>

            </div>

        </div>

    </div>


    <!-- TIMELINE -->

    <div class="relative">


        <?php foreach ($stages as $status => $title): ?>

            <?php

            $isDone = in_array(
                $status,
                $completedStatuses,
                true
            );

            ?>


            <div class="relative flex gap-4 pb-8 last:pb-0">


                <!-- CONNECTING LINE -->

                <?php if ($status !== array_key_last($stages)): ?>

                    <div class="absolute left-[17px] top-9 h-[calc(100%-20px)] w-px bg-slate-200"></div>

                <?php endif; ?>


                <!-- STEP CIRCLE -->

                <div class="relative z-10 flex shrink-0">

                    <div
                        class="<?= $isDone
                            ? 'border-servora-700 bg-servora-700 text-white shadow-sm'
                            : 'border-slate-200 bg-white text-slate-300'
                        ?> flex h-9 w-9 items-center justify-center rounded-full border-2 text-xs font-black"
                    >

                        <?= $isDone ? "✓" : "" ?>

                    </div>

                </div>


                <!-- STEP CONTENT -->

                <div class="min-w-0 flex-1 pt-1">


                    <div class="flex flex-wrap items-center gap-2">

                        <h3
                            class="<?= $isDone
                                ? 'text-slate-900'
                                : 'text-slate-400'
                            ?> text-sm font-bold"
                        >

                            <?= e($title) ?>

                        </h3>


                        <?php if ($isDone): ?>

                            <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-bold text-emerald-600">
                                Completed
                            </span>

                        <?php endif; ?>

                    </div>


                    <?php foreach ($statusHistory as $history): ?>

                        <?php

                        if (
                            $history["status"] === $status
                            &&
                            !empty($history["message"])
                        ):

                        ?>

                            <div class="mt-2 rounded-xl bg-slate-50 px-3 py-2.5 text-sm leading-6 text-slate-500">

                                <?= nl2br(
                                    e($history["message"])
                                ) ?>

                            </div>

                        <?php endif; ?>

                    <?php endforeach; ?>


                </div>

            </div>


        <?php endforeach; ?>


    </div>

</section>


<!-- =====================================================
     RESULT FILES
====================================================== -->

<?php if (!empty($resultFiles)): ?>

    <section class="mb-6 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">


        <div class="mb-5 flex items-start gap-3">


            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">

                <svg
                    class="h-5 w-5"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M5 13l4 4L19 7"
                    />
                </svg>

            </div>


            <div>

                <h2 class="text-lg font-black text-slate-900">
                    Your result is ready
                </h2>

                <p class="mt-1 text-sm text-slate-500">
                    Download your processed file below.
                </p>

            </div>

        </div>


        <!-- SUCCESS MESSAGE -->

        <div class="mb-5 rounded-2xl border border-emerald-100 bg-emerald-50 px-4 py-3">

            <p class="text-sm font-medium leading-6 text-emerald-700">
                Your request has been completed and your result is available for download.
            </p>

        </div>


        <!-- FILE LIST -->

        <div class="space-y-3">


            <?php foreach ($resultFiles as $file): ?>

                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">


                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">


                        <div class="min-w-0">

                            <p class="break-all text-sm font-bold text-slate-800">
                                <?= e($file["original_name"]) ?>
                            </p>

                            <p class="mt-1 text-xs text-slate-400">

                                <?= e($file["mime_type"]) ?>

                                <span class="mx-1">
                                    ·
                                </span>

                                <?= number_format(
                                    ((int) $file["file_size"]) / 1024,
                                    2
                                ) ?>

                                KB

                            </p>

                        </div>


                        <a
                            href="download_result.php?id=<?= (int) $file["id"] ?>"
                            class="inline-flex w-full items-center justify-center rounded-xl bg-servora-700 px-5 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-servora-800 sm:w-auto"
                        >

                            Download Result

                        </a>


                    </div>

                </div>

            <?php endforeach; ?>


        </div>

    </section>

<?php endif; ?>


<!-- FOOTER -->

<footer class="py-6 text-center">

    <p class="text-xs text-slate-400">
        Subnext · Digital Services, Simplified.
    </p>

</footer>

</div>

</main>

<?php require_once __DIR__ . "/../includes/client_bottom_nav.php"; ?>
</body>
</html>
