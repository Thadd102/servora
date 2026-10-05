<?php

class DataOrderResolutionService
{
    private PDO $pdo;
    private DataOrderReconciliationService $reconciliationService;


    /*
    |--------------------------------------------------------------------------
    | CONSTRUCTOR
    |--------------------------------------------------------------------------
    */

    public function __construct(
        PDO $pdo,
        DataOrderReconciliationService $reconciliationService
    ) {
        $this->pdo = $pdo;
        $this->reconciliationService = $reconciliationService;
    }


    /*
    |--------------------------------------------------------------------------
    | CONFIRM SUPPLIER SUCCESS
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    |
    | This method does NOT trust anything sent from the browser except
    | the order ID.
    |
    | Before changing the order it performs reconciliation again.
    |
    | The order can only be confirmed when:
    |
    | - order is still processing
    | - reconciliation succeeds
    | - state is high_confidence_candidate
    | - exactly one candidate exists
    | - candidate classification is high_confidence
    | - supplier transaction is completed/successful
    | - provider reference exists
    | - provider reference is not already attached elsewhere
    | - there is no cross-order collision
    |
    */

    public function confirmSupplierSuccess(
        int $orderId,
        int $adminId
    ): array {

        if ($orderId <= 0) {
            return $this->fail(
                "invalid_order",
                "Invalid data order."
            );
        }

        if ($adminId <= 0) {
            return $this->fail(
                "invalid_admin",
                "Invalid administrator."
            );
        }


        /*
        |--------------------------------------------------------------------------
        | RECONCILE AGAIN
        |--------------------------------------------------------------------------
        |
        | Never rely on reconciliation information previously displayed
        | in the browser.
        |
        */

        try {

            $reconciliation =
                $this->reconciliationService
                    ->inspect($orderId);

        } catch (Throwable $e) {

            error_log(
                "Data order resolution reconciliation failed."
            );

            return $this->fail(
                "reconciliation_error",
                "The supplier transaction could not be verified."
            );
        }


        if (
            !is_array($reconciliation) ||
            ($reconciliation["success"] ?? false) !== true
        ) {

            return $this->fail(
                "reconciliation_failed",
                "The supplier transaction could not be verified."
            );
        }


        /*
        |--------------------------------------------------------------------------
        | REQUIRE EXACT HIGH-CONFIDENCE STATE
        |--------------------------------------------------------------------------
        */

        if (
            ($reconciliation["state"] ?? "")
            !== "high_confidence_candidate"
        ) {

            return $this->fail(
                "not_high_confidence",
                "This order does not have a unique high-confidence supplier match."
            );
        }


        /*
        |--------------------------------------------------------------------------
        | GET EXACT CANDIDATE
        |--------------------------------------------------------------------------
        */

        $candidate =
            $reconciliation["candidate"]
            ?? null;

        if (!is_array($candidate)) {

            return $this->fail(
                "candidate_missing",
                "The supplier transaction candidate is unavailable."
            );
        }


        /*
        |--------------------------------------------------------------------------
        | VERIFY CLASSIFICATION
        |--------------------------------------------------------------------------
        */

        if (
            ($candidate["classification"] ?? "")
            !== "high_confidence"
        ) {

            return $this->fail(
                "candidate_not_confirmed",
                "The supplier transaction is not classified as high confidence."
            );
        }


        /*
        |--------------------------------------------------------------------------
        | BLOCK CROSS-ORDER COLLISION
        |--------------------------------------------------------------------------
        */

        if (
            !empty(
                $candidate[
                    "has_cross_order_collision"
                ]
            )
        ) {

            return $this->fail(
                "cross_order_collision",
                "Another Servora order could match this supplier transaction."
            );
        }


        /*
        |--------------------------------------------------------------------------
        | VERIFY CORE MATCH EVIDENCE AGAIN
        |--------------------------------------------------------------------------
        */

        if (
            empty($candidate["phone_match"]) ||
            empty($candidate["amount_match"]) ||
            empty($candidate["data_match"]) ||
            empty($candidate["network_match"])
        ) {

            return $this->fail(
                "insufficient_evidence",
                "The supplier transaction does not contain enough matching evidence."
            );
        }


        /*
        |--------------------------------------------------------------------------
        | PROVIDER REFERENCE
        |--------------------------------------------------------------------------
        |
        | Our reconciliation service only calls a candidate high-confidence
        | when the provider reference exists and is available.
        |
        */

        $providerReference =
            trim(
                (string) (
                    $candidate[
                        "provider_reference"
                    ]
                    ?? ""
                )
            );

        if ($providerReference === "") {

            return $this->fail(
                "provider_reference_missing",
                "The supplier transaction has no usable provider reference."
            );
        }


        if (
            empty(
                $candidate[
                    "reference_available"
                ]
            )
        ) {

            return $this->fail(
                "provider_reference_unavailable",
                "The supplier transaction reference cannot be safely assigned."
            );
        }


        /*
        |--------------------------------------------------------------------------
        | VERIFY SUPPLIER COMPLETION
        |--------------------------------------------------------------------------
        |
        | CheapDataHub transaction history currently exposes "completed".
        |
        | Accept only explicit successful values.
        | Unknown/null values fail closed.
        |
        */

        $completed =
            $candidate["completed"]
            ?? null;

        $supplierCompleted =
            $this->isCompletedValue(
                $completed
            );

        if (!$supplierCompleted) {

            return $this->fail(
                "supplier_not_completed",
                "The supplier transaction is not explicitly confirmed as completed."
            );
        }


        /*
        |--------------------------------------------------------------------------
        | BEGIN DATABASE TRANSACTION
        |--------------------------------------------------------------------------
        */

        try {

            $this->pdo->beginTransaction();


            /*
            |--------------------------------------------------------------------------
            | LOCK ORDER
            |--------------------------------------------------------------------------
            */

            $stmt = $this->pdo->prepare("
                SELECT
                    id,
                    user_id,
                    status,
                    provider_reference,
                    refund_transaction_id,
                    wallet_transaction_id
                FROM data_orders
                WHERE id = ?
                LIMIT 1
                FOR UPDATE
            ");

            $stmt->execute([
                $orderId
            ]);

            $order =
                $stmt->fetch(
                    PDO::FETCH_ASSOC
                );


            if (!$order) {

                $this->pdo->rollBack();

                return $this->fail(
                    "order_not_found",
                    "Data order not found."
                );
            }


            /*
            |--------------------------------------------------------------------------
            | ORDER MUST STILL BE PROCESSING
            |--------------------------------------------------------------------------
            */

            $currentStatus =
                strtolower(
                    trim(
                        (string)
                        $order["status"]
                    )
                );


            if ($currentStatus !== "processing") {

                $this->pdo->rollBack();

                return $this->fail(
                    "order_not_processing",
                    "This order is no longer processing."
                );
            }


            /*
            |--------------------------------------------------------------------------
            | BLOCK REFUNDED ORDERS
            |--------------------------------------------------------------------------
            */

            if (
                !empty(
                    $order[
                        "refund_transaction_id"
                    ]
                )
            ) {

                $this->pdo->rollBack();

                return $this->fail(
                    "order_refunded",
                    "This order already has a refund transaction."
                );
            }


            /*
            |--------------------------------------------------------------------------
            | REQUIRE ORIGINAL WALLET DEBIT
            |--------------------------------------------------------------------------
            */

            if (
                empty(
                    $order[
                        "wallet_transaction_id"
                    ]
                )
            ) {

                $this->pdo->rollBack();

                return $this->fail(
                    "wallet_debit_missing",
                    "The original wallet debit is not linked to this order."
                );
            }


            /*
            |--------------------------------------------------------------------------
            | VERIFY ADMIN
            |--------------------------------------------------------------------------
            */

            $stmt = $this->pdo->prepare("
                SELECT
                    id,
                    role,
                    status
                FROM users
                WHERE id = ?
                LIMIT 1
                FOR UPDATE
            ");

            $stmt->execute([
                $adminId
            ]);

            $admin =
                $stmt->fetch(
                    PDO::FETCH_ASSOC
                );


            if (!$admin) {

                $this->pdo->rollBack();

                return $this->fail(
                    "admin_not_found",
                    "Administrator account could not be verified."
                );
            }


            $adminRole =
                strtolower(
                    trim(
                        (string)
                        $admin["role"]
                    )
                );

            $adminStatus =
                strtolower(
                    trim(
                        (string)
                        $admin["status"]
                    )
                );


            if (
                !in_array(
                    $adminRole,
                    [
                        "admin",
                        "super_admin"
                    ],
                    true
                ) ||
                $adminStatus !== "active"
            ) {

                $this->pdo->rollBack();

                return $this->fail(
                    "admin_not_authorized",
                    "Administrator account is not authorized."
                );
            }


            /*
            |--------------------------------------------------------------------------
            | VERIFY PROVIDER REFERENCE UNIQUENESS
            |--------------------------------------------------------------------------
            |
            | Check again inside the transaction.
            |
            */

            $stmt = $this->pdo->prepare("
                SELECT id
                FROM data_orders
                WHERE provider_reference = ?
                  AND id <> ?
                LIMIT 1
                FOR UPDATE
            ");

            $stmt->execute([
                $providerReference,
                $orderId
            ]);

            if ($stmt->fetchColumn()) {

                $this->pdo->rollBack();

                return $this->fail(
                    "provider_reference_conflict",
                    "This supplier reference is already linked to another data order."
                );
            }


            /*
            |--------------------------------------------------------------------------
            | CHECK PROVIDER TRANSACTION TABLE TOO
            |--------------------------------------------------------------------------
            */

            $stmt = $this->pdo->prepare("
                SELECT id
                FROM data_provider_transactions
                WHERE provider_reference = ?
                  AND order_id <> ?
                LIMIT 1
                FOR UPDATE
            ");

            $stmt->execute([
                $providerReference,
                $orderId
            ]);

            if ($stmt->fetchColumn()) {

                $this->pdo->rollBack();

                return $this->fail(
                    "provider_reference_conflict",
                    "This supplier reference is already linked to another provider transaction."
                );
            }


            /*
            |--------------------------------------------------------------------------
            | UPDATE ORDER
            |--------------------------------------------------------------------------
            */

            $providerMessage =
                "Supplier delivery confirmed through high-confidence reconciliation.";


            $stmt = $this->pdo->prepare("
                UPDATE data_orders
                SET
                    status = 'successful',
                    provider_reference = ?,
                    provider_message = ?,
                    completed_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?
                  AND status = 'processing'
            ");

            $stmt->execute([
                $providerReference,
                $providerMessage,
                $orderId
            ]);


            if ($stmt->rowCount() !== 1) {

                $this->pdo->rollBack();

                return $this->fail(
                    "order_changed",
                    "The order changed while confirmation was being processed."
                );
            }


            /*
            |--------------------------------------------------------------------------
            | UPDATE THIS ORDER'S LATEST PROVIDER TRANSACTION
            |--------------------------------------------------------------------------
            |
            | We do not create another supplier purchase.
            | We only update the existing uncertain transaction trail.
            |
            */

            $stmt = $this->pdo->prepare("
                SELECT id
                FROM data_provider_transactions
                WHERE order_id = ?
                ORDER BY id DESC
                LIMIT 1
                FOR UPDATE
            ");

            $stmt->execute([
                $orderId
            ]);

            $providerTransactionId =
                $stmt->fetchColumn();


            if ($providerTransactionId) {

                $stmt = $this->pdo->prepare("
                    UPDATE data_provider_transactions
                    SET
                        provider_reference = ?,
                        status = 'successful',
                        response_message = ?,
                        last_checked_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                ");

                $stmt->execute([
                    $providerReference,
                    $providerMessage,
                    (int)
                    $providerTransactionId
                ]);
            }


            /*
            |--------------------------------------------------------------------------
            | COMMIT
            |--------------------------------------------------------------------------
            */

            $this->pdo->commit();


            return [
                "success" =>
                    true,

                "state" =>
                    "confirmed_successful",

                "message" =>
                    "The supplier transaction was confirmed and the order was marked successful.",

                "order_id" =>
                    $orderId,

                "provider_reference" =>
                    $providerReference
            ];


        } catch (Throwable $e) {

            if (
                $this->pdo->inTransaction()
            ) {
                $this->pdo->rollBack();
            }

            error_log(
                "Data order success resolution failed."
            );

            return $this->fail(
                "database_error",
                "The order could not be updated safely."
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | SUPPLIER COMPLETED VALUE
    |--------------------------------------------------------------------------
    */

    private function isCompletedValue(
        mixed $value
    ): bool {

        if ($value === true) {
            return true;
        }

        if ($value === 1) {
            return true;
        }

        if ($value === "1") {
            return true;
        }

        if (!is_string($value)) {
            return false;
        }


        $value =
            strtolower(
                trim($value)
            );


        return in_array(
            $value,
            [
                "true",
                "yes",
                "completed",
                "complete",
                "successful",
                "success"
            ],
            true
        );
    }


    /*
    |--------------------------------------------------------------------------
    | FAILURE RESPONSE
    |--------------------------------------------------------------------------
    */

    private function fail(
        string $state,
        string $message
    ): array {

        return [
            "success" => false,
            "state" => $state,
            "message" => $message
        ];
    }
}