
<?php

require_once "../config/database.php";
require_once "../includes/admin_auth.php";


// Get service ID
$serviceId = (int) ($_GET["id"] ?? 0);

if ($serviceId <= 0) {
    die("Invalid service ID.");
}


// Get service
$stmt = $pdo->prepare("
    SELECT
        id,
        name,
        slug,
        description,
        image,
        regular_price,
        promo_price,
        promo_active,
        status
    FROM services
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$serviceId]);

$service = $stmt->fetch();

if (!$service) {
    die("Service not found.");
}


$message = "";
$error = "";


// Handle form submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    verifyCsrfToken();

    $name = trim($_POST["name"] ?? "");
    $description = trim($_POST["description"] ?? "");

    $regularPrice = (float) ($_POST["regular_price"] ?? 0);

    $promoPriceInput = trim($_POST["promo_price"] ?? "");

    $promoActive = isset($_POST["promo_active"]) ? 1 : 0;

    $status = $_POST["status"] ?? "inactive";


    /*
    |--------------------------------------------------------------------------
    | Validate basic information
    |--------------------------------------------------------------------------
    */

    if ($name === "") {

        $error = "Service name is required.";

    } elseif ($regularPrice <= 0) {

        $error = "Regular price must be greater than zero.";

    } elseif (
        !in_array($status, ["active", "inactive"], true)
    ) {

        $error = "Invalid service status.";

    }


    /*
    |--------------------------------------------------------------------------
    | Promo price
    |--------------------------------------------------------------------------
    */

    $promoPrice = null;

    if ($promoPriceInput !== "") {

        $promoPrice = (float) $promoPriceInput;

        if ($promoPrice <= 0) {

            $error = "Promo price must be greater than zero.";

        } elseif ($promoPrice >= $regularPrice) {

            $error = "Promo price must be less than regular price.";

        }

    }


    /*
    |--------------------------------------------------------------------------
    | Image handling
    |--------------------------------------------------------------------------
    */

    $newImageName = $service["image"];


    if (
        $error === ""
        &&
        isset($_FILES["image"])
        &&
        $_FILES["image"]["error"] !== UPLOAD_ERR_NO_FILE
    ) {

        if ($_FILES["image"]["error"] !== UPLOAD_ERR_OK) {

            $error = "There was a problem uploading the image.";

        } else {

            $fileSize = (int) $_FILES["image"]["size"];

            // Maximum 2MB
            if ($fileSize > 2 * 1024 * 1024) {

                $error = "Image must not be larger than 2MB.";

            } else {

                $tmpFile = $_FILES["image"]["tmp_name"];

                $finfo = new finfo(FILEINFO_MIME_TYPE);

                $mimeType = $finfo->file($tmpFile);


                $allowedTypes = [
                    "image/jpeg" => "jpg",
                    "image/png"  => "png",
                    "image/webp" => "webp"
                ];


                if (!isset($allowedTypes[$mimeType])) {

                    $error = "Only JPG, PNG, and WEBP images are allowed.";

                } else {

                    $extension = $allowedTypes[$mimeType];

                    $newImageName =
                        bin2hex(random_bytes(16))
                        . "."
                        . $extension;


                    $uploadDirectory =
                        __DIR__
                        . "/../uploads/services/";


                    if (!is_dir($uploadDirectory)) {

                        mkdir(
                            $uploadDirectory,
                            0755,
                            true
                        );

                    }


                    $destination =
                        $uploadDirectory
                        . $newImageName;


                    if (
                        !move_uploaded_file(
                            $tmpFile,
                            $destination
                        )
                    ) {

                        $error = "Unable to save the uploaded image.";

                    }

                }

            }

        }

    }


    /*
    |--------------------------------------------------------------------------
    | Update database
    |--------------------------------------------------------------------------
    */

    if ($error === "") {

        try {

            $pdo->beginTransaction();


            $stmt = $pdo->prepare("
                UPDATE services
                SET
                    name = ?,
                    description = ?,
                    regular_price = ?,
                    promo_price = ?,
                    promo_active = ?,
                    status = ?,
                    image = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");


            $stmt->execute([
                $name,
                $description,
                $regularPrice,
                $promoPrice,
                $promoActive,
                $status,
                $newImageName,
                $serviceId
            ]);


            $pdo->commit();


            /*
            |--------------------------------------------------------------------------
            | Delete old image after successful database update
            |--------------------------------------------------------------------------
            */

            if (
                $newImageName !== $service["image"]
                &&
                !empty($service["image"])
            ) {

                $oldImagePath =
                    __DIR__
                    . "/../uploads/services/"
                    . $service["image"];


                if (is_file($oldImagePath)) {

                    unlink($oldImagePath);

                }

            }


            $message = "Service updated successfully.";


            /*
            |--------------------------------------------------------------------------
            | Reload service
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT
                    id,
                    name,
                    slug,
                    description,
                    image,
                    regular_price,
                    promo_price,
                    promo_active,
                    status
                FROM services
                WHERE id = ?
                LIMIT 1
            ");

            $stmt->execute([$serviceId]);

            $service = $stmt->fetch();


        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {

                $pdo->rollBack();

            }


            /*
            |--------------------------------------------------------------------------
            | If a new image was uploaded but database update failed,
            | remove that new image.
            |--------------------------------------------------------------------------
            */

            if (
                $newImageName !== $service["image"]
                &&
                !empty($newImageName)
            ) {

                $newImagePath =
                    __DIR__
                    . "/../uploads/services/"
                    . $newImageName;


                if (is_file($newImagePath)) {

                    unlink($newImagePath);

                }

            }


            $error = "Unable to update service. Please try again.";

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

    <title>Edit Service | Servora</title>

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


<main class="mx-auto w-full max-w-4xl px-4 py-6 sm:px-6 sm:py-8 lg:px-8">


    <!-- Header -->

    <div class="mb-6">

        <a
            href="services.php"
            class="mb-5 inline-flex items-center gap-2 text-sm font-semibold text-slate-500 transition hover:text-servora-700"
        >

            <span class="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-white">
                ←
            </span>

            Back to Services

        </a>


        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">

            <div>

                <div class="mb-2 inline-flex items-center rounded-full bg-servora-50 px-3 py-1 text-xs font-bold text-servora-700">

                    Service Management

                </div>

                <h1 class="text-2xl font-black tracking-tight text-slate-900 sm:text-3xl">
                    Edit Service
                </h1>

                <p class="mt-1 text-sm leading-6 text-slate-500">
                    Update the service details, pricing, promotion and image.
                </p>

            </div>


            <div class="rounded-xl border border-slate-200 bg-white px-4 py-3">

                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                    Service ID
                </p>

                <p class="mt-0.5 text-sm font-bold text-slate-800">
                    #<?= (int) $service["id"] ?>
                </p>

            </div>

        </div>

    </div>



    <!-- Success Message -->

    <?php if ($message !== ""): ?>

        <div class="mb-5 flex items-start gap-3 rounded-2xl border border-emerald-100 bg-emerald-50 p-4 text-emerald-700">

            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-emerald-100 font-bold">
                ✓
            </div>

            <div>

                <p class="text-sm font-bold">
                    Update successful
                </p>

                <p class="mt-0.5 text-sm">
                    <?= htmlspecialchars($message) ?>
                </p>

            </div>

        </div>

    <?php endif; ?>



    <!-- Error Message -->

    <?php if ($error !== ""): ?>

        <div class="mb-5 flex items-start gap-3 rounded-2xl border border-red-100 bg-red-50 p-4 text-red-700">

            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-red-100 font-bold">
                !
            </div>

            <div>

                <p class="text-sm font-bold">
                    Unable to update service
                </p>

                <p class="mt-0.5 text-sm">
                    <?= htmlspecialchars($error) ?>
                </p>

            </div>

        </div>

    <?php endif; ?>



    <!-- Form -->

    <form
        method="POST"
        enctype="multipart/form-data"
        class="space-y-6"
    >
<?= csrfField() ?>


        <!-- Basic Information -->

        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">

            <div class="border-b border-slate-100 px-5 py-5 sm:px-6">

                <h2 class="text-base font-black text-slate-900">
                    Basic Information
                </h2>

                <p class="mt-1 text-sm text-slate-500">
                    Update the name and description customers will see.
                </p>

            </div>


            <div class="space-y-5 p-5 sm:p-6">


                <!-- Service Name -->

                <div>

                    <label
                        for="name"
                        class="mb-2 block text-sm font-bold text-slate-700"
                    >
                        Service Name
                    </label>

                    <input
                        type="text"
                        id="name"
                        name="name"
                        value="<?= htmlspecialchars($service["name"]) ?>"
                        required
                        class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-servora-500 focus:bg-white focus:ring-4 focus:ring-servora-100"
                    >

                </div>



                <!-- Description -->

                <div>

                    <label
                        for="description"
                        class="mb-2 block text-sm font-bold text-slate-700"
                    >
                        Description
                    </label>

                    <textarea
                        id="description"
                        name="description"
                        rows="5"
                        placeholder="Describe what this service provides..."
                        class="w-full resize-y rounded-xl border border-slate-200 bg-slate-50 px-4 py-3.5 text-sm leading-6 text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-servora-500 focus:bg-white focus:ring-4 focus:ring-servora-100"
                    ><?= htmlspecialchars($service["description"] ?? "") ?></textarea>

                    <p class="mt-2 text-xs text-slate-400">
                        Keep the description clear and easy for customers to understand.
                    </p>

                </div>


            </div>

        </section>



        <!-- Pricing -->

        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">

            <div class="border-b border-slate-100 px-5 py-5 sm:px-6">

                <h2 class="text-base font-black text-slate-900">
                    Pricing & Promotion
                </h2>

                <p class="mt-1 text-sm text-slate-500">
                    Control the regular price and promotional price.
                </p>

            </div>


            <div class="grid gap-5 p-5 sm:grid-cols-2 sm:p-6">


                <!-- Regular Price -->

                <div>

                    <label
                        for="regular_price"
                        class="mb-2 block text-sm font-bold text-slate-700"
                    >
                        Regular Price
                    </label>

                    <div class="relative">

                        <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-sm font-bold text-slate-400">
                            ₦
                        </span>

                        <input
                            type="number"
                            id="regular_price"
                            name="regular_price"
                            min="0"
                            step="0.01"
                            value="<?= htmlspecialchars($service["regular_price"]) ?>"
                            required
                            class="w-full rounded-xl border border-slate-200 bg-slate-50 py-3.5 pl-10 pr-4 text-sm font-semibold text-slate-900 outline-none transition focus:border-servora-500 focus:bg-white focus:ring-4 focus:ring-servora-100"
                        >

                    </div>

                </div>



                <!-- Promo Price -->

                <div>

                    <label
                        for="promo_price"
                        class="mb-2 block text-sm font-bold text-slate-700"
                    >
                        Promo Price
                    </label>

                    <div class="relative">

                        <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-sm font-bold text-slate-400">
                            ₦
                        </span>

                        <input
                            type="number"
                            id="promo_price"
                            name="promo_price"
                            min="0"
                            step="0.01"
                            value="<?= $service["promo_price"] !== null
                                ? htmlspecialchars($service["promo_price"])
                                : ""
                            ?>"
                            placeholder="Optional"
                            class="w-full rounded-xl border border-slate-200 bg-slate-50 py-3.5 pl-10 pr-4 text-sm font-semibold text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-servora-500 focus:bg-white focus:ring-4 focus:ring-servora-100"
                        >

                    </div>

                    <p class="mt-2 text-xs text-slate-400">
                        Must be lower than the regular price.
                    </p>

                </div>



                <!-- Promotion Toggle -->

                <div class="sm:col-span-2">

                    <label class="flex cursor-pointer items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 transition hover:border-servora-200 hover:bg-servora-50">

                        <input
                            type="checkbox"
                            id="promo_active"
                            name="promo_active"
                            <?= (int) $service["promo_active"] === 1
                                ? "checked"
                                : ""
                            ?>
                            class="mt-1 h-4 w-4 shrink-0 rounded border-slate-300 text-servora-700 focus:ring-servora-500"
                        >

                        <span>

                            <span class="block text-sm font-bold text-slate-800">
                                Promotion is active
                            </span>

                            <span class="mt-1 block text-xs leading-5 text-slate-500">
                                Customers will see the promotional price when available.
                            </span>

                        </span>

                    </label>

                </div>


            </div>

        </section>



        <!-- Status -->

        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">

            <div class="border-b border-slate-100 px-5 py-5 sm:px-6">

                <h2 class="text-base font-black text-slate-900">
                    Service Availability
                </h2>

                <p class="mt-1 text-sm text-slate-500">
                    Choose whether customers can currently request this service.
                </p>

            </div>


            <div class="p-5 sm:p-6">

                <label
                    for="status"
                    class="mb-2 block text-sm font-bold text-slate-700"
                >
                    Service Status
                </label>

                <select
                    id="status"
                    name="status"
                    class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3.5 text-sm font-semibold text-slate-900 outline-none transition focus:border-servora-500 focus:bg-white focus:ring-4 focus:ring-servora-100"
                >

                    <option
                        value="active"
                        <?= $service["status"] === "active"
                            ? "selected"
                            : ""
                        ?>
                    >
                        Active — Customers can request
                    </option>

                    <option
                        value="inactive"
                        <?= $service["status"] === "inactive"
                            ? "selected"
                            : ""
                        ?>
                    >
                        Inactive — Hidden from customers
                    </option>

                </select>

            </div>

        </section>



        <!-- Image -->

        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">

            <div class="border-b border-slate-100 px-5 py-5 sm:px-6">

                <h2 class="text-base font-black text-slate-900">
                    Service Image
                </h2>

                <p class="mt-1 text-sm text-slate-500">
                    Update the image displayed on the service card.
                </p>

            </div>


            <div class="p-5 sm:p-6">


                <?php if (!empty($service["image"])): ?>

                    <div class="mb-6 overflow-hidden rounded-2xl border border-slate-200 bg-slate-50">

                        <img
                            src="../uploads/services/<?= htmlspecialchars($service["image"]) ?>"
                            alt="<?= htmlspecialchars($service["name"]) ?>"
                            class="h-52 w-full object-cover sm:h-64"
                        >

                        <div class="border-t border-slate-200 bg-white px-4 py-3">

                            <p class="text-xs font-semibold text-slate-500">
                                Current service image
                            </p>

                        </div>

                    </div>

                <?php else: ?>

                    <div class="mb-6 rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center">

                        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-slate-100 text-slate-400">
                            —
                        </div>

                        <p class="mt-3 text-sm font-bold text-slate-700">
                            No image added
                        </p>

                        <p class="mt-1 text-xs text-slate-400">
                            Upload an image below to add one.
                        </p>

                    </div>

                <?php endif; ?>



                <!-- Upload -->

                <label
                    for="image"
                    class="mb-2 block text-sm font-bold text-slate-700"
                >
                    Replace Image
                </label>

                <input
                    type="file"
                    id="image"
                    name="image"
                    accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                    class="block w-full cursor-pointer rounded-xl border border-slate-200 bg-slate-50 text-sm text-slate-600 file:mr-4 file:border-0 file:bg-servora-50 file:px-4 file:py-3.5 file:text-sm file:font-bold file:text-servora-700 hover:file:bg-servora-100"
                >

                <p class="mt-2 text-xs leading-5 text-slate-400">
                    Maximum 2MB. JPG, PNG, or WEBP only.
                </p>

            </div>

        </section>



        <!-- Actions -->

        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">

            <a
                href="services.php"
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
            Servora Admin · Service Management
        </p>

    </div>


</main>

</body>

</html>
```
