<?php

/**
 * ReceiptService - Central Reusable Receipt Generation Engine
 *
 * Provides authentic, professional receipt data across all Servora services:
 * - Airtime VTU
 * - Data Subscription
 * - Electricity Bill Payment
 * - Cable TV Subscription
 * - Examination PINs
 * - Bulk SMS
 * - Foreign Virtual Numbers
 * - Wallet Funding & Financial Transactions
 *
 * Security & Integrity:
 * - Strict client-level data isolation (WHERE user_id = :userId)
 * - Safe presentation (zero internal credentials, cost prices, supplier IDs, or profit margins)
 */
class ReceiptService
{
    /**
     * Retrieve and normalize receipt data for a client
     *
     * @param PDO $pdo
     * @param int $userId Logged-in user ID
     * @param string|null $ref Unique transaction / order reference
     * @param string|null $type Optional type discriminator ('utility', 'data', 'foreign_number', 'wallet')
     * @param int|null $id Optional primary key ID
     * @return array|null Clean formatted receipt or null if not found or unauthorized
     */
    public static function getReceipt(
        PDO $pdo,
        int $userId,
        ?string $ref = null,
        ?string $type = null,
        ?int $id = null
    ): ?array {
        if ($userId <= 0) {
            return null;
        }

        $ref = trim((string)$ref);
        $type = strtolower(trim((string)$type));
        $id = $id !== null ? (int)$id : 0;

        if ($ref === '' && $id <= 0) {
            return null;
        }

        // Fetch client details
        $stmtUser = $pdo->prepare("SELECT id, full_name, email, phone FROM users WHERE id = ? LIMIT 1");
        $stmtUser->execute([$userId]);
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return null;
        }

        $clientInfo = [
            'name' => trim((string)($user['full_name'] ?? 'Client')),
            'email' => trim((string)($user['email'] ?? '')),
            'phone' => trim((string)($user['phone'] ?? ''))
        ];

        // 1. Check utility_orders (Airtime, Electricity, Cable TV, Exam PINs, Bulk SMS)
        if ($type === '' || $type === 'utility' || $type === 'airtime' || $type === 'electricity' || $type === 'cable_tv' || $type === 'exam_pins' || $type === 'bulk_sms') {
            $receipt = self::findUtilityOrderReceipt($pdo, $userId, $ref, $id, $clientInfo);
            if ($receipt !== null) {
                return $receipt;
            }
        }

        // 2. Check data_orders (Data Subscription)
        if ($type === '' || $type === 'data') {
            $receipt = self::findDataOrderReceipt($pdo, $userId, $ref, $id, $clientInfo);
            if ($receipt !== null) {
                return $receipt;
            }
        }

        // 3. Check virtual_number_orders (Foreign Numbers)
        if ($type === '' || $type === 'foreign_number' || $type === 'virtual_number' || $type === 'foreign_numbers') {
            $receipt = self::findForeignNumberReceipt($pdo, $userId, $ref, $id, $clientInfo);
            if ($receipt !== null) {
                return $receipt;
            }
        }

        // 4. Check wallet_transactions (Wallet Funding / Direct ledger transfers)
        if ($type === '' || $type === 'wallet' || $type === 'funding' || $type === 'transaction') {
            $receipt = self::findWalletTransactionReceipt($pdo, $userId, $ref, $id, $clientInfo);
            if ($receipt !== null) {
                return $receipt;
            }
        }

