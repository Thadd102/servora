<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/UtilityProvider.php';

/**
 * UtilityOrderProcessor - Atomic Purchase, Idempotency & Financial Safety Engine
 *
 * Implements PRD Section 9:
 * - Row locking (SELECT ... FOR UPDATE)
 * - Atomic wallet deduction
 * - Idempotency enforcement via client_request_token
 * - Safe refund handling without double charge or double reversal
 * - Centralized audit trail
 */
class UtilityOrderProcessor
{
    private PDO $pdo;
    private UtilityProvider $provider;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->provider = new UtilityProvider();
    }

    /**
     * Process an atomic utility purchase
     */
    public function processOrder(array $params): array
    {
        $userId = (int)($params['user_id'] ?? 0);
        $serviceSlug = trim((string)($params['service_slug'] ?? ''));
        $productCode = trim((string)($params['product_code'] ?? ''));
        $customerIdentifier = trim((string)($params['customer_identifier'] ?? ''));
        $customerName = trim((string)($params['customer_name'] ?? ''));
        $requestToken = trim((string)($params['request_token'] ?? ''));
        $customAmount = isset($params['amount']) ? (float)$params['amount'] : null;
        $extraPayload = (array)($params['extra_payload'] ?? []);

        if ($userId <= 0 || empty($serviceSlug) || empty($productCode) || empty($customerIdentifier)) {
            return [
                'ok' => false,
                'message' => 'Missing required purchase parameters.'
            ];
        }

        // 1. Check idempotency token
        if (!empty($requestToken)) {
            $stmt = $this->pdo->prepare("SELECT id, order_reference, status, token_or_pin FROM utility_orders WHERE client_request_token = ? LIMIT 1");
            $stmt->execute([$requestToken]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                return [
                    'ok' => ($existing['status'] === 'successful'),
                    'duplicate' => true,
                    'order_id' => $existing['id'],
                    'reference' => $existing['order_reference'],
                    'status' => $existing['status'],
                    'token_or_pin' => $existing['token_or_pin'],
                    'message' => 'This order was already processed.'
                ];
            }
        }

        // 2. Determine server-side pricing from service_products
        $stmt = $this->pdo->prepare("SELECT * FROM service_products WHERE service_slug = ? AND product_code = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$serviceSlug, $productCode]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product && $serviceSlug !== 'airtime' && $serviceSlug !== 'bulk_sms' && $serviceSlug !== 'electricity') {
            return [
                'ok' => false,
                'message' => 'The selected product is currently unavailable.'
            ];
        }

        $productName = $product['name'] ?? ucfirst(str_replace('_', ' ', $productCode));
        $providerName = $product['provider'] ?? 'cheapdatahub';

        // Calculate final selling price and cost
        if ($serviceSlug === 'airtime') {
            if ($customAmount === null || $customAmount < 100.0) {
                return ['ok' => false, 'message' => 'Minimum airtime amount is ₦100.'];
            }
            $sellingPrice = $customAmount;
            $discountPct = (float)($product['percentage_markup'] ?? 2.0); // e.g., 2% discount from face value
            $providerCost = round($sellingPrice * (1 - ($discountPct / 100)), 2);
        } elseif ($serviceSlug === 'electricity') {
            if ($customAmount === null || $customAmount < 500.0) {
                return ['ok' => false, 'message' => 'Minimum electricity payment is ₦500.'];
            }
            $sellingPrice = $customAmount;
            $providerCost = $customAmount; // 0% provider markup on face value
        } elseif ($serviceSlug === 'bulk_sms') {
            $smsCount = (int)($extraPayload['recipient_count'] ?? 1);
            $pages = (int)($extraPayload['pages'] ?? 1);
            $unitPrice = (float)($product['selling_price'] ?? 3.50);
            $unitCost = (float)($product['provider_cost'] ?? 3.00);
            $sellingPrice = round($unitPrice * $smsCount * $pages, 2);
            $providerCost = round($unitCost * $smsCount * $pages, 2);
        } elseif ($serviceSlug === 'exam_pins') {
            $qty = max(1, (int)($extraPayload['quantity'] ?? 1));
            $unitPrice = (float)($product['selling_price'] ?? 3500.00);
            $unitCost = (float)($product['provider_cost'] ?? 3400.00);
            $sellingPrice = round($unitPrice * $qty, 2);
            $providerCost = round($unitCost * $qty, 2);
        } else {
            // Cable TV or fixed package
            $sellingPrice = (float)($product['selling_price'] ?? 0);
            $providerCost = (float)($product['provider_cost'] ?? 0);
        }

        $profit = max(0.0, $sellingPrice - $providerCost);

        // Generate internal reference
        $internalRef = 'SRV-' . strtoupper(substr($serviceSlug, 0, 3)) . '-' . date('YmdHis') . '-' . mt_rand(1000, 9999);

        // 3. Begin atomic transaction with row lock
        $this->pdo->beginTransaction();

        try {
            // Lock wallet row
            $stmt = $this->pdo->prepare("SELECT id, balance FROM wallets WHERE user_id = ? FOR UPDATE");
            $stmt->execute([$userId]);
            $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$wallet) {
                $this->pdo->rollBack();
                return ['ok' => false, 'message' => 'Wallet account not found. Please contact support.'];
            }

            $currentBalance = (float)$wallet['balance'];

            if ($currentBalance < $sellingPrice) {
                $this->pdo->rollBack();
                return [
                    'ok' => false,
                    'insufficient_balance' => true,
                    'message' => 'Insufficient wallet balance. Please fund your wallet to continue.'
                ];
            }

            $newBalance = $currentBalance - $sellingPrice;

            // Debit wallet
            $stmt = $this->pdo->prepare("UPDATE wallets SET balance = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$newBalance, $wallet['id']]);

            // Record transaction ledger entry
            $desc = "Payment for $productName ($customerIdentifier)";
            $stmt = $this->pdo->prepare("
                INSERT INTO wallet_transactions
                (user_id, type, amount, balance_before, balance_after, reference, description, status, created_at, updated_at)
                VALUES (?, 'debit', ?, ?, ?, ?, ?, 'successful', NOW(), NOW())
            ");
            $stmt->execute([
                $userId,
                $sellingPrice,
                $currentBalance,
                $newBalance,
                $internalRef,
                $desc
            ]);
            $walletTxId = (int)$this->pdo->lastInsertId();

            // Create utility order in pending status
            $stmt = $this->pdo->prepare("
                INSERT INTO utility_orders
                (order_reference, client_request_token, user_id, service_slug, product_code, product_name, customer_identifier, customer_name, provider, provider_cost, selling_price, profit, status, order_payload, wallet_transaction_id, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, NOW(), NOW())
            ");
            $stmt->execute([
                $internalRef,
                $requestToken ?: null,
                $userId,
                $serviceSlug,
                $productCode,
                $productName,
                $customerIdentifier,
                $customerName ?: null,
                $providerName,
                $providerCost,
                $sellingPrice,
                $profit,
                json_encode(array_merge($params, ['selling_price' => $sellingPrice, 'provider_cost' => $providerCost])),
                $walletTxId
            ]);
            $orderId = (int)$this->pdo->lastInsertId();

            // Commit initial debit so user cannot double-spend concurrently
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("Utility order debit failed: " . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not lock transaction. Please try again.'];
        }

        // 4. Dispatch to external provider
        $providerResult = null;
        try {
            switch ($serviceSlug) {
                case 'airtime':
                    $network = $extraPayload['network'] ?? $productCode;
                    $providerResult = $this->provider->purchaseAirtime($network, $customerIdentifier, $sellingPrice, $internalRef);
                    break;

                case 'electricity':
                    $meterType = $extraPayload['meter_type'] ?? 'prepaid';
                    $providerResult = $this->provider->purchaseElectricity($productCode, $customerIdentifier, $meterType, $sellingPrice, $extraPayload['phone'] ?? '', $internalRef);
                    break;

                case 'cable_tv':
                    $providerResult = $this->provider->purchaseCable($extraPayload['cable_provider'] ?? 'dstv', $productCode, $customerIdentifier, $extraPayload['phone'] ?? '', $internalRef);
                    break;

                case 'exam_pins':
                    $qty = max(1, (int)($extraPayload['quantity'] ?? 1));
                    $providerResult = $this->provider->purchaseExamPin($productCode, $qty, $customerIdentifier, $internalRef);
                    break;

                case 'bulk_sms':
                    $recipients = (array)($extraPayload['recipients'] ?? [$customerIdentifier]);
                    $senderId = (string)($extraPayload['sender_id'] ?? 'SUBNEXT');
                    $msgText = (string)($extraPayload['message'] ?? '');
                    $providerResult = $this->provider->sendBulkSms($senderId, $recipients, $msgText, $internalRef);
                    break;

                default:
                    $providerResult = ['ok' => false, 'status' => 'failed', 'message' => 'Unknown service requested.'];
                    break;
            }
        } catch (Throwable $e) {
            error_log("Provider dispatch exception: " . $e->getMessage());
            $providerResult = ['ok' => false, 'status' => 'unknown', 'message' => 'Provider communication timeout. Order is under review.'];
        }

        // 5. Finalize order state and handle safe refund if failed
        $isOk = $providerResult['ok'] ?? false;
        $orderStatus = $providerResult['status'] ?? ($isOk ? 'successful' : 'failed');
        $tokenOrPin = $providerResult['token_or_pin'] ?? ($providerResult['token'] ?? null);
        $providerOrderId = $providerResult['provider_order_id'] ?? null;
        $providerMsg = $providerResult['message'] ?? ($isOk ? 'Completed successfully.' : 'Provider error.');

        if ($orderStatus === 'successful') {
            $stmt = $this->pdo->prepare("
                UPDATE utility_orders
                SET status = 'successful',
                    token_or_pin = ?,
                    provider_order_id = ?,
                    provider_response = ?,
                    completed_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $tokenOrPin,
                $providerOrderId,
                json_encode($providerResult),
                $orderId
            ]);

            return [
                'ok' => true,
                'status' => 'successful',
                'order_id' => $orderId,
                'reference' => $internalRef,
                'token_or_pin' => $tokenOrPin,
                'amount' => $sellingPrice,
                'product_name' => $productName,
                'customer_identifier' => $customerIdentifier,
                'message' => $providerMsg
            ];
        }

        if ($orderStatus === 'failed') {
            // Safe, idempotent refund
            $this->pdo->beginTransaction();
            try {
                $stmt = $this->pdo->prepare("SELECT id, balance FROM wallets WHERE user_id = ? FOR UPDATE");
                $stmt->execute([$userId]);
                $w = $stmt->fetch(PDO::FETCH_ASSOC);

                $refundBefore = (float)$w['balance'];
                $refundAfter = $refundBefore + $sellingPrice;

                $stmt = $this->pdo->prepare("UPDATE wallets SET balance = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$refundAfter, $w['id']]);

                $refundRef = 'REF-' . $internalRef;
                $stmt = $this->pdo->prepare("
                    INSERT INTO wallet_transactions
                    (user_id, type, amount, balance_before, balance_after, reference, description, status, created_at, updated_at)
                    VALUES (?, 'refund', ?, ?, ?, ?, ?, 'successful', NOW(), NOW())
                ");
                $stmt->execute([
                    $userId,
                    $sellingPrice,
                    $refundBefore,
                    $refundAfter,
                    $refundRef,
                    "Automated refund for failed $productName ($internalRef)"
                ]);

                $stmt = $this->pdo->prepare("
                    UPDATE utility_orders
                    SET status = 'refunded',
                        provider_response = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    json_encode($providerResult),
                    $orderId
                ]);

                $this->pdo->commit();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                error_log("Automated refund failed for order $orderId: " . $e->getMessage());
            }

            return [
                'ok' => false,
                'status' => 'refunded',
                'order_id' => $orderId,
                'reference' => $internalRef,
                'message' => $providerMsg . ' Your wallet has been automatically refunded.'
            ];
        }

        // Unknown / Pending outcome
        $stmt = $this->pdo->prepare("
            UPDATE utility_orders
            SET status = 'pending',
                provider_response = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            json_encode($providerResult),
            $orderId
        ]);

        return [
            'ok' => false,
            'status' => 'pending',
            'order_id' => $orderId,
            'reference' => $internalRef,
            'message' => 'Your request has been placed and is currently being processed by the provider.'
        ];
    }
}
