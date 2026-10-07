<?php

/**
 * Subnext Support Ticket Service
 *
 * Handles client ticket creation, admin management, message threads,
 * status transitions, and strict authorization enforcement.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/EmailService.php';

class SupportService
{
    public const STATUS_OPEN = 'open';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CLOSED = 'closed';

    public const CATEGORIES = [
        'Airtime & Data Bundles' => 'Data / Airtime issues or top-up delay',
        'Wallet Funding & Payments' => 'Paystack, Flutterwave, or Moniepoint transfer issues',
        'Foreign Virtual Numbers' => '5SIM foreign number orders & SMS verification codes',
        'Electricity & Cable TV' => 'Token generation, meter validation, decoder activation',
        'Exam PINs & Bulk SMS' => 'WAEC/NECO/JAMB PINs or Termii SMS delivery',
        'Account & Security' => 'Login, profile, or password assistance',
        'General Enquiry' => 'Other questions, partnerships, or feedback'
    ];

    /**
     * Ensure database schema tables exist automatically
     */
    public static function ensureTables(PDO $pdo): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }

        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS support_tickets (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    ticket_code VARCHAR(32) NOT NULL UNIQUE,
                    user_id INT NOT NULL,
                    subject VARCHAR(255) NOT NULL,
                    category VARCHAR(64) NOT NULL DEFAULT 'General Enquiry',
                    priority ENUM('low', 'medium', 'high', 'urgent') NOT NULL DEFAULT 'medium',
                    status ENUM('open', 'in_progress', 'resolved', 'closed') NOT NULL DEFAULT 'open',
                    last_reply_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    last_reply_by ENUM('client', 'admin') NOT NULL DEFAULT 'client',
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_tickets_user_id (user_id),
                    INDEX idx_tickets_status (status),
                    INDEX idx_tickets_code (ticket_code),
                    INDEX idx_tickets_last_reply (last_reply_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS support_ticket_messages (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    ticket_id INT NOT NULL,
                    user_id INT NOT NULL,
                    sender_type ENUM('client', 'admin') NOT NULL,
                    message TEXT NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_messages_ticket (ticket_id),
                    INDEX idx_messages_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            $checked = true;
        } catch (Throwable $e) {
            error_log('SupportService ensureTables error: ' . $e->getMessage());
        }
    }

    /**
     * Generate unique ticket code like TKT-894215
     */
    public static function generateTicketCode(PDO $pdo): string
    {
        do {
            $code = 'TKT-' . random_int(100000, 999999);
            $stmt = $pdo->prepare("SELECT id FROM support_tickets WHERE ticket_code = ? LIMIT 1");
            $stmt->execute([$code]);
        } while ($stmt->fetch());

        return $code;
    }

    /**
     * Create a new ticket and initial message (client action)
     */
    public static function createTicket(
        PDO $pdo,
        int $userId,
        string $subject,
        string $category,
        string $priority,
        string $message
    ): int {
        self::ensureTables($pdo);

        $pdo->beginTransaction();
        try {
            $code = self::generateTicketCode($pdo);
            $cleanPriority = in_array($priority, ['low', 'medium', 'high', 'urgent'], true) ? $priority : 'medium';

            $stmt = $pdo->prepare("
                INSERT INTO support_tickets (
                    ticket_code, user_id, subject, category, priority, status,
                    last_reply_at, last_reply_by, created_at
                ) VALUES (?, ?, ?, ?, ?, 'open', NOW(), 'client', NOW())
            ");
            $stmt->execute([
                $code,
                $userId,
                $subject,
                $category,
                $cleanPriority
            ]);

            $ticketId = (int)$pdo->lastInsertId();

            $msgStmt = $pdo->prepare("
                INSERT INTO support_ticket_messages (
                    ticket_id, user_id, sender_type, message, created_at
                ) VALUES (?, ?, 'client', ?, NOW())
            ");
            $msgStmt->execute([
                $ticketId,
                $userId,
                $message
            ]);

            $pdo->commit();
            return $ticketId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Add a message to an existing ticket (client or admin)
     */
    public static function addMessage(
        PDO $pdo,
        int $ticketId,
        int $userId,
        string $senderType,
        string $message,
        ?string $updateStatus = null
    ): bool {
        self::ensureTables($pdo);

        $pdo->beginTransaction();
        try {
            $msgStmt = $pdo->prepare("
                INSERT INTO support_ticket_messages (
                    ticket_id, user_id, sender_type, message, created_at
                ) VALUES (?, ?, ?, ?, NOW())
            ");
            $msgStmt->execute([
                $ticketId,
                $userId,
                $senderType,
                $message
            ]);

            // Update ticket metadata
            if ($senderType === 'admin') {
                $newStatus = in_array($updateStatus, [self::STATUS_OPEN, self::STATUS_IN_PROGRESS, self::STATUS_RESOLVED, self::STATUS_CLOSED], true)
                    ? $updateStatus
                    : self::STATUS_IN_PROGRESS;

                $updateStmt = $pdo->prepare("
                    UPDATE support_tickets
                    SET status = ?, last_reply_at = NOW(), last_reply_by = 'admin', updated_at = NOW()
                    WHERE id = ?
                ");
                $updateStmt->execute([$newStatus, $ticketId]);
            } else {
                // Client reply: if it was resolved, change status back to open/in_progress
                $fetchStatus = $pdo->prepare("SELECT status FROM support_tickets WHERE id = ?");
                $fetchStatus->execute([$ticketId]);
                $curr = $fetchStatus->fetchColumn();

                $newStatus = ($curr === self::STATUS_RESOLVED) ? self::STATUS_IN_PROGRESS : $curr;

                $updateStmt = $pdo->prepare("
                    UPDATE support_tickets
                    SET status = ?, last_reply_at = NOW(), last_reply_by = 'client', updated_at = NOW()
                    WHERE id = ?
                ");
                $updateStmt->execute([$newStatus, $ticketId]);
            }

            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('SupportService addMessage error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update ticket status (e.g. from admin panel)
     */
    public static function updateStatus(PDO $pdo, int $ticketId, string $status): bool
    {
        self::ensureTables($pdo);

        if (!in_array($status, [self::STATUS_OPEN, self::STATUS_IN_PROGRESS, self::STATUS_RESOLVED, self::STATUS_CLOSED], true)) {
            return false;
        }

        $stmt = $pdo->prepare("
            UPDATE support_tickets
            SET status = ?, updated_at = NOW()
            WHERE id = ?
        ");
        return $stmt->execute([$status, $ticketId]);
    }

    /**
     * Retrieve tickets for a specific client (enforces user isolation)
     */
    public static function getClientTickets(PDO $pdo, int $userId, ?string $statusFilter = null): array
    {
        self::ensureTables($pdo);

        $sql = "
            SELECT t.*,
                (SELECT COUNT(*) FROM support_ticket_messages WHERE ticket_id = t.id) AS message_count
            FROM support_tickets t
            WHERE t.user_id = ?
        ";
        $params = [$userId];

        if ($statusFilter && in_array($statusFilter, [self::STATUS_OPEN, self::STATUS_IN_PROGRESS, self::STATUS_RESOLVED, self::STATUS_CLOSED], true)) {
            $sql .= " AND t.status = ?";
            $params[] = $statusFilter;
        }

        $sql .= " ORDER BY t.last_reply_at DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get single ticket for a client with strict authorization check
     */
    public static function getClientTicket(PDO $pdo, int $ticketId, int $userId): ?array
    {
        self::ensureTables($pdo);

        $stmt = $pdo->prepare("
            SELECT t.*, u.full_name, u.email
            FROM support_tickets t
            JOIN users u ON u.id = t.user_id
            WHERE t.id = ? AND t.user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$ticketId, $userId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        return $ticket ?: null;
    }

    /**
     * Get single ticket for admin
     */
    public static function getAdminTicket(PDO $pdo, int $ticketId): ?array
    {
        self::ensureTables($pdo);

        $stmt = $pdo->prepare("
            SELECT t.*, u.full_name, u.email, u.phone,
                w.balance AS wallet_balance,
                u.created_at AS user_registered_at
            FROM support_tickets t
            JOIN users u ON u.id = t.user_id
            LEFT JOIN wallets w ON w.user_id = u.id
            WHERE t.id = ?
            LIMIT 1
        ");
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        return $ticket ?: null;
    }

    /**
     * Get all messages for a ticket
     */
    public static function getTicketMessages(PDO $pdo, int $ticketId): array
    {
        self::ensureTables($pdo);

        $stmt = $pdo->prepare("
            SELECT m.*, u.full_name, u.role
            FROM support_ticket_messages m
            JOIN users u ON u.id = m.user_id
            WHERE m.ticket_id = ?
            ORDER BY m.created_at ASC
        ");
        $stmt->execute([$ticketId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get all tickets for admin panel with search & filter
     */
    public static function getAdminTickets(
        PDO $pdo,
        ?string $statusFilter = null,
        ?string $searchQuery = null,
        int $limit = 50,
        int $offset = 0
    ): array {
        self::ensureTables($pdo);

        $sql = "
            SELECT t.*, u.full_name, u.email,
                (SELECT COUNT(*) FROM support_ticket_messages WHERE ticket_id = t.id) AS message_count
            FROM support_tickets t
            JOIN users u ON u.id = t.user_id
            WHERE 1=1
        ";
        $params = [];

        if ($statusFilter && in_array($statusFilter, [self::STATUS_OPEN, self::STATUS_IN_PROGRESS, self::STATUS_RESOLVED, self::STATUS_CLOSED], true)) {
            $sql .= " AND t.status = ?";
            $params[] = $statusFilter;
        }

        if ($searchQuery !== null && trim($searchQuery) !== '') {
            $term = '%' . trim($searchQuery) . '%';
            $sql .= " AND (t.ticket_code LIKE ? OR t.subject LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)";
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        $sql .= " ORDER BY (t.status = 'open') DESC, t.last_reply_at DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get statistics counts for admin dashboard
     */
    public static function getStats(PDO $pdo): array
    {
        self::ensureTables($pdo);

        $stmt = $pdo->query("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) AS open,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
                SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) AS resolved,
                SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) AS closed
            FROM support_tickets
        ");
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'total' => (int)($res['total'] ?? 0),
            'open' => (int)($res['open'] ?? 0),
            'in_progress' => (int)($res['in_progress'] ?? 0),
            'resolved' => (int)($res['resolved'] ?? 0),
            'closed' => (int)($res['closed'] ?? 0),
        ];
    }

    /**
     * Format status badge HTML
     */
    public static function renderStatusBadge(string $status): string
    {
        switch ($status) {
            case self::STATUS_OPEN:
                return '<span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-bold text-amber-700 border border-amber-200">
                    <span class="h-1.5 w-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                    Open
                </span>';
            case self::STATUS_IN_PROGRESS:
                return '<span class="inline-flex items-center gap-1.5 rounded-full bg-sky-50 px-2.5 py-1 text-xs font-bold text-sky-700 border border-sky-200">
                    <span class="h-1.5 w-1.5 rounded-full bg-sky-500"></span>
                    In Progress
                </span>';
            case self::STATUS_RESOLVED:
                return '<span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700 border border-emerald-200">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                    Resolved
                </span>';
            case self::STATUS_CLOSED:
            default:
                return '<span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600 border border-slate-200">
                    <span class="h-1.5 w-1.5 rounded-full bg-slate-400"></span>
                    Closed
                </span>';
        }
    }

    /**
     * Format priority badge HTML
     */
    public static function renderPriorityBadge(string $priority): string
    {
        switch (strtolower($priority)) {
            case 'urgent':
                return '<span class="rounded-md bg-rose-50 px-2 py-0.5 text-[11px] font-bold text-rose-700 border border-rose-200">Urgent</span>';
            case 'high':
                return '<span class="rounded-md bg-orange-50 px-2 py-0.5 text-[11px] font-bold text-orange-700 border border-orange-200">High</span>';
            case 'low':
                return '<span class="rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-600 border border-slate-200">Low</span>';
            case 'medium':
            default:
                return '<span class="rounded-md bg-indigo-50 px-2 py-0.5 text-[11px] font-bold text-indigo-700 border border-indigo-200">Normal</span>';
        }
    }
}
