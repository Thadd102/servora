<?php
/**
 * Subnext Real-Time Web Push Notification Service
 *
 * Implements Web Push API with VAPID authentication (RFC 8292) and
 * AES-128-GCM payload encryption (RFC 8291).
 */

if (!class_exists('PushNotificationService')) {
    class PushNotificationService
    {
        private PDO $pdo;
        private string $vapidPublicKey = '';
        private string $vapidPrivateKey = '';
        private string $vapidSubject = '';

        public function __construct(?PDO $pdo = null)
        {
            if ($pdo !== null) {
                $this->pdo = $pdo;
            } else {
                global $pdo;
                if ($pdo instanceof PDO) {
                    $this->pdo = $pdo;
                } else {
                    require_once __DIR__ . '/../config/database.php';
                    $this->pdo = $GLOBALS['pdo'];
                }
            }

            require_once __DIR__ . '/../config/env.php';

            // Load from .env first
            $this->vapidPublicKey = trim((string)(subnextEnv('VAPID_PUBLIC_KEY') ?: getenv('VAPID_PUBLIC_KEY') ?: ($_ENV['VAPID_PUBLIC_KEY'] ?? '')));
            $this->vapidPrivateKey = trim((string)(subnextEnv('VAPID_PRIVATE_KEY') ?: getenv('VAPID_PRIVATE_KEY') ?: ($_ENV['VAPID_PRIVATE_KEY'] ?? '')));
            $this->vapidSubject = trim((string)(subnextEnv('VAPID_SUBJECT') ?: getenv('VAPID_SUBJECT') ?: ($_ENV['VAPID_SUBJECT'] ?? 'mailto:support@subnext.com.ng')));

            // Fallback to app_settings in database
            if ($this->vapidPublicKey === '' || $this->vapidPrivateKey === '') {
                try {
                    $stmt = $this->pdo->query("
                        SELECT setting_key, setting_value
                        FROM app_settings
                        WHERE setting_key IN ('vapid_public_key', 'vapid_private_key', 'vapid_subject')
                    ");
                    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_KEY_PAIR) : [];
                    if (!empty($rows['vapid_public_key'])) {
                        $this->vapidPublicKey = trim((string)$rows['vapid_public_key']);
                    }
                    if (!empty($rows['vapid_private_key'])) {
                        $this->vapidPrivateKey = trim((string)$rows['vapid_private_key']);
                    }
                    if (!empty($rows['vapid_subject'])) {
                        $this->vapidSubject = trim((string)$rows['vapid_subject']);
                    }
                } catch (\Throwable) {}
            }
        }

        public function getVapidPublicKey(): string
        {
            return $this->vapidPublicKey;
        }

        /**
         * Save or renew a client push subscription.
         */
        public function saveSubscription(?int $userId, array $sub, ?string $userAgent = null): array
        {
            $endpoint = trim((string)($sub['endpoint'] ?? ''));
            $keys = $sub['keys'] ?? [];
            $p256dh = trim((string)($keys['p256dh'] ?? ''));
            $auth = trim((string)($keys['auth'] ?? ''));

            if ($endpoint === '' || $p256dh === '' || $auth === '') {
                return ['ok' => false, 'error' => 'Missing subscription credentials'];
            }

            $endpointHash = hash('sha256', $endpoint);

            $stmt = $this->pdo->prepare("
                INSERT INTO push_subscriptions (
                    user_id, endpoint, endpoint_hash, p256dh, auth,
                    user_agent, is_active, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, 1, NOW(), NOW()
                )
                ON DUPLICATE KEY UPDATE
                    user_id = COALESCE(VALUES(user_id), user_id),
                    p256dh = VALUES(p256dh),
                    auth = VALUES(auth),
                    user_agent = COALESCE(VALUES(user_agent), user_agent),
                    is_active = 1,
                    updated_at = NOW()
            ");

            $ok = $stmt->execute([
                ($userId !== null && $userId > 0) ? $userId : null,
                $endpoint,
                $endpointHash,
                $p256dh,
                $auth,
                $userAgent ? substr($userAgent, 0, 255) : null
            ]);

            return ['ok' => $ok];
        }

        /**
         * Deactivate subscription upon customer unsubscribe.
         */
        public function unsubscribe(string $endpoint): bool
        {
            $endpointHash = hash('sha256', trim($endpoint));
            $stmt = $this->pdo->prepare("
                UPDATE push_subscriptions
                SET is_active = 0, updated_at = NOW()
                WHERE endpoint_hash = ?
            ");
            return $stmt->execute([$endpointHash]);
        }

        /**
         * Check if customer already has active push subscription.
         */
        public function isSubscribed(?int $userId, ?string $endpoint = null): bool
        {
            if ($endpoint !== null && trim($endpoint) !== '') {
                $hash = hash('sha256', trim($endpoint));
                $stmt = $this->pdo->prepare("SELECT is_active FROM push_subscriptions WHERE endpoint_hash = ? LIMIT 1");
                $stmt->execute([$hash]);
                return (int)$stmt->fetchColumn() === 1;
            }

            if ($userId !== null && $userId > 0) {
                $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ? AND is_active = 1");
                $stmt->execute([$userId]);
                return (int)$stmt->fetchColumn() > 0;
            }

            return false;
        }

        /**
         * Compose and dispatch web push notifications.
         */
        public function send(array $data, ?int $adminId = null): array
        {
            $title = trim((string)($data['title'] ?? ''));
            $body = trim((string)($data['body'] ?? ''));
            $targetUrl = trim((string)($data['target_url'] ?? '/client/dashboard.php'));
            if ($targetUrl === '') {
                $targetUrl = '/client/dashboard.php';
            }
            $iconUrl = trim((string)($data['icon_url'] ?? '/assets/images/favicon-192x192.png'));

            $targetType = strtolower(trim((string)($data['target_type'] ?? 'all')));
            if (!in_array($targetType, ['all', 'specific'], true)) {
                $targetType = 'all';
            }

            $targetUserIds = null;
            if ($targetType === 'specific' && !empty($data['target_user_ids'])) {
                if (is_array($data['target_user_ids'])) {
                    $targetUserIds = implode(',', array_filter(array_map('intval', $data['target_user_ids'])));
                } else {
                    $targetUserIds = trim((string)$data['target_user_ids']);
                }
            }

            if ($title === '' || $body === '') {
                return ['ok' => false, 'error' => 'Title and body are required'];
            }

            // Fetch active target subscriptions
            $sql = "SELECT id, endpoint, p256dh, auth, user_id FROM push_subscriptions WHERE is_active = 1";
            $params = [];

            if ($targetType === 'specific' && !empty($targetUserIds)) {
                $ids = array_filter(array_map('intval', explode(',', $targetUserIds)));
                if (!empty($ids)) {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $sql .= " AND user_id IN ($placeholders)";
                    $params = $ids;
                } else {
                    return ['ok' => false, 'error' => 'No valid target users specified'];
                }
            }

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $subs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $totalTargeted = count($subs);

            // Record in push_notifications table
            $insStmt = $this->pdo->prepare("
                INSERT INTO push_notifications (
                    title, body, target_url, icon_url, target_type,
                    target_user_ids, total_targeted, total_sent, total_failed,
                    sent_by, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, 0, 0,
                    ?, NOW()
                )
            ");
            $insStmt->execute([
                $title,
                $body,
                $targetUrl,
                $iconUrl,
                $targetType,
                $targetUserIds,
                $totalTargeted,
                $adminId
            ]);
            $notificationId = (int)$this->pdo->lastInsertId();

            if ($totalTargeted === 0) {
                return [
                    'ok' => true,
                    'notification_id' => $notificationId,
                    'total_targeted' => 0,
                    'total_sent' => 0,
                    'total_failed' => 0,
                    'message' => 'No active subscribers targeted'
                ];
            }

            // Prepare payload for bin/send_push.js
            $payload = [
                'title' => $title,
                'body' => $body,
                'icon' => $iconUrl,
                'badge' => '/assets/images/favicon-32x32.png',
                'url' => $targetUrl,
                'data' => [
                    'notification_id' => $notificationId,
                    'url' => $targetUrl,
                    'timestamp' => time()
                ]
            ];

            $dispatchPayload = [
                'subscriptions' => $subs,
                'payload' => $payload,
                'vapid' => [
                    'publicKey' => $this->vapidPublicKey,
                    'privateKey' => $this->vapidPrivateKey,
                    'subject' => $this->vapidSubject
                ]
            ];

            $tmpFile = tempnam(sys_get_temp_dir(), 'sn_push_');
            file_put_contents($tmpFile, json_encode($dispatchPayload, JSON_UNESCAPED_SLASHES));

            $nodeBin = 'node';
            $scriptPath = escapeshellarg(__DIR__ . '/../bin/send_push.js');
            $argFile = escapeshellarg($tmpFile);

            $cmd = "$nodeBin $scriptPath $argFile 2>&1";
            $output = shell_exec($cmd);
            @unlink($tmpFile);

            $result = @json_decode((string)$output, true);
            $totalSent = (int)($result['total_sent'] ?? 0);
            $totalFailed = (int)($result['total_failed'] ?? ($totalTargeted - $totalSent));
            $expiredIds = $result['expired_ids'] ?? [];

            // Deactivate expired/unregistered subscriptions automatically
            if (!empty($expiredIds) && is_array($expiredIds)) {
                $expClean = array_map('intval', $expiredIds);
                $in = implode(',', array_fill(0, count($expClean), '?'));
                $expStmt = $this->pdo->prepare("UPDATE push_subscriptions SET is_active = 0, updated_at = NOW() WHERE id IN ($in)");
                $expStmt->execute($expClean);
            }

            // Update stats on notification record
            $upStmt = $this->pdo->prepare("
                UPDATE push_notifications
                SET total_sent = ?, total_failed = ?
                WHERE id = ?
            ");
            $upStmt->execute([$totalSent, $totalFailed, $notificationId]);

            return [
                'ok' => true,
                'notification_id' => $notificationId,
                'total_targeted' => $totalTargeted,
                'total_sent' => $totalSent,
                'total_failed' => $totalFailed,
                'expired_cleaned' => count($expiredIds)
            ];
        }

        /**
         * Fetch notification history for admin audit log.
         */
        public function getHistory(int $limit = 50): array
        {
            $stmt = $this->pdo->prepare("
                SELECT pn.*, u.full_name AS sender_name
                FROM push_notifications pn
                LEFT JOIN users u ON pn.sent_by = u.id
                ORDER BY pn.created_at DESC
                LIMIT ?
            ");
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        /**
         * Retrieve system stats.
         */
        public function getStats(): array
        {
            $totalSubs = (int)$this->pdo->query("SELECT COUNT(*) FROM push_subscriptions")->fetchColumn();
            $activeSubs = (int)$this->pdo->query("SELECT COUNT(*) FROM push_subscriptions WHERE is_active = 1")->fetchColumn();
            $totalDispatched = (int)$this->pdo->query("SELECT COUNT(*) FROM push_notifications")->fetchColumn();
            $totalSent = (int)$this->pdo->query("SELECT COALESCE(SUM(total_sent), 0) FROM push_notifications")->fetchColumn();

            return [
                'total_subscriptions' => $totalSubs,
                'active_subscriptions' => $activeSubs,
                'total_dispatched' => $totalDispatched,
                'total_sent' => $totalSent
            ];
        }
    }
}

