<?php

require_once __DIR__ . "/CheapDataHubClient.php";

class DataOrderReconciliationService
{
    private PDO $pdo;
    private CheapDataHubClient $cheapDataHub;


    /*
    |--------------------------------------------------------------------------
    | CONSTRUCTOR
    |--------------------------------------------------------------------------
    */

    public function __construct(
        PDO $pdo,
        CheapDataHubClient $cheapDataHub
    ) {
        $this->pdo = $pdo;
        $this->cheapDataHub = $cheapDataHub;
    }


    /*
    |--------------------------------------------------------------------------
    | NORMALIZE PHONE NUMBER
    |--------------------------------------------------------------------------
    */

    private function normalizePhone(
        string $phone
    ): string {

        $phone = preg_replace(
            '/\D+/',
            '',
            $phone
        );

        $phone = (string) $phone;

        if (
            str_starts_with(
                $phone,
                "234"
            )
        ) {
            $phone =
                "0" .
                substr(
                    $phone,
                    3
                );
        }

        return $phone;
    }


    /*
    |--------------------------------------------------------------------------
    | NORMALIZE TEXT
    |--------------------------------------------------------------------------
    */

    private function normalizeText(
        string $value
    ): string {

        $value =
            mb_strtolower(
                trim($value)
            );

        $value =
            preg_replace(
                '/\s+/',
                ' ',
                $value
            );

        return trim(
            (string) $value
        );
    }


    /*
    |--------------------------------------------------------------------------
    | LOAD ORDER
    |--------------------------------------------------------------------------
    */

