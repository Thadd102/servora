/**
 * Subnext PWA Installation Manager
 * Captures beforeinstallprompt, manages standalone state, and controls install banner.
 */

(function (window) {
    'use strict';

    window.deferredPrompt = null;

    function isStandalone() {
        return window.matchMedia('(display-mode: standalone)').matches ||
               window.navigator.standalone === true ||
               document.referrer.includes('android-app://');
    }

    function isDismissedRecently() {
        try {
            var ts = parseInt(localStorage.getItem('subnext_install_dismissed') || '0', 10);
            // 3-day cooldown on dismiss
            return (Date.now() - ts) < (86400 * 1000 * 3);
        } catch (e) {
            return false;
        }
    }

    function isAlreadyInstalled() {
        try {
            return localStorage.getItem('subnext_pwa_installed') === '1';
        } catch (e) {
            return false;
        }
    }

    function showBanner() {
        if (isStandalone() || isAlreadyInstalled() || isDismissedRecently()) {
            return;
        }
        var banner = document.getElementById('subnext-install-banner');
        if (banner) {
            banner.style.display = 'block';
        }
    }

    function hideBanner() {
        var banner = document.getElementById('subnext-install-banner');
        if (banner) {
            banner.style.opacity = '0';
            banner.style.transform = 'translateY(16px)';
            banner.style.transition = 'all 0.25s ease';
            setTimeout(function () {
                banner.style.display = 'none';
            }, 260);
        }
    }

    // Capture standard PWA install prompt
    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        window.deferredPrompt = e;
        showBanner();
    });

    // Detect completed installation
    window.addEventListener('appinstalled', function () {
        window.deferredPrompt = null;
        try {
            localStorage.setItem('subnext_pwa_installed', '1');
        } catch (e) {}
        hideBanner();
        console.log('[PWA] Subnext successfully installed.');
    });

    // Fallback: If on mobile browser (iOS / Android) and beforeinstallprompt doesn't fire immediately
    window.addEventListener('load', function () {
        var isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
        if (isMobile && !isStandalone() && !isAlreadyInstalled() && !isDismissedRecently()) {
            setTimeout(function () {
                showBanner();
            }, 3000);
        }
    });

    window.subnextTriggerPwaInstall = async function () {
        if (window.deferredPrompt) {
            window.deferredPrompt.prompt();
            var choice = await window.deferredPrompt.userChoice;
            if (choice.outcome === 'accepted') {
                try {
                    localStorage.setItem('subnext_pwa_installed', '1');
                } catch (e) {}
            }
            window.deferredPrompt = null;
            hideBanner();
        } else {
            // No direct prompt available (e.g. Safari or Firefox)
            subnextShowInstallGuide();
        }
    };

    window.subnextDismissInstallPrompt = function () {
        try {
            localStorage.setItem('subnext_install_dismissed', Date.now().toString());
        } catch (e) {}
        hideBanner();
    };

    window.subnextShowInstallGuide = function () {
        var modal = document.getElementById('sn-install-guide-modal');
        if (modal) {
            modal.style.display = 'flex';
        }
    };

    window.subnextCloseInstallGuide = function () {
        var modal = document.getElementById('sn-install-guide-modal');
        if (modal) {
            modal.style.display = 'none';
        }
    };
})(window);

