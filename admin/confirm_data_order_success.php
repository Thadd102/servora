<?php

require_once "../config/database.php";
require_once "../includes/admin_auth.php";
require_once "../includes/CheapDataHubClient.php";
require_once "../includes/DataOrderReconciliationService.php";
require_once "../includes/DataOrderResolutionService.php";


/*
|--------------------------------------------------------------------------
| POST ONLY
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: data_orders.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| ADMIN SESSION
|--------------------------------------------------------------------------
*/

$adminId =
    (int) (
        $_SESSION["user_id"]
        ?? 0
    );

if ($adminId <= 0) {
    header("Location: ../login.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| ORDER ID
|--------------------------------------------------------------------------
*/

$orderId =
    (int) (
        $_POST["order_id"]
        ?? 0
    );

if ($orderId <= 0) {
    header(
        "Location: data_orders.php?error=invalid_order"
    );
    exit;
}


/*
|--------------------------------------------------------------------------
| CSRF TOKEN
|--------------------------------------------------------------------------
*/

$submittedCsrfToken =
    (string) (
        $_POST["csrf_token"]
        ?? ""
    );

$sessionCsrfToken =
    (string) (
        $_SESSION["csrf_token"]
        ?? ""
    );


if (
    $submittedCsrfToken === "" ||
    $sessionCsrfToken === "" ||
    !hash_equals(
        $sessionCsrfToken,
        $submittedCsrfToken
    )
) {

    header(
        "Location: data_order_view.php?id=" .
        $orderId .
        "&error=invalid_request"
    );
    exit;
}


/*
|--------------------------------------------------------------------------
| CHEAPDATAHUB CONFIGURATION
|--------------------------------------------------------------------------
*/

$cheapDataHubApiKey =
    trim(
        (string) (
            getenv(
                "CHEAPDATAHUB_API_KEY"
            )
            ?: (
                $_ENV[
                    "CHEAPDATAHUB_API_KEY"
                ]
                ?? ""
            )
        )
    );


$cheapDataHubBaseUrl =
    trim(
        (string) (
            getenv(
                "CHEAPDATAHUB_BASE_URL"
            )
            ?: (
                $_ENV[
                    "CHEAPDATAHUB_BASE_URL"
                ]
                ?? "https://www.cheapdatahub.ng/api/v1/resellers/"
            )
        )
    );


if ($cheapDataHubApiKey === "") {

    header(
        "Location: data_order_view.php?id=" .
        $orderId .
        "&error=provider_configuration"
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| CREATE SERVICES
|--------------------------------------------------------------------------
*/

try {

    $cheapDataHubClient =
        new CheapDataHubClient(
            $cheapDataHubApiKey,
            $cheapDataHubBaseUrl
        );


    $reconciliationService =
        new DataOrderReconciliationService(
            $pdo,
            $cheapDataHubClient
        );


    $resolutionService =
        new DataOrderResolutionService(
            $pdo,
            $reconciliationService
        );


    /*
    |--------------------------------------------------------------------------
    | CONFIRM SUCCESS
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    |
    | The resolution service performs reconciliation AGAIN.
    |
    | It does not trust:
    |
    | - browser candidate information
    | - provider reference from POST
    | - previous reconciliation result
    |
    */

    $result =
        $resolutionService
            ->confirmSupplierSuccess(
                $orderId,
                $adminId
            );


} catch (Throwable $exception) {

    error_log(
        "Admin data order confirmation failed."
    );

    header(
        "Location: data_order_view.php?id=" .
        $orderId .
        "&error=confirmation_failed"
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| HANDLE RESULT
|--------------------------------------------------------------------------
*/

if (
    is_array($result) &&
    ($result["success"] ?? false) === true
) {

    /*
    |--------------------------------------------------------------------------
    | ROTATE CSRF TOKEN
    |--------------------------------------------------------------------------
    */

    $_SESSION["csrf_token"] =
        bin2hex(
            random_bytes(32)
        );


    header(
        "Location: data_order_view.php?id=" .
        $orderId .
        "&success=order_confirmed"
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| FAILED / BLOCKED
|--------------------------------------------------------------------------
*/

$errorState =
    trim(
        (string) (
            $result["state"]
            ?? "confirmation_failed"
        )
    );


/*
|--------------------------------------------------------------------------
| ALLOW ONLY SAFE ERROR VALUES IN URL
|--------------------------------------------------------------------------
*/

$allowedErrors = [

    "invalid_order",

    "invalid_admin",

    "reconciliation_error",

    "reconciliation_failed",

    "not_high_confidence",

    "candidate_missing",

    "candidate_not_confirmed",

    "cross_order_collision",

    "insufficient_evidence",

    "provider_reference_missing",

    "provider_reference_unavailable",

    "supplier_not_completed",

    "order_not_found",

    "order_not_processing",

    "order_refunded",

    "wallet_debit_missing",

    "admin_not_found",

    "admin_not_authorized",

    "provider_reference_conflict",

    "order_changed",

    "database_error"
];


if (
    !in_array(
        $errorState,
        $allowedErrors,
        true
    )
) {
    $errorState =
        "confirmation_failed";
}


/*
|--------------------------------------------------------------------------
| ROTATE CSRF TOKEN AFTER FAILED ACTION TOO
|--------------------------------------------------------------------------
*/

$_SESSION["csrf_token"] =
    bin2hex(
        random_bytes(32)
    );


header(
    "Location: data_order_view.php?id=" .
    $orderId .
    "&error=" .
    urlencode($errorState)
);

exit;