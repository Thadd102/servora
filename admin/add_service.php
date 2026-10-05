<?php

require_once "../config/database.php";
require_once "../includes/admin_auth.php";

$message = "";
$messageType = "";

function createSlug(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    verifyCsrfToken();

    $name = trim($_POST["name"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $regularPrice = (float) ($_POST["regular_price"] ?? 0);
    $promoPriceInput = trim($_POST["promo_price"] ?? "");
    $promoActive = isset($_POST["promo_active"]) ? 1 : 0;
    $status = $_POST["status"] ?? "active";

    // Basic validation
    if ($name === "") {
        $message = "Service name is required.";
        $messageType = "error";
    } elseif ($regularPrice <= 0) {
        $message = "Regular price must be greater than ₦0.";
        $messageType = "error";
    } elseif (!in_array($status, ["active", "inactive"], true)) {
        $message = "Invalid service status.";
        $messageType = "error";
    }

    // Promo price validation
    $promoPrice = null;

    if ($message === "" && $promoPriceInput !== "") {

        $promoPrice = (float) $promoPriceInput;

        if ($promoPrice <= 0) {
            $message = "Promo price must be greater than ₦0.";
            $messageType = "error";
        } elseif ($promoPrice >= $regularPrice) {
            $message = "Promo price must be less than the regular price.";
            $messageType = "error";
        }
    }

    // If there is no promo price, promotion must be OFF
    if ($promoPrice === null) {
        $promoActive = 0;
    }

    /*
    |--------------------------------------------------------------------------
    | Image validation
    |--------------------------------------------------------------------------
    */

    $uploadedFileName = null;
    $uploadedFilePath = null;

    if ($message === "") {

        if (
            !isset($_FILES["image"]) ||
            $_FILES["image"]["error"] !== UPLOAD_ERR_OK
        ) {
            $message = "Please select a service image.";
            $messageType = "error";
        } else {

            $file = $_FILES["image"];

            // Maximum file size: 2MB
            if ($file["size"] > 2 * 1024 * 1024) {

                $message = "Image must not be larger than 2MB.";
                $messageType = "error";

            } else {

                // Detect actual MIME type
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->file($file["tmp_name"]);

                $allowedTypes = [
                    "image/jpeg" => "jpg",
                    "image/png"  => "png",
                    "image/webp" => "webp"
                ];

                if (!isset($allowedTypes[$mimeType])) {

                    $message = "Only JPG, PNG, and WEBP images are allowed.";
                    $messageType = "error";

                } else {

                    $extension = $allowedTypes[$mimeType];

                    // Generate a random filename
                    $uploadedFileName =
                        bin2hex(random_bytes(16)) .
                        "." .
                        $extension;

                    $uploadDirectory =
                        __DIR__ .
                        "/../uploads/services/";

                    // Create directory if it does not exist
                    if (!is_dir($uploadDirectory)) {
                        mkdir($uploadDirectory, 0755, true);
                    }

                    $uploadedFilePath =
                        $uploadDirectory .
                        $uploadedFileName;

                    if (
                        !move_uploaded_file(
                            $file["tmp_name"],
                            $uploadedFilePath
                        )
                    ) {

                        $message = "Unable to upload the service image.";
                        $messageType = "error";

                        $uploadedFileName = null;
                        $uploadedFilePath = null;
                    }
                }
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Create unique slug and save service
    |--------------------------------------------------------------------------
    */

    if ($message === "") {

        $baseSlug = createSlug($name);

        if ($baseSlug === "") {
            $message = "Unable to create a valid service slug.";
            $messageType = "error";

            if ($uploadedFilePath && file_exists($uploadedFilePath)) {
                unlink($uploadedFilePath);
            }

        } else {

            $slug = $baseSlug;
            $counter = 2;

            while (true) {

                $stmt = $pdo->prepare("
                    SELECT id
                    FROM services
                    WHERE slug = ?
                    LIMIT 1
                ");

                $stmt->execute([$slug]);

                if (!$stmt->fetch()) {
                    break;
                }

                $slug = $baseSlug . "-" . $counter;
                $counter++;
            }

            try {

                $stmt = $pdo->prepare("
                    INSERT INTO services
                    (
                        name,
                        slug,
                        description,
                        image,
                        regular_price,
                        promo_price,
                        promo_active,
                        status
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?
                    )
                ");

                $stmt->execute([
                    $name,
                    $slug,
                    $description,
                    $uploadedFileName,
                    $regularPrice,
                    $promoPrice,
                    $promoActive,
                    $status
                ]);

                $message = "Service added successfully.";
                $messageType = "success";

                // Clear form values
                $name = "";
                $description = "";
                $regularPrice = "";
                $promoPriceInput = "";
                $promoActive = 0;
                $status = "active";

            } catch (Throwable $e) {

                // Delete uploaded image if database insertion fails
                if (
                    $uploadedFilePath &&
                    file_exists($uploadedFilePath)
                ) {
                    unlink($uploadedFilePath);
                }

                $message = "Unable to add the service. Please try again.";
                $messageType = "error";
            }
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

<title>Add Service</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #f5f6f8;
    color: #222;
}

.container {
    width: 100%;
    max-width: 700px;
    margin: auto;
    padding: 30px 20px;
}

.back {
    display: inline-block;
    margin-bottom: 20px;
    color: #222;
    text-decoration: none;
}

.card {
    background: #fff;
    border: 1px solid #e5e5e5;
    border-radius: 10px;
    padding: 25px;
}

h1 {
    margin-top: 0;
    margin-bottom: 10px;
}

.description {
    color: #666;
    margin-bottom: 25px;
}

.message {
    padding: 12px;
    border-radius: 6px;
    margin-bottom: 20px;
}

.message.success {
    background: #e7f7ed;
    color: #16743b;
}

.message.error {
    background: #ffe9e9;
    color: #b00020;
}

.form-group {
    margin-bottom: 18px;
}

label {
    display: block;
    margin-bottom: 7px;
    font-weight: bold;
}

input,
textarea,
select {
    width: 100%;
    padding: 12px;
    border: 1px solid #ccc;
    border-radius: 6px;
    font-size: 15px;
    font-family: inherit;
}

textarea {
    min-height: 120px;
    resize: vertical;
}

input[type="checkbox"] {
    width: auto;
    margin-right: 8px;
}

.checkbox-label {
    display: flex;
    align-items: center;
    font-weight: normal;
}

.help {
    margin-top: 6px;
    font-size: 13px;
    color: #777;
}

button {
    width: 100%;
    padding: 13px;
    border: none;
    border-radius: 6px;
    background: #222;
    color: #fff;
    font-size: 16px;
    cursor: pointer;
}

button:hover {
    opacity: 0.9;
}

@media (max-width: 600px) {

    .container {
        padding: 20px 15px;
    }

    .card {
        padding: 20px;
    }

}

</style>

</head>

<body>

<main class="container">

<a href="services.php" class="back">
    ← Back to Services
</a>

<div class="card">

<h1>Add Service</h1>

<p class="description">
    Create a new service and upload the picture that will represent it.
</p>

<?php if ($message !== ""): ?>

<div class="message <?= htmlspecialchars($messageType) ?>">
    <?= htmlspecialchars($message) ?>
</div>

<?php endif; ?>

<form method="POST" enctype="multipart/form-data">
<?= csrfField() ?>

    <div class="form-group">

        <label for="name">
            Service Name
        </label>

        <input
            type="text"
            id="name"
            name="name"
            value="<?= htmlspecialchars($name ?? "") ?>"
            placeholder="e.g. Airtime Top-up"
            required
        >

    </div>


    <div class="form-group">

        <label for="description">
            Description
        </label>

        <textarea
            id="description"
            name="description"
            placeholder="Describe what this service is for..."
        ><?= htmlspecialchars($description ?? "") ?></textarea>

    </div>


    <div class="form-group">

        <label for="image">
            Service Image
        </label>

        <input
            type="file"
            id="image"
            name="image"
            accept="image/jpeg,image/png,image/webp"
            required
        >

        <div class="help">
            JPG, PNG or WEBP. Maximum size: 2MB.
        </div>

    </div>


    <div class="form-group">

        <label for="regular_price">
            Regular Price (₦)
        </label>

        <input
            type="number"
            id="regular_price"
            name="regular_price"
            value="<?= htmlspecialchars((string) ($regularPrice ?? "")) ?>"
            min="1"
            step="0.01"
            placeholder="2000"
            required
        >

    </div>


    <div class="form-group">

        <label for="promo_price">
            Promo Price (₦)
        </label>

        <input
            type="number"
            id="promo_price"
            name="promo_price"
            value="<?= htmlspecialchars($promoPriceInput ?? "") ?>"
            min="1"
            step="0.01"
            placeholder="1500"
        >

        <div class="help">
            Leave blank if there is no promotion.
        </div>

    </div>


    <div class="form-group">

        <label class="checkbox-label">

            <input
                type="checkbox"
                name="promo_active"
                value="1"
                <?= !empty($promoActive) ? "checked" : "" ?>
            >

            Enable promotion

        </label>

    </div>


    <div class="form-group">

        <label for="status">
            Status
        </label>

        <select
            id="status"
            name="status"
        >

            <option
                value="active"
                <?= ($status ?? "active") === "active" ? "selected" : "" ?>
            >
                Active
            </option>

            <option
                value="inactive"
                <?= ($status ?? "") === "inactive" ? "selected" : "" ?>
            >
                Inactive
            </option>

        </select>

    </div>


    <button type="submit">
        Add Service
    </button>

</form>

</div>

</main>

</body>

</html>