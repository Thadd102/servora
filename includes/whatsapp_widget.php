<?php
/**
 * Subnext Floating WhatsApp Customer Support Widget
 *
 * Dynamically queries admin-configured WhatsApp number and default welcoming message.
 * Updates instantly whenever the admin modifies settings in the database.
 * Visible exclusively on the landing page (index.php) and client-side portal.
 */

if (!function_exists('renderSubnextWhatsAppWidget')) {
    function renderSubnextWhatsAppWidget(bool $hasMobileBottomNav = false): void
    {
        global $pdo;

        if (!$pdo) {
            return;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT setting_key, setting_value
                FROM app_settings
                WHERE setting_key IN ('whatsapp_support_number', 'whatsapp_support_message', 'whatsapp_support_status')
            ");
            $stmt->execute();
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            $status = $settings['whatsapp_support_status'] ?? '1';
            if ($status !== '1') {
                return;
            }

            $rawNumber = trim($settings['whatsapp_support_number'] ?? '');
            if ($rawNumber === '') {
                return;
            }

            // Normalize phone number for wa.me link
            $cleanNumber = preg_replace('/[^\d]/', '', $rawNumber);

            // If Nigerian local format (e.g. 080... or 070... or 090...), convert to 234...
            if (str_starts_with($cleanNumber, '0') && strlen($cleanNumber) === 11) {
                $cleanNumber = '234' . substr($cleanNumber, 1);
            }

            if (strlen($cleanNumber) < 8) {
                return;
            }

            $welcomeMessage = trim($settings['whatsapp_support_message'] ?? 'Hello Subnext Support, I need assistance with your services.');
            if ($welcomeMessage === '') {
                $welcomeMessage = 'Hello Subnext Support, I need assistance with your services.';
            }

            $waUrl = 'https://wa.me/' . $cleanNumber . '?text=' . rawurlencode($welcomeMessage);
            $mobileBottom = $hasMobileBottomNav ? '84px' : '20px';
            ?>
            <!-- Subnext Floating WhatsApp Support Widget (Self-Contained & Responsive) -->
            <style>
                #subnext-whatsapp-widget {
                    position: fixed !important;
                    top: auto !important;
                    left: auto !important;
                    bottom: 24px !important;
                    right: 24px !important;
                    z-index: 999999 !important;
                    display: flex !important;
                    align-items: center !important;
                    justify-content: flex-end !important;
                    text-decoration: none !important;
                    font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
                    pointer-events: auto !important;
                    margin: 0 !important;
                    padding: 0 !important;
                }

                @media (max-width: 640px) {
                    #subnext-whatsapp-widget {
                        bottom: <?= $mobileBottom ?> !important;
                        right: 16px !important;
                    }
                }

                #subnext-whatsapp-widget .sn-wa-btn {
                    position: relative !important;
                    display: flex !important;
                    align-items: center !important;
                    justify-content: center !important;
                    width: 56px !important;
                    height: 56px !important;
                    border-radius: 50% !important;
                    background: #25D366 !important;
                    color: #FFFFFF !important;
                    box-shadow: 0 4px 18px rgba(37, 211, 102, 0.45) !important;
                    transition: transform 0.22s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.22s ease, background-color 0.2s ease !important;
                    cursor: pointer !important;
                    text-decoration: none !important;
                    outline: none !important;
                    border: none !important;
                    flex-shrink: 0 !important;
                    -webkit-tap-highlight-color: transparent !important;
                }

                #subnext-whatsapp-widget .sn-wa-btn:hover {
                    background: #20bd5a !important;
                    transform: scale(1.08) !important;
                    box-shadow: 0 8px 24px rgba(37, 211, 102, 0.6) !important;
                }

                #subnext-whatsapp-widget .sn-wa-btn:active {
                    transform: scale(0.95) !important;
                }

                #subnext-whatsapp-widget .sn-wa-btn svg {
                    width: 32px !important;
                    height: 32px !important;
                    fill: #FFFFFF !important;
                    position: relative !important;
                    z-index: 2 !important;
                    display: block !important;
                }

                #subnext-whatsapp-widget .sn-wa-pulse {
                    position: absolute !important;
                    top: -3px !important;
                    left: -3px !important;
                    right: -3px !important;
                    bottom: -3px !important;
                    border-radius: 50% !important;
                    background: rgba(37, 211, 102, 0.35) !important;
                    animation: snWaPulseAnim 2.2s infinite ease-out !important;
                    pointer-events: none !important;
                    z-index: 1 !important;
                }

                @keyframes snWaPulseAnim {
                    0% { transform: scale(1); opacity: 0.85; }
                    70% { transform: scale(1.4); opacity: 0; }
                    100% { transform: scale(1.4); opacity: 0; }
                }

                #subnext-whatsapp-widget .sn-wa-tooltip {
                    display: none !important;
                    align-items: center !important;
                    margin-right: 12px !important;
                    padding: 8px 14px !important;
                    background: rgba(15, 23, 42, 0.94) !important;
                    color: #FFFFFF !important;
                    font-size: 13px !important;
                    font-weight: 700 !important;
                    letter-spacing: -0.01em !important;
                    border-radius: 9999px !important;
                    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.18) !important;
                    white-space: nowrap !important;
                    pointer-events: none !important;
                    opacity: 0 !important;
                    transform: translateX(8px) !important;
                    transition: opacity 0.2s ease, transform 0.2s ease !important;
                    border: 1px solid rgba(255, 255, 255, 0.1) !important;
                }

                #subnext-whatsapp-widget .sn-wa-dot {
                    width: 8px !important;
                    height: 8px !important;
                    border-radius: 50% !important;
                    background: #34D399 !important;
                    margin-right: 8px !important;
                    display: inline-block !important;
                    flex-shrink: 0 !important;
                }

                #subnext-whatsapp-widget:hover .sn-wa-tooltip {
                    opacity: 1 !important;
                    transform: translateX(0) !important;
                }

                @media (min-width: 640px) {
                    #subnext-whatsapp-widget .sn-wa-tooltip {
                        display: inline-flex !important;
                    }
                }
            </style>
            <div id="subnext-whatsapp-widget" role="complementary" aria-label="Customer Support">
                <!-- Tooltip badge (Visible on Desktop hover) -->
                <div class="sn-wa-tooltip">
                    <span class="sn-wa-dot"></span>
                    <span>Chat on WhatsApp</span>
                    <span style="opacity:0.85; font-size:11px; margin-left:4px; font-weight:600;">(<?= htmlspecialchars($rawNumber, ENT_QUOTES, 'UTF-8') ?>)</span>
                </div>

                <!-- Floating WhatsApp Trigger Button -->
                <a href="<?= htmlspecialchars($waUrl, ENT_QUOTES, 'UTF-8') ?>"
                   target="_blank"
                   rel="noopener noreferrer"
                   title="WhatsApp Support: <?= htmlspecialchars($rawNumber, ENT_QUOTES, 'UTF-8') ?>"
                   aria-label="Chat with Subnext Support on WhatsApp (<?= htmlspecialchars($rawNumber, ENT_QUOTES, 'UTF-8') ?>)"
                   class="sn-wa-btn">
                    <span class="sn-wa-pulse"></span>
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91C2.13 13.66 2.59 15.36 3.45 16.86L2.05 22L7.3 20.62C8.75 21.41 10.38 21.83 12.04 21.83C17.5 21.83 21.95 17.38 21.95 11.92C21.95 9.27 20.92 6.78 19.05 4.91C17.18 3.03 14.69 2 12.04 2M12.05 3.67C14.25 3.67 16.31 4.53 17.87 6.09C19.42 7.65 20.28 9.72 20.28 11.92C20.28 16.46 16.58 20.15 12.04 20.15C10.56 20.15 9.11 19.76 7.85 19.01L7.55 18.83L4.43 19.65L5.26 16.61L5.06 16.29C4.24 14.99 3.8 13.47 3.8 11.91C3.81 7.37 7.5 3.67 12.05 3.67M9.53 7.34C9.33 7.34 9 7.42 8.71 7.73C8.42 8.04 7.6 8.81 7.6 10.37C7.6 11.93 8.74 13.44 8.9 13.65C9.06 13.86 11.13 17.05 14.3 18.42C15.06 18.75 15.65 18.95 16.11 19.09C16.87 19.33 17.57 19.3 18.12 19.22C18.74 19.13 20.02 18.45 20.28 17.7C20.55 16.96 20.55 16.33 20.47 16.2C20.39 16.07 20.19 16 19.88 15.84C19.58 15.68 18.07 14.94 17.79 14.84C17.51 14.73 17.31 14.68 17.11 14.99C16.9 15.29 16.33 15.97 16.15 16.17C15.98 16.38 15.8 16.4 15.5 16.25C15.19 16.1 13.9 15.67 12.36 14.3C11.17 13.23 10.36 11.91 10.06 11.4C9.75 10.89 10.03 10.61 10.18 10.46C10.32 10.32 10.49 10.09 10.65 9.91C10.8 9.72 10.85 9.59 10.95 9.38C11.06 9.18 11 9 10.93 8.85C10.85 8.7 10.28 7.31 10.05 6.74C9.82 6.19 9.58 6.27 9.4 6.26L8.85 6.25C8.65 6.25 8.35 6.33 8.05 6.64L9.53 7.34Z"/>
                    </svg>
                </a>
            </div>
            <?php
        } catch (Throwable $e) {
            error_log('[WhatsAppWidget] Error: ' . $e->getMessage());
        }
    }
}
