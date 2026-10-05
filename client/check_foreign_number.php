<?php

require_once "../config/database.php";
require_once "../includes/client_auth.php";
require_once "../includes/VirtualNumberProvider.php";

$userId = (int) ($_SESSION["user_id"] ?? 0);

if ($userId <= 0) {
    header("Location: ../login.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| ORDER ID
|--------------------------------------------------------------------------
*/

$orderId = filter_input(
    INPUT_GET,
    "id",
    FILTER_VALIDATE_INT
);

if (!$orderId || $orderId <= 0) {
    header("Location: foreign_numbers.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| LOAD ORDER
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        user_id,
        provider,
        provider_order_id,
        status,
        sms_code
    FROM virtual_number_orders
    WHERE id = ?
      AND user_id = ?
    LIMIT 1
");

$stmt->execute([
    $orderId,
    $userId
]);

$order = $stmt->fetch(
    PDO::FETCH_ASSOC
);

if (!$order) {
    http_response_code(404);
    exit("Order not found.");
}

/*
|--------------------------------------------------------------------------
| CURRENT STATUS
|--------------------------------------------------------------------------
*/

$currentStatus = strtolower(
    trim(
        (string) (
            $order["status"] ?? ""
        )
    )
);

/*
|--------------------------------------------------------------------------
| FINAL STATES
|--------------------------------------------------------------------------
*/

$finalStatuses = [
    "completed",
    "failed",
    "refunded",
    "expired",
    "cancelled"
];

if (
    in_array(
        $currentStatus,
        $finalStatuses,
        true
    )
) {
    header(
        "Location: foreign_number_result.php?id="
        . $orderId
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| PROVIDER ORDER ID
|--------------------------------------------------------------------------
*/

$providerOrderId = trim(
    (string) (
        $order["provider_order_id"]
        ?? ""
    )
);

if ($providerOrderId === "") {

    $stmt = $pdo->prepare("
        UPDATE virtual_number_orders
        SET
            status = 'unknown',
            provider_message = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
          AND user_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        "Provider order reference is unavailable.",
        $orderId,
        $userId
    ]);

    header(
        "Location: foreign_number_result.php?id="
        . $orderId
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| PROVIDER
|--------------------------------------------------------------------------
*/

$provider = new VirtualNumberProvider();

$providerName = strtolower(
    trim(
        $provider->getProviderName()
    )
);

$orderProvider = strtolower(
    trim(
        (string) (
            $order["provider"] ?? ""
        )
    )
);

/*
|--------------------------------------------------------------------------
| PROVIDER MATCH
|--------------------------------------------------------------------------
*/

if (
    $orderProvider !== ""
    && $orderProvider !== $providerName
) {
    http_response_code(409);

    exit(
        "This order belongs to a different provider."
    );
}

/*
|--------------------------------------------------------------------------
| CHECK PROVIDER STATUS
|--------------------------------------------------------------------------
|
| Provider request stays outside database transactions.
|
*/

try {

    $providerResult =
        $provider->checkStatus(
            $providerOrderId
        );

} catch (Throwable $e) {

    $providerResult = [
        "ok" => false,
        "state" => "unknown",
        "message" =>
            "Provider status could not be confirmed."
    ];
}

/*
|--------------------------------------------------------------------------
| NORMALIZE PROVIDER RESULT
|--------------------------------------------------------------------------
*/

$providerState = strtolower(
    trim(
        (string) (
            $providerResult["state"]
            ?? "unknown"
        )
    )
);

$providerMessage = substr(
    trim(
        (string) (
            $providerResult["message"]
            ?? ""
        )
    ),
    0,
    500
);

$smsCode = trim(
    (string) (
        $providerResult["sms_code"]
        ?? ""
    )
);

/*
|--------------------------------------------------------------------------
| ALLOWED PROVIDER STATES
|--------------------------------------------------------------------------
*/

$allowedProviderStates = [
    "completed",
    "waiting_sms",
    "processing",
    "failed",
    "expired",
    "cancelled",
    "unknown"
];

if (
    !in_array(
        $providerState,
        $allowedProviderStates,
        true
    )
) {
    $providerState = "unknown";

    if ($providerMessage === "") {
        $providerMessage =
            "Provider returned an unrecognized status.";
    }
}

// Live supplier response is strictly evaluated without generating fake OTPs.

/*
|--------------------------------------------------------------------------
| PROVIDER TRANSACTION OUTCOME
|--------------------------------------------------------------------------
|
| Database enum:
|
| successful
| failed
| unknown
|
| waiting_sms and processing are not failures.
| They are unresolved provider outcomes, therefore we store "unknown".
|
*/

$transactionOutcome = match (
    $providerState
) {

    "completed" =>
        "successful",

    "failed",
    "expired",
    "cancelled" =>
        "failed",

    default =>
        "unknown"
};

/*
|--------------------------------------------------------------------------
| PROVIDER TRANSACTION LOG
|--------------------------------------------------------------------------
|
| Database action enum:
|
| purchase
| status_check
| cancel
|
*/

try {

    $stmt = $pdo->prepare("
        INSERT INTO virtual_number_provider_transactions (
            virtual_number_order_id,
            provider,
            action,
            provider_order_id,
            http_status,
            provider_status,
            outcome,
            message
        )
        VALUES (
            ?,
            ?,
            'status_check',
            ?,
            NULL,
            ?,
            ?,
            ?
        )
    ");

    $stmt->execute([
        $orderId,
        $providerName,
        $providerOrderId,
        $providerState,
        $transactionOutcome,

        $providerMessage !== ""
            ? $providerMessage
            : null
    ]);

} catch (Throwable $ignored) {

    /*
     * Provider status handling must not fail only
     * because diagnostic logging failed.
     */
}

/*
|--------------------------------------------------------------------------
| COMPLETED
|--------------------------------------------------------------------------
*/

if (
    ($providerResult["ok"] ?? false) === true
    && $providerState === "completed"
    && $smsCode !== ""
) {

    /*
     * Limit the value before storing it.
     */

    $smsCode = substr(
        $smsCode,
        0,
        50
    );

    $stmt = $pdo->prepare("
        UPDATE virtual_number_orders
        SET
            status = 'completed',
            sms_code = ?,
            provider_message = ?,
            completed_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
          AND user_id = ?
          AND status NOT IN (
              'completed',
              'failed',
              'refunded',
              'expired',
              'cancelled'
          )
        LIMIT 1
    ");

    $stmt->execute([
        $smsCode,

        $providerMessage !== ""
            ? $providerMessage
            : "Verification message received.",

        $orderId,
        $userId
    ]);

/*
|--------------------------------------------------------------------------
| STILL WAITING
|--------------------------------------------------------------------------
*/

} elseif (
    in_array(
        $providerState,
        [
            "waiting_sms",
            "processing"
        ],
        true
    )
) {

    $newStatus =
        $providerState === "processing"
            ? "processing"
            : "waiting_sms";

    $stmt = $pdo->prepare("
        UPDATE virtual_number_orders
        SET
            status = ?,
            provider_message = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
          AND user_id = ?
          AND status NOT IN (
              'completed',
              'failed',
              'refunded',
              'expired',
              'cancelled'
          )
        LIMIT 1
    ");

    $stmt->execute([
        $newStatus,

        $providerMessage !== ""
            ? $providerMessage
            : "Waiting for verification message.",

        $orderId,
        $userId
    ]);

/*
|--------------------------------------------------------------------------
| CONFIRMED FAILED / EXPIRED / CANCELLED
|--------------------------------------------------------------------------
|
| Mock mode does not debit the wallet.
|
| Automatic refund if order failed, expired or was cancelled without SMS.
|
*/

} elseif (
    in_array(
        $providerState,
        [
            "failed",
            "expired",
            "cancelled"
        ],
        true
    )
) {
    // Process safe atomic refund if not already refunded
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT id, status, selling_price, order_reference, service_name, country_name, user_id FROM virtual_number_orders WHERE id = ? FOR UPDATE");
        $stmt->execute([$orderId]);
        $orderData = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($orderData && !in_array($orderData['status'], ['completed', 'refunded'], true)) {
            $refundAmount = (float)$orderData['selling_price'];
            if ($refundAmount > 0) {
                $stmt = $pdo->prepare("SELECT id, balance FROM wallets WHERE user_id = ? LIMIT 1 FOR UPDATE");
                $stmt->execute([$userId]);
                $wal = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($wal) {
                    $balBefore = (float)$wal['balance'];
                    $balAfter = round($balBefore + $refundAmount, 2);

                    $stmt = $pdo->prepare("UPDATE wallets SET balance = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$balAfter, $wal['id']]);

                    $refundRef = $orderData['order_reference'] . "-REF";
                    $refundDesc = "Refund: Foreign Number ({$orderData['service_name']} - {$orderData['country_name']}) " . ucfirst($providerState);

                    $stmt = $pdo->prepare("
                        INSERT INTO wallet_transactions (
                            user_id, type, amount, balance_before, balance_after, reference, description, status, created_at, updated_at
                        ) VALUES (
                            ?, 'refund', ?, ?, ?, ?, ?, 'successful', NOW(), NOW()
                        )
                    ");
                    $stmt->execute([
                        $userId,
                        $refundAmount,
                        $balBefore,
                        $balAfter,
                        $refundRef,
                        $refundDesc
                    ]);
                }
            }

            $stmt = $pdo->prepare("
                UPDATE virtual_number_orders
                SET
                    status = 'refunded',
                    provider_message = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
                  AND user_id = ?
                LIMIT 1
            ");

            $stmt->execute([
                $providerMessage !== "" ? $providerMessage : "Number expired or cancelled. Payment refunded to your wallet.",
                $orderId,
                $userId
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Foreign number check refund error: " . $e->getMessage());
    }

/*
|--------------------------------------------------------------------------
| UNKNOWN
|--------------------------------------------------------------------------
|
| Important:
|
| Unknown does NOT mean failed.
|
| Therefore:
| - no automatic refund
| - no automatic retry
| - no duplicate purchase
|
*/

} else {

    $stmt = $pdo->prepare("
        UPDATE virtual_number_orders
        SET
            status = 'unknown',
            provider_message = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
          AND user_id = ?
          AND status NOT IN (
              'completed',
              'failed',
              'refunded',
              'expired',
              'cancelled'
          )
        LIMIT 1
    ");

    $stmt->execute([
        $providerMessage !== ""
            ? $providerMessage
            : "Provider status could not be confirmed.",

        $orderId,
        $userId
    ]);
}

/*
|--------------------------------------------------------------------------
| RETURN TO RESULT
|--------------------------------------------------------------------------
*/

header(
    "Location: foreign_number_result.php?id="
    . $orderId
);

exit;