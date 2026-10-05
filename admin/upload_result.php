<?php

require_once "../config/database.php";
require_once "../includes/admin_auth.php";

// Only allow POST requests
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    die("Method not allowed.");
}

// --------------------------------------------------
// CSRF PROTECTION
// --------------------------------------------------

if (
    !isset($_POST["csrf_token"], $_SESSION["csrf_token"]) ||
    !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
) {
    die("Invalid request.");
}

// --------------------------------------------------
// VALIDATE REQUEST ID
// --------------------------------------------------

$requestId = filter_input(
    INPUT_POST,
    "request_id",
    FILTER_VALIDATE_INT
);

if (!$requestId || $requestId <= 0) {
    die("Invalid request ID.");
}

if (!isset($_FILES["result_file"])) {
    die("Please select a file.");
}

$file = $_FILES["result_file"];

// --------------------------------------------------
// CHECK UPLOAD ERROR
// --------------------------------------------------

if ($file["error"] !== UPLOAD_ERR_OK) {
    die("File upload failed.");
}

// Maximum file size: 5MB
$maxFileSize = 5 * 1024 * 1024;

if ($file["size"] > $maxFileSize) {
    die("File is too large. Maximum size is 5MB.");
}

if ($file["size"] <= 0) {
    die("Invalid file.");
}

// --------------------------------------------------
// ALLOWED FILE TYPES
// --------------------------------------------------

$allowedMimeTypes = [
    "application/pdf" => "pdf",
    "image/jpeg" => "jpg",
    "image/png" => "png"
];

$finfo = new finfo(FILEINFO_MIME_TYPE);

$mimeType = $finfo->file($file["tmp_name"]);

if (!isset($allowedMimeTypes[$mimeType])) {
    die("Invalid file type. Only PDF, JPG and PNG files are allowed.");
}

$extension = $allowedMimeTypes[$mimeType];

$adminId = currentUserId();

try {

    $pdo->beginTransaction();

    // --------------------------------------------------
    // LOCK REQUEST
    // --------------------------------------------------

    $stmt = $pdo->prepare("
        SELECT
            id,
            user_id,
            request_code,
            status
        FROM service_requests
        WHERE id = ?
        FOR UPDATE
    ");

    $stmt->execute([$requestId]);

    $request = $stmt->fetch();

    if (!$request) {
        throw new Exception("Request not found.");
    }

    // --------------------------------------------------
    // CREATE SECURE RANDOM FILE NAME
    // --------------------------------------------------

    $storedName =
        bin2hex(random_bytes(32)) .
        "." .
        $extension;

    $uploadDirectory = "../uploads/results/";

    if (!is_dir($uploadDirectory)) {
        if (!mkdir($uploadDirectory, 0755, true)) {
            throw new Exception("Unable to create upload directory.");
        }
    }

    $filePath = $uploadDirectory . $storedName;

    // --------------------------------------------------
    // MOVE FILE
    // --------------------------------------------------

    if (!move_uploaded_file($file["tmp_name"], $filePath)) {
        throw new Exception("Unable to save uploaded file.");
    }

    // --------------------------------------------------
    // SAVE FILE INFORMATION
    // --------------------------------------------------

    $stmt = $pdo->prepare("
        INSERT INTO request_files
        (
            request_id,
            original_name,
            stored_name,
            file_path,
            mime_type,
            file_size,
            uploaded_by
        )
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $requestId,
        basename($file["name"]),
        $storedName,
        $filePath,
        $mimeType,
        $file["size"],
        $adminId
    ]);

    // --------------------------------------------------
    // UPDATE REQUEST STATUS
    // --------------------------------------------------

    $stmt = $pdo->prepare("
        UPDATE service_requests
        SET
            status = 'ready_for_download',
            assigned_admin_id = ?,
            updated_at = NOW()
        WHERE id = ?
    ");

    $stmt->execute([
        $adminId,
        $requestId
    ]);

    // --------------------------------------------------
    // STATUS HISTORY
    // --------------------------------------------------

    $message = "Your result is ready for download.";

    $stmt = $pdo->prepare("
        INSERT INTO request_status_history
        (
            request_id,
            status,
            message,
            changed_by
        )
        VALUES (?, 'ready_for_download', ?, ?)
    ");

    $stmt->execute([
        $requestId,
        $message,
        $adminId
    ]);

    // --------------------------------------------------
    // CLIENT NOTIFICATION
    // --------------------------------------------------

    $stmt = $pdo->prepare("
        INSERT INTO notifications
        (
            user_id,
            request_id,
            title,
            message,
            is_read
        )
        VALUES (?, ?, ?, ?, 0)
    ");

    $stmt->execute([
        $request["user_id"],
        $requestId,
        "Result Ready",
        "The result for request " .
        $request["request_code"] .
        " is now ready for download."
    ]);

    // --------------------------------------------------
    // ADMIN ACTIVITY LOG
    // --------------------------------------------------

    $stmt = $pdo->prepare("
        INSERT INTO activity_logs
        (
            user_id,
            request_id,
            action,
            description
        )
        VALUES (?, ?, ?, ?)
    ");

    $stmt->execute([
        $adminId,
        $requestId,
        "result_uploaded",
        "Uploaded result file for request " .
        $request["request_code"] . "."
    ]);

    // --------------------------------------------------
    // COMMIT
    // --------------------------------------------------

    $pdo->commit();

    header(
        "Location: request_details.php?id=" .
        (int) $requestId
    );

    exit;

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // Remove uploaded file if database operation failed
    if (
        isset($filePath) &&
        file_exists($filePath)
    ) {
        unlink($filePath);
    }

    http_response_code(500);

    die("Unable to upload result. Please try again.");
}