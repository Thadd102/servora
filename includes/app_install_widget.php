<?php
/**
 * Subnext Mobile App & PWA Installation Widget
 *
 * Provides a responsive, non-obstructive "Install Subnext App" button
 * positioned near the bottom of the screen with APK download detection.
 */

if (!function_exists('renderSubnextInstallWidget')) {
    function renderSubnextInstallWidget(bool $hasMobileBottomNav = false): void
    {
        // Check if an actual Android APK has been uploaded to downloads/
        $apkFileRelative = 'downloads/subnext.apk';
        $apkFileAbsolute = __DIR__ . '/../' . $apkFileRelative;
        $hasApk = file_exists($apkFileAbsolute) && filesize($apkFileAbsolute) > 1000;
        $apkUrl = $hasApk ? ($hasMobileBottomNav ? '../' : '') . $apkFileRelative : null;
        $baseAssets = $hasMobileBottomNav ? '../' : '';

        $bottomPosition = $hasMobileBottomNav ? '88px' : '24px';
        ?>
        <!-- Subnext App Installation Component -->
        <style>
            #subnext-install-banner {
                position: fixed;
                bottom: <?= $bottomPosition ?>;
                left: 20px;
                z-index: 99990;
                display: none;
                max-width: 380px;
                width: calc(100% - 40px);
                background: rgba(15, 23, 42, 0.96);
                backdrop-filter: blur(16px);
                -webkit-backdrop-filter: blur(16px);
                border: 1px solid rgba(255, 255, 255, 0.12);
                border-radius: 20px;
                padding: 14px 16px;
                color: #FFFFFF;
                box-shadow: 0 16px 36px -6px rgba(15, 23, 42, 0.35);
                font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                animation: snSlideUp 0.35s cubic-bezier(0.16, 1, 0.3, 1);
                box-sizing: border-box;
            }

            @media (min-width: 640px) {
                #subnext-install-banner {
                    width: 360px;
                    left: 24px;
                    bottom: 24px;
                }
            }

            @keyframes snSlideUp {
                from { opacity: 0; transform: translateY(20px); }
                to { opacity: 1; transform: translateY(0); }
            }

            .sn-inst-row {
                display: flex;
                align-items: center;
                gap: 12px;
            }

            .sn-inst-icon {
                width: 44px;
                height: 44px;
                border-radius: 12px;
                background: linear-gradient(135deg, #3E37B7 0%, #635BDB 100%);
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 20px;
                font-weight: 900;
                color: #FFFFFF;
                flex-shrink: 0;
                box-shadow: 0 4px 12px rgba(62, 55, 183, 0.35);
            }

            .sn-inst-info {
                flex: 1;
                min-width: 0;
            }

            .sn-inst-title {
                font-size: 13px;
                font-weight: 800;
                color: #FFFFFF;
                margin: 0;
                line-height: 1.2;
            }

            .sn-inst-sub {
                font-size: 11px;
                color: #94A3B8;
                margin: 2px 0 0;
                line-height: 1.3;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .sn-inst-actions {
                display: flex;
                align-items: center;
                gap: 8px;
                margin-top: 10px;
                padding-top: 10px;
                border-top: 1px solid rgba(255, 255, 255, 0.08);
            }

            .sn-btn-install {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 6px;
                background: linear-gradient(135deg, #3E37B7 0%, #5146C7 100%);
                color: #FFFFFF;
                font-size: 12px;
                font-weight: 800;
                padding: 7px 14px;
                border-radius: 10px;
                border: none;
                cursor: pointer;
                text-decoration: none;
                transition: all 0.2s ease;
                flex: 1;
            }

            .sn-btn-install:hover {
                filter: brightness(1.1);
            }

            .sn-btn-apk {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 6px;
                background: rgba(255, 255, 255, 0.1);
                border: 1px solid rgba(255, 255, 255, 0.15);
                color: #E2E8F0;
                font-size: 11px;
                font-weight: 700;
                padding: 7px 12px;
                border-radius: 10px;
                text-decoration: none;
                transition: background 0.18s ease;
            }

            .sn-btn-apk:hover {
                background: rgba(255, 255, 255, 0.2);
            }

            .sn-inst-close {
                background: transparent;
                border: none;
                color: #64748B;
                cursor: pointer;
                padding: 4px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 6px;
                transition: color 0.15s ease;
            }

            .sn-inst-close:hover {
                color: #FFFFFF;
            }

            /* Manual Install Guide Modal */
            #sn-install-guide-modal {
                position: fixed;
                inset: 0;
                z-index: 999999;
                display: none;
                align-items: center;
                justify-content: center;
                padding: 20px;
                background: rgba(15, 23, 42, 0.7);
                backdrop-filter: blur(8px);
                font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            }
            .sn-guide-card {
                background: #FFFFFF;
                border-radius: 24px;
                max-width: 420px;
                width: 100%;
                padding: 24px;
                color: #0F172A;
                box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.25);
            }
        </style>

        <div id="subnext-install-banner" role="dialog" aria-label="Install Subnext Mobile App">
            <div class="sn-inst-row">
                <div class="sn-inst-icon">S</div>
                <div class="sn-inst-info">
                    <p class="sn-inst-title">Subnext Mobile App</p>
                    <p class="sn-inst-sub">Faster access & lock-screen push alerts</p>
                </div>
                <button type="button" class="sn-inst-close" onclick="subnextDismissInstallPrompt()" aria-label="Close install prompt">
                    <svg style="width:16px; height:16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="sn-inst-actions">
                <button type="button" id="sn-btn-pwa-install" class="sn-btn-install" onclick="subnextTriggerPwaInstall()">
                    <svg style="width:14px; height:14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    <span>Install App</span>
                </button>

                <?php if ($hasApk && $apkUrl): ?>
                    <!-- Actual APK download button only visible when physical APK is present -->
                    <a href="<?= htmlspecialchars($apkUrl, ENT_QUOTES, 'UTF-8') ?>" download="Subnext.apk" class="sn-btn-apk" title="Download direct Android APK">
                        <span>Direct APK</span>
                    </a>
                <?php endif; ?>

                <button type="button" onclick="subnextShowInstallGuide()" class="sn-inst-close" title="Installation Help" style="font-size:11px; padding:6px;">
                    <span>Help ?</span>
                </button>
            </div>
        </div>

        <!-- Manual Installation Help Modal (For iOS Safari & Desktop browsers) -->
        <div id="sn-install-guide-modal" onclick="subnextCloseInstallGuide(event)">
            <div class="sn-guide-card" onclick="event.stopPropagation()">
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <div class="sn-inst-icon" style="width:36px; height:36px; font-size:16px;">S</div>
                        <h3 style="font-size:16px; font-weight:800; margin:0;">Install Subnext App</h3>
                    </div>
                    <button type="button" class="sn-inst-close" onclick="subnextCloseInstallGuide()" style="color:#64748B;">
                        <svg style="width:20px; height:20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <div style="font-size:13px; color:#475569; line-height:1.6; space-y-3;">
                    <p style="margin:0 0 12px;"><strong>On Android (Chrome / Brave / Edge):</strong><br>Tap the <strong>Install App</strong> button or open your browser menu (⋮) and tap <strong>Add to Home screen</strong> or <strong>Install app</strong>.</p>
                    <p style="margin:0 0 12px;"><strong>On iPhone / iPad (Safari):</strong><br>Tap the <strong>Share button (⎋)</strong> at the bottom of Safari, scroll down and tap <strong>Add to Home Screen (⊞)</strong>.</p>
                    <p style="margin:0;">Once installed, Subnext opens in fullscreen mode with lock-screen alerts just like a native app.</p>
                </div>

                <div style="margin-top:20px; text-align:right;">
                    <button type="button" onclick="subnextCloseInstallGuide()" class="sn-btn-install" style="width:100%; padding:10px;">
                        Got It, Thanks!
                    </button>
                </div>
            </div>
        </div>

        <script src="<?= $baseAssets ?>assets/js/pwa_installer.js" defer></script>
        <?php
    }
}

