<?php

require_once "../includes/client_auth.php";
require_once "../config/database.php";


/*
|--------------------------------------------------------------------------
| GET FILE ID
|--------------------------------------------------------------------------
*/

$fileId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$fileId) {
    http_response_code(400);
    die("Invalid file.");
}


/*
|--------------------------------------------------------------------------
| GET CURRENT CLIENT
|--------------------------------------------------------------------------
*/

$userId = currentUserId();


/*
|--------------------------------------------------------------------------
| FETCH FILE
|
| IMPORTANT:
| We join request_files with service_requests and check the
| request belongs to the currently logged-in client.
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        rf.id,
        rf.original_name,
        rf.stored_name,
        rf.file_path,
        rf.mime_type,
        rf.file_size
    FROM request_files rf
    INNER JOIN service_requests sr
        ON rf.request_id = sr.id
    WHERE rf.id = ?
      AND sr.user_id = ?
    LIMIT 1
");

$stmt->execute([
    $fileId,
    $userId
]);

$file = $stmt->fetch();


/*
|--------------------------------------------------------------------------
| SECURITY CHECK
|--------------------------------------------------------------------------
*/

if (!$file) {

    http_response_code(404);

    die("File not found.");

}


/*
|--------------------------------------------------------------------------
| BUILD SECURE FILE PATH
|--------------------------------------------------------------------------
*/

$resultsDirectory = realpath(
    __DIR__ . "/../uploads/results"
);

if ($resultsDirectory === false) {

    http_response_code(500);

    die("Result directory not found.");

}


$storedName = basename($file["stored_name"]);

$filePath = realpath(
    $resultsDirectory . DIRECTORY_SEPARATOR . $storedName
);


/*
|--------------------------------------------------------------------------
| PREVENT PATH TRAVERSAL
|--------------------------------------------------------------------------
*/

if (
    $filePath === false ||
    strpos($filePath, $resultsDirectory . DIRECTORY_SEPARATOR) !== 0
) {

    http_response_code(403);

    die("Access denied.");

}


/*
|--------------------------------------------------------------------------
| CHECK FILE EXISTS
|--------------------------------------------------------------------------
*/

if (!is_file($filePath)) {

    http_response_code(404);

    die("The result file is no longer available.");

}


/*
|--------------------------------------------------------------------------
| SEND FILE
|--------------------------------------------------------------------------
*/

$mimeType = $file["mime_type"];

$allowedMimeTypes = [
    "application/pdf",
    "image/jpeg",
    "image/png"
];

if (!in_array($mimeType, $allowedMimeTypes, true)) {

    $mimeType = "application/octet-stream";

}


/*
|--------------------------------------------------------------------------
| CLEAN OUTPUT BUFFER
|--------------------------------------------------------------------------
*/

while (ob_get_level()) {
    ob_end_clean();
}


/*
|--------------------------------------------------------------------------
| DOWNLOAD HEADERS
|--------------------------------------------------------------------------
*/

header("Content-Type: " . $mimeType);

header(
    "Content-Length: " . filesize($filePath)
);

header(
    'Content-Disposition: attachment; filename="' .
    basename($file["original_name"]) .
    '"'
);

header("X-Content-Type-Options: nosniff");

header("Cache-Control: private, no-store, no-cache, must-revalidate");

header("Pragma: no-cache");


/*
|--------------------------------------------------------------------------
| SEND FILE
|--------------------------------------------------------------------------
*/

readfile($filePath);

exit;