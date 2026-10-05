<?php

require_once __DIR__ . "/CheapDataHubClient.php";
require_once __DIR__ . "/VTPassDataClient.php";
require_once __DIR__ . "/DataOrderRefundService.php";

class DataProviderProcessor
{
    private PDO $pdo;
    private ?CheapDataHubClient $cheapDataHub;
    private ?VTPassDataClient $vtpassClient;
    private DataOrderRefundService $refundService;


    /*
    |--------------------------------------------------------------------------
    | CONSTRUCTOR
    |--------------------------------------------------------------------------
    */

    public function __construct(
        PDO $pdo,
        ?CheapDataHubClient $cheapDataHub = null,
        ?VTPassDataClient $vtpassClient = null
    ) {
        $this->pdo = $pdo;
        $this->cheapDataHub = $cheapDataHub;
        $this->vtpassClient = $vtpassClient;

        $this->refundService = new DataOrderRefundService($pdo);
    }


    /*
    |--------------------------------------------------------------------------
    | PROCESS DATA ORDER
    |--------------------------------------------------------------------------
    */

    public function process(int $orderId): array
    {
        $providerTransactionId = null;
        $order = null;


        /*
        |--------------------------------------------------------------------------
        | STEP 1: LOCK AND CLAIM ORDER
        |--------------------------------------------------------------------------
        |
        | Only one request may change:
        |
        | pending -> processing
        |
        | The external supplier API is NOT called while this transaction is open.
        |
        */

        try {

            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("
                SELECT
                    do.id,
                    do.order_reference,
                    do.user_id,
                    do.phone_number,
                    do.status,

                    dp.provider_id,
                    dp.provider_plan_code,
                    dp.cost_price,

                    n.slug AS network_slug,

                    ap.slug AS provider_slug,
                    ap.name AS provider_name

                FROM data_orders do

                INNER JOIN data_plans dp
                    ON dp.id = do.plan_id

                INNER JOIN networks n
                    ON n.id = dp.network_id

                LEFT JOIN api_providers ap
                    ON ap.id = dp.provider_id

                WHERE do.id = ?

                LIMIT 1

                FOR UPDATE
            ");

            $stmt->execute([$orderId]);

            $order = $stmt->fetch(PDO::FETCH_ASSOC);


            /*
            |--------------------------------------------------------------------------
            | ORDER NOT FOUND
            |--------------------------------------------------------------------------
            */

            if (!$order) {
                $this->pdo->rollBack();

                return [
                    "success" => false,
                    "state" => "failed",
                    "message" => "Data order not found."
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | DO NOT SEND NON-PENDING ORDERS AGAIN
            |--------------------------------------------------------------------------
            */

            if ($order["status"] !== "pending") {
                $this->pdo->rollBack();

                if ($order["status"] === "processing") {
                    return [
                        "success" => false,
                        "state" => "processing",
                        "message" => "This order is already being processed."
                    ];
                }

                return [
                    "success" => false,
                    "state" => "already_processed",
                    "message" => "This order has already been processed."
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | VALIDATE PROVIDER CONFIGURATION
            |--------------------------------------------------------------------------
            */

            if (
                empty($order["provider_id"]) ||
                empty($order["provider_plan_code"])
            ) {
                $this->pdo->rollBack();

                return [
                    "success" => false,
                    "state" => "configuration_error",
                    "message" => "Supplier is not configured for this plan."
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | SUPPORTED PROVIDERS: CheapDataHub & VTPass
            |--------------------------------------------------------------------------
            */

            $providerSlug = strtolower(trim((string)($order["provider_slug"] ?? '')));

            if (!in_array($providerSlug, ["cheapdatahub", "vtpass"], true)) {
                $this->pdo->rollBack();

                return [
                    "success" => false,
                    "state" => "configuration_error",
                    "message" => "Unsupported data supplier."
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | VALIDATE PROVIDER PLAN CODE & SETUP REQUEST REFERENCE
            |--------------------------------------------------------------------------
            */

            $bundleId = null;
            $variationCode = null;
            $requestReference = "";

            if ($providerSlug === "cheapdatahub") {
                $bundleId = filter_var($order["provider_plan_code"], FILTER_VALIDATE_INT);

                if ($bundleId === false || $bundleId <= 0) {
                    $this->pdo->rollBack();

                    return [
                        "success" => false,
                        "state" => "configuration_error",
                        "message" => "Invalid supplier bundle configuration."
                    ];
                }

                $requestReference = "CDH-" . $order["order_reference"] . "-" . strtoupper(bin2hex(random_bytes(3)));
            } elseif ($providerSlug === "vtpass") {
                $variationCode = trim((string)$order["provider_plan_code"]);

                if ($variationCode === "") {
                    $this->pdo->rollBack();

                    return [
                        "success" => false,
                        "state" => "configuration_error",
                        "message" => "Invalid supplier variation code configuration."
                    ];
                }

                $requestReference = VTPassDataClient::generateRequestId($order["order_reference"]);
            }


            /*
            |--------------------------------------------------------------------------
            | RECORD PROVIDER ATTEMPT
            |--------------------------------------------------------------------------
            */

            $stmt = $this->pdo->prepare("
                INSERT INTO data_provider_transactions
                (
                    order_id,
                    provider_id,
                    request_reference,
                    action,
                    status,
                    attempt_no
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    'purchase',
                    'pending',
                    1
                )
            ");

            $stmt->execute([
                $order["id"],
                $order["provider_id"],
                $requestReference
            ]);

            $providerTransactionId = (int) $this->pdo->lastInsertId();

            if ($providerTransactionId <= 0) {
                throw new RuntimeException("Could not create provider transaction.");
            }


            /*
            |--------------------------------------------------------------------------
            | CLAIM ORDER
            |--------------------------------------------------------------------------
            */

            $providerDisplayName = trim((string)($order["provider_name"] ?? ''));
            if ($providerDisplayName === '') {
                $providerDisplayName = ($providerSlug === 'vtpass') ? 'VTpass' : 'CheapDataHub';
            }

            $stmt = $this->pdo->prepare("
                UPDATE data_orders
                SET status = 'processing',
                    provider_name = ?
                WHERE id = ?
                  AND status = 'pending'
            ");

            $stmt->execute([
                $providerDisplayName,
                $order["id"]
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException("Could not claim data order for processing.");
            }


            /*
            |--------------------------------------------------------------------------
            | COMMIT BEFORE EXTERNAL API CALL
            |--------------------------------------------------------------------------
            */

            $this->pdo->commit();

        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            error_log("Data provider claim error: " . $e->getMessage());

            return [
                "success" => false,
                "state" => "failed",
                "message" => "Could not prepare data order for processing."
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | STEP 2: CALL SUPPLIER API (CHEAPDATAHUB OR VTPASS)
        |--------------------------------------------------------------------------
        |
        | The order is already locked in 'processing'.
        | Therefore another concurrent request cannot purchase the same order again.
        |
        */

        try {

            if ($providerSlug === "cheapdatahub") {
                if ($this->cheapDataHub === null) {
                    $apiKey = $_ENV["CHEAPDATAHUB_API_KEY"] ?? getenv("CHEAPDATAHUB_API_KEY");
                    $baseUrl = $_ENV["CHEAPDATAHUB_BASE_URL"] ?? getenv("CHEAPDATAHUB_BASE_URL") ?: "https://www.cheapdatahub.ng/api/v1/resellers/";
                    $this->cheapDataHub = new CheapDataHubClient($apiKey, $baseUrl);
                }

                $result = $this->cheapDataHub->purchaseData(
                    (int)$bundleId,
                    $order["phone_number"]
                );
            } elseif ($providerSlug === "vtpass") {
                if ($this->vtpassClient === null) {
                    $this->vtpassClient = new VTPassDataClient();
                }

                $networkSlug = strtolower(trim((string)($order["network_slug"] ?? '')));
                $serviceIdMap = [
                    'mtn'     => 'mtn-data',
                    'airtel'  => 'airtel-data',
                    'glo'     => 'glo-data',
                    't2'      => 'etisalat-data',
                    '9mobile' => 'etisalat-data'
                ];
                $serviceId = $serviceIdMap[$networkSlug] ?? 'mtn-data';
                $costAmount = (float)($order["cost_price"] ?? 0);

                $result = $this->vtpassClient->purchaseData(
                    $serviceId,
                    $variationCode,
                    $costAmount,
                    $order["phone_number"],
                    $requestReference
                );
            }

        } catch (Throwable $e) {

            /*
            |--------------------------------------------------------------------------
            | EXCEPTION / CONNECTION UNCERTAINTY
            |--------------------------------------------------------------------------
            |
            | The supplier may have received the request.
            | NEVER refund automatically.
            |
            */

            error_log("Data supplier request error ({$providerSlug}): " . $e->getMessage());

            $this->markUnknown(
                $providerTransactionId,
                $orderId,
                null,
                "Supplier connection failed: " . $e->getMessage(),
                $providerDisplayName
            );

            return [
                "success" => false,
                "state" => "processing",
                "message" => "Supplier response is uncertain. Order remains processing."
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | NORMALIZE SUPPLIER RESULT
        |--------------------------------------------------------------------------
        */

        $httpStatus = (int) ($result["http_status"] ?? 0);

        $message = trim((string) ($result["message"] ?? "No supplier message."));
        if ($message === "") {
            $message = "No supplier message.";
        }

        $response = $result["data"] ?? null;


        /*
        |--------------------------------------------------------------------------
        | STEP 3: CONFIRMED SUCCESS
        |--------------------------------------------------------------------------
        */

        $isConfirmedSuccess = false;
        $providerReference = null;

        if ($providerSlug === "cheapdatahub") {
            if (
                ($result["ok"] ?? false) === true &&
                is_array($response) &&
                isset($response["status"]) &&
                in_array(
                    $response["status"],
                    [true, 1, "1", "true", "successful", "success"],
                    true
                )
            ) {
                $isConfirmedSuccess = true;

                $possibleProviderReferences = [
                    $response["reference"] ?? null,
                    $response["tx_ref"] ?? null,
                    $response["data"]["reference"] ?? null,
                    $response["data"]["tx_ref"] ?? null
                ];

                foreach ($possibleProviderReferences as $possibleReference) {
                    if (is_scalar($possibleReference) && trim((string) $possibleReference) !== "") {
                        $providerReference = trim((string) $possibleReference);
                        break;
                    }
                }
            }
        } elseif ($providerSlug === "vtpass") {
            $vtState = (string)($result["state"] ?? "");
            $vtCode = (string)($result["code"] ?? "");

            if (
                ($result["ok"] ?? false) === true &&
                ($vtState === "successful" || $vtCode === "000")
            ) {
                $isConfirmedSuccess = true;

                $possibleReferences = [
                    $response["content"]["transactions"]["transactionId"] ?? null,
                    $response["transactionId"] ?? null,
                    $response["requestId"] ?? null,
                    $requestReference
                ];

                foreach ($possibleReferences as $cand) {
                    if (is_scalar($cand) && trim((string)$cand) !== "") {
                        $providerReference = trim((string)$cand);
                        break;
                    }
                }
            }
        }

        if ($isConfirmedSuccess) {
            try {
                $this->pdo->beginTransaction();

                /*
                |--------------------------------------------------------------------------
                | UPDATE PROVIDER TRANSACTION
                |--------------------------------------------------------------------------
                */

                $stmt = $this->pdo->prepare("
                    UPDATE data_provider_transactions
                    SET provider_reference = ?,
                        status = 'successful',
                        http_status = ?,
                        response_message = ?
                    WHERE id = ?
                      AND order_id = ?
                ");

                $stmt->execute([
                    $providerReference,
                    $httpStatus ?: null,
                    substr($message, 0, 255),
                    $providerTransactionId,
                    $orderId
                ]);

                /*
                |--------------------------------------------------------------------------
                | UPDATE ORDER
                |--------------------------------------------------------------------------
                */

                $stmt = $this->pdo->prepare("
                    UPDATE data_orders
                    SET provider_name = ?,
                        provider_reference = ?,
                        provider_message = ?,
                        status = 'successful',
                        completed_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                      AND status = 'processing'
                ");

                $stmt->execute([
                    $providerDisplayName,
                    $providerReference,
                    substr($message, 0, 65535),
                    $orderId
                ]);

                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException("Could not mark data order successful.");
                }

                $this->pdo->commit();

            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                error_log("Data order success update error: " . $e->getMessage());

                return [
                    "success" => false,
                    "state" => "requires_review",
                    "message" => "Supplier may have completed the order, but the local record could not be updated."
                ];
            }

            return [
                "success" => true,
                "state" => "successful",
                "message" => $message,
                "provider_reference" => $providerReference
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | STEP 4: UNCERTAIN RESULT
        |--------------------------------------------------------------------------
        |
        | Examples:
        | - network timeout
        | - cURL error
        | - invalid/non-JSON supplier response
        | - HTTP 409
        | - HTTP 500+
        | - pending states ('099', '016', or explicit processing state)
        |
        | DO NOT REFUND.
        | DO NOT RESEND.
        |
        */

        $isUncertain = (
            ($result["state"] ?? "") === "unknown" ||
            ($result["state"] ?? "") === "processing" ||
            in_array(($result["code"] ?? ""), ["099", "016"], true) ||
            $httpStatus === 409 ||
            ($httpStatus >= 500 && $httpStatus <= 599)
        );

        if ($isUncertain) {
            $this->markUnknown(
                $providerTransactionId,
                $orderId,
                $httpStatus ?: null,
                $message,
                $providerDisplayName
            );

            return [
                "success" => false,
                "state" => "processing",
                "message" => "Supplier response requires verification. Order remains processing."
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | STEP 5: CONFIRMED SUPPLIER REJECTION
        |--------------------------------------------------------------------------
        */

        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("
                UPDATE data_provider_transactions
                SET status = 'failed',
                    http_status = ?,
                    response_message = ?
                WHERE id = ?
                  AND order_id = ?
            ");

            $stmt->execute([
                $httpStatus ?: null,
                substr($message, 0, 255),
                $providerTransactionId,
                $orderId
            ]);

            $stmt = $this->pdo->prepare("
                UPDATE data_orders
                SET provider_name = ?,
                    provider_message = ?
                WHERE id = ?
                  AND status = 'processing'
            ");

            $stmt->execute([
                $providerDisplayName,
                substr($message, 0, 65535),
                $orderId
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException("Could not record supplier failure on data order.");
            }

            $this->pdo->commit();

        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            error_log("Data provider failure update error: " . $e->getMessage());

            return [
                "success" => false,
                "state" => "requires_review",
                "message" => "Supplier rejected the request, but Subnext could not safely prepare the refund."
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | STEP 6: REFUND CONFIRMED FAILURE
        |--------------------------------------------------------------------------
        */

        $refund = $this->refundService->refund(
            $orderId,
            $message
        );

        if (($refund["success"] ?? false) === true) {
            return [
                "success" => false,
                "state" => "refunded",
                "message" => "Data purchase failed and the amount has been returned to the customer's wallet.",
                "supplier_message" => $message,
                "refund_amount" => $refund["amount"] ?? null
            ];
        }

        error_log(
            "Confirmed data failure could not be refunded. " .
            "Order ID: " . $orderId .
            " | Reason: " . ($refund["message"] ?? "Unknown refund error.")
        );

        return [
            "success" => false,
            "state" => "requires_review",
            "message" => "Data purchase failed, but the automatic wallet refund could not be completed. Please review the order."
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | MARK UNCERTAIN PROVIDER RESULT
    |--------------------------------------------------------------------------
    */

    private function markUnknown(
        int $providerTransactionId,
        int $orderId,
        ?int $httpStatus,
        string $message,
        string $providerName = ""
    ): void {
        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("
                UPDATE data_provider_transactions
                SET status = 'unknown',
                    http_status = ?,
                    response_message = ?
                WHERE id = ?
                  AND order_id = ?
            ");

            $stmt->execute([
                $httpStatus,
                substr($message, 0, 255),
                $providerTransactionId,
                $orderId
            ]);

            if ($providerName !== "") {
                $stmt = $this->pdo->prepare("
                    UPDATE data_orders
                    SET provider_name = ?,
                        provider_message = ?,
                        status = 'processing'
                    WHERE id = ?
                      AND status = 'processing'
                ");

                $stmt->execute([
                    $providerName,
                    substr($message, 0, 65535),
                    $orderId
                ]);
            } else {
                $stmt = $this->pdo->prepare("
                    UPDATE data_orders
                    SET provider_message = ?,
                        status = 'processing'
                    WHERE id = ?
                      AND status = 'processing'
                ");

                $stmt->execute([
                    substr($message, 0, 65535),
                    $orderId
                ]);
            }

            $this->pdo->commit();

        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            error_log("Unknown provider response update error: " . $e->getMessage());
        }
    }
}