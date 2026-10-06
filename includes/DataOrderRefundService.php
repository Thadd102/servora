<?php

class DataOrderRefundService
{
    private PDO $pdo;


    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }


    /*
    |--------------------------------------------------------------------------
    | REFUND DATA ORDER
    |--------------------------------------------------------------------------
    |
    | Refunds a confirmed failed data purchase exactly once.
    |
    | NEVER use this method for:
    |
    | - timeout
    | - HTTP 409
    | - HTTP 500+
    | - unknown supplier result
    | - processing supplier result
    | - successful supplier result
    |
    */

    public function refund(
        int $orderId,
        string $reason = "Data purchase failed"
    ): array {

        if ($orderId <= 0) {

            return [
                "success" => false,
                "message" => "Invalid data order."
            ];
        }


        try {

            /*
            |--------------------------------------------------------------------------
            | START REFUND TRANSACTION
            |--------------------------------------------------------------------------
            */

            $this->pdo->beginTransaction();


            /*
            |--------------------------------------------------------------------------
            | LOCK DATA ORDER
            |--------------------------------------------------------------------------
            */

            $stmt = $this->pdo->prepare("
                SELECT
                    id,
                    order_reference,
                    user_id,
                    selling_price,
                    status,
                    wallet_transaction_id,
                    refund_transaction_id

                FROM data_orders

                WHERE id = ?

                LIMIT 1

                FOR UPDATE
            ");

            $stmt->execute([
                $orderId
            ]);

            $order = $stmt->fetch(
                PDO::FETCH_ASSOC
            );


            /*
            |--------------------------------------------------------------------------
            | ORDER NOT FOUND
            |--------------------------------------------------------------------------
            */

            if (!$order) {

                $this->pdo->rollBack();

                return [
                    "success" => false,
                    "message" =>
                        "Data order was not found."
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | ALREADY REFUNDED
            |--------------------------------------------------------------------------
            |
            | refund_transaction_id is our main database-level link proving
            | that this order has already received a refund.
            |
            */

            if (
                !empty(
                    $order["refund_transaction_id"]
                ) ||
                $order["status"] === "refunded"
            ) {

                $this->pdo->rollBack();

                return [
                    "success" => true,
                    "already_refunded" => true,
                    "message" =>
                        "Order has already been refunded."
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | ORIGINAL WALLET DEBIT MUST EXIST
            |--------------------------------------------------------------------------
            |
            | We should never refund an order that Servora cannot prove was
            | originally charged to the customer's wallet.
            |
            */

            if (
                empty(
                    $order["wallet_transaction_id"]
                )
            ) {

                $this->pdo->rollBack();

                return [
                    "success" => false,
                    "message" =>
                        "Original wallet debit is missing."
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | VERIFY ORIGINAL WALLET DEBIT
            |--------------------------------------------------------------------------
            */

            $stmt = $this->pdo->prepare("
                SELECT
                    id,
                    user_id,
                    type,
                    amount,
                    reference,
                    status

                FROM wallet_transactions

                WHERE id = ?

                LIMIT 1

                FOR UPDATE
            ");

            $stmt->execute([
                $order["wallet_transaction_id"]
            ]);

            $originalDebit = $stmt->fetch(
                PDO::FETCH_ASSOC
            );


            if (!$originalDebit) {

                $this->pdo->rollBack();

                return [
                    "success" => false,
                    "message" =>
                        "Original wallet transaction was not found."
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | VERIFY ORIGINAL DEBIT BELONGS TO CUSTOMER
            |--------------------------------------------------------------------------
            */

            if (
                (int) $originalDebit["user_id"]
                !==
                (int) $order["user_id"]
            ) {

                throw new RuntimeException(
                    "Original wallet transaction belongs to another customer."
                );
            }


            /*
            |--------------------------------------------------------------------------
            | VERIFY ORIGINAL TRANSACTION TYPE
            |--------------------------------------------------------------------------
            */

            if (
                $originalDebit["type"]
                !== "data_purchase"
            ) {

                throw new RuntimeException(
                    "Original wallet transaction is not a data purchase."
                );
            }


            /*
            |--------------------------------------------------------------------------
            | VERIFY ORIGINAL TRANSACTION STATUS
            |--------------------------------------------------------------------------
            */

            if (
                $originalDebit["status"]
                !== "successful"
            ) {

                $this->pdo->rollBack();

                return [
                    "success" => false,
                    "message" =>
                        "Original wallet debit is not a successful transaction."
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | REFUNDABLE ORDER STATUS
            |--------------------------------------------------------------------------
            */

            if (
                !in_array(
                    $order["status"],
                    [
                        "processing",
                        "failed"
                    ],
                    true
                )
            ) {

                $this->pdo->rollBack();

                return [
                    "success" => false,
                    "message" =>
                        "Order is not eligible for refund."
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | VERIFY CONFIRMED PROVIDER FAILURE
            |--------------------------------------------------------------------------
            */

            $stmt = $this->pdo->prepare("
                SELECT
                    id,
                    status,
                    http_status,
                    response_message

                FROM data_provider_transactions

                WHERE order_id = ?
                  AND action = 'purchase'

                ORDER BY id DESC

                LIMIT 1

                FOR UPDATE
            ");

            $stmt->execute([
                $orderId
            ]);

            $providerTransaction =
                $stmt->fetch(
                    PDO::FETCH_ASSOC
                );


            if (!$providerTransaction) {

                $this->pdo->rollBack();

                return [
                    "success" => false,
                    "message" =>
                        "Provider transaction was not found."
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | PROVIDER MUST BE DEFINITELY FAILED
            |--------------------------------------------------------------------------
            */

            if (
                $providerTransaction["status"]
                !== "failed"
            ) {

                $this->pdo->rollBack();

                return [
                    "success" => false,
                    "message" =>
                        "Supplier transaction is not a confirmed failure."
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | CHECK HTTP STATUS
            |--------------------------------------------------------------------------
            */

            $httpStatus =
                (int) (
                    $providerTransaction["http_status"]
                    ?? 0
                );


            /*
            |--------------------------------------------------------------------------
            | NEVER REFUND UNCERTAIN RESPONSES
            |--------------------------------------------------------------------------
            |
            | 409:
            | Supplier may regard request as duplicate.
            |
            | 5xx:
            | Supplier/server uncertainty.
            |
            */

            if (
                $httpStatus === 409 ||
                (
                    $httpStatus >= 500 &&
                    $httpStatus <= 599
                )
            ) {

                $this->pdo->rollBack();

                return [
                    "success" => false,
                    "message" =>
                        "Supplier result requires investigation before refund."
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | VALIDATE REFUND AMOUNT
            |--------------------------------------------------------------------------
            */

            $refundAmount =
                (float)
                $order["selling_price"];


            $originalDebitAmount =
                (float)
                $originalDebit["amount"];


            if ($refundAmount <= 0) {

                throw new RuntimeException(
                    "Invalid refund amount."
                );
            }


            /*
            |--------------------------------------------------------------------------
            | VERIFY ORDER AMOUNT AGAINST ORIGINAL DEBIT
            |--------------------------------------------------------------------------
            |
            | The amount returned must correspond to the amount that was
            | actually charged.
            |
            */

            if (
                abs(
                    $refundAmount -
                    $originalDebitAmount
                ) > 0.009
            ) {

                throw new RuntimeException(
                    "Refund amount does not match original wallet debit."
                );
            }


            /*
            |--------------------------------------------------------------------------
            | CREATE UNIQUE REFUND REFERENCE
            |--------------------------------------------------------------------------
            */

            $refundReference =
                "DATA-REFUND-" .
                $order["order_reference"];


            /*
            |--------------------------------------------------------------------------
            | CHECK FOR EXISTING REFUND TRANSACTION
            |--------------------------------------------------------------------------
            |
            | IMPORTANT:
            |
            | If this reference already exists but data_orders does not contain
            | refund_transaction_id, something is inconsistent.
            |
            | We DO NOT:
            |
            | - credit again
            | - automatically repair it
            | - assume anything
            |
            | It must be reviewed.
            |
            */

            $stmt = $this->pdo->prepare("
                SELECT
                    id,
                    user_id,
                    amount,
                    status

                FROM wallet_transactions

                WHERE reference = ?

                LIMIT 1

                FOR UPDATE
            ");

            $stmt->execute([
                $refundReference
            ]);

            $existingRefund =
                $stmt->fetch(
                    PDO::FETCH_ASSOC
                );


            if ($existingRefund) {

                $this->pdo->rollBack();

                error_log(
                    "Refund reference already exists but order is not linked. " .
                    "Order ID: " .
                    $orderId .
                    " | Refund transaction ID: " .
                    $existingRefund["id"]
                );

                return [
                    "success" => false,
                    "already_refunded" => true,
                    "requires_review" => true,
                    "message" =>
                        "A refund transaction already exists for this order. Manual review is required."
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | LOCK CUSTOMER WALLET
            |--------------------------------------------------------------------------
            */

            $stmt = $this->pdo->prepare("
                SELECT
                    id,
                    balance

                FROM wallets

                WHERE user_id = ?

                LIMIT 1

                FOR UPDATE
            ");

            $stmt->execute([
                $order["user_id"]
            ]);

            $wallet =
                $stmt->fetch(
                    PDO::FETCH_ASSOC
                );


            if (!$wallet) {

                throw new RuntimeException(
                    "Customer wallet was not found."
                );
            }


            /*
            |--------------------------------------------------------------------------
            | CALCULATE WALLET BALANCE
            |--------------------------------------------------------------------------
            */

            $balanceBefore =
                (float)
                $wallet["balance"];


            $balanceAfter =
                $balanceBefore +
                $refundAmount;


            /*
            |--------------------------------------------------------------------------
            | CREATE DESCRIPTION
            |--------------------------------------------------------------------------
            */

            $reason = trim($reason);


            if ($reason === "") {

                $reason =
                    "Data purchase failed";
            }


            $description =
                "Refund for data order " .
                $order["order_reference"] .
                ": " .
                $reason;


            /*
            |--------------------------------------------------------------------------
            | LIMIT DESCRIPTION LENGTH
            |--------------------------------------------------------------------------
            */

            if (
                function_exists(
                    "mb_strlen"
                ) &&
                function_exists(
                    "mb_substr"
                )
            ) {

                if (
                    mb_strlen(
                        $description
                    ) > 250
                ) {

                    $description =
                        mb_substr(
                            $description,
                            0,
                            250
                        );
                }

            } else {

                if (
                    strlen(
                        $description
                    ) > 250
                ) {

                    $description =
                        substr(
                            $description,
                            0,
                            250
                        );
                }
            }


            /*
            |--------------------------------------------------------------------------
            | CREDIT CUSTOMER WALLET
            |--------------------------------------------------------------------------
            */

            $stmt = $this->pdo->prepare("
                UPDATE wallets

                SET balance = balance + ?

                WHERE user_id = ?
            ");

            $stmt->execute([
                $refundAmount,
                $order["user_id"]
            ]);


            if (
                $stmt->rowCount() !== 1
            ) {

                throw new RuntimeException(
                    "Could not credit customer wallet."
                );
            }


            /*
            |--------------------------------------------------------------------------
            | CREATE REFUND WALLET TRANSACTION
            |--------------------------------------------------------------------------
            */

            $stmt = $this->pdo->prepare("
                INSERT INTO wallet_transactions (
                    user_id,
                    type,
                    amount,
                    balance_before,
                    balance_after,
                    reference,
                    description,
                    status
                )
                VALUES (
                    ?,
                    'refund',
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    'successful'
                )
            ");

            $stmt->execute([
                $order["user_id"],
                $refundAmount,
                $balanceBefore,
                $balanceAfter,
                $refundReference,
                $description
            ]);


            /*
            |--------------------------------------------------------------------------
            | GET REFUND TRANSACTION ID
            |--------------------------------------------------------------------------
            */

            $refundTransactionId =
                (int)
                $this->pdo->lastInsertId();


            if (
                $refundTransactionId <= 0
            ) {

                throw new RuntimeException(
                    "Could not retrieve refund transaction ID."
                );
            }


            /*
            |--------------------------------------------------------------------------
            | LINK REFUND TO ORDER
            |--------------------------------------------------------------------------
            */

            $stmt = $this->pdo->prepare("
                UPDATE data_orders

                SET
                    refund_transaction_id = ?,
                    status = 'refunded',
                    completed_at = CURRENT_TIMESTAMP

                WHERE id = ?
                  AND refund_transaction_id IS NULL
                  AND status IN (
                      'processing',
                      'failed'
                  )
            ");

            $stmt->execute([
                $refundTransactionId,
                $orderId
            ]);


            if (
                $stmt->rowCount() !== 1
            ) {

                throw new RuntimeException(
                    "Could not link refund to data order."
                );
            }


            /*
            |--------------------------------------------------------------------------
            | COMMIT REFUND
            |--------------------------------------------------------------------------
            |
            | The following happen atomically:
            |
            | 1. Wallet credited
            | 2. Refund transaction created
            | 3. Refund linked to order
            | 4. Order marked refunded
            |
            | If anything fails, everything rolls back.
            |
            */

            $this->pdo->commit();


            return [
                "success" => true,
                "already_refunded" => false,
                "refund_transaction_id" =>
                    $refundTransactionId,
                "amount" =>
                    $refundAmount,
                "balance_before" =>
                    $balanceBefore,
                "balance_after" =>
                    $balanceAfter,
                "message" =>
                    "Customer wallet refunded successfully."
            ];


        } catch (Throwable $e) {

            if (
                $this->pdo->inTransaction()
            ) {

                $this->pdo->rollBack();
            }


            error_log(
                "Subnext data refund error for order " .
                $orderId .
                ": " .
                $e->getMessage()
            );


            return [
                "success" => false,
                "message" =>
                    "Refund could not be completed."
            ];
        }
    }
}