<?php

class CheapDataHubClient
{
    private string $apiKey;
    private string $baseUrl;


    /*
    |--------------------------------------------------------------------------
    | CREATE CHEAPDATAHUB CLIENT
    |--------------------------------------------------------------------------
    */

    public function __construct(
        string $apiKey,
        string $baseUrl
    ) {
        $this->apiKey = trim($apiKey);
        $this->baseUrl = rtrim($baseUrl, "/");
    }


    /*
    |--------------------------------------------------------------------------
    | CLEAN SUPPLIER RESPONSE FOR SAFE LOGGING
    |--------------------------------------------------------------------------
    |
    | Sometimes an API returns HTML instead of JSON during a server error.
    |
    | We remove HTML and limit the stored message so Servora doesn't save
    | an enormous error page in the database.
    |
    */

    private function cleanResponseMessage(
        string $response
    ): string {

        /*
         * Remove HTML tags.
         */

        $message = strip_tags($response);


        /*
         * Decode things such as &nbsp; and &quot;
         */

        $message = html_entity_decode(
            $message,
            ENT_QUOTES | ENT_HTML5,
            "UTF-8"
        );


        /*
         * Replace repeated spaces/newlines with one space.
         */

        $message = preg_replace(
            '/\s+/',
            ' ',
            $message
        );


        $message = trim(
            $message ?? ""
        );


        /*
         * Don't store huge supplier responses.
         */

        if (
            mb_strlen($message) > 220
        ) {

            $message =
                mb_substr(
                    $message,
                    0,
                    220
                ) .
                "...";
        }


        if ($message === "") {

            return "Supplier returned an empty or unreadable response.";
        }


        return $message;
    }


    /*
    |--------------------------------------------------------------------------
    | SEND REQUEST TO CHEAPDATAHUB
    |--------------------------------------------------------------------------
    */