        return null;
    }

    /**
     * Resolve receipt from utility_orders table
     */
    private static function findUtilityOrderReceipt(
        PDO $pdo,
        int $userId,
        string $ref,
        int $id,
        array $clientInfo
    ): ?array {
        $sql = "
            SELECT
                id,
                order_reference,
                service_slug,
                product_code,
                product_name,
                customer_identifier,
                customer_name,
                selling_price,
                status,
                token_or_pin,
                order_payload,
                created_at,
                completed_at
            FROM utility_orders
            WHERE user_id = ?
        ";
        $params = [$userId];

        if ($ref !== '') {
            $sql .= " AND order_reference = ? LIMIT 1";
            $params[] = $ref;
        } elseif ($id > 0) {
            $sql .= " AND id = ? LIMIT 1";
            $params[] = $id;
        } else {
            return null;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $status = strtolower(trim((string)$row['status']));
        if ($status !== 'successful' && $status !== 'completed') {
            return null;
        }

        $serviceSlug = strtolower(trim((string)$row['service_slug']));
        $payload = [];
        if (!empty($row['order_payload'])) {
            $decoded = json_decode($row['order_payload'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }
        $extraPayload = (array)($payload['extra_payload'] ?? []);

        // Service labels and specific details
        $serviceCategory = match ($serviceSlug) {
            'airtime' => 'Airtime VTU Top-up',
            'electricity' => 'Electricity Bill Payment',
            'cable_tv' => 'Cable TV Subscription',
            'exam_pins' => 'Examination PIN Purchase',
            'bulk_sms' => 'Bulk SMS Dispatch',
            default => ucwords(str_replace('_', ' ', $serviceSlug))
        };

        $details = [];
        $tokenHighlight = null;

        if ($serviceSlug === 'airtime') {
            $network = strtoupper((string)($extraPayload['network'] ?? ''));
            if ($network !== '') {
                $details['Mobile Network'] = $network;
            }
            $details['Recipient Phone'] = $row['customer_identifier'];
            $details['Recharge Type'] = 'Instant VTU Credit';
        } elseif ($serviceSlug === 'electricity') {
            $details['Electricity DISCO'] = $row['product_name'];
            $details['Meter Number'] = $row['customer_identifier'];
            if (!empty($row['customer_name'])) {
                $details['Account Name'] = $row['customer_name'];
            }
            if (!empty($extraPayload['meter_type'])) {
                $details['Meter Type'] = strtoupper((string)$extraPayload['meter_type']);
            }
            if (!empty($extraPayload['address'])) {
                $details['Meter Address'] = (string)$extraPayload['address'];
            }
            if (!empty($row['token_or_pin'])) {
                $tokenHighlight = [
                    'label' => 'Prepaid Token',
                    'value' => $row['token_or_pin']
                ];
            }
        } elseif ($serviceSlug === 'cable_tv') {
            $details['Cable Provider'] = $row['product_name'];
            $details['Smartcard / IUC No.'] = $row['customer_identifier'];
            if (!empty($row['customer_name'])) {
                $details['Customer Name'] = $row['customer_name'];
            }
            if (!empty($extraPayload['package'])) {
                $details['Subscription Bouquet'] = (string)$extraPayload['package'];
            }
        } elseif ($serviceSlug === 'exam_pins') {
            $details['Examination Board'] = $row['product_name'];
            $details['Candidate / Contact Phone'] = $row['customer_identifier'];
            if (!empty($extraPayload['quantity'])) {
                $details['Quantity'] = (string)$extraPayload['quantity'] . ' PIN(s)';
            }
            if (!empty($row['token_or_pin'])) {
                $tokenHighlight = [
                    'label' => 'Exam PIN / Serial',
                    'value' => $row['token_or_pin']
                ];
            }
        } elseif ($serviceSlug === 'bulk_sms') {
            if (!empty($extraPayload['sender_id'])) {
                $details['Sender ID'] = (string)$extraPayload['sender_id'];
            }
            if (!empty($extraPayload['recipient_count'])) {
                $details['Total Recipients'] = (string)$extraPayload['recipient_count'];
            }
            if (!empty($extraPayload['pages'])) {
                $details['Pages / Units per SMS'] = (string)$extraPayload['pages'];
            }
        } else {
            $details['Product'] = $row['product_name'];
            $details['Customer / Recipient'] = $row['customer_identifier'];
            if (!empty($row['token_or_pin'])) {
                $tokenHighlight = [
                    'label' => 'Token / PIN',
                    'value' => $row['token_or_pin']
                ];
            }
        }

        $dateValue = $row['completed_at'] ?: $row['created_at'];

        return [
            'receipt_no' => $row['order_reference'],
            'reference' => $row['order_reference'],
            'service_type' => $serviceSlug,
            'service_category' => $serviceCategory,
            'product_name' => $row['product_name'],
            'amount' => (float)$row['selling_price'],
            'formatted_amount' => '₦' . number_format((float)$row['selling_price'], 2),
            'status' => 'SUCCESSFUL',
            'payment_method' => 'Servora Wallet',
            'date' => $dateValue,
            'formatted_date' => date('d M Y, h:i A', strtotime($dateValue)),
            'customer_identifier' => $row['customer_identifier'],
            'customer_name' => $row['customer_name'] ?: $clientInfo['name'],
            'details' => $details,
            'token_highlight' => $tokenHighlight,
            'client' => $clientInfo
        ];
    }

    /**
     * Resolve receipt from data_orders table
     */
    private static function findDataOrderReceipt(
        PDO $pdo,
        int $userId,
        string $ref,
        int $id,
        array $clientInfo
    ): ?array {
        $sql = "
            SELECT
                do.id,
                do.order_reference,
                do.phone_number,
                do.selling_price,
                do.status,
                do.created_at,
                do.completed_at,
                dp.name AS plan_name,
                dp.data_amount,
                dp.validity,
                n.name AS network_name
            FROM data_orders do
            LEFT JOIN data_plans dp ON dp.id = do.plan_id
            LEFT JOIN networks n ON n.id = dp.network_id
            WHERE do.user_id = ?
        ";
        $params = [$userId];

        if ($ref !== '') {
            $sql .= " AND do.order_reference = ? LIMIT 1";
            $params[] = $ref;
        } elseif ($id > 0) {
            $sql .= " AND do.id = ? LIMIT 1";
            $params[] = $id;
        } else {
            return null;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $status = strtolower(trim((string)$row['status']));
        if ($status !== 'successful' && $status !== 'completed') {
            return null;
        }

        $networkName = trim((string)($row['network_name'] ?? 'Data'));
        $planName = trim((string)($row['plan_name'] ?? 'Data Plan'));
        $dataAmount = trim((string)($row['data_amount'] ?? ''));
        $validity = trim((string)($row['validity'] ?? ''));

        $details = [
            'Network' => $networkName,
            'Data Bundle' => $planName,
            'Recipient Phone' => $row['phone_number']
        ];

        if ($dataAmount !== '') {
            $details['Data Volume'] = $dataAmount;
        }
        if ($validity !== '') {
            $details['Validity'] = $validity;
        }
        $details['Delivery Status'] = 'Instant Electronic Top-up';

        $dateValue = $row['completed_at'] ?: $row['created_at'];

        return [
            'receipt_no' => $row['order_reference'],
            'reference' => $row['order_reference'],
            'service_type' => 'data',
            'service_category' => 'Data Subscription',
            'product_name' => "{$networkName} {$planName}",
            'amount' => (float)$row['selling_price'],
            'formatted_amount' => '₦' . number_format((float)$row['selling_price'], 2),
            'status' => 'SUCCESSFUL',
            'payment_method' => 'Servora Wallet',
            'date' => $dateValue,
            'formatted_date' => date('d M Y, h:i A', strtotime($dateValue)),
            'customer_identifier' => $row['phone_number'],
            'customer_name' => $clientInfo['name'],
            'details' => $details,
            'token_highlight' => null,
            'client' => $clientInfo
        ];
    }

    /**
     * Resolve receipt from virtual_number_orders table
     */
    private static function findForeignNumberReceipt(
        PDO $pdo,
        int $userId,
        string $ref,
        int $id,
        array $clientInfo
    ): ?array {
        $sql = "
            SELECT
                id,
                order_reference,
                service_code,
                service_name,
                country_code,
                country_name,
                operator_code,
                operator_name,
                provider_phone_number,
                sms_code,
                selling_price,
                status,
                created_at,
                completed_at
            FROM virtual_number_orders
            WHERE user_id = ?
        ";
        $params = [$userId];

        if ($ref !== '') {
            $sql .= " AND order_reference = ? LIMIT 1";
            $params[] = $ref;
        } elseif ($id > 0) {
            $sql .= " AND id = ? LIMIT 1";
            $params[] = $id;
        } else {
            return null;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $status = strtolower(trim((string)$row['status']));
        // For foreign numbers, completed or waiting_sms (where number was successfully allocated)
        if (!in_array($status, ['completed', 'waiting_sms', 'successful'], true)) {
            return null;
        }

        $phone = trim((string)($row['provider_phone_number'] ?? ''));
        $smsCode = trim((string)($row['sms_code'] ?? ''));

        $details = [
            'Target Platform / App' => $row['service_name'],
            'Country' => $row['country_name'] . ' (' . strtoupper($row['country_code']) . ')'
        ];

        if ($phone !== '') {
            $details['Allocated Number'] = $phone;
        }

        $tokenHighlight = null;
        if ($smsCode !== '') {
            $tokenHighlight = [
                'label' => 'Received Verification Code (OTP)',
                'value' => $smsCode
            ];
            $details['SMS Verification Code'] = $smsCode;
        }

        $dateValue = $row['completed_at'] ?: $row['created_at'];

        return [
            'receipt_no' => $row['order_reference'],
            'reference' => $row['order_reference'],
            'service_type' => 'foreign_number',
            'service_category' => 'Foreign Number Verification',
            'product_name' => "{$row['service_name']} ({$row['country_name']})",
            'amount' => (float)$row['selling_price'],
            'formatted_amount' => '₦' . number_format((float)$row['selling_price'], 2),
            'status' => 'COMPLETED',
            'payment_method' => 'Servora Wallet',
            'date' => $dateValue,
            'formatted_date' => date('d M Y, h:i A', strtotime($dateValue)),
            'customer_identifier' => $phone ?: ($clientInfo['phone'] ?: 'N/A'),
            'customer_name' => $clientInfo['name'],
            'details' => $details,
            'token_highlight' => $tokenHighlight,
            'client' => $clientInfo
        ];
    }

    /**
     * Resolve receipt from wallet_transactions table (Wallet Funding or Ledger)
     */
    private static function findWalletTransactionReceipt(
        PDO $pdo,
        int $userId,
        string $ref,
        int $id,
        array $clientInfo
    ): ?array {
        $sql = "
            SELECT
                id,
                type,
                amount,
                balance_before,
                balance_after,
                reference,
                description,
                status,
                created_at
            FROM wallet_transactions
            WHERE user_id = ?
        ";
        $params = [$userId];

        if ($ref !== '') {
            $sql .= " AND reference = ? LIMIT 1";
            $params[] = $ref;
        } elseif ($id > 0) {
            $sql .= " AND id = ? LIMIT 1";
            $params[] = $id;
        } else {
            return null;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $status = strtolower(trim((string)$row['status']));
        if ($status !== 'successful') {
            return null;
        }

        $type = strtolower(trim((string)$row['type']));
        $typeLabel = match ($type) {
            'funding' => 'Wallet Funding Deposit',
            'credit' => 'Wallet Credit Adjustment',
            'refund' => 'Service Refund Settlement',
            'debit' => 'Service Wallet Payment',
            default => ucwords(str_replace('_', ' ', $type))
        };

        $description = trim((string)($row['description'] ?? $typeLabel));
        $amount = (float)$row['amount'];

        $details = [
            'Transaction Type' => $typeLabel,
            'Description' => $description
        ];

        if (isset($row['balance_before'], $row['balance_after'])) {
            $details['Balance Prior'] = '₦' . number_format((float)$row['balance_before'], 2);
            $details['Settled Balance'] = '₦' . number_format((float)$row['balance_after'], 2);
        }

        // Determine funding gateway if applicable
        $paymentMethod = 'Online Payment Gateway';
        $refUpper = strtoupper($row['reference']);
        if (str_starts_with($refUpper, 'FLW') || str_contains($refUpper, 'FLUTTERWAVE')) {
            $paymentMethod = 'Flutterwave Payment Gateway';
        } elseif (str_starts_with($refUpper, 'PSK') || str_contains($refUpper, 'PAYSTACK')) {
            $paymentMethod = 'Paystack Payment Gateway';
        } elseif (str_starts_with($refUpper, 'MANUAL') || str_contains(strtolower($description), 'admin')) {
            $paymentMethod = 'Servora Direct Bank Transfer';
        } elseif ($type === 'debit') {
            $paymentMethod = 'Servora Wallet Account';
        }

        return [
            'receipt_no' => $row['reference'],
            'reference' => $row['reference'],
            'service_type' => 'wallet',
            'service_category' => 'Servora Wallet Transaction',
            'product_name' => $description,
            'amount' => $amount,
            'formatted_amount' => '₦' . number_format($amount, 2),
            'status' => 'SUCCESSFUL',
            'payment_method' => $paymentMethod,
            'date' => $row['created_at'],
            'formatted_date' => date('d M Y, h:i A', strtotime($row['created_at'])),
            'customer_identifier' => $clientInfo['email'] ?: $clientInfo['phone'],
            'customer_name' => $clientInfo['name'],
            'details' => $details,
            'token_highlight' => null,
            'client' => $clientInfo
        ];
    }
}
