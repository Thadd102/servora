
<?php

require_once "../config/database.php";
require_once "../includes/admin_auth.php";

/*
|--------------------------------------------------------------------------
| CSRF TOKEN
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/*
|--------------------------------------------------------------------------
| GET FIELD ID
|--------------------------------------------------------------------------
*/

$fieldId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$fieldId) {
    die("Invalid field ID.");
}

/*
|--------------------------------------------------------------------------
| FETCH FIELD
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        sf.*,
        s.name AS service_name
    FROM service_fields sf
    INNER JOIN services s
        ON s.id = sf.service_id
    WHERE sf.id = ?
    LIMIT 1
");

$stmt->execute([$fieldId]);

$field = $stmt->fetch();

if (!$field) {
    die("Service field not found.");
}

/*
|--------------------------------------------------------------------------
| DEFAULT VALUES
|--------------------------------------------------------------------------
*/

$fieldName   = $field['field_name'];
$fieldLabel  = $field['field_label'];
$fieldType   = $field['field_type'];
$placeholder = $field['placeholder'];
$options     = $field['options'];
$isRequired  = (int) $field['is_required'];
$fieldOrder  = (int) $field['field_order'];

$errors = [];
$success = "";

/*
|--------------------------------------------------------------------------
| UPDATE FIELD
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /*
    |--------------------------------------------------------------------------
    | CSRF CHECK
    |--------------------------------------------------------------------------
    */

    if (
        empty($_POST['csrf_token']) ||
        !hash_equals($csrfToken, $_POST['csrf_token'])
    ) {
        $errors[] = "Invalid request. Please refresh the page and try again.";
    }

    /*
    |--------------------------------------------------------------------------
    | GET FORM VALUES
    |--------------------------------------------------------------------------
    */

    $fieldName = trim($_POST['field_name'] ?? '');
    $fieldLabel = trim($_POST['field_label'] ?? '');
    $fieldType = $_POST['field_type'] ?? 'text';
    $placeholder = trim($_POST['placeholder'] ?? '');
    $options = trim($_POST['options'] ?? '');

    $isRequired = isset($_POST['is_required']) ? 1 : 0;

    $fieldOrder = filter_input(
        INPUT_POST,
        'field_order',
        FILTER_VALIDATE_INT
    );

    /*
    |--------------------------------------------------------------------------
    | VALIDATION
    |--------------------------------------------------------------------------
    */

    if ($fieldName === '') {

        $errors[] = "Field name is required.";

    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $fieldName)) {

        $errors[] = "Field name can only contain letters, numbers and underscores.";

    }


    if ($fieldLabel === '') {

        $errors[] = "Field label is required.";

    }


    $allowedTypes = [
        'text',
        'number',
        'email',
        'tel',
        'date',
        'textarea',
        'select'
    ];


    if (!in_array($fieldType, $allowedTypes, true)) {

        $errors[] = "Invalid field type.";

    }


    if ($fieldOrder === false || $fieldOrder < 0) {

        $errors[] = "Field order must be a valid number.";

    }


    /*
    |--------------------------------------------------------------------------
    | SELECT OPTIONS
    |--------------------------------------------------------------------------
    */

    if ($fieldType === 'select') {

        if ($options === '') {

            $errors[] = "Please provide options for the select field.";

        }

    } else {

        $options = null;

    }


    /*
    |--------------------------------------------------------------------------
    | CHECK DUPLICATE FIELD NAME
    |--------------------------------------------------------------------------
    */

    if (empty($errors)) {

        $duplicateStmt = $pdo->prepare("
            SELECT id
            FROM service_fields
            WHERE service_id = ?
            AND field_name = ?
            AND id != ?
            LIMIT 1
        ");

        $duplicateStmt->execute([
            $field['service_id'],
            $fieldName,
            $fieldId
        ]);

        if ($duplicateStmt->fetch()) {

            $errors[] = "Another field with this name already exists for this service.";

        }

    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE DATABASE
    |--------------------------------------------------------------------------
    */

    if (empty($errors)) {

        try {

            $stmt = $pdo->prepare("
                UPDATE service_fields
                SET
                    field_name = ?,
                    field_label = ?,
                    field_type = ?,
                    placeholder = ?,
                    options = ?,
                    is_required = ?,
                    field_order = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $fieldName,
                $fieldLabel,
                $fieldType,
                $placeholder !== '' ? $placeholder : null,
                $options,
                $isRequired,
                $fieldOrder,
                $fieldId
            ]);


            /*
            |--------------------------------------------------------------------------
            | REDIRECT AFTER SUCCESS
            |--------------------------------------------------------------------------
            */

            header(
                "Location: service_fields.php?id=" .
                (int) $field['service_id'] .
                "&updated=1"
            );

            exit;

        } catch (PDOException $e) {

            $errors[] = "Unable to update the service field. Please try again.";

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

    <title>Edit Service Field | Subnext</title>


    <!-- Precompiled Production Stylesheet -->
    <link rel="stylesheet" href="../assets/css/style.css">

</head>


<body class="min-h-screen bg-slate-50 text-slate-900">


<main class="mx-auto w-full max-w-3xl px-4 py-6 sm:px-6 sm:py-8 lg:px-8">


    <!-- Back -->

    <a
        href="service_fields.php?id=<?= (int) $field['service_id'] ?>"
        class="mb-6 inline-flex items-center gap-2 text-sm font-semibold text-slate-500 transition hover:text-servora-700"
    >

        <span class="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-white">
            ←
        </span>

        Back to Service Fields

    </a>



    <!-- Page Header -->

    <div class="mb-6">

        <div class="mb-3 inline-flex items-center rounded-full bg-servora-50 px-3 py-1 text-xs font-bold text-servora-700">
            Form Configuration
        </div>


        <h1 class="text-2xl font-black tracking-tight text-slate-900 sm:text-3xl">
            Edit Service Field
        </h1>


        <p class="mt-1 text-sm leading-6 text-slate-500">
            Update how this field appears and behaves on the client's request form.
        </p>

    </div>



    <!-- Service Information -->

    <div class="mb-5 rounded-2xl border border-servora-100 bg-servora-50 p-4">

        <p class="text-[11px] font-bold uppercase tracking-wider text-servora-600">
            Service
        </p>

        <div class="mt-1 flex flex-wrap items-center justify-between gap-3">

            <h2 class="text-base font-black text-servora-900">
                <?= htmlspecialchars($field['service_name']) ?>
            </h2>

            <span class="rounded-full bg-white px-3 py-1 text-xs font-bold text-servora-700 shadow-sm">
                Field #<?= (int) $fieldId ?>
            </span>

        </div>

    </div>



    <!-- Errors -->

    <?php if (!empty($errors)): ?>

        <div class="mb-5 rounded-2xl border border-red-100 bg-red-50 p-4">

            <div class="flex gap-3">

                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-red-100 text-sm font-black text-red-600">
                    !
                </div>


                <div class="min-w-0">

                    <p class="text-sm font-bold text-red-800">
                        Please correct the following:
                    </p>


                    <ul class="mt-2 space-y-1 text-sm text-red-700">

                        <?php foreach ($errors as $error): ?>

                            <li>
                                <?= htmlspecialchars($error) ?>
                            </li>

                        <?php endforeach; ?>

                    </ul>

                </div>

            </div>

        </div>

    <?php endif; ?>



    <!-- Main Form -->

    <form
        method="POST"
        class="space-y-5"
    >

        <input
            type="hidden"
            name="csrf_token"
            value="<?= htmlspecialchars($csrfToken) ?>"
        >



        <!-- Field Identity -->

        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">

            <div class="border-b border-slate-100 px-5 py-5 sm:px-6">

                <h2 class="text-base font-black text-slate-900">
                    Field Information
                </h2>

                <p class="mt-1 text-sm text-slate-500">
                    Define the internal name and label customers will see.
                </p>

            </div>


            <div class="space-y-5 p-5 sm:p-6">


                <!-- Field Name -->

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
                        value="<?= htmlspecialchars($fieldName) ?>"
                        required
                        autocomplete="off"
                        class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3.5 text-sm text-slate-900 outline-none transition focus:border-servora-500 focus:bg-white focus:ring-4 focus:ring-servora-100"
                    >


                    <p class="mt-2 text-xs leading-5 text-slate-400">
                        Internal name. Use letters, numbers and underscores.
                        Example: <span class="font-semibold">nin_number</span>
                    </p>

                </div>



                <!-- Field Label -->

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
                        value="<?= htmlspecialchars($fieldLabel) ?>"
                        required
                        class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3.5 text-sm text-slate-900 outline-none transition focus:border-servora-500 focus:bg-white focus:ring-4 focus:ring-servora-100"
                    >


                    <p class="mt-2 text-xs leading-5 text-slate-400">
                        This is the visible label shown to the client.
                    </p>

                </div>


            </div>

        </section>



        <!-- Field Type -->

        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">

            <div class="border-b border-slate-100 px-5 py-5 sm:px-6">

                <h2 class="text-base font-black text-slate-900">
                    Field Configuration
                </h2>

                <p class="mt-1 text-sm text-slate-500">
                    Choose what type of information this field should collect.
                </p>

            </div>


            <div class="space-y-5 p-5 sm:p-6">


                <!-- Field Type -->

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
                        required
                        class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3.5 text-sm font-semibold text-slate-900 outline-none transition focus:border-servora-500 focus:bg-white focus:ring-4 focus:ring-servora-100"
                    >

                        <option
                            value="text"
                            <?= $fieldType === 'text' ? 'selected' : '' ?>
                        >
                            Text
                        </option>


                        <option
                            value="number"
                            <?= $fieldType === 'number' ? 'selected' : '' ?>
                        >
                            Number
                        </option>


                        <option
                            value="email"
                            <?= $fieldType === 'email' ? 'selected' : '' ?>
                        >
                            Email
                        </option>


                        <option
                            value="tel"
                            <?= $fieldType === 'tel' ? 'selected' : '' ?>
                        >
                            Phone
                        </option>


                        <option
                            value="date"
                            <?= $fieldType === 'date' ? 'selected' : '' ?>
                        >
                            Date
                        </option>


                        <option
                            value="textarea"
                            <?= $fieldType === 'textarea' ? 'selected' : '' ?>
                        >
                            Textarea
                        </option>


                        <option
                            value="select"
                            <?= $fieldType === 'select' ? 'selected' : '' ?>
                        >
                            Select / Dropdown
                        </option>

                    </select>

                </div>



                <!-- Placeholder -->

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
                        value="<?= htmlspecialchars($placeholder ?? '') ?>"
                        placeholder="Example: Enter your meter number"
                        class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-servora-500 focus:bg-white focus:ring-4 focus:ring-servora-100"
                    >


                    <p class="mt-2 text-xs text-slate-400">
                        Helpful text displayed inside the input before the client types.
                    </p>

                </div>



                <!-- Options -->

                <div
                    id="optionsGroup"
                    class="rounded-2xl border border-servora-100 bg-servora-50 p-4"
                >

                    <label
                        for="options"
                        class="mb-2 block text-sm font-bold text-slate-800"
                    >
                        Dropdown Options
                    </label>


                    <textarea
                        id="options"
                        name="options"
                        rows="4"
                        placeholder="Example: Male, Female"
                        class="w-full resize-y rounded-xl border border-slate-200 bg-white px-4 py-3.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-servora-500 focus:ring-4 focus:ring-servora-100"
                    ><?= htmlspecialchars($options ?? '') ?></textarea>


                    <p class="mt-2 text-xs leading-5 text-slate-500">
                        Only required for Select / Dropdown fields.
                        Separate each option with a comma.
                    </p>

                </div>


            </div>

        </section>



        <!-- Ordering -->

        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">

            <div class="border-b border-slate-100 px-5 py-5 sm:px-6">

                <h2 class="text-base font-black text-slate-900">
                    Display Settings
                </h2>

                <p class="mt-1 text-sm text-slate-500">
                    Control where this field appears on the request form.
                </p>

            </div>


            <div class="space-y-5 p-5 sm:p-6">


                <!-- Field Order -->

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
                        value="<?= $fieldOrder ?>"
                        min="0"
                        required
                        class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3.5 text-sm font-semibold text-slate-900 outline-none transition focus:border-servora-500 focus:bg-white focus:ring-4 focus:ring-servora-100"
                    >


                    <p class="mt-2 text-xs text-slate-400">
                        Smaller numbers appear first on the client's form.
                    </p>

                </div>



                <!-- Required -->

                <label class="flex cursor-pointer items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 transition hover:border-servora-200 hover:bg-servora-50">

                    <input
                        type="checkbox"
                        name="is_required"
                        value="1"
                        <?= $isRequired ? 'checked' : '' ?>
                        class="mt-1 h-4 w-4 shrink-0 rounded border-slate-300 text-servora-700 focus:ring-servora-500"
                    >


                    <span>

                        <span class="block text-sm font-bold text-slate-800">
                            Required field
                        </span>

                        <span class="mt-1 block text-xs leading-5 text-slate-500">
                            Clients must provide a value before submitting their request.
                        </span>

                    </span>

                </label>


            </div>

        </section>



        <!-- Actions -->

        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">

            <a
                href="service_fields.php?id=<?= (int) $field['service_id'] ?>"
                class="flex min-h-12 items-center justify-center rounded-xl border border-slate-200 bg-white px-6 text-sm font-bold text-slate-600 transition hover:border-slate-300 hover:bg-slate-50"
            >
                Cancel
            </a>


            <button
                type="submit"
                class="flex min-h-12 items-center justify-center rounded-xl bg-servora-700 px-7 text-sm font-bold text-white shadow-sm transition hover:bg-servora-800 focus:outline-none focus:ring-4 focus:ring-servora-100"
            >
                Save Changes
            </button>

        </div>


    </form>



    <!-- Footer -->

    <div class="mt-8 border-t border-slate-200 pt-5 text-center">

        <p class="text-xs text-slate-400">
            Subnext Admin · Form Configuration
        </p>

    </div>


</main>



<script>

    const fieldType = document.getElementById("field_type");
    const optionsGroup = document.getElementById("optionsGroup");

    function toggleOptions() {

        if (fieldType.value === "select") {

            optionsGroup.style.display = "block";

        } else {

            optionsGroup.style.display = "none";

        }

    }

    fieldType.addEventListener("change", toggleOptions);

    toggleOptions();

</script>


</body>

</html>
```
