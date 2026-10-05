
<?php

require_once "../includes/admin_auth.php";
require_once "../config/database.php";

// --------------------------------------------------
// CSRF TOKEN
// --------------------------------------------------
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION["csrf_token"];

// --------------------------------------------------
// VALIDATE REQUEST ID
// --------------------------------------------------
$requestId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$requestId || $requestId <= 0) {
    die("Invalid request ID.");
}

// --------------------------------------------------
// GET REQUEST DETAILS
// --------------------------------------------------
$stmt = $pdo->prepare("
    SELECT
        sr.*,
        u.full_name,
        u.email,
        u.phone,
        s.name AS service_name
    FROM service_requests sr
    INNER JOIN users u
        ON u.id = sr.user_id
    INNER JOIN services s
        ON s.id = sr.service_id
    WHERE sr.id = ?
    LIMIT 1
");

$stmt->execute([$requestId]);

$request = $stmt->fetch();

if (!$request) {
    die("Request not found.");
}

// --------------------------------------------------
// DECODE FORM DATA
// --------------------------------------------------
$formData = [];

if (!empty($request["form_data"])) {

    $decoded = json_decode(
        $request["form_data"],
        true
    );

    if (is_array($decoded)) {
        $formData = $decoded;
    }
}

// --------------------------------------------------
// GET RESULT FILES
// --------------------------------------------------
$fileStmt = $pdo->prepare("
    SELECT
        rf.id,
        rf.original_name,
        rf.mime_type,
        rf.file_size,
        rf.created_at,
        u.full_name AS uploaded_by_name
    FROM request_files rf
    LEFT JOIN users u
        ON u.id = rf.uploaded_by
    WHERE rf.request_id = ?
    ORDER BY rf.created_at DESC
");

$fileStmt->execute([$requestId]);

$resultFiles = $fileStmt->fetchAll();

// --------------------------------------------------
// GET STATUS HISTORY
// --------------------------------------------------
$historyStmt = $pdo->prepare("
    SELECT
        rsh.status,
        rsh.message,
        rsh.created_at,
        u.full_name AS changed_by_name
    FROM request_status_history rsh
    LEFT JOIN users u
        ON u.id = rsh.changed_by
    WHERE rsh.request_id = ?
    ORDER BY rsh.created_at ASC
");

$historyStmt->execute([$requestId]);

$statusHistory = $historyStmt->fetchAll();

// --------------------------------------------------
// STATUS LABEL HELPER
// --------------------------------------------------
function statusLabel(string $status): string
{
    return ucwords(
        str_replace("_", " ", $status)
    );
}

// --------------------------------------------------
// FORMAT FILE SIZE
// --------------------------------------------------
function formatFileSize(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . " B";
    }

    if ($bytes < 1024 * 1024) {
        return number_format($bytes / 1024, 2) . " KB";
    }

    return number_format(
        $bytes / (1024 * 1024),
        2
    ) . " MB";
}

// --------------------------------------------------
// STATUS COLORS
// --------------------------------------------------
function statusClasses(string $status): string
{
    return match ($status) {

        "submitted" =>
            "bg-blue-50 text-blue-700 border-blue-100",

        "payment_confirmed" =>
            "bg-servora-50 text-servora-700 border-servora-100",

        "processing" =>
            "bg-amber-50 text-amber-700 border-amber-100",

        "completed" =>
            "bg-green-50 text-green-700 border-green-100",

             "ready_for_download" =>
            "bg-emerald-50 text-emerald-700 border-emerald-100",

        "cancelled" =>
            "bg-red-50 text-red-700 border-red-100",

        default =>
            "bg-slate-50 text-slate-600 border-slate-200"
    };
}

// --------------------------------------------------
// REFUNDABLE STATUSES
// --------------------------------------------------
$refundableStatuses = [
    "submitted",
    "payment_confirmed",
    "processing",
    "under_review",
    "awaiting_information"
];

$canRefund = in_array(
    $request["status"],
    $refundableStatuses,
    true
);

// --------------------------------------------------
// STATUS OPTIONS
// --------------------------------------------------
$statusOptions = [
    "submitted",
    "payment_confirmed",
    "processing",
    "completed",
    "ready_for_download"
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
        Request <?= htmlspecialchars($request["request_code"]) ?> | Subnext Admin
    </title>

    <!-- Precompiled Production Stylesheet -->
    <link rel="stylesheet" href="../assets/css/style.css">

</head>


<body class="min-h-screen bg-slate-50 text-slate-900">

<div class="mx-auto w-full max-w-6xl px-4 py-5 sm:px-6 lg:px-8">


    <!-- ==================================================
         PAGE HEADER
    ================================================== -->

    <header class="mb-6">

        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">

            <div>

                <div class="mb-2 inline-flex items-center gap-2 rounded-full bg-servora-50 px-3 py-1 text-xs font-bold text-servora-700">

                    <span class="h-1.5 w-1.5 rounded-full bg-servora-600"></span>

                    Request Management

                </div>

                <h1 class="text-2xl font-black tracking-tight text-slate-900 sm:text-3xl">
                    Request Details
                </h1>

                <p class="mt-1 text-sm text-slate-500">
                    Review, process and manage this service request.
                </p>

            </div>


            <a
                href="requests.php"
                class="inline-flex min-h-[46px] items-center justify-center rounded-xl border border-slate-200 bg-white px-4 text-sm font-bold text-slate-700 shadow-sm transition hover:border-servora-200 hover:bg-servora-50 hover:text-servora-700"
            >
                ← Back to Requests
            </a>

        </div>

    </header>


    <!-- ==================================================
         REQUEST SUMMARY
    ================================================== -->

    <section class="mb-5 overflow-hidden rounded-3xl bg-gradient-to-br from-servora-800 via-servora-700 to-servora-500 p-5 text-white shadow-lg sm:p-7">

        <div class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">

            <div>

                <p class="text-xs font-bold uppercase tracking-[0.18em] text-white/60">
                    Request Code
                </p>

                <h2 class="mt-2 break-all text-2xl font-black sm:text-3xl">
                    <?= htmlspecialchars($request["request_code"]) ?>
                </h2>

                <p class="mt-2 text-sm text-white/75">
                    <?= htmlspecialchars($request["service_name"]) ?>
                </p>

            </div>


            <div class="flex flex-wrap gap-3">

                <span class="inline-flex items-center rounded-full border border-white/20 bg-white/10 px-4 py-2 text-sm font-bold backdrop-blur">

                    <?= htmlspecialchars(
                        statusLabel($request["status"])
                    ) ?>

                </span>

                <span class="inline-flex items-center rounded-full bg-white px-4 py-2 text-sm font-black text-servora-800">

                    ₦<?= number_format(
                        (float) $request["amount"],
                        2
                    ) ?>

                </span>

            </div>

        </div>

    </section>


    <!-- ==================================================
         REQUEST INFORMATION
    ================================================== -->

    <section class="mb-5 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">

        <div class="mb-5">

            <p class="text-xs font-bold uppercase tracking-wider text-servora-600">
                Overview
            </p>

            <h2 class="mt-1 text-lg font-black text-slate-900">
                Request Information
            </h2>

        </div>


        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">

            <div class="rounded-2xl bg-slate-50 p-4">

                <p class="text-xs font-semibold text-slate-400">
                    Request Code
                </p>

                <p class="mt-1 break-all text-sm font-bold text-slate-900">
                    <?= htmlspecialchars($request["request_code"]) ?>
                </p>

            </div>


            <div class="rounded-2xl bg-slate-50 p-4">

                <p class="text-xs font-semibold text-slate-400">
                    Service
                </p>

                <p class="mt-1 text-sm font-bold text-slate-900">
                    <?= htmlspecialchars($request["service_name"]) ?>
                </p>

            </div>


            <div class="rounded-2xl bg-slate-50 p-4">

                <p class="text-xs font-semibold text-slate-400">
                    Amount
                </p>

                <p class="mt-1 text-sm font-black text-servora-700">
                    ₦<?= number_format(
                        (float) $request["amount"],
                        2
                    ) ?>
                </p>

            </div>


            <div class="rounded-2xl bg-slate-50 p-4">

                <p class="text-xs font-semibold text-slate-400">
                    Status
                </p>

                <span class="mt-2 inline-flex rounded-full border px-3 py-1 text-xs font-bold <?= statusClasses($request["status"]) ?>">

                    <?= htmlspecialchars(
                        statusLabel($request["status"])
                    ) ?>

                </span>

            </div>


            <div class="rounded-2xl bg-slate-50 p-4">

                <p class="text-xs font-semibold text-slate-400">
                    Submitted
                </p>

                <p class="mt-1 text-sm font-bold text-slate-900">
                    <?= htmlspecialchars($request["submitted_at"]) ?>
                </p>

            </div>


            <div class="rounded-2xl bg-slate-50 p-4">

                <p class="text-xs font-semibold text-slate-400">
                    Last Updated
                </p>

                <p class="mt-1 text-sm font-bold text-slate-900">
                    <?= htmlspecialchars($request["updated_at"]) ?>
                </p>

            </div>

        </div>

    </section>


    <!-- ==================================================
         CLIENT INFORMATION
    ================================================== -->

    <section class="mb-5 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">

        <div class="mb-5 flex items-center justify-between gap-3">

            <div>

                <p class="text-xs font-bold uppercase tracking-wider text-servora-600">
                    Customer
                </p>

                <h2 class="mt-1 text-lg font-black text-slate-900">
                    Client Information
                </h2>

            </div>


            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-servora-50 font-black text-servora-700">

                <?= strtoupper(
                    substr(
                        $request["full_name"],
                        0,
                        1
                    )
                ) ?>

            </div>

        </div>


        <div class="grid gap-3 sm:grid-cols-2">

            <div class="rounded-2xl border border-slate-100 p-4">

                <p class="text-xs font-semibold text-slate-400">
                    Full Name
                </p>

                <p class="mt-1 text-sm font-bold text-slate-900">
                    <?= htmlspecialchars($request["full_name"]) ?>
                </p>

            </div>


            <div class="rounded-2xl border border-slate-100 p-4">

                <p class="text-xs font-semibold text-slate-400">
                    Email Address
                </p>

                <p class="mt-1 break-all text-sm font-bold text-slate-900">
                    <?= htmlspecialchars($request["email"]) ?>
                </p>

            </div>


            <div class="rounded-2xl border border-slate-100 p-4">

                <p class="text-xs font-semibold text-slate-400">
                    Phone Number
                </p>

                <p class="mt-1 text-sm font-bold text-slate-900">
                    <?= htmlspecialchars($request["phone"]) ?>
                </p>

            </div>


            <div class="rounded-2xl border border-slate-100 p-4">

                <p class="text-xs font-semibold text-slate-400">
                    Client ID
                </p>

                <p class="mt-1 text-sm font-bold text-slate-900">
                    #<?= (int) $request["user_id"] ?>
                </p>

            </div>

        </div>

    </section>


    <!-- ==================================================
         SUBMITTED FORM DATA
    ================================================== -->

    <section class="mb-5 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">

        <div class="mb-5">

            <p class="text-xs font-bold uppercase tracking-wider text-servora-600">
                Client Submission
            </p>

            <h2 class="mt-1 text-lg font-black text-slate-900">
                Submitted Information
            </h2>

        </div>


        <?php if (!empty($formData)): ?>

            <div class="space-y-3">

                <?php foreach ($formData as $key => $value): ?>

                    <?php

                    if (
                        in_array(
                            strtolower((string) $key),
                            [
                                "password",
                                "csrf_token"
                            ],
                            true
                        )
                    ) {
                        continue;
                    }

                    if (is_array($value)) {
                        $value = implode(", ", $value);
                    }

                    ?>

                    <div class="rounded-2xl border border-slate-100 bg-slate-50 p-4">

                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400">
                            <?= htmlspecialchars(
                                ucwords(
                                    str_replace(
                                        "_",
                                        " ",
                                        (string) $key
                                    )
                                )
                            ) ?>
                        </p>

                        <p class="mt-2 whitespace-pre-wrap break-words text-sm font-semibold leading-6 text-slate-800">
                            <?= htmlspecialchars((string) $value) ?>
                        </p>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-5 py-8 text-center">

                <p class="text-sm font-semibold text-slate-500">
                    No submitted form information found.
                </p>

            </div>

        <?php endif; ?>

    </section>


    <!-- ==================================================
         UPDATE STATUS
    ================================================== -->

    <section class="mb-5 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">

        <div class="mb-5">

            <p class="text-xs font-bold uppercase tracking-wider text-servora-600">
                Request Workflow
            </p>

            <h2 class="mt-1 text-lg font-black text-slate-900">
                Update Request Status
            </h2>

            <p class="mt-1 text-sm text-slate-500">
                Update the client's request progress and optionally send a message.
            </p>

        </div>


        <form
            method="POST"
            action="update_request_status.php"
            class="space-y-5"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($csrfToken) ?>"
            >

            <input
                type="hidden"
                name="request_id"
                value="<?= (int) $requestId ?>"
            >


            <div>

                <label
                    for="status"
                    class="mb-2 block text-sm font-bold text-slate-700"
                >
                    New Status
                </label>

                <select
                    name="status"
                    id="status"
                    required
                    class="min-h-[50px] w-full rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 outline-none transition focus:border-servora-500 focus:ring-4 focus:ring-servora-100"
                >

                    <?php foreach ($statusOptions as $status): ?>

                        <option
                            value="<?= htmlspecialchars($status) ?>"
                            <?= $request["status"] === $status
                                ? "selected"
                                : "" ?>
                        >

                            <?= htmlspecialchars(
                                statusLabel($status)
                            ) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <div>

                <label
                    for="message"
                    class="mb-2 block text-sm font-bold text-slate-700"
                >
                    Message
                </label>

                <textarea
                    name="message"
                    id="message"
                    maxlength="1000"
                    rows="5"
                    placeholder="Optional message to the client..."
                    class="w-full resize-y rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none transition placeholder:text-slate-400 focus:border-servora-500 focus:ring-4 focus:ring-servora-100"
                ></textarea>

                <p class="mt-2 text-xs text-slate-400">
                    Maximum 1000 characters.
                </p>

            </div>


            <button
                type="submit"
                class="inline-flex min-h-[50px] w-full items-center justify-center rounded-xl bg-servora-700 px-5 text-sm font-bold text-white shadow-sm transition hover:bg-servora-800 focus:outline-none focus:ring-4 focus:ring-servora-100 sm:w-auto"
            >
                Update Status
            </button>

        </form>

    </section>


    <!-- ==================================================
         CANCEL & REFUND
    ================================================== -->

    <?php if ($canRefund): ?>

        <section class="mb-5 rounded-3xl border border-red-100 bg-white p-5 shadow-sm sm:p-6">

            <div class="mb-5">

                <p class="text-xs font-bold uppercase tracking-wider text-red-600">
                    Financial Action
                </p>

                <h2 class="mt-1 text-lg font-black text-slate-900">
                    Cancel & Refund
                </h2>

            </div>


            <div class="rounded-2xl border border-red-100 bg-red-50 p-4 sm:p-5">

                <div class="flex gap-3">

                    <div class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-red-100 text-sm font-black text-red-600">
                        !
                    </div>

                    <div>

                        <p class="text-sm font-bold text-red-800">
                            Refund this request?
                        </p>

                        <p class="mt-1 text-sm leading-6 text-red-700">
                            If this service cannot be completed, cancelling the request will return the service fee to the client's wallet.
                        </p>

                    </div>

                </div>


                <div class="mt-4 rounded-xl bg-white/80 p-4">

                    <p class="text-xs font-semibold text-slate-400">
                        Refund Amount
                    </p>

                    <p class="mt-1 text-xl font-black text-slate-900">
                        ₦<?= number_format(
                            (float) $request["amount"],
                            2
                        ) ?>
                    </p>

                </div>


                <p class="mt-4 text-xs leading-5 text-red-600">
                    This action cannot be undone through this page. The refund will be recorded in the wallet transaction history.
                </p>

            </div>


            <form
                method="POST"
                action="refund_request.php"
                class="mt-4"
                onsubmit="return confirm('Are you sure you want to cancel this request and refund the client?');"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars($csrfToken) ?>"
                >

                <input
                    type="hidden"
                    name="request_id"
                    value="<?= (int) $requestId ?>"
                >

                <button
                    type="submit"
                    class="inline-flex min-h-[50px] w-full items-center justify-center rounded-xl bg-red-600 px-5 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 focus:outline-none focus:ring-4 focus:ring-red-100 sm:w-auto"
                >
                    Cancel & Refund
                    ₦<?= number_format(
                        (float) $request["amount"],
                        2
                    ) ?>
                </button>

            </form>

        </section>

    <?php elseif ($request["status"] === "cancelled"): ?>

        <section class="mb-5 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">

            <div class="rounded-2xl border border-amber-100 bg-amber-50 p-5">

                <p class="text-sm font-bold text-amber-800">
                    This request has been cancelled.
                </p>

                <p class="mt-1 text-sm text-amber-700">
                    The refund action is no longer available.
                </p>

            </div>

        </section>

    <?php elseif ($request["status"] === "completed"): ?>

        <section class="mb-5 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">

            <div class="rounded-2xl border border-emerald-100 bg-emerald-50 p-5">

                <p class="text-sm font-bold text-emerald-800">
                    This request has been completed.
                </p>

                <p class="mt-1 text-sm text-emerald-700">
                    Refund is not available for completed requests.
                </p>

            </div>

        </section>

    <?php elseif ($request["status"] === "ready_for_download"): ?>

        <section class="mb-5 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">

            <div class="rounded-2xl border border-emerald-100 bg-emerald-50 p-5">

                <p class="text-sm font-bold text-emerald-800">
                    Result is ready for download.
                </p>

                <p class="mt-1 text-sm text-emerald-700">
                    Refund is not available because a result has already been prepared.
                </p>

            </div>

        </section>

    <?php endif; ?>


    <!-- ==================================================
         UPLOAD RESULT
    ================================================== -->

    <section class="mb-5 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">

        <div class="mb-5">

            <p class="text-xs font-bold uppercase tracking-wider text-servora-600">
                Client Delivery
            </p>

            <h2 class="mt-1 text-lg font-black text-slate-900">
                Upload Result
            </h2>

            <p class="mt-1 text-sm text-slate-500">
                Upload the result file that the client will be allowed to download.
            </p>

        </div>


        <form
            method="POST"
            action="upload_result.php"
            enctype="multipart/form-data"
            class="space-y-5"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($csrfToken) ?>"
            >

            <input
                type="hidden"
                name="request_id"
                value="<?= (int) $requestId ?>"
            >


            <div>

                <label
                    for="result_file"
                    class="mb-2 block text-sm font-bold text-slate-700"
                >
                    Select Result File
                </label>

                <label
                    for="result_file"
                    class="flex cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed border-slate-200 bg-slate-50 px-5 py-8 text-center transition hover:border-servora-300 hover:bg-servora-50"
                >

                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-white text-servora-700 shadow-sm">
                        ↑
                    </div>

                    <p class="mt-3 text-sm font-bold text-slate-700">
                        Choose a result file
                    </p>

                    <p class="mt-1 text-xs text-slate-400">
                        PDF, JPG or PNG • Maximum 5MB
                    </p>

                    <input
                        type="file"
                        name="result_file"
                        id="result_file"
                        accept=".pdf,.jpg,.jpeg,.png"
                        required
                        class="sr-only"
                    >

                </label>

                <p
                    id="fileName"
                    class="mt-2 hidden text-xs font-semibold text-servora-700"
                ></p>

            </div>


            <button
                type="submit"
                class="inline-flex min-h-[50px] w-full items-center justify-center rounded-xl bg-emerald-600 px-5 text-sm font-bold text-white shadow-sm transition hover:bg-emerald-700 focus:outline-none focus:ring-4 focus:ring-emerald-100 sm:w-auto"
            >
                Upload Result
            </button>

        </form>

    </section>


    <!-- ==================================================
         UPLOADED RESULT FILES
    ================================================== -->

    <section class="mb-5 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">

        <div class="mb-5">

            <p class="text-xs font-bold uppercase tracking-wider text-servora-600">
                Files
            </p>

            <h2 class="mt-1 text-lg font-black text-slate-900">
                Uploaded Result Files
            </h2>

        </div>


        <?php if (!empty($resultFiles)): ?>

            <div class="space-y-3">

                <?php foreach ($resultFiles as $file): ?>

                    <div class="rounded-2xl border border-slate-100 bg-slate-50 p-4">

                        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">

                            <div class="min-w-0">

                                <div class="flex items-start gap-3">

                                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white text-xs font-black text-servora-700 shadow-sm">
                                        FILE
                                    </div>

                                    <div class="min-w-0">

                                        <p class="break-all text-sm font-bold text-slate-900">
                                            <?= htmlspecialchars(
                                                $file["original_name"]
                                            ) ?>
                                        </p>

                                        <p class="mt-1 text-xs leading-5 text-slate-500">

                                            <?= htmlspecialchars(
                                                $file["mime_type"]
                                            ) ?>

                                            ·

                                            <?= formatFileSize(
                                                (int) $file["file_size"]
                                            ) ?>

                                        </p>

                                    </div>

                                </div>


                                <div class="mt-3 text-xs leading-5 text-slate-400">

                                    Uploaded by:
                                    <span class="font-semibold text-slate-500">
                                        <?= htmlspecialchars(
                                            $file["uploaded_by_name"] ?? "Unknown"
                                        ) ?>
                                    </span>

                                    <br>

                                    <?= htmlspecialchars(
                                        $file["created_at"]
                                    ) ?>

                                </div>

                            </div>


                            <a
                                href="download_result.php?id=<?= (int) $file["id"] ?>"
                                class="inline-flex min-h-[44px] shrink-0 items-center justify-center rounded-xl bg-servora-700 px-4 text-sm font-bold text-white transition hover:bg-servora-800"
                            >
                                View / Download
                            </a>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-5 py-8 text-center">

                <p class="text-sm font-semibold text-slate-500">
                    No result file has been uploaded yet.
                </p>

                <p class="mt-1 text-xs text-slate-400">
                    Uploaded files will appear here.
                </p>

            </div>

        <?php endif; ?>

    </section>


    <!-- ==================================================
         STATUS HISTORY
    ================================================== -->

    <section class="mb-8 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">

        <div class="mb-6">

            <p class="text-xs font-bold uppercase tracking-wider text-servora-600">
                Activity
            </p>

            <h2 class="mt-1 text-lg font-black text-slate-900">
                Status History
            </h2>

        </div>


        <?php if (!empty($statusHistory)): ?>

            <div class="relative">

                <div class="absolute bottom-3 left-[17px] top-3 w-px bg-slate-200"></div>

                <div class="space-y-6">

                    <?php foreach ($statusHistory as $history): ?>

                        <div class="relative flex gap-4">

                            <div class="relative z-10 flex h-9 w-9 shrink-0 items-center justify-center rounded-full border-4 border-white bg-servora-700 text-[10px] font-black text-white shadow-sm">
                                ✓
                            </div>


                            <div class="min-w-0 flex-1 rounded-2xl border border-slate-100 bg-slate-50 p-4">

                                <div class="flex flex-wrap items-center gap-2">

                                    <span class="inline-flex rounded-full border px-3 py-1 text-xs font-bold <?= statusClasses($history["status"]) ?>">

                                        <?= htmlspecialchars(
                                            statusLabel($history["status"])
                                        ) ?>

                                    </span>

                                </div>


                                <?php if (!empty($history["message"])): ?>

                                    <p class="mt-3 whitespace-pre-wrap text-sm leading-6 text-slate-700">
                                        <?= nl2br(
                                            htmlspecialchars(
                                                $history["message"]
                                            )
                                        ) ?>
                                    </p>

                                <?php endif; ?>


                                <p class="mt-3 text-xs leading-5 text-slate-400">

                                    Changed by:
                                    <span class="font-semibold text-slate-500">
                                        <?= htmlspecialchars(
                                            $history["changed_by_name"] ?? "System"
                                        ) ?>
                                    </span>

                                    <br>

                                    <?= htmlspecialchars(
                                        $history["created_at"]
                                    ) ?>

                                </p>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            </div>

        <?php else: ?>

            <div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-5 py-8 text-center">

                <p class="text-sm font-semibold text-slate-500">
                    No status history found.
                </p>

            </div>

        <?php endif; ?>

    </section>


    <!-- ==================================================
         FOOTER
    ================================================== -->

    <footer class="border-t border-slate-200 py-6 text-center">

        <p class="text-xs text-slate-400">
            Subnext Admin · Request Management
        </p>

    </footer>


</div>


<script>

const resultFile = document.getElementById("result_file");
const fileName = document.getElementById("fileName");

if (resultFile) {

    resultFile.addEventListener("change", function () {

        if (this.files.length > 0) {

            fileName.textContent =
                "Selected: " + this.files[0].name;

            fileName.classList.remove("hidden");

        } else {

            fileName.classList.add("hidden");

        }

    });

}

</script>


</body>
</html>
```