    private function loadOrder(
        int $orderId
    ): ?array {

        $stmt = $this->pdo->prepare("
            SELECT
                do.id,
                do.order_reference,
                do.user_id,
                do.plan_id,
                do.phone_number,
                do.cost_price,
                do.selling_price,
                do.profit,
                do.provider_reference,
                do.provider_message,
                do.status,
                do.created_at,
                do.updated_at,
                do.completed_at,

                dp.name AS plan_name,
                dp.data_amount,
                dp.validity,
                dp.provider_plan_code,
                dp.provider_id,
                dp.network_id,

                n.name AS network_name,

                ap.name AS provider_name,
                ap.slug AS provider_slug

            FROM data_orders do

            INNER JOIN data_plans dp
                ON dp.id = do.plan_id

            INNER JOIN networks n
                ON n.id = dp.network_id

            LEFT JOIN api_providers ap
                ON ap.id = dp.provider_id

            WHERE do.id = ?

            LIMIT 1
        ");

        $stmt->execute([
            $orderId
        ]);

        $order =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        return $order ?: null;
    }


    /*
    |--------------------------------------------------------------------------
    | CHECK WHETHER PROVIDER REFERENCE IS ALREADY USED
    |--------------------------------------------------------------------------
    |
    | We check BOTH:
    |
    | 1. data_orders
    | 2. data_provider_transactions
    |
    | A supplier reference must never silently become associated with two
    | different Servora orders.
    |
    */

    private function providerReferenceUsedByAnotherOrder(
        string $providerReference,
        int $currentOrderId
    ): bool {

        $providerReference =
            trim($providerReference);

        if ($providerReference === "") {
            return false;
        }


        /*
        |--------------------------------------------------------------------------
        | CHECK DATA ORDERS
        |--------------------------------------------------------------------------
        */

        $stmt = $this->pdo->prepare("
            SELECT id
            FROM data_orders
            WHERE provider_reference = ?
              AND id <> ?
            LIMIT 1
        ");

        $stmt->execute([
            $providerReference,
            $currentOrderId
        ]);

        if ($stmt->fetchColumn()) {
            return true;
        }


        /*
        |--------------------------------------------------------------------------
        | CHECK PROVIDER TRANSACTION HISTORY
        |--------------------------------------------------------------------------
        */

        $stmt = $this->pdo->prepare("
            SELECT id
            FROM data_provider_transactions
            WHERE provider_reference = ?
              AND order_id <> ?
            LIMIT 1
        ");

        $stmt->execute([
            $providerReference,
            $currentOrderId
        ]);

        return
            (bool) $stmt->fetchColumn();
    }


    /*
    |--------------------------------------------------------------------------
    | FIND OTHER SERVORA ORDERS THAT COULD MATCH THE SAME SUPPLIER RECORD
    |--------------------------------------------------------------------------
    |
    | This protects us from a dangerous situation:
    |
    | Order A:
    | Airtel 1GB
    | 090...
    | Supplier cost ₦295
    |
    | Order B:
    | Airtel 1GB
    | 090...
    | Supplier cost ₦295
    |
    | Supplier history:
    | One Airtel 1GB transaction for the same number and ₦295.
    |
    | If the supplier gives no timestamp, we cannot safely know whether that
    | supplier record belongs to Order A or Order B.
    |
    | Therefore this method looks for OTHER Servora orders that could also
    | reasonably match the same supplier transaction.
    |
    */

    private function findCrossOrderCollisions(
        array $currentOrder,
        string $supplierRecipient,
        ?float $supplierAmount,
        string $supplierItem
    ): array {

        $currentOrderId =
            (int) (
                $currentOrder["id"]
                ?? 0
            );

        $providerId =
            (int) (
                $currentOrder["provider_id"]
                ?? 0
            );

        $networkId =
            (int) (
                $currentOrder["network_id"]
                ?? 0
            );

        $normalizedSupplierPhone =
            $this->normalizePhone(
                $supplierRecipient
            );

        $normalizedSupplierItem =
            $this->normalizeText(
                $supplierItem
            );


        if (
            $currentOrderId <= 0 ||
            $providerId <= 0 ||
            $networkId <= 0 ||
            $normalizedSupplierPhone === "" ||
            $supplierAmount === null
        ) {
            return [];
        }


        /*
        |--------------------------------------------------------------------------
        | LOAD POSSIBLE ORDERS FROM SAME PROVIDER + NETWORK + COST
        |--------------------------------------------------------------------------
        |
        | We intentionally do not filter by phone in SQL because Servora may
        | contain phone numbers in either 0XXXXXXXXXX or 234XXXXXXXXXX format.
        |
        */

        $stmt = $this->pdo->prepare("
            SELECT
                do.id,
                do.order_reference,
                do.phone_number,
                do.cost_price,
                do.status,
                do.provider_reference,
                do.created_at,

                dp.name AS plan_name,
                dp.data_amount,
                dp.provider_plan_code,

                n.name AS network_name

            FROM data_orders do

            INNER JOIN data_plans dp
                ON dp.id = do.plan_id

            INNER JOIN networks n
                ON n.id = dp.network_id

            WHERE do.id <> ?
              AND dp.provider_id = ?
              AND dp.network_id = ?
              AND ABS(do.cost_price - ?) < 0.01

            ORDER BY do.created_at DESC
        ");

        $stmt->execute([
            $currentOrderId,
            $providerId,
            $networkId,
            $supplierAmount
        ]);

        $possibleOrders =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            );


        if (!$possibleOrders) {
            return [];
        }


        $collisions = [];


        foreach (
            $possibleOrders
            as $otherOrder
        ) {

            $otherPhone =
                $this->normalizePhone(
                    (string) (
                        $otherOrder[
                            "phone_number"
                        ]
                        ?? ""
                    )
                );


            /*
            |--------------------------------------------------------------------------
            | PHONE MUST MATCH
            |--------------------------------------------------------------------------
            */

            if (
                $otherPhone === "" ||
                $otherPhone !==
                    $normalizedSupplierPhone
            ) {
                continue;
            }


            $otherData =
                $this->normalizeText(
                    (string) (
                        $otherOrder[
                            "data_amount"
                        ]
                        ?? ""
                    )
                );

            $otherPlan =
                $this->normalizeText(
                    (string) (
                        $otherOrder[
                            "plan_name"
                        ]
                        ?? ""
                    )
                );

            $dataMatch = false;

            $planMatch = false;


            /*
            |--------------------------------------------------------------------------
            | DATA AMOUNT MATCH
            |--------------------------------------------------------------------------
            */

            if (
                $otherData !== "" &&
                $normalizedSupplierItem !== "" &&
                str_contains(
                    $normalizedSupplierItem,
                    $otherData
                )
            ) {
                $dataMatch = true;
            }


            /*
            |--------------------------------------------------------------------------
            | PLAN MATCH
            |--------------------------------------------------------------------------
            |
            | This is only supporting evidence because supplier plan wording can
            | differ from Servora plan wording.
            |
            */

            if (
                $otherPlan !== "" &&
                $normalizedSupplierItem !== ""
            ) {

                if (
                    str_contains(
                        $normalizedSupplierItem,
                        $otherPlan
                    ) ||
                    str_contains(
                        $otherPlan,
                        $normalizedSupplierItem
                    )
                ) {
                    $planMatch = true;
                }
            }


            /*
            |--------------------------------------------------------------------------
            | COLLISION RULE
            |--------------------------------------------------------------------------
            |
            | Same:
            |
            | - provider
            | - network
            | - supplier cost
            | - phone
            | - data amount
            |
            | is enough to create a collision when supplier timing is missing.
            |
            */

            if (!$dataMatch) {
                continue;
            }


            $collisions[] = [

                "order_id" =>
                    (int)
                    $otherOrder["id"],

                "order_reference" =>
                    (string)
                    $otherOrder[
                        "order_reference"
                    ],

                "status" =>
                    (string)
                    $otherOrder["status"],

                "phone_number" =>
                    (string)
                    $otherOrder[
                        "phone_number"
                    ],

                "cost_price" =>
                    (float)
                    $otherOrder[
                        "cost_price"
                    ],

                "plan_name" =>
                    (string)
                    $otherOrder[
                        "plan_name"
                    ],

                "data_amount" =>
                    (string)
                    $otherOrder[
                        "data_amount"
                    ],

                "created_at" =>
                    (string)
                    $otherOrder[
                        "created_at"
                    ],

                "provider_reference" =>
                    (string) (
                        $otherOrder[
                            "provider_reference"
                        ]
                        ?? ""
                    ),

                "data_match" =>
                    $dataMatch,

                "plan_match" =>
                    $planMatch
            ];
        }


        return $collisions;
    }


    /*
    |--------------------------------------------------------------------------
    | INSPECT ORDER
    |--------------------------------------------------------------------------
    |
    | READ ONLY.
    |
    | This method NEVER:
    |
    | - buys data
    | - retries data
    | - debits wallet
    | - refunds wallet
    | - changes order status
    | - writes provider reference
    |
    */

    public function inspect(
        int $orderId
    ): array {

        if ($orderId <= 0) {

            return [
                "success" => false,
                "state" => "invalid_order",
                "message" =>
                    "Invalid data order ID.",
                "order" => null,
                "candidate" => null,
                "candidates" => [],
                "supplier_record_count" => 0
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | LOAD ORDER
        |--------------------------------------------------------------------------
        */

        try {

            $order =
                $this->loadOrder(
                    $orderId
                );

        } catch (Throwable $e) {

            error_log(
                "Data reconciliation order lookup error: " .
                $e->getMessage()
            );

            return [
                "success" => false,
                "state" => "database_error",
                "message" =>
                    "Could not load the data order.",
                "order" => null,
                "candidate" => null,
                "candidates" => [],
                "supplier_record_count" => 0
            ];
        }


        if (!$order) {

            return [
                "success" => false,
                "state" => "not_found",
                "message" =>
                    "Data order not found.",
                "order" => null,
                "candidate" => null,
                "candidates" => [],
                "supplier_record_count" => 0
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | VERIFY PROVIDER
        |--------------------------------------------------------------------------
        */

        if (
            (
                $order[
                    "provider_slug"
                ]
                ?? ""
            )
            !== "cheapdatahub"
        ) {

            return [
                "success" => false,
                "state" =>
                    "unsupported_provider",
                "message" =>
                    "Reconciliation is not configured for this supplier.",
                "order" => $order,
                "candidate" => null,
                "candidates" => [],
                "supplier_record_count" => 0
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | ONLY PROCESSING ORDERS REQUIRE THIS INSPECTION
        |--------------------------------------------------------------------------
        */

        if (
            (
                $order["status"]
                ?? ""
            )
            !== "processing"
        ) {

            return [
                "success" => true,
                "state" =>
                    "not_required",
                "message" =>
                    "This order does not currently require reconciliation.",
                "order" => $order,
                "candidate" => null,
                "candidates" => [],
                "supplier_record_count" => 0
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | FETCH SUPPLIER TRANSACTION HISTORY
        |--------------------------------------------------------------------------
        */

        try {

            $result =
                $this->cheapDataHub
                    ->getTransactions();

        } catch (Throwable $e) {

            error_log(
                "CheapDataHub reconciliation request error: " .
                $e->getMessage()
            );

            return [
                "success" => false,
                "state" =>
                    "supplier_error",
                "message" =>
                    "Could not retrieve supplier transaction history.",
                "order" => $order,
                "candidate" => null,
                "candidates" => [],
                "supplier_record_count" => 0
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | VALIDATE SUPPLIER RESPONSE
        |--------------------------------------------------------------------------
        */

        if (
            !is_array($result) ||
            ($result["ok"] ?? false)
                !== true
        ) {

            return [
                "success" => false,
                "state" =>
                    "supplier_error",
                "message" =>
                    trim(
                        (string) (
                            $result["message"]
                            ?? "Supplier transaction history could not be retrieved."
                        )
                    ),
                "order" => $order,
                "candidate" => null,
                "candidates" => [],
                "supplier_record_count" => 0,
                "http_status" =>
                    (int) (
                        $result[
                            "http_status"
                        ]
                        ?? 0
                    )
            ];
        }


        $response =
            $result["data"]
            ?? null;


        /*
        |--------------------------------------------------------------------------
        | EXTRACT SUPPLIER TRANSACTIONS
        |--------------------------------------------------------------------------
        */

        if (
            !is_array($response) ||
            !isset(
                $response["data"]
            ) ||
            !is_array(
                $response["data"]
            )
        ) {

            return [
                "success" => true,
                "state" =>
                    "no_supplier_records",
                "message" =>
                    "Supplier returned no transaction records.",
                "order" => $order,
                "candidate" => null,
                "candidates" => [],
                "supplier_record_count" => 0
            ];
        }


        $transactions =
            $response["data"];


        if (empty($transactions)) {

            return [
                "success" => true,
                "state" =>
                    "no_supplier_records",
                "message" =>
                    "Supplier returned no transaction records.",
                "order" => $order,
                "candidate" => null,
                "candidates" => [],
                "supplier_record_count" => 0
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | CURRENT ORDER VALUES
        |--------------------------------------------------------------------------
        */

        $orderPhone =
            $this->normalizePhone(
                (string)
                $order[
                    "phone_number"
                ]
            );

        $orderCost =
            round(
                (float)
                $order[
                    "cost_price"
                ],
                2
            );

        $orderPlan =
            $this->normalizeText(
                (string)
                $order[
                    "plan_name"
                ]
            );

        $orderDataAmount =
            $this->normalizeText(
                (string)
                $order[
                    "data_amount"
                ]
            );

        $orderNetwork =
            $this->normalizeText(
                (string)
                $order[
                    "network_name"
                ]
            );

        $orderCreated =
            strtotime(
                (string)
                $order[
                    "created_at"
                ]
            );


        $candidates = [];


        /*
        |--------------------------------------------------------------------------
        | COMPARE SUPPLIER TRANSACTIONS
        |--------------------------------------------------------------------------
        */

        foreach (
            $transactions
            as $index => $transaction
        ) {

            if (!is_array($transaction)) {
                continue;
            }


            /*
            |--------------------------------------------------------------------------
            | SUPPLIER VALUES
            |--------------------------------------------------------------------------
            */

            $rawRecipient =
                (string) (
                    $transaction[
                        "recipient"
                    ]
                    ?? ""
                );

            $recipient =
                $this->normalizePhone(
                    $rawRecipient
                );

            $rawItem =
                (string) (
                    $transaction[
                        "item"
                    ]
                    ?? ""
                );

            $item =
                $this->normalizeText(
                    $rawItem
                );

            $category =
                $this->normalizeText(
                    (string) (
                        $transaction[
                            "category"
                        ]
                        ?? ""
                    )
                );

            $providerReference =
                trim(
                    (string) (
                        $transaction[
                            "tx_ref"
                        ]
                        ?? ""
                    )
                );

            $supplierAmount =
                isset(
                    $transaction[
                        "amount"
                    ]
                )
                    ? round(
                        (float)
                        $transaction[
                            "amount"
                        ],
                        2
                    )
                    : null;

            $timeline =
                trim(
                    (string) (
                        $transaction[
                            "timeline"
                        ]
                        ?? ""
                    )
                );

            $completed =
                $transaction[
                    "completed"
                ]
                ?? null;


            /*
            |--------------------------------------------------------------------------
            | PHONE MATCH
            |--------------------------------------------------------------------------
            */

            $phoneMatch =
                $orderPhone !== "" &&
                $recipient !== "" &&
                $orderPhone ===
                    $recipient;


            /*
            |--------------------------------------------------------------------------
            | COST MATCH
            |--------------------------------------------------------------------------
            */

            $amountMatch =
                $supplierAmount !== null &&
                abs(
                    $supplierAmount -
                    $orderCost
                ) < 0.01;


            /*
            |--------------------------------------------------------------------------
            | DATA MATCH
            |--------------------------------------------------------------------------
            */

            $dataMatch =
                $orderDataAmount !== "" &&
                $item !== "" &&
                str_contains(
                    $item,
                    $orderDataAmount
                );


            /*
            |--------------------------------------------------------------------------
            | NETWORK MATCH
            |--------------------------------------------------------------------------
            */

            $networkMatch =
                $orderNetwork !== "" &&
                $item !== "" &&
                str_contains(
                    $item,
                    $orderNetwork
                );


            /*
            |--------------------------------------------------------------------------
            | PLAN MATCH
            |--------------------------------------------------------------------------
            */

            $planMatch = false;

            if (
                $orderPlan !== "" &&
                $item !== ""
            ) {

                if (
                    str_contains(
                        $item,
                        $orderPlan
                    ) ||
                    str_contains(
                        $orderPlan,
                        $item
                    )
                ) {

                    $planMatch = true;
                }
            }


            /*
            |--------------------------------------------------------------------------
            | TIME MATCH
            |--------------------------------------------------------------------------
            */

            $supplierTime =
                $timeline !== ""
                    ? strtotime(
                        $timeline
                    )
                    : false;

            $timeMatch = false;

            $timeDifferenceMinutes =
                null;


            if (
                $orderCreated !== false &&
                $supplierTime !== false
            ) {

                $difference =
                    abs(
                        $supplierTime -
                        $orderCreated
                    );

                $timeDifferenceMinutes =
                    round(
                        $difference / 60,
                        1
                    );


                /*
                 * 15 minute reconciliation window.
                 */

                if ($difference <= 900) {
                    $timeMatch = true;
                }
            }


            /*
            |--------------------------------------------------------------------------
            | PROVIDER REFERENCE CHECK
            |--------------------------------------------------------------------------
            */

            $referenceAlreadyUsed =
                false;

            if (
                $providerReference !== ""
            ) {

                try {

                    $referenceAlreadyUsed =
                        $this
                            ->providerReferenceUsedByAnotherOrder(
                                $providerReference,
                                $orderId
                            );

                } catch (Throwable $e) {

                    error_log(
                        "Provider reference uniqueness check error: " .
                        $e->getMessage()
                    );

                    /*
                     * Fail closed.
                     */

                    $referenceAlreadyUsed =
                        true;
                }
            }


            $referenceAvailable =
                $providerReference !== "" &&
                !$referenceAlreadyUsed;


            /*
            |--------------------------------------------------------------------------
            | CROSS-ORDER COLLISION CHECK
            |--------------------------------------------------------------------------
            */

            $crossOrderCollisions = [];

            if (
                $phoneMatch &&
                $amountMatch &&
                $dataMatch
            ) {

                try {

                    $crossOrderCollisions =
                        $this
                            ->findCrossOrderCollisions(
                                $order,
                                $rawRecipient,
                                $supplierAmount,
                                $rawItem
                            );

                } catch (Throwable $e) {

                    error_log(
                        "Cross-order reconciliation collision check error: " .
                        $e->getMessage()
                    );

                    /*
                     * If the collision check itself fails, we cannot safely
                     * classify this supplier transaction as high-confidence.
                     */

                    $crossOrderCollisions = [
                        [
                            "order_id" => null,
                            "order_reference" =>
                                "Collision check unavailable",
                            "status" =>
                                "unknown",
                            "phone_number" => "",
                            "cost_price" =>
                                $supplierAmount,
                            "plan_name" => "",
                            "data_amount" => "",
                            "created_at" => "",
                            "provider_reference" => "",
                            "data_match" => true,
                            "plan_match" => false
                        ]
                    ];
                }
            }


            $hasCrossOrderCollision =
                !empty(
                    $crossOrderCollisions
                );


            /*
            |--------------------------------------------------------------------------
            | EVIDENCE SCORE
            |--------------------------------------------------------------------------
            |
            | Score is for display/investigation only.
            | It never causes a database change.
            |
            */

            $score = 0;

            if ($phoneMatch) {
                $score += 4;
            }

            if ($amountMatch) {
                $score += 3;
            }

            if ($dataMatch) {
                $score += 1;
            }

            if ($networkMatch) {
                $score += 1;
            }

            if ($timeMatch) {
                $score += 2;
            }

            if ($planMatch) {
                $score += 1;
            }

            if ($referenceAvailable) {
                $score += 1;
            }


            /*
            |--------------------------------------------------------------------------
            | IGNORE UNRELATED TRANSACTIONS
            |--------------------------------------------------------------------------
            */

            if ($score <= 0) {
                continue;
            }


            /*
            |--------------------------------------------------------------------------
            | INITIAL CLASSIFICATION
            |--------------------------------------------------------------------------
            */

            $classification =
                "needs_review";


            /*
            |--------------------------------------------------------------------------
            | HIGH-CONFIDENCE RULE
            |--------------------------------------------------------------------------
            |
            | IMPORTANT:
            |
            | If supplier time exists and matches this order, that gives us
            | important transaction-specific evidence.
            |
            | If supplier time is unavailable, there must be NO other Servora
            | order that could plausibly match the same supplier transaction.
            |
            */

            $baseStrongMatch =
                $phoneMatch &&
                $amountMatch &&
                $dataMatch &&
                $networkMatch &&
                $referenceAvailable;


            if ($baseStrongMatch) {

                if ($timeMatch) {

                    $classification =
                        "high_confidence";

                } elseif (
                    !$hasCrossOrderCollision
                ) {

                    $classification =
                        "high_confidence";

                } else {

                    $classification =
                        "ambiguous";
                }

            } elseif (
                $phoneMatch &&
                $amountMatch
            ) {

                $classification =
                    $hasCrossOrderCollision
                        ? "ambiguous"
                        : "possible";
            }


            /*
            |--------------------------------------------------------------------------
            | STORE CANDIDATE
            |--------------------------------------------------------------------------
            */

            $candidates[] = [

                "supplier_index" =>
                    $index,

                "score" =>
                    $score,

                "classification" =>
                    $classification,

                "item" =>
                    $rawItem,

                "recipient" =>
                    $rawRecipient,

                "category" =>
                    $category,

                "amount" =>
                    $supplierAmount,

                "provider_reference" =>
                    $providerReference,

                "completed" =>
                    $completed,

                "timeline" =>
                    $timeline,

                "phone_match" =>
                    $phoneMatch,

                "amount_match" =>
                    $amountMatch,

                "data_match" =>
                    $dataMatch,

                "network_match" =>
                    $networkMatch,

                "plan_match" =>
                    $planMatch,

                "time_match" =>
                    $timeMatch,

                "time_difference_minutes" =>
                    $timeDifferenceMinutes,

                "reference_available" =>
                    $referenceAvailable,

                "reference_already_used" =>
                    $referenceAlreadyUsed,

                "has_cross_order_collision" =>
                    $hasCrossOrderCollision,

                "cross_order_collision_count" =>
                    count(
                        $crossOrderCollisions
                    ),

                "cross_order_collisions" =>
                    $crossOrderCollisions
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | SORT CANDIDATES
        |--------------------------------------------------------------------------
        */

        usort(
            $candidates,
            function (
                array $a,
                array $b
            ): int {

                return
                    $b["score"]
                    <=>
                    $a["score"];
            }
        );


        /*
        |--------------------------------------------------------------------------
        | GROUP CLASSIFICATIONS
        |--------------------------------------------------------------------------
        */

        $highConfidenceCandidates =
            array_values(
                array_filter(
                    $candidates,
                    function (
                        array $candidate
                    ): bool {

                        return
                            (
                                $candidate[
                                    "classification"
                                ]
                                ?? ""
                            )
                            ===
                            "high_confidence";
                    }
                )
            );


        $ambiguousCandidates =
            array_values(
                array_filter(
                    $candidates,
                    function (
                        array $candidate
                    ): bool {

                        return
                            (
                                $candidate[
                                    "classification"
                                ]
                                ?? ""
                            )
                            ===
                            "ambiguous";
                    }
                )
            );


        /*
        |--------------------------------------------------------------------------
        | FINAL RESULT
        |--------------------------------------------------------------------------
        |
        | Ambiguity takes priority.
        |
        | Even if another candidate appears strong, we do not want the admin
        | page to imply certainty when multiple Servora orders could correspond
        | to supplier history.
        |
        */


        if (
            !empty(
                $ambiguousCandidates
            )
        ) {

            return [
                "success" => true,

                "state" =>
                    "ambiguous",

                "message" =>
                    "A supplier transaction matches this order, but another Servora order could also match the same supplier record. The transaction must not be assigned automatically.",

                "order" =>
                    $order,

                "candidate" =>
                    null,

                "candidates" =>
                    $candidates,

                "supplier_record_count" =>
                    count(
                        $transactions
                    ),

                "ambiguous_candidate_count" =>
                    count(
                        $ambiguousCandidates
                    )
            ];
        }


        if (
            count(
                $highConfidenceCandidates
            ) === 1
        ) {

            return [
                "success" => true,

                "state" =>
                    "high_confidence_candidate",

                "message" =>
                    "Exactly one high-confidence supplier transaction candidate was found.",

                "order" =>
                    $order,

                "candidate" =>
                    $highConfidenceCandidates[0],

                "candidates" =>
                    $candidates,

                "supplier_record_count" =>
                    count(
                        $transactions
                    )
            ];
        }


        if (
            count(
                $highConfidenceCandidates
            ) > 1
        ) {

            return [
                "success" => true,

                "state" =>
                    "ambiguous",

                "message" =>
                    "Multiple high-confidence supplier transactions match this Servora order. Manual investigation is required.",

                "order" =>
                    $order,

                "candidate" =>
                    null,

                "candidates" =>
                    $candidates,

                "supplier_record_count" =>
                    count(
                        $transactions
                    )
            ];
        }


        if (!empty($candidates)) {

            return [
                "success" => true,

                "state" =>
                    "possible_candidate",

                "message" =>
                    "Supplier transactions with matching evidence were found, but none can be safely identified as this exact Servora order.",

                "order" =>
                    $order,

                "candidate" =>
                    null,

                "candidates" =>
                    $candidates,

                "supplier_record_count" =>
                    count(
                        $transactions
                    )
            ];
        }


        return [
            "success" => true,

            "state" =>
                "no_match",

            "message" =>
                "No matching supplier transaction was found.",

            "order" =>
                $order,

            "candidate" =>
                null,

            "candidates" =>
                [],

            "supplier_record_count" =>
                count(
                    $transactions
                )
        ];
    }
}