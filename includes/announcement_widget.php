<?php
/**
 * Subnext Customer Announcements Widget
 *
 * Renders modern, responsive, non-obstructive announcement banners/popups
 * on index and client portal pages with instant dismissal memory.
 */

require_once __DIR__ . '/AnnouncementService.php';

if (!function_exists('renderSubnextAnnouncements')) {
    function renderSubnextAnnouncements(?int $userId = null): void
    {
        global $pdo;

        if (!$pdo) {
            return;
        }

        try {
            if ($userId === null && isset($_SESSION['user_id'])) {
                $userId = (int)$_SESSION['user_id'];
            }

            $guestToken = null;
            if ($userId === null) {
                if (empty($_COOKIE['sn_guest_id'])) {
                    $guestToken = bin2hex(random_bytes(16));
                    @setcookie('sn_guest_id', $guestToken, time() + (86400 * 365), '/');
                } else {
                    $guestToken = preg_replace('/[^a-zA-Z0-9]/', '', (string)$_COOKIE['sn_guest_id']);
                }
            }

            $service = new AnnouncementService($pdo);
            $announcements = $service->getActiveForCustomer($userId, $guestToken);

            if (empty($announcements)) {
                return;
            }

            ?>
            <!-- Subnext Dynamic Announcements System -->
            <style>
                .sn-announcement-wrapper {
                    width: 100%;
                    position: relative;
                    z-index: 999;
                    box-sizing: border-box;
                    margin-bottom: 16px;
                }
                .sn-announcement-card {
                    position: relative;
                    display: flex;
                    align-items: flex-start;
                    gap: 14px;
                    border-radius: 16px;
                    padding: 16px 20px;
                    box-shadow: 0 4px 18px rgba(15, 23, 42, 0.06);
                    transition: all 0.28s cubic-bezier(0.16, 1, 0.3, 1);
                    border: 1px solid transparent;
                    margin-bottom: 10px;
                    font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                }
                .sn-announcement-card.sn-type-info {
                    background: #EEF2FF;
                    border-color: #C7D2FE;
                    color: #1E1B4B;
                }
                .sn-announcement-card.sn-type-warning {
                    background: #FFFBEB;
                    border-color: #FDE68A;
                    color: #78350F;
                }
                .sn-announcement-card.sn-type-maintenance {
                    background: #FFF1F2;
                    border-color: #FECDD3;
                    color: #881337;
                }
                .sn-announcement-card.sn-type-important {
                    background: linear-gradient(135deg, #1E1B4B 0%, #312E81 100%);
                    border-color: rgba(99, 91, 219, 0.4);
                    color: #FFFFFF;
                    box-shadow: 0 10px 25px -4px rgba(49, 46, 129, 0.3);
                }
                .sn-ann-icon-box {
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    width: 38px;
                    height: 38px;
                    border-radius: 12px;
                    flex-shrink: 0;
                    font-size: 18px;
                }
                .sn-type-info .sn-ann-icon-box { background: #E0E7FF; color: #4338CA; }
                .sn-type-warning .sn-ann-icon-box { background: #FEF3C7; color: #B45309; }
                .sn-type-maintenance .sn-ann-icon-box { background: #FFE4E6; color: #BE123C; }
                .sn-type-important .sn-ann-icon-box { background: rgba(255, 255, 255, 0.15); color: #FFFFFF; }

                .sn-ann-content {
                    flex: 1;
                    min-width: 0;
                }
                .sn-ann-header {
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    margin-bottom: 4px;
                }
                .sn-ann-badge {
                    font-size: 10px;
                    font-weight: 800;
                    text-transform: uppercase;
                    letter-spacing: 0.08em;
                    padding: 2px 8px;
                    border-radius: 9999px;
                }
                .sn-type-info .sn-ann-badge { background: #E0E7FF; color: #3730A3; }
                .sn-type-warning .sn-ann-badge { background: #FDE68A; color: #92400E; }
                .sn-type-maintenance .sn-ann-badge { background: #FECDD3; color: #9F1239; }
                .sn-type-important .sn-ann-badge { background: #10B981; color: #FFFFFF; }

                .sn-ann-title {
                    font-size: 14px;
                    font-weight: 800;
                    margin: 0;
                    line-height: 1.3;
                }
                .sn-ann-message {
                    font-size: 13px;
                    line-height: 1.5;
                    margin: 4px 0 0;
                    opacity: 0.92;
                }
                .sn-ann-dismiss-btn {
                    background: transparent;
                    border: none;
                    cursor: pointer;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    width: 32px;
                    height: 32px;
                    border-radius: 8px;
                    padding: 0;
                    opacity: 0.65;
                    transition: opacity 0.15s ease, background-color 0.15s ease;
                    color: inherit;
                    flex-shrink: 0;
                }
                .sn-ann-dismiss-btn:hover {
                    opacity: 1;
                    background-color: rgba(0, 0, 0, 0.06);
                }
                .sn-type-important .sn-ann-dismiss-btn:hover {
                    background-color: rgba(255, 255, 255, 0.12);
                }
            </style>

            <div class="sn-announcement-wrapper" id="sn-announcements-container">
                <?php foreach ($announcements as $ann): ?>
                    <?php
                    $id = (int)$ann['id'];
                    $type = htmlspecialchars($ann['type'], ENT_QUOTES, 'UTF-8');
                    $title = htmlspecialchars($ann['title'], ENT_QUOTES, 'UTF-8');
                    $message = nl2br(htmlspecialchars($ann['message'], ENT_QUOTES, 'UTF-8'));
                    
                    $icon = '📢';
                    $badgeText = 'Announcement';
                    if ($ann['type'] === 'warning') {
                        $icon = '⚠️';
                        $badgeText = 'Warning';
                    } elseif ($ann['type'] === 'maintenance') {
                        $icon = '🛠️';
                        $badgeText = 'Maintenance';
                    } elseif ($ann['type'] === 'important') {
                        $icon = '🚨';
                        $badgeText = 'Urgent Notice';
                    }
                    ?>
                    <div class="sn-announcement-card sn-type-<?= $type ?>" id="sn-ann-<?= $id ?>" data-ann-id="<?= $id ?>" role="alert">
                        <div class="sn-ann-icon-box"><?= $icon ?></div>
                        <div class="sn-ann-content">
                            <div class="sn-ann-header">
                                <span class="sn-ann-badge"><?= $badgeText ?></span>
                                <h4 class="sn-ann-title"><?= $title ?></h4>
                            </div>
                            <div class="sn-ann-message"><?= $message ?></div>
                        </div>
                        <button type="button" class="sn-ann-dismiss-btn" aria-label="Dismiss announcement" onclick="subnextDismissAnnouncement(<?= $id ?>)">
                            <svg style="width:18px; height:18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>

            <script>
            (function() {
                // Client-side quick filter: immediately hide items already in localStorage
                try {
                    var dismissed = JSON.parse(localStorage.getItem('subnext_dismissed_announcements') || '[]');
                    if (Array.isArray(dismissed)) {
                        dismissed.forEach(function(annId) {
                            var el = document.getElementById('sn-ann-' + annId);
                            if (el) el.style.display = 'none';
                        });
                    }
                } catch(e) {}
            })();

            function subnextDismissAnnouncement(annId) {
                var el = document.getElementById('sn-ann-' + annId);
                if (el) {
                    el.style.opacity = '0';
                    el.style.transform = 'translateY(-8px)';
                    setTimeout(function() {
                        el.style.display = 'none';
                    }, 280);
                }

                // Save to localStorage immediately
                try {
                    var dismissed = JSON.parse(localStorage.getItem('subnext_dismissed_announcements') || '[]');
                    if (!dismissed.includes(annId)) {
                        dismissed.push(annId);
                        localStorage.setItem('subnext_dismissed_announcements', JSON.stringify(dismissed));
                    }
                } catch(e) {}

                // Persist to server via API
                var basePath = window.location.pathname.includes('/client/') ? '../' : '';
                fetch(basePath + 'api/dismiss_announcement.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ announcement_id: annId })
                }).catch(function(err) {
                    console.log('[Announcement] Dismiss sync error:', err);
                });
            }
            </script>
            <?php
        } catch (Throwable $e) {
            error_log('[AnnouncementWidget] Error: ' . $e->getMessage());
        }
    }
}