    private function request(
        string $method,
        string $endpoint,
        ?array $payload = null
    ): array {

        /*
        |--------------------------------------------------------------------------
        | BUILD URL
        |--------------------------------------------------------------------------
        */

        $url =
            $this->baseUrl .
            "/" .
            ltrim(
                $endpoint,
                "/"
            );


        /*
        |--------------------------------------------------------------------------
        | INITIALIZE CURL
        |--------------------------------------------------------------------------
        */

        $ch = curl_init();


        if ($ch === false) {

            return [
                "ok" => false,
                "state" => "unknown",
                "http_status" => 0,
                "message" =>
                    "Could not initialize supplier connection.",
                "data" => null
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | REQUEST HEADERS
        |--------------------------------------------------------------------------
        |
        | Never log these because the Authorization header contains
        | the real API key.
        |
        */

        $headers = [
            "Authorization: Bearer " . $this->apiKey,
            "Accept: application/json",
            "Content-Type: application/json"
        ];


        /*
        |--------------------------------------------------------------------------
        | BASIC CURL SETTINGS
        |--------------------------------------------------------------------------
        */

        curl_setopt_array(
            $ch,
            [
                CURLOPT_URL =>
                    $url,

                CURLOPT_RETURNTRANSFER =>
                    true,

                CURLOPT_HTTPHEADER =>
                    $headers,

                CURLOPT_CONNECTTIMEOUT =>
                    10,

                CURLOPT_TIMEOUT =>
                    30
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | HANDLE POST REQUEST
        |--------------------------------------------------------------------------
        */

        if (
            strtoupper($method) === "POST"
        ) {

            curl_setopt(
                $ch,
                CURLOPT_POST,
                true
            );


            if ($payload !== null) {

                $jsonPayload =
                    json_encode(
                        $payload,
                        JSON_UNESCAPED_SLASHES
                    );


                /*
                |--------------------------------------------------------------------------
                | JSON ENCODING FAILED
                |--------------------------------------------------------------------------
                */

                if ($jsonPayload === false) {

                    curl_close($ch);

                    return [
                        "ok" => false,
                        "state" => "failed",
                        "http_status" => 0,
                        "message" =>
                            "Could not prepare supplier request.",
                        "data" => null
                    ];
                }


                curl_setopt(
                    $ch,
                    CURLOPT_POSTFIELDS,
                    $jsonPayload
                );
            }
        }


        /*
        |--------------------------------------------------------------------------
        | SEND REQUEST
        |--------------------------------------------------------------------------
        */

        $response =
            curl_exec($ch);


        /*
        |--------------------------------------------------------------------------
        | GET CURL INFORMATION
        |--------------------------------------------------------------------------
        */

        $curlError =
            curl_error($ch);

        $curlErrno =
            curl_errno($ch);

        $httpStatus =
            (int) curl_getinfo(
                $ch,
                CURLINFO_HTTP_CODE
            );


        /*
        |--------------------------------------------------------------------------
        | CLOSE CURL
        |--------------------------------------------------------------------------
        */

        curl_close($ch);


        /*
        |--------------------------------------------------------------------------
        | NETWORK / TIMEOUT ERROR
        |--------------------------------------------------------------------------
        |
        | This remains UNKNOWN.
        |
        | We cannot safely assume that CheapDataHub did not receive the
        | purchase request.
        |
        */

        if (
            $response === false ||
            $curlErrno !== 0
        ) {

            return [
                "ok" => false,

                "state" =>
                    "unknown",

                "http_status" =>
                    $httpStatus,

                "message" =>
                    $curlError
                    ?: "Supplier connection error.",

                "data" =>
                    null
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | RESPONSE IS EMPTY
        |--------------------------------------------------------------------------
        */

        if (
            trim($response) === ""
        ) {

            return [
                "ok" => false,

                "state" =>
                    "unknown",

                "http_status" =>
                    $httpStatus,

                "message" =>
                    "Supplier returned an empty response.",

                "data" =>
                    null
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | DECODE JSON RESPONSE
        |--------------------------------------------------------------------------
        */

        $decoded =
            json_decode(
                $response,
                true
            );


        /*
        |--------------------------------------------------------------------------
        | INVALID / NON-JSON SUPPLIER RESPONSE
        |--------------------------------------------------------------------------
        |
        | This is exactly what we need for the HTTP 500 problem we're
        | currently investigating.
        |
        | Instead of throwing the supplier's response away, Servora keeps
        | a short sanitized version.
        |
        */

        if (!is_array($decoded)) {

            $safeResponse =
                $this->cleanResponseMessage(
                    $response
                );


            /*
             * Write a safe diagnostic to the PHP error log.
             *
             * API key, headers and request credentials are NOT included.
             */

            error_log(
                "CheapDataHub non-JSON response. " .
                "HTTP " .
                $httpStatus .
                " | Endpoint: " .
                $endpoint .
                " | Response: " .
                $safeResponse
            );


            return [
                "ok" => false,

                /*
                 * Even HTTP 500 remains unknown for a purchase.
                 *
                 * We don't know whether the supplier processed something
                 * before its server generated the error.
                 */

                "state" =>
                    "unknown",

                "http_status" =>
                    $httpStatus,

                "message" =>
                    "Supplier HTTP " .
                    $httpStatus .
                    ": " .
                    $safeResponse,

                "data" =>
                    null
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | GET SUPPLIER MESSAGE
        |--------------------------------------------------------------------------
        */

        $supplierMessage =
            $decoded["message"]
            ?? $decoded["detail"]
            ?? $decoded["error"]
            ?? "Supplier response received.";


        /*
        |--------------------------------------------------------------------------
        | MAKE SURE MESSAGE IS A STRING
        |--------------------------------------------------------------------------
        */

        if (
            is_array($supplierMessage) ||
            is_object($supplierMessage)
        ) {

            $supplierMessage =
                json_encode(
                    $supplierMessage
                );
        }


        $supplierMessage =
            trim(
                (string)
                $supplierMessage
            );


        if ($supplierMessage === "") {

            $supplierMessage =
                "Supplier response received.";
        }


        /*
        |--------------------------------------------------------------------------
        | SUCCESSFUL HTTP RESPONSE
        |--------------------------------------------------------------------------
        */

        if (
            $httpStatus >= 200 &&
            $httpStatus < 300
        ) {

            return [
                "ok" => true,

                "state" =>
                    "received",

                "http_status" =>
                    $httpStatus,

                "message" =>
                    $supplierMessage,

                "data" =>
                    $decoded
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | HTTP 409 — DUPLICATE / UNCERTAIN
        |--------------------------------------------------------------------------
        |
        | We should not blindly retry a purchase after a duplicate response.
        |
        */

        if ($httpStatus === 409) {

            return [
                "ok" => false,

                "state" =>
                    "unknown",

                "http_status" =>
                    $httpStatus,

                "message" =>
                    $supplierMessage,

                "data" =>
                    $decoded
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | SERVER-SIDE ERROR — 5XX
        |--------------------------------------------------------------------------
        |
        | Treat supplier server errors as UNKNOWN for purchase operations.
        |
        | We should investigate/requery rather than immediately refunding
        | or sending the purchase again.
        |
        */

        if (
            $httpStatus >= 500 &&
            $httpStatus <= 599
        ) {

            return [
                "ok" => false,

                "state" =>
                    "unknown",

                "http_status" =>
                    $httpStatus,

                "message" =>
                    $supplierMessage,

                "data" =>
                    $decoded
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | NORMAL REJECTED HTTP RESPONSE
        |--------------------------------------------------------------------------
        |
        | Examples could include validation errors.
        |
        */

        return [
            "ok" => false,

            "state" =>
                "failed",

            "http_status" =>
                $httpStatus,

            "message" =>
                $supplierMessage,

            "data" =>
                $decoded
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | GET SUPPLIER WALLET BALANCE
    |--------------------------------------------------------------------------
    */

    public function getBalance(): array
    {
        return $this->request(
            "GET",
            "wallet/balance/"
        );
    }


    /*
    |--------------------------------------------------------------------------
    | PURCHASE DATA
    |--------------------------------------------------------------------------
    */

    public function purchaseData(
        int $bundleId,
        string $phoneNumber
    ): array {


        /*
        |--------------------------------------------------------------------------
        | VALIDATE BUNDLE ID
        |--------------------------------------------------------------------------
        */

        if ($bundleId <= 0) {

            return [
                "ok" => false,
                "state" => "failed",
                "http_status" => 0,
                "message" =>
                    "Invalid data bundle ID.",
                "data" => null
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | CLEAN PHONE NUMBER
        |--------------------------------------------------------------------------
        */

        $phoneNumber =
            preg_replace(
                '/\D+/',
                '',
                $phoneNumber
            );


        /*
        |--------------------------------------------------------------------------
        | CONVERT SERVORA FORMAT TO LOCAL FORMAT
        |--------------------------------------------------------------------------
        |
        | Servora:
        |
        | 2349012345678
        |
        | Supplier request:
        |
        | 09012345678
        |
        */

        if (
            str_starts_with(
                $phoneNumber,
                "234"
            )
        ) {

            $phoneNumber =
                "0" .
                substr(
                    $phoneNumber,
                    3
                );
        }


        /*
        |--------------------------------------------------------------------------
        | VALIDATE LOCAL PHONE NUMBER
        |--------------------------------------------------------------------------
        */

        if (
            !preg_match(
                '/^0\d{10}$/',
                $phoneNumber
            )
        ) {

            return [
                "ok" => false,

                "state" =>
                    "failed",

                "http_status" =>
                    0,

                "message" =>
                    "Invalid phone number format.",

                "data" =>
                    null
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | SEND PURCHASE
        |--------------------------------------------------------------------------
        */

        return $this->request(
            "POST",
            "data/purchase/",
            [
                "bundle_id" =>
                    $bundleId,

                "phone_number" =>
                    $phoneNumber
            ]
        );
    }


    /*
    |--------------------------------------------------------------------------
    | GET TRANSACTION HISTORY
    |--------------------------------------------------------------------------
    */

    public function getTransactions(): array
    {
        return $this->request(
            "GET",
            "transactions/"
        );
    }


    /*
    |--------------------------------------------------------------------------
    | GET SINGLE TRANSACTION
    |--------------------------------------------------------------------------
    */

    public function getTransaction(
        int $transactionId
    ): array {

        if ($transactionId <= 0) {

            return [
                "ok" => false,

                "state" =>
                    "failed",

                "http_status" =>
                    0,

                "message" =>
                    "Invalid supplier transaction ID.",

                "data" =>
                    null
            ];
        }


        return $this->request(
            "GET",
            "transactions/" .
            $transactionId .
            "/"
        );
    }
}