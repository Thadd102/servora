<?php

require_once "../config/database.php";
require_once "../includes/admin_auth.php";

// CSRF token
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION["csrf_token"];

$error = "";
$success = "";

// --------------------------------------------------
// Get service ID
// --------------------------------------------------

$serviceId = filter_input(
    INPUT_GET,
    "id",
    FILTER_VALIDATE_INT
);

if (!$serviceId) {
    die("Invalid service.");
}

// --------------------------------------------------
// Get service
// --------------------------------------------------

$stmt = $pdo->prepare("
    SELECT *
    FROM services
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$serviceId]);

$service = $stmt->fetch();

if (!$service) {
    die("Service not found.");
}

// --------------------------------------------------
// Handle POST
// --------------------------------------------------

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // CSRF check
    if (
        empty($_POST["csrf_token"]) ||
        !hash_equals(
            $_SESSION["csrf_token"],
            $_POST["csrf_token"]
        )
    ) {

        $error = "Invalid request.";

    } else {

        $action = $_POST["action"] ?? "";

        // ==========================================
        // ADD FIELD
        // ==========================================

        if ($action === "add_field") {

            $fieldName = trim(
                $_POST["field_name"] ?? ""
            );

            $fieldLabel = trim(
                $_POST["field_label"] ?? ""
            );

            $fieldType = $_POST["field_type"] ?? "text";

            $placeholder = trim(
                $_POST["placeholder"] ?? ""
            );

            $options = trim(
                $_POST["options"] ?? ""
            );

            $isRequired = isset($_POST["is_required"])
                ? 1
                : 0;

            $fieldOrder = filter_input(
                INPUT_POST,
                "field_order",
                FILTER_VALIDATE_INT
            );

            if ($fieldOrder === false || $fieldOrder === null) {
                $fieldOrder = 0;
            }

            // Validate field name
            if (
                $fieldName === "" ||
                !preg_match(
                    '/^[a-zA-Z][a-zA-Z0-9_]*$/',
                    $fieldName
                )
            ) {

                $error =
                    "Field name must contain only letters, numbers and underscores.";

            } elseif ($fieldLabel === "") {

                $error =
                    "Field label is required.";

            } elseif (
                !in_array(
                    $fieldType,
                    [
                        "text",
                        "number",
                        "email",
                        "tel",
                        "date",
                        "textarea",
                        "select"
                    ],
                    true
                )
            ) {

                $error = "Invalid field type.";

            } elseif (
                $fieldType === "select" &&
                $options === ""
            ) {

                $error =
                    "Enter options for the select field.";

            } else {

                try {

                    // Prevent duplicate field names
                    $check = $pdo->prepare("
                        SELECT id
                        FROM service_fields
                        WHERE service_id = ?
                        AND field_name = ?
                        LIMIT 1
                    ");

                    $check->execute([
                        $serviceId,
                        $fieldName
                    ]);

                    if ($check->fetch()) {

                        $error =
                            "This field name already exists.";

                    } else {

                        $stmt = $pdo->prepare("
                            INSERT INTO service_fields
                            (
                                service_id,
                                field_name,
                                field_label,
                                field_type,
                                placeholder,
                                options,
                                is_required,
                                field_order
                            )
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ");

                        $stmt->execute([
                            $serviceId,
                            $fieldName,
                            $fieldLabel,
                            $fieldType,
                            $placeholder !== ""
                                ? $placeholder
                                : null,
                            $options !== ""
                                ? $options
                                : null,
                            $isRequired,
                            $fieldOrder
                        ]);

                        $success =
                            "Field added successfully.";
                    }

                } catch (Throwable $e) {

                    $error =
                        "Unable to add field.";
                }
            }
        }

        // ==========================================
        // DELETE FIELD
        // ==========================================

        elseif ($action === "delete_field") {

            $fieldId = filter_input(
                INPUT_POST,
                "field_id",
                FILTER_VALIDATE_INT
            );

            if (!$fieldId) {

                $error = "Invalid field.";

            } else {

                try {

                    $stmt = $pdo->prepare("
                        DELETE FROM service_fields
                        WHERE id = ?
                        AND service_id = ?
                    ");

                    $stmt->execute([
                        $fieldId,
                        $serviceId
                    ]);

                    if ($stmt->rowCount() > 0) {

                        $success =
                            "Field deleted successfully.";

                    } else {

                        $error =
                            "Field not found.";
                    }

                } catch (Throwable $e) {

                    $error =
                        "Unable to delete field.";
                }
            }
        }
    }
}

// --------------------------------------------------
// Get fields
// --------------------------------------------------

$stmt = $pdo->prepare("
    SELECT *
    FROM service_fields
    WHERE service_id = ?
    ORDER BY field_order ASC, id ASC
");

$stmt->execute([$serviceId]);

$fields = $stmt->fetchAll();

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
        Define Fields | Servora
    </title>

    <script src="https://cdn.tailwindcss.com"></script>

    <script>

        tailwind.config = {

            theme: {

                extend: {

                    colors: {

                        servora: {

                            50: "#F5F3FF",
                            100: "#EDE9FE",
                            200: "#DDD6FE",
                            500: "#635BDB",
                            600: "#5146C7",
                            700: "#3E37B7",
                            800: "#312E81",
                            900: "#1E1B4B"

                        }

                    }

                }

            }

        };

    </script>

</head>


<body class="min-h-screen bg-slate-50 text-slate-900">


<!-- PAGE WRAPPER -->

<div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8 lg:py-10">


    <!-- =========================================
         HEADER
    ========================================== -->

    <div class="mb-8">

        <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">

            <div>

                <div class="mb-3 flex items-center gap-3">

                    <a
                        href="services.php"
                        class="flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 shadow-sm transition hover:border-servora-200 hover:bg-servora-50 hover:text-servora-700"
                        aria-label="Back to services"
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
                        Service Configuration
                    </span>

                </div>


                <h1 class="text-2xl font-black tracking-tight text-slate-900 sm:text-3xl">
                    Define Service Fields
                </h1>

                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-500 sm:text-base">
                    Create the information clients must provide when requesting this service.
                </p>

            </div>


            <!-- SERVICE BADGE -->

            <div class="rounded-2xl border border-servora-100 bg-servora-50 px-4 py-3">

                <p class="text-[11px] font-bold uppercase tracking-wider text-servora-500">
                    Current Service
                </p>

                <p class="mt-1 font-bold text-servora-800">
                    <?= htmlspecialchars($service["name"]) ?>
                </p>

            </div>

        </div>

    </div>


    <!-- =========================================
         ALERTS
    ========================================== -->

    <?php if ($success): ?>

        <div class="mb-6 flex items-start gap-3 rounded-2xl border border-emerald-100 bg-emerald-50 px-4 py-4 text-emerald-700">

            <div class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-emerald-100">

                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="h-4 w-4"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="2.5"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M5 13l4 4L19 7"
                    />
                </svg>

            </div>

            <div>

                <p class="font-bold">
                    Success
                </p>

                <p class="mt-0.5 text-sm">
                    <?= htmlspecialchars($success) ?>
                </p>

            </div>

        </div>

    <?php endif; ?>


    <?php if ($error): ?>

        <div class="mb-6 flex items-start gap-3 rounded-2xl border border-red-100 bg-red-50 px-4 py-4 text-red-700">

            <div class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-red-100">

                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="h-4 w-4"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="2.5"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M6 18L18 6M6 6l12 12"
                    />
                </svg>

            </div>

            <div>

                <p class="font-bold">
                    Something went wrong
                </p>

                <p class="mt-0.5 text-sm">
                    <?= htmlspecialchars($error) ?>
                </p>

            </div>

        </div>

    <?php endif; ?>


    <!-- =========================================
         ADD FIELD CARD
    ========================================== -->

    <div class="mb-8 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">


        <!-- CARD HEADER -->

        <div class="border-b border-slate-100 bg-gradient-to-r from-servora-50 to-white px-5 py-6 sm:px-7">

            <div class="flex items-start gap-4">

                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-servora-700 text-white shadow-sm">

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
                            d="M12 4v16m8-8H4"
                        />
                    </svg>

                </div>


                <div>

                    <h2 class="text-lg font-black text-slate-900">
                        Add Form Field
                    </h2>

                    <p class="mt-1 text-sm leading-6 text-slate-500">
                        Define a new piece of information the client must provide.
                    </p>

                </div>

            </div>

        </div>


        <!-- FORM -->

        <form method="POST" class="p-5 sm:p-7">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($csrfToken) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="add_field"
            >


            <div class="grid gap-6 md:grid-cols-2">


                <!-- FIELD NAME -->

                <div>

                    <label
                        for="field_name"
                        class="mb-2 block text-sm font-bold text-slate-700"
                    >
                        Field Name
                    </label>

                    <input
                        type="text"
                        id="field_name"
                        name="field_name"
                        placeholder="e.g. meter_number"
                        required
                        autocomplete="off"
                        class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-servora-500 focus:ring-4 focus:ring-servora-100"
                    >

                    <p class="mt-2 text-xs leading-5 text-slate-400">
                        Use letters, numbers and underscores. Example: <span class="font-semibold">meter_number</span>
                    </p>

                </div>


                <!-- FIELD LABEL -->

                <div>

                    <label
                        for="field_label"
                        class="mb-2 block text-sm font-bold text-slate-700"
                    >
                        Field Label
                    </label>

                    <input
                        type="text"
                        id="field_label"
                        name="field_label"
                        placeholder="e.g. Meter Number"
                        required
                        class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-servora-500 focus:ring-4 focus:ring-servora-100"
                    >

                    <p class="mt-2 text-xs text-slate-400">
                        This is what the client will see on the form.
                    </p>

                </div>


                <!-- FIELD TYPE -->

                <div>

                    <label
                        for="field_type"
                        class="mb-2 block text-sm font-bold text-slate-700"
                    >
                        Field Type
                    </label>

                    <select
                        name="field_type"
                        id="field_type"
                        onchange="toggleOptions()"
                        class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-servora-500 focus:ring-4 focus:ring-servora-100"
                    >

                        <option value="text">
                            Text
                        </option>

                        <option value="number">
                            Number
                        </option>

                        <option value="email">
                            Email
                        </option>

                        <option value="tel">
                            Phone
                        </option>

                        <option value="date">
                            Date
                        </option>

                        <option value="textarea">
                            Long Text
                        </option>

                        <option value="select">
                            Dropdown
                        </option>

                    </select>

                </div>


                <!-- PLACEHOLDER -->

                <div>

                    <label
                        for="placeholder"
                        class="mb-2 block text-sm font-bold text-slate-700"
                    >
                        Placeholder
                    </label>

                    <input
                        type="text"
                        id="placeholder"
                        name="placeholder"
                        placeholder="e.g. Enter your meter number"
                        class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-servora-500 focus:ring-4 focus:ring-servora-100"
                    >

                    <p class="mt-2 text-xs text-slate-400">
                        Optional hint shown inside the input.
                    </p>

                </div>


                <!-- DROPDOWN OPTIONS -->

                <div
                    id="options_box"
                    class="hidden md:col-span-2"
                >

                    <div class="rounded-2xl border border-servora-100 bg-servora-50/60 p-4 sm:p-5">

                        <label
                            for="options"
                            class="mb-2 block text-sm font-bold text-slate-700"
                        >
                            Dropdown Options
                        </label>

                        <textarea
                            id="options"
                            name="options"
                            rows="5"
                            placeholder="Male&#10;Female"
                            class="w-full resize-y rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-servora-500 focus:ring-4 focus:ring-servora-100"
                        ></textarea>

                        <p class="mt-2 text-xs text-slate-500">
                            Enter one option per line.
                        </p>

                    </div>

                </div>


                <!-- FIELD ORDER -->

                <div>

                    <label
                        for="field_order"
                        class="mb-2 block text-sm font-bold text-slate-700"
                    >
                        Field Order
                    </label>

                    <input
                        type="number"
                        id="field_order"
                        name="field_order"
                        value="0"
                        min="0"
                        class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-servora-500 focus:ring-4 focus:ring-servora-100"
                    >

                    <p class="mt-2 text-xs text-slate-400">
                        Lower numbers appear first.
                    </p>

                </div>


                <!-- REQUIRED -->

                <div class="flex items-center">

                    <label class="flex w-full cursor-pointer items-center justify-between rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 transition hover:border-servora-200 hover:bg-servora-50">

                        <div>

                            <p class="text-sm font-bold text-slate-800">
                                Required field
                            </p>

                            <p class="mt-1 text-xs text-slate-400">
                                Client must complete this field.
                            </p>

                        </div>


                        <div class="relative">

                            <input
                                type="checkbox"
                                name="is_required"
                                id="is_required"
                                checked
                                class="peer sr-only"
                            >

                            <div class="h-6 w-11 rounded-full bg-slate-300 transition peer-checked:bg-servora-700"></div>

                            <div class="absolute left-1 top-1 h-4 w-4 rounded-full bg-white shadow-sm transition peer-checked:translate-x-5"></div>

                        </div>

                    </label>

                </div>

            </div>


            <!-- ACTIONS -->

            <div class="mt-7 flex flex-col-reverse gap-3 border-t border-slate-100 pt-6 sm:flex-row sm:justify-end">

                <a
                    href="services.php"
                    class="inline-flex min-h-12 items-center justify-center rounded-xl border border-slate-200 bg-white px-5 text-sm font-bold text-slate-600 transition hover:bg-slate-50"
                >
                    Cancel
                </a>


                <button
                    type="submit"
                    class="inline-flex min-h-12 items-center justify-center rounded-xl bg-servora-700 px-6 text-sm font-bold text-white shadow-sm transition hover:bg-servora-800 focus:outline-none focus:ring-4 focus:ring-servora-100"
                >
                    Add Field
                </button>

            </div>

        </form>

    </div>


    <!-- =========================================
         EXISTING FIELDS
    ========================================== -->

    <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">


        <!-- HEADER -->

        <div class="border-b border-slate-100 px-5 py-6 sm:px-7">

            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">

                <div>

                    <h2 class="text-lg font-black text-slate-900">
                        Service Fields
                    </h2>

                    <p class="mt-1 text-sm text-slate-500">
                        Fields currently used by this service.
                    </p>

                </div>


                <div class="inline-flex w-fit items-center rounded-full bg-servora-50 px-3 py-1.5 text-xs font-bold text-servora-700">

                    <?= count($fields) ?>

                    <?= count($fields) === 1 ? "field" : "fields" ?>

                </div>

            </div>

        </div>


        <?php if (!$fields): ?>


            <!-- EMPTY STATE -->

            <div class="px-5 py-14 text-center sm:px-7">

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
                    No fields added yet
                </h3>

                <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500">
                    Add the fields your clients need to complete when requesting this service.
                </p>

            </div>


        <?php else: ?>


            <!-- =========================================
                 MOBILE CARDS
            ========================================== -->

            <div class="space-y-3 p-4 lg:hidden">

                <?php foreach ($fields as $field): ?>

                    <div class="rounded-2xl border border-slate-200 bg-white p-4">

                        <div class="flex items-start justify-between gap-4">

                            <div class="min-w-0">

                                <div class="flex flex-wrap items-center gap-2">

                                    <h3 class="font-bold text-slate-900">
                                        <?= htmlspecialchars($field["field_label"]) ?>
                                    </h3>

                                    <?php if ($field["is_required"]): ?>

                                        <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-[10px] font-bold text-emerald-700">
                                            Required
                                        </span>

                                    <?php else: ?>

                                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-bold text-slate-500">
                                            Optional
                                        </span>

                                    <?php endif; ?>

                                </div>


                                <p class="mt-1 font-mono text-xs text-slate-400">
                                    <?= htmlspecialchars($field["field_name"]) ?>
                                </p>

                            </div>


                            <span class="shrink-0 rounded-lg bg-servora-50 px-2.5 py-1.5 text-xs font-bold capitalize text-servora-700">
                                <?= htmlspecialchars($field["field_type"]) ?>
                            </span>

                        </div>


                        <div class="mt-4 grid grid-cols-2 gap-3">

                            <div class="rounded-xl bg-slate-50 p-3">

                                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                                    Order
                                </p>

                                <p class="mt-1 text-sm font-bold text-slate-700">
                                    <?= (int) $field["field_order"] ?>
                                </p>

                            </div>


                            <div class="rounded-xl bg-slate-50 p-3">

                                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                                    Field ID
                                </p>

                                <p class="mt-1 text-sm font-bold text-slate-700">
                                    #<?= (int) $field["id"] ?>
                                </p>

                            </div>

                        </div>


                        <div class="mt-4 flex gap-2">

                            <a
                                href="edit_service_field.php?id=<?= (int) $field["id"] ?>"
                                class="flex min-h-11 flex-1 items-center justify-center rounded-xl bg-servora-50 px-4 text-sm font-bold text-servora-700 transition hover:bg-servora-100"
                            >
                                Edit
                            </a>


                            <form
                                method="POST"
                                class="flex-1"
                                onsubmit="return confirm('Delete this field?');"
                            >

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= htmlspecialchars($csrfToken) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="delete_field"
                                >

                                <input
                                    type="hidden"
                                    name="field_id"
                                    value="<?= (int) $field["id"] ?>"
                                >

                                <button
                                    type="submit"
                                    class="min-h-11 w-full rounded-xl bg-red-50 px-4 text-sm font-bold text-red-600 transition hover:bg-red-100"
                                >
                                    Delete
                                </button>

                            </form>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>


            <!-- =========================================
                 DESKTOP TABLE
            ========================================== -->

            <div class="hidden overflow-x-auto lg:block">

                <table class="w-full text-left">

                    <thead>

                        <tr class="border-b border-slate-100 bg-slate-50/70">

                            <th class="px-6 py-4 text-xs font-bold uppercase tracking-wider text-slate-400">
                                Order
                            </th>

                            <th class="px-6 py-4 text-xs font-bold uppercase tracking-wider text-slate-400">
                                Label
                            </th>

                            <th class="px-6 py-4 text-xs font-bold uppercase tracking-wider text-slate-400">
                                Name
                            </th>

                            <th class="px-6 py-4 text-xs font-bold uppercase tracking-wider text-slate-400">
                                Type
                            </th>

                            <th class="px-6 py-4 text-xs font-bold uppercase tracking-wider text-slate-400">
                                Required
                            </th>

                            <th class="px-6 py-4 text-right text-xs font-bold uppercase tracking-wider text-slate-400">
                                Actions
                            </th>

                        </tr>

                    </thead>


                    <tbody class="divide-y divide-slate-100">

                    <?php foreach ($fields as $field): ?>

                        <tr class="transition hover:bg-slate-50/70">


                            <!-- ORDER -->

                            <td class="px-6 py-5">

                                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-slate-100 text-xs font-bold text-slate-600">

                                    <?= (int) $field["field_order"] ?>

                                </span>

                            </td>


                            <!-- LABEL -->

                            <td class="px-6 py-5">

                                <p class="font-bold text-slate-800">
                                    <?= htmlspecialchars($field["field_label"]) ?>
                                </p>

                            </td>


                            <!-- NAME -->

                            <td class="px-6 py-5">

                                <code class="rounded-lg bg-slate-100 px-2.5 py-1.5 text-xs text-slate-600">
                                    <?= htmlspecialchars($field["field_name"]) ?>
                                </code>

                            </td>


                            <!-- TYPE -->

                            <td class="px-6 py-5">

                                <span class="rounded-lg bg-servora-50 px-2.5 py-1.5 text-xs font-bold capitalize text-servora-700">
                                    <?= htmlspecialchars($field["field_type"]) ?>
                                </span>

                            </td>


                            <!-- REQUIRED -->

                            <td class="px-6 py-5">

                                <?php if ($field["is_required"]): ?>

                                    <span class="inline-flex items-center gap-1.5 text-sm font-bold text-emerald-600">

                                        <span class="h-2 w-2 rounded-full bg-emerald-500"></span>

                                        Yes

                                    </span>

                                <?php else: ?>

                                    <span class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-400">

                                        <span class="h-2 w-2 rounded-full bg-slate-300"></span>

                                        No

                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- ACTIONS -->

                            <td class="px-6 py-5">

                                <div class="flex justify-end gap-2">


                                    <a
                                        href="edit_service_field.php?id=<?= (int) $field["id"] ?>"
                                        class="inline-flex min-h-10 items-center justify-center rounded-xl bg-servora-50 px-4 text-xs font-bold text-servora-700 transition hover:bg-servora-100"
                                    >
                                        Edit
                                    </a>


                                    <form
                                        method="POST"
                                        onsubmit="return confirm('Delete this field?');"
                                    >

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= htmlspecialchars($csrfToken) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="delete_field"
                                        >

                                        <input
                                            type="hidden"
                                            name="field_id"
                                            value="<?= (int) $field["id"] ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="inline-flex min-h-10 items-center justify-center rounded-xl bg-red-50 px-4 text-xs font-bold text-red-600 transition hover:bg-red-100"
                                        >
                                            Delete
                                        </button>

                                    </form>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </div>


    <!-- =========================================
         FOOTER
    ========================================== -->

    <div class="py-8 text-center">

        <p class="text-xs text-slate-400">
            Servora Admin
            <span class="mx-1">•</span>
            Service configuration
        </p>

    </div>

</div>


<script>

function toggleOptions() {

    const type =
        document.getElementById("field_type").value;

    const optionsBox =
        document.getElementById("options_box");

    if (type === "select") {

        optionsBox.classList.remove("hidden");

    } else {

        optionsBox.classList.add("hidden");

    }

}

document.addEventListener("DOMContentLoaded", function () {

    toggleOptions();

});

</script>


</body>

</html>