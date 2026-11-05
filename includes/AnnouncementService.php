<?php
/**
 * Subnext Announcement Service
 *
 * Handles creation, management, expiry checks, targeting, and dismissal
 * of admin-controlled announcement banners and popups.
 */

if (!class_exists('AnnouncementService')) {
    class AnnouncementService
    {
        private PDO $pdo;

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
        }

        /**
         * Create a new announcement.
         */
        public function create(array $data, ?int $adminId = null): int
        {
            $title = trim((string)($data['title'] ?? ''));
            $message = trim((string)($data['message'] ?? ''));
            $type = strtolower(trim((string)($data['type'] ?? 'info')));
            if (!in_array($type, ['info', 'warning', 'maintenance', 'important'], true)) {
                $type = 'info';
            }

            $targetType = strtolower(trim((string)($data['target_type'] ?? 'all')));
            if ($targetType === 'specific_users') {
                $targetType = 'specific';
            }
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

            $isActive = !empty($data['is_active']) ? 1 : 0;
            $expiresAt = !empty($data['expires_at']) ? date('Y-m-d H:i:s', strtotime($data['expires_at'])) : null;

            $stmt = $this->pdo->prepare("
                INSERT INTO announcements (
                    title, message, type, target_type, target_user_ids,
                    is_active, expires_at, created_by, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, NOW(), NOW()
                )
            ");
            $stmt->execute([
                $title,
                $message,
                $type,
                $targetType,
                $targetUserIds,
                $isActive,
                $expiresAt,
                $adminId,
            ]);

            return (int)$this->pdo->lastInsertId();
        }

        /**
         * Update an existing announcement.
         */
        public function update(int $id, array $data): bool
        {
            $title = trim((string)($data['title'] ?? ''));
            $message = trim((string)($data['message'] ?? ''));
            $type = strtolower(trim((string)($data['type'] ?? 'info')));
            if (!in_array($type, ['info', 'warning', 'maintenance', 'important'], true)) {
                $type = 'info';
            }

            $targetType = strtolower(trim((string)($data['target_type'] ?? 'all')));
            if ($targetType === 'specific_users') {
                $targetType = 'specific';
            }
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

            $isActive = isset($data['is_active']) ? (!empty($data['is_active']) ? 1 : 0) : 1;
            $expiresAt = !empty($data['expires_at']) ? date('Y-m-d H:i:s', strtotime($data['expires_at'])) : null;

            $stmt = $this->pdo->prepare("
                UPDATE announcements SET
                    title = ?,
                    message = ?,
                    type = ?,
                    target_type = ?,
                    target_user_ids = ?,
                    is_active = ?,
                    expires_at = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");

            return $stmt->execute([
                $title,
                $message,
                $type,
                $targetType,
                $targetUserIds,
                $isActive,
                $expiresAt,
                $id,
            ]);
        }

        /**
         * Toggle active status.
         */
        public function toggleStatus(int $id): bool
        {
            $stmt = $this->pdo->prepare("
                UPDATE announcements
                SET is_active = IF(is_active = 1, 0, 1), updated_at = NOW()
                WHERE id = ?
            ");
            return $stmt->execute([$id]);
        }

        /**
         * Delete announcement.
         */
        public function delete(int $id): bool
        {
            $stmt = $this->pdo->prepare("DELETE FROM announcements WHERE id = ?");
            return $stmt->execute([$id]);
        }

        /**
         * Find single announcement by ID.
         */
        public function getById(int $id): ?array
        {
            $stmt = $this->pdo->prepare("SELECT * FROM announcements WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        }

        /**
         * Retrieve list of announcements for admin table.
         */
        public function getAll(?string $type = null, bool $activeOnly = false): array
        {
            $sql = "SELECT a.*, u.full_name AS creator_name,
                           (SELECT COUNT(*) FROM announcement_dismissals ad WHERE ad.announcement_id = a.id) AS dismiss_count
                    FROM announcements a
                    LEFT JOIN users u ON a.created_by = u.id
                    WHERE 1=1";
            $params = [];

            if ($type !== null && in_array($type, ['info', 'warning', 'maintenance', 'important'], true)) {
                $sql .= " AND a.type = ?";
                $params[] = $type;
            }

            if ($activeOnly) {
                $sql .= " AND a.is_active = 1 AND (a.expires_at IS NULL OR a.expires_at > NOW())";
            }

            $sql .= " ORDER BY a.created_at DESC";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        /**
         * Fetch active un-dismissed announcements targeting a specific customer (or guest).
         */
        public function getActiveForCustomer(?int $userId = null, ?string $guestToken = null): array
        {
            $sql = "
                SELECT a.*
                FROM announcements a
                WHERE a.is_active = 1
                  AND (a.expires_at IS NULL OR a.expires_at > NOW())
            ";
            $params = [];

            // Exclude dismissed
            if ($userId !== null && $userId > 0) {
                $sql .= " AND a.id NOT IN (
                    SELECT announcement_id FROM announcement_dismissals WHERE user_id = ?
                )";
                $params[] = $userId;
            } elseif ($guestToken !== null && $guestToken !== '') {
                $sql .= " AND a.id NOT IN (
                    SELECT announcement_id FROM announcement_dismissals WHERE guest_token = ?
                )";
                $params[] = $guestToken;
            }

            $sql .= " ORDER BY FIELD(a.type, 'important', 'maintenance', 'warning', 'info'), a.created_at DESC";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Filter specific target audience in PHP for reliable accuracy
            $filtered = [];
            foreach ($rows as $row) {
                if ($row['target_type'] === 'all') {
                    $filtered[] = $row;
                } elseif (in_array($row['target_type'], ['specific', 'specific_users'], true) && $userId !== null && $userId > 0) {
                    $allowed = array_map('intval', explode(',', (string)$row['target_user_ids']));
                    if (in_array($userId, $allowed, true)) {
                        $filtered[] = $row;
                    }
                }
            }

            return $filtered;
        }

        /**
         * Record user dismissal of an announcement so it is never shown again.
         */
        public function dismiss(int $announcementId, ?int $userId = null, ?string $guestToken = null): bool
        {
            if ($userId === null && ($guestToken === null || trim($guestToken) === '')) {
                return false;
            }

            $stmt = $this->pdo->prepare("
                INSERT IGNORE INTO announcement_dismissals (announcement_id, user_id, guest_token, dismissed_at)
                VALUES (?, ?, ?, NOW())
            ");

            return $stmt->execute([
                $announcementId,
                ($userId !== null && $userId > 0) ? $userId : null,
                $guestToken ?: null
            ]);
        }
    }
}

