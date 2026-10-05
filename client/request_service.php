<?php

require_once "../config/database.php";
require_once "../includes/client_auth.php";

$userId = currentUserId();

$serviceId = filter_input(INPUT_GET, "service_id", FILTER_VALIDATE_INT);

if (!$serviceId) {
    die("Invalid service.");
}

/*
|--------------------------------------------------------------------------
| CSRF TOKEN
|--------------------------------------------------------------------------
*/

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION["csrf_token"];

/*
|--------------------------------------------------------------------------
| FETCH SERVICE
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        name,
        description,
        regular_price,
        promo_price,
        promo_active
    FROM services
    WHERE id = ?
      AND status = 'active'
    LIMIT 1
");

$stmt->execute([$serviceId]);

$service = $stmt->fetch();

if (!$service) {
    die("Service not found or unavailable.");
}

/*
|--------------------------------------------------------------------------
| DETERMINE PRICE
|--------------------------------------------------------------------------
*/

$amount = (
    (int) $service["promo_active"] === 1
    && $service["promo_price"] !== null
)
    ? (float) $service["promo_price"]
    : (float) $service["regular_price"];

$stmt = $pdo->prepare("SELECT balance FROM wallets WHERE user_id = ? LIMIT 1");
$stmt->execute([$userId]);
$walletBalance = (float)($stmt->fetchColumn() ?: 0.00);

$fullName = trim((string)($_SESSION["full_name"] ?? "Client"));
$profileInitial = strtoupper(substr($fullName, 0, 1) ?: "C");

/*
|--------------------------------------------------------------------------
| FETCH DYNAMIC FIELDS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT *
    FROM service_fields
    WHERE service_id = ?
    ORDER BY field_order ASC, id ASC
");

$stmt->execute([$serviceId]);

$fields = $stmt->fetchAll();

$errors = [];
$formData = [];

/*
|--------------------------------------------------------------------------
| HANDLE FORM SUBMISSION
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    /*
    |--------------------------------------------------------------------------
    | CSRF CHECK
    |--------------------------------------------------------------------------
    */

    if (
        empty($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        $errors[] = "Invalid form request. Please refresh and try again.";
    }

    /*
    |--------------------------------------------------------------------------
    | COLLECT & VALIDATE DYNAMIC FIELDS
    |--------------------------------------------------------------------------
    */

    foreach ($fields as $field) {

        $fieldName = $field["field_name"];
        $fieldLabel = $field["field_label"];
        $fieldType = $field["field_type"];
        $required = (int) $field["is_required"] === 1;

        $value = trim($_POST[$fieldName] ?? "");

        $formData[$fieldName] = $value;

        // Required validation
        if ($required && $value === "") {

            $errors[] = $fieldLabel . " is required.";

            continue;
        }

        // Optional empty fields don't need further validation
        if ($value === "") {
            continue;
        }

        /*
        |--------------------------------------------------------------------------
        | TEXT VALIDATION
        |--------------------------------------------------------------------------
        */

        if (
            in_array(
                $fieldType,
                ["text", "textarea"],
                true
            )
        ) {

            if (mb_strlen($value) > 2000) {

                $errors[] =
                    $fieldLabel .
                    " is too long.";
            }
        }

        /*
        |--------------------------------------------------------------------------
        | EMAIL VALIDATION
        |--------------------------------------------------------------------------
        */

        if ($fieldType === "email") {

            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {

                $errors[] =
                    $fieldLabel .
                    " must be a valid email address.";
            }
        }

        /*
        |--------------------------------------------------------------------------
        | NUMBER VALIDATION
        |--------------------------------------------------------------------------
        */

        if ($fieldType === "number") {

            if (!is_numeric($value)) {

                $errors[] =
                    $fieldLabel .
                    " must contain a valid number.";
            }
        }

        /*
        |--------------------------------------------------------------------------
        | PHONE VALIDATION
        |--------------------------------------------------------------------------
        */

        if ($fieldType === "tel") {

            if (
                !preg_match(
                    '/^[0-9+\-\s()]{7,30}$/',
                    $value
                )
            ) {

                $errors[] =
                    $fieldLabel .
                    " contains an invalid phone number.";
            }
        }

        /*
        |--------------------------------------------------------------------------
        | DATE VALIDATION
        |--------------------------------------------------------------------------
        */

        if ($fieldType === "date") {

            $date = DateTime::createFromFormat(
                "Y-m-d",
                $value
            );

            if (
                !$date ||
                $date->format("Y-m-d") !== $value
            ) {

                $errors[] =
                    $fieldLabel .
                    " must be a valid date.";
            }
        }

        /*
        |--------------------------------------------------------------------------
        | SELECT VALIDATION
        |--------------------------------------------------------------------------
        */

        if ($fieldType === "select") {

            $options = preg_split(
                "/\r\n|\r|\n/",
                $field["options"] ?? ""
            );

            $options = array_map(
                "trim",
                $options
            );

            $options = array_filter(
                $options,
                fn($option) => $option !== ""
            );

            if (!in_array($value, $options, true)) {

                $errors[] =
                    "Invalid selection for " .
                    $fieldLabel . ".";
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | PROCESS REQUEST
    |--------------------------------------------------------------------------
    */

    if (empty($errors)) {

        try {

            $pdo->beginTransaction();

            /*
            |--------------------------------------------------------------------------
            | RE-FETCH SERVICE INSIDE TRANSACTION
            |--------------------------------------------------------------------------
            | Prevents price manipulation from the browser.
            */

            $stmt = $pdo->prepare("
                SELECT
                    id,
                    name,
                    regular_price,
                    promo_price,
                    promo_active
                FROM services
                WHERE id = ?
                  AND status = 'active'
                LIMIT 1
                FOR UPDATE
            ");

            $stmt->execute([$serviceId]);

            $lockedService = $stmt->fetch();

            if (!$lockedService) {
                throw new Exception(
                    "Service is no longer available."
                );
            }

            $amount = (
                (int) $lockedService["promo_active"] === 1
                && $lockedService["promo_price"] !== null
            )
                ? (float) $lockedService["promo_price"]
                : (float) $lockedService["regular_price"];

            /*
            |--------------------------------------------------------------------------
            | LOCK CLIENT WALLET
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT id, balance
                FROM wallets
                WHERE user_id = ?
                LIMIT 1
                FOR UPDATE
            ");

            $stmt->execute([$userId]);

            $wallet = $stmt->fetch();

            if (!$wallet) {
                throw new Exception(
                    "Wallet not found."
                );
            }

            $balanceBefore = (float) $wallet["balance"];

            if ($balanceBefore < $amount) {

                throw new Exception(
                    "Insufficient wallet balance. Please fund your wallet."
                );
            }

            $balanceAfter =
                $balanceBefore - $amount;

            /*
            |--------------------------------------------------------------------------
            | GENERATE REQUEST CODE
            |--------------------------------------------------------------------------
            */

            $requestCode =
                "SRV-" .
                date("YmdHis") .
                "-" .
                strtoupper(
                    bin2hex(random_bytes(4))
                );

            /*
            |--------------------------------------------------------------------------
            | INSERT SERVICE REQUEST
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                INSERT INTO service_requests (
                    request_code,
                    user_id,
                    service_id,
                    amount,
                    status,
                    form_data,
                    submitted_at,
                    updated_at
                )
                VALUES (
                    ?, ?, ?, ?, 'submitted', ?, NOW(), NOW()
                )
            ");

            $stmt->execute([
                $requestCode,
                $userId,
                $serviceId,
                $amount,
                json_encode(
                    $formData,
                    JSON_UNESCAPED_UNICODE
                )
            ]);

            $requestId =
                (int) $pdo->lastInsertId();

            /*
            |--------------------------------------------------------------------------
            | DEDUCT WALLET
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                UPDATE wallets
                SET balance = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");

            $stmt->execute([
                $balanceAfter,
                $wallet["id"]
            ]);

            /*
            |--------------------------------------------------------------------------
            | WALLET TRANSACTION
            |--------------------------------------------------------------------------
            */

            $reference =
                "SERVICE-" .
                $requestId .
                "-" .
                strtoupper(
                    bin2hex(random_bytes(4))
                );

            $stmt = $pdo->prepare("
                INSERT INTO wallet_transactions (
                    user_id,
                    type,
                    amount,
                    balance_before,
                    balance_after,
                    reference,
                    description,
                    status
                )
                VALUES (
                    ?,
                    'service_payment',
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    'successful'
                )
            ");

            $stmt->execute([
                $userId,
                $amount,
                $balanceBefore,
                $balanceAfter,
                $reference,
                "Payment for service request " .
                $requestCode
            ]);

            /*
            |--------------------------------------------------------------------------
            | REQUEST STATUS HISTORY
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                INSERT INTO request_status_history (
                    request_id,
                    status,
                    message,
                    changed_by
                )
                VALUES (?, 'submitted', ?, ?)
            ");

            $stmt->execute([
                $requestId,
                "Your service request was successfully submitted.",
                $userId
            ]);

            $stmt = $pdo->prepare("
                INSERT INTO request_status_history (
                    request_id,
                    status,
                    message,
                    changed_by
                )
                VALUES (?, 'payment_confirmed', ?, ?)
            ");

            $stmt->execute([
                $requestId,
                "Payment has been confirmed from your wallet.",
                $userId
            ]);

            /*
            |--------------------------------------------------------------------------
            | CLIENT NOTIFICATION
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                INSERT INTO notifications (
                    user_id,
                    request_id,
                    title,
                    message
                )
                VALUES (?, ?, ?, ?)
            ");

            $stmt->execute([
                $userId,
                $requestId,
                "Service Request Submitted",
                "Your request " .
                $requestCode .
                " was successfully submitted and payment confirmed."
            ]);

            /*
            |--------------------------------------------------------------------------
            | ADMIN NOTIFICATIONS
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT id
                FROM users
                WHERE role IN ('admin', 'super_admin')
                  AND status = 'active'
            ");

            $stmt->execute();

            $admins = $stmt->fetchAll();

            $notificationStmt = $pdo->prepare("
                INSERT INTO notifications (
                    user_id,
                    request_id,
                    title,
                    message
                )
                VALUES (?, ?, ?, ?)
            ");

            foreach ($admins as $admin) {

                $notificationStmt->execute([
                    $admin["id"],
                    $requestId,
                    "New Service Request",
                    "A new service request " .
                    $requestCode .
                    " has been submitted."
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | ACTIVITY LOG
            |--------------------------------------------------------------------------
            | Do NOT store submitted sensitive form values here.
            */

            $stmt = $pdo->prepare("
                INSERT INTO activity_logs (
                    user_id,
                    request_id,
                    action,
                    description
                )
                VALUES (?, ?, ?, ?)
            ");

            $stmt->execute([
                $userId,
                $requestId,
                "submit_service_request",
                "Client submitted service request " .
                $requestCode .
                "."
            ]);

            /*
            |--------------------------------------------------------------------------
            | COMMIT
            |--------------------------------------------------------------------------
            */

            $pdo->commit();

            header(
                "Location: request_tracker.php?id=" .
                $requestId
            );

            exit;

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] =
                $e->getMessage();
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

    <title>
        <?= htmlspecialchars($service["name"]) ?>
        - Subnext
    </title>

    <!-- Precompiled Production Stylesheet -->
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f5f7fb;
            color: #222;
        }

        .container {
            max-width: 750px;
            margin: 30px auto;
            padding: 15px;
        }

        .card {
            background: #fff;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,.06);
        }

        h1 {
            margin-top: 0;
        }

        .description {
            color: #666;
            line-height: 1.6;
        }

        .price {
            font-size: 22px;
            font-weight: bold;
            margin: 20px 0;
            color: #3E37B7;
        }

        .old-price {
            color: #999;
            text-decoration: line-through;
            font-size: 15px;
            margin-right: 8px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        label {
            display: block;
            font-weight: bold;
            margin-bottom: 7px;
        }

        input,
        textarea,
        select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 7px;
            font-size: 15px;
            background: #fff;
        }

        textarea {
            min-height: 110px;
            resize: vertical;
        }

        input:focus,
        textarea:focus,
        select:focus {
            outline: none;
            border-color: #3E37B7;
        }

        .required {
            color: #d00;
        }

        .error {
            background: #fdeaea;
            color: #a52222;
            padding: 12px;
            border-radius: 7px;
            margin-bottom: 20px;
        }

        .error p {
            margin: 5px 0;
        }

        .button {
            border: none;
            background: #3E37B7;
            color: white;
            padding: 13px 20px;
            border-radius: 7px;
            cursor: pointer;
            font-size: 15px;
            width: 100%;
        }

        .back {
            display: block;
            text-align: center;
            margin-top: 12px;
            color: #555;
            text-decoration: none;
        }

        .notice {
            background: #f0efff;
            padding: 12px;
            border-radius: 7px;
            margin-bottom: 20px;
            color: #38328e;
        }

    </style>

</head>

<body class="min-h-screen bg-slate-50 text-slate-900">

<!-- Desktop Navigation Header -->
<header class="hidden md:block sticky top-0 z-40 border-b border-slate-200/80 bg-white/95 backdrop-blur">
    <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6">
        <a href="dashboard.php" class="flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-servora-700 text-lg font-black text-white shadow-sm">S</div>
            <div>
                <div class="text-lg font-bold tracking-tight text-slate-900 leading-tight">Subnext</div>
                <div class="text-[10px] font-medium text-slate-400 uppercase tracking-wider">Service Request</div>
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
        <a href="services.php" class="text-sm font-semibold text-white/70 hover:text-white transition">
            ← Services
        </a>
        <div class="mt-6">
            <p class="text-sm font-semibold text-white/70">
                Subnext Assistance
            </p>
            <h1 class="mt-1 text-3xl font-black">
                <?= htmlspecialchars($service["name"], ENT_QUOTES, "UTF-8") ?>
            </h1>
            <p class="mt-2 max-w-xl text-sm text-white/70">
                Provide the required details below to place this service request.
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

    <div class="card mt-6">

        <h1 class="text-2xl font-black text-slate-900 mb-2">
            <?= htmlspecialchars($service["name"]) ?>
        </h1>

        <?php if (!empty($service["description"])): ?>

            <div class="description">
                <?= nl2br(htmlspecialchars($service["description"])) ?>
            </div>

        <?php endif; ?>


        <div class="price">

            <?php if (
                (int) $service["promo_active"] === 1
                && $service["promo_price"] !== null
            ): ?>

                <span class="old-price">
                    ₦<?= number_format(
                        (float) $service["regular_price"],
                        2
                    ) ?>
                </span>

            <?php endif; ?>

            ₦<?= number_format($amount, 2) ?>

        </div>


        <div class="notice">
            The service fee will be deducted from your wallet
            when you submit this request.
        </div>


        <?php if (!empty($errors)): ?>

            <div class="error">

                <?php foreach ($errors as $error): ?>

                    <p>
                        <?= htmlspecialchars($error) ?>
                    </p>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>


        <form method="POST">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($csrfToken) ?>"
            >

            <?php foreach ($fields as $field): ?>

                <?php

                $fieldName = $field["field_name"];
                $fieldLabel = $field["field_label"];
                $fieldType = $field["field_type"];
                $placeholder = $field["placeholder"] ?? "";
                $required = (int) $field["is_required"] === 1;

                $value = $formData[$fieldName] ?? "";

                ?>

                <div class="form-group">

                    <label for="<?= htmlspecialchars($fieldName) ?>">

                        <?= htmlspecialchars($fieldLabel) ?>

                        <?php if ($required): ?>

                            <span class="required">*</span>

                        <?php endif; ?>

                    </label>


                    <?php if ($fieldType === "textarea"): ?>

                        <textarea
                            id="<?= htmlspecialchars($fieldName) ?>"
                            name="<?= htmlspecialchars($fieldName) ?>"
                            placeholder="<?= htmlspecialchars($placeholder) ?>"
                            <?= $required ? "required" : "" ?>
                        ><?= htmlspecialchars($value) ?></textarea>


                    <?php elseif ($fieldType === "select"): ?>

                        <select
                            id="<?= htmlspecialchars($fieldName) ?>"
                            name="<?= htmlspecialchars($fieldName) ?>"
                            <?= $required ? "required" : "" ?>
                        >

                            <option value="">
                                Select <?= htmlspecialchars($fieldLabel) ?>
                            </option>

                            <?php

                            $options = preg_split(
                                "/\r\n|\r|\n/",
                                $field["options"] ?? ""
                            );

                            foreach ($options as $option):

                                $option = trim($option);

                                if ($option === "") {
                                    continue;
                                }

                            ?>

                                <option
                                    value="<?= htmlspecialchars($option) ?>"
                                    <?= $value === $option ? "selected" : "" ?>
                                >
                                    <?= htmlspecialchars($option) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>


                    <?php else: ?>

                        <input
                            type="<?= htmlspecialchars($fieldType) ?>"
                            id="<?= htmlspecialchars($fieldName) ?>"
                            name="<?= htmlspecialchars($fieldName) ?>"
                            value="<?= htmlspecialchars($value) ?>"
                            placeholder="<?= htmlspecialchars($placeholder) ?>"
                            <?= $required ? "required" : "" ?>
                        >

                    <?php endif; ?>

                </div>

            <?php endforeach; ?>


            <?php if (empty($fields)): ?>

                <div class="notice">
                    This service currently has no form fields configured.
                    Please contact the administrator.
                </div>

            <?php else: ?>

                <button
                    type="submit"
                    class="button"
                >
                    Submit Request — ₦<?= number_format($amount, 2) ?>
                </button>

            <?php endif; ?>

        </form>


        <a
            href="services.php"
            class="back"
        >
            ← Back to Services
        </a>

    </div>

</main>

<?php require_once __DIR__ . "/../includes/client_bottom_nav.php"; ?>
</body>

</html>