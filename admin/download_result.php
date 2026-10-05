<?php

require_once "../config/database.php";
require_once "../includes/admin_auth.php";

// --------------------------------------------------
// GET FILE ID
// --------------------------------------------------

$fileId = filter_input(
    INPUT_GET,
    "id",
    FILTER_VALIDATE_INT
);

if (!$fileId || $fileId <= 0) {
    http_response_code(404);
    die("File not found.");
}

// --------------------------------------------------
// GET FILE
// --------------------------------------------------

$stmt = $pdo->prepare("
    SELECT
        rf.id,
        rf.original_name,
        rf.stored_name,
        rf.file_path,
        rf.mime_type,
        rf.file_size
    FROM request_files rf
    WHERE rf.id = ?
    LIMIT 1
");

$stmt->execute([$fileId]);

$file = $stmt->fetch();

if (!$file) {
    http_response_code(404);
    die("File not found.");
}

// --------------------------------------------------
// SECURITY CHECK
// --------------------------------------------------

$baseDirectory = realpath("../uploads/results");

$filePath = realpath(
    "../uploads/results/" .
    basename($file["stored_name"])
);

if (
    $baseDirectory === false ||
    $filePath === false ||
    strpos($filePath, $baseDirectory . DIRECTORY_SEPARATOR) !== 0
) {
    http_response_code(404);
    die("File not found.");
}

if (!is_file($filePath)) {
    http_response_code(404);
    die("File not found.");
}

// --------------------------------------------------
// SEND FILE
// --------------------------------------------------

$downloadName = basename(
    $file["original_name"]
);

header(
    "Content-Type: " .
    $file["mime_type"]
);

header(
    "Content-Length: " .
    filesize($filePath)
);

header(
    'Content-Disposition: attachment; filename="' .
    addslashes($downloadName) .
    '"'
);

header("X-Content-Type-Options: nosniff");

readfile($filePath);

exit;