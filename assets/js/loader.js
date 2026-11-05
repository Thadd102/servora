/**
 * Subnext Modern Loader, Navigation Progress & Network Monitor System
 * High-performance visual feedback for page navigations, form submissions, and connectivity states.
 *
 * SAFETY INVARIANT: Never automatically retry financial transactions on network reconnect.
 */
(function (window, document) {
    'use strict';

    var progressBar = null;
    var overlay = null;
    var titleEl = null;
    var subtitleEl = null;
    var progressTimer = null;
    var safetyTimer = null;
    var progressWidth = 0;
    var overlayShownTime = 0;

    // Network Toast Elements
    var networkToast = null;
    var networkTimer = null;
    var wasOffline = false;

    // Inject self-contained styles for progress, loader, and network notifications
    function injectRequiredStyles() {
        if (document.getElementById('subnext-loader-injected-styles')) return;
        var style = document.createElement('style');
        style.id = 'subnext-loader-injected-styles';
        style.textContent = [
            '/* Network Toast Notification */',
            '#subnext-network-toast {',
            '  position: fixed;',
            '  top: 18px;',
            '  left: 50%;',
            '  transform: translateX(-50%) translateY(-35px);',
            '  z-index: 100000;',
            '  max-width: 92%;',
            '  width: auto;',
            '  min-width: 280px;',
            '  padding: 11px 18px;',
            '  border-radius: 9999px;',
            '  display: flex;',
            '  align-items: center;',
            '  justify-content: center;',
            '  gap: 10px;',
            '  font-family: "Plus Jakarta Sans", system-ui, -apple-system, sans-serif;',
            '  font-size: 13px;',
            '  font-weight: 700;',
            '  letter-spacing: -0.01em;',
            '  box-shadow: 0 12px 30px -4px rgba(15, 23, 42, 0.4), 0 0 0 1px rgba(255, 255, 255, 0.1);',
            '  opacity: 0;',
            '  visibility: hidden;',
            '  transition: transform 0.28s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.25s ease, visibility 0.25s ease;',
            '  pointer-events: none;',
            '}',
            '#subnext-network-toast.show {',
            '  opacity: 1;',
            '  visibility: visible;',
            '  transform: translateX(-50%) translateY(0);',
            '  pointer-events: auto;',
            '}',
            '#subnext-network-toast.offline {',
            '  background: rgba(15, 23, 42, 0.96);',
            '  color: #FEF2F2;',
            '  border: 1.5px solid #EF4444;',
            '}',
            '#subnext-network-toast.online {',
            '  background: rgba(15, 23, 42, 0.96);',
            '  color: #ECFDF5;',
            '  border: 1.5px solid #10B981;',
            '}',
            '#subnext-network-toast svg {',
            '  width: 18px;',
            '  height: 18px;',
            '  flex-shrink: 0;',
            '}'
        ].join('\n');
        document.head.appendChild(style);
    }

    function createElements() {
        if (overlay) return;
        injectRequiredStyles();

        // Top progress bar
        progressBar = document.createElement('div');
        progressBar.id = 'subnext-top-progress';
        progressBar.setAttribute('role', 'progressbar');
        progressBar.setAttribute('aria-valuemin', '0');
        progressBar.setAttribute('aria-valuemax', '100');
        document.body.appendChild(progressBar);

        // Glassmorphic Modal Overlay
        overlay = document.createElement('div');
        overlay.id = 'subnext-loader-overlay';
        overlay.setAttribute('role', 'status');
        overlay.setAttribute('aria-live', 'polite');
        overlay.innerHTML =
            '<div class="subnext-loader-card">' +
                '<div class="subnext-spinner-wrapper">' +
                    '<div class="subnext-spinner-glow"></div>' +
                    '<div class="subnext-spinner-track"></div>' +
                    '<div class="subnext-spinner-ring"></div>' +
                    '<div class="subnext-spinner-emblem">S</div>' +
                '</div>' +
                '<h3 class="subnext-loader-title">' +
                    '<span id="subnext-loader-text">Loading</span>' +
                    '<span class="subnext-loader-dots"><span>.</span><span>.</span><span>.</span></span>' +
                '</h3>' +
                '<p class="subnext-loader-subtitle" id="subnext-loader-subtext">Please wait while we load your page securely.</p>' +
            '</div>';

        document.body.appendChild(overlay);
        titleEl = document.getElementById('subnext-loader-text');
        subtitleEl = document.getElementById('subnext-loader-subtext');

        // Allow user dismissal if overlay hangs for more than 3.5 seconds
        overlay.addEventListener('click', function () {
            if (Date.now() - overlayShownTime > 3500) {
                SubnextLoader.hide();
            }
        });
    }

    var SubnextLoader = {
        startProgress: function () {
            createElements();
            if (!progressBar) return;
            clearInterval(progressTimer);
            progressWidth = 15;
            progressBar.classList.add('active');
            progressBar.style.width = progressWidth + '%';

            progressTimer = setInterval(function () {
                if (progressWidth < 85) {
                    progressWidth += Math.random() * 12;
                    if (progressWidth > 85) progressWidth = 85;
                    progressBar.style.width = progressWidth + '%';
                }
            }, 250);
        },

        finishProgress: function () {
            if (!progressBar) return;
            clearInterval(progressTimer);
            progressBar.style.width = '100%';
            setTimeout(function () {
                progressBar.classList.remove('active');
                progressBar.style.opacity = '0';
                setTimeout(function () {
                    progressBar.style.width = '0%';
                }, 300);
            }, 200);
        },

        show: function (title, subtitle) {
            createElements();
            if (!overlay) return;

            if (title && titleEl) {
                titleEl.textContent = title;
            }
            if (subtitle && subtitleEl) {
                subtitleEl.textContent = subtitle;
            }

            overlayShownTime = Date.now();
            this.startProgress();
            overlay.classList.add('show');
            document.body.style.overflow = 'hidden';

            // Anti-Stuck Safeguard: Automatically force-dismiss loader after 7.5 seconds
            clearTimeout(safetyTimer);
            safetyTimer = setTimeout(function () {
                SubnextLoader.hide();
            }, 7500);
        },

        hide: function () {
            clearTimeout(safetyTimer);
            if (!overlay) return;
            overlay.classList.remove('show');
            this.finishProgress();
            document.body.style.overflow = '';
        }
    };

    // Helper: Determine if an anchor link qualifies for smooth internal navigation loading
    function isInternalNavLink(link) {
        if (!link || !link.href) return false;

        // Protocol check (only standard web protocols)
        if (link.protocol !== 'http:' && link.protocol !== 'https:') return false;

        // Origin check: must be strictly same domain/origin
        if (link.origin !== window.location.origin) return false;

        // Target check: skip links opening in new window/tab/frame
        var target = (link.getAttribute('target') || '').toLowerCase();
        if (target && target !== '_self') return false;

        // Skip download links or special rels
        if (link.hasAttribute('download')) return false;
        var rel = (link.getAttribute('rel') || '').toLowerCase();
        if (rel.indexOf('external') !== -1) return false;

        // Skip links explicitly opting out
        if (link.classList.contains('no-loader') || link.getAttribute('data-no-loader') === 'true') {
            return false;
        }

        // Skip phone, sms, whatsapp, or script links
        var hrefAttr = link.getAttribute('href') || '';
        if (hrefAttr.indexOf('javascript:') === 0 ||
            hrefAttr.indexOf('mailto:') === 0 ||
            hrefAttr.indexOf('tel:') === 0 ||
            hrefAttr.indexOf('sms:') === 0 ||
            hrefAttr.indexOf('wa.me') !== -1 ||
            hrefAttr.indexOf('whatsapp:') !== -1) {
            return false;
        }

        // Skip pure hash anchor links on current page (e.g. href="#services", href="#faq")
        if (hrefAttr.indexOf('#') === 0) return false;

        // If link URL is identical to current URL (ignoring hash) and has a hash
        if (link.pathname === window.location.pathname && link.search === window.location.search && link.hash) {
            return false;
        }

        // If link points to exactly the current full URL, don't show loading overlay
        if (link.href === window.location.href) {
            return false;
        }

        return true;
    }

    // Intercept internal link navigation clicks smoothly across desktop and mobile
    function setupLinkNavigationHooks() {
        document.addEventListener('click', function (e) {
            // Ignore non-primary click or modified clicks (Ctrl, Cmd, Shift, Alt, middle-click)
            if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) {
                return;
            }

            if (e.defaultPrevented) return;

            var link = e.target.closest('a');
            if (!link) return;

            if (!isInternalNavLink(link)) return;

            // Contextual loading title based on destination
            var destPath = (link.pathname || '').toLowerCase();
            var title = 'Loading';
            var subtitle = 'Please wait while we load the page...';

            if (destPath.indexOf('data') !== -1) {
                title = 'Loading Data Portal';
                subtitle = 'Fetching data plans and bundles...';
            } else if (destPath.indexOf('airtime') !== -1) {
                title = 'Loading Airtime Portal';
                subtitle = 'Connecting VTU recharging services...';
            } else if (destPath.indexOf('wallet') !== -1 || destPath.indexOf('fund') !== -1) {
                title = 'Loading Wallet';
                subtitle = 'Retrieving balance and funding options...';
            } else if (destPath.indexOf('orders') !== -1) {
                title = 'Loading Orders';
                subtitle = 'Retrieving your purchase history...';
            } else if (destPath.indexOf('profile') !== -1) {
                title = 'Loading Profile';
                subtitle = 'Opening user settings and preferences...';
            } else if (destPath.indexOf('support') !== -1) {
                title = 'Loading Support';
                subtitle = 'Connecting customer help desk...';
            } else if (destPath.indexOf('dashboard') !== -1) {
                title = 'Loading Dashboard';
                subtitle = 'Opening your control center...';
            } else if (destPath.indexOf('login') !== -1) {
                title = 'Opening Sign In';
                subtitle = 'Loading account authentication...';
            } else if (destPath.indexOf('register') !== -1) {
                title = 'Opening Registration';
                subtitle = 'Loading new client setup...';
            }

            SubnextLoader.show(title, subtitle);
        }, false);
    }

    // Auto-hook into form submissions across the application
    function setupFormHooks() {
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form || form.tagName !== 'FORM') return;

            // Skip if ignored or opens in a new tab
            if (form.classList.contains('no-loader') || form.getAttribute('data-no-loader') === 'true') {
                return;
            }
            if (form.target === '_blank') {
                return;
            }

            // Check HTML5 validity
            if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
                return;
            }

            // Contextual messaging based on form purpose
            var action = (form.getAttribute('action') || window.location.pathname).toLowerCase();
            var title = 'Processing Request';
            var subtitle = 'Please wait a moment while we process your request.';

            if (action.indexOf('data') !== -1) {
                title = 'Processing Data Order';
                subtitle = 'Connecting with network provider...';
            } else if (action.indexOf('airtime') !== -1) {
                title = 'Vending Airtime';
                subtitle = 'Dispensing airtime to recipient...';
            } else if (action.indexOf('fund') !== -1 || action.indexOf('pay') !== -1) {
                title = 'Connecting Gateway';
                subtitle = 'Initializing secure payment session...';
            } else if (action.indexOf('login') !== -1) {
                title = 'Signing In';
                subtitle = 'Verifying your account credentials...';
            } else if (action.indexOf('register') !== -1) {
                title = 'Creating Account';
                subtitle = 'Setting up your Subnext profile...';
            } else if (action.indexOf('foreign') !== -1) {
                title = 'Allocating Number';
                subtitle = 'Communicating with telecom provider...';
            } else if (action.indexOf('electricity') !== -1 || action.indexOf('cable') !== -1) {
                title = 'Processing Utility';
                subtitle = 'Verifying meter / decoder details...';
            }

            // Disable submit buttons to prevent double-submit
            var submitButtons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
            submitButtons.forEach(function (btn) {
                btn.disabled = true;
            });

            SubnextLoader.show(title, subtitle);
        }, true);
    }

    /* ==========================================================================
       NETWORK MONITOR & DISCONNECTION NOTIFICATIONS
       Detects online/offline events and displays friendly notifications.
       STRICT REQUIREMENT: Never automatically retry financial transactions.
       ========================================================================== */
    function createNetworkToastElement() {
        if (networkToast) return;
        injectRequiredStyles();

        networkToast = document.createElement('div');
        networkToast.id = 'subnext-network-toast';
        networkToast.setAttribute('role', 'alert');
        networkToast.setAttribute('aria-live', 'assertive');
        document.body.appendChild(networkToast);
    }

    function showNetworkNotice(isOnline) {
        createNetworkToastElement();
        if (!networkToast) return;

        clearTimeout(networkTimer);

        if (!isOnline) {
            // Dismiss any active navigation loader so user is not stuck on a loading screen when network drops
            SubnextLoader.hide();

            networkToast.className = 'offline show';
            networkToast.innerHTML =
                '<svg viewBox="0 0 24 24" fill="none" stroke="#EF4444" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">' +
                    '<line x1="1" y1="1" x2="23" y2="23"></line>' +
                    '<path d="M16.72 11.06A10.94 10.94 0 0 1 19 12.55"></path>' +
                    '<path d="M5 12.55a10.94 10.94 0 0 1 5.17-2.39"></path>' +
                    '<path d="M10.71 5.05A16 16 0 0 1 22.58 9"></path>' +
                    '<path d="M1.42 9a15.91 15.91 0 0 1 4.7-2.88"></path>' +
                    '<path d="M8.53 16.11a6 6 0 0 1 6.95 0"></path>' +
                    '<line x1="12" y1="20" x2="12.01" y2="20"></line>' +
                '</svg>' +
                '<div>' +
                    '<span>No internet connection</span>' +
                    '<span style="opacity:0.8; font-weight:500; font-size:12px; margin-left:6px;">— Please check your network.</span>' +
                '</div>';
        } else {
            networkToast.className = 'online show';
            networkToast.innerHTML =
                '<svg viewBox="0 0 24 24" fill="none" stroke="#10B981" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">' +
                    '<path d="M5 12.55a11 11 0 0 1 14.08 0"></path>' +
                    '<path d="M1.42 9a16 16 0 0 1 21.16 0"></path>' +
                    '<path d="M8.53 16.11a6 6 0 0 1 6.95 0"></path>' +
                    '<line x1="12" y1="20" x2="12.01" y2="20"></line>' +
                '</svg>' +
                '<div>' +
                    '<span>You\'re back online</span>' +
                    '<span style="opacity:0.8; font-weight:500; font-size:12px; margin-left:6px;">— Connection restored.</span>' +
                '</div>';

            // Auto-dismiss after 3.5 seconds
            networkTimer = setTimeout(function () {
                if (networkToast) {
                    networkToast.classList.remove('show');
                }
            }, 3500);

            /**
             * CRITICAL SAFETY RULE:
             * Never automatically retry or replay financial/wallet transactions.
             * The user must explicitly inspect their orders or wallet balance.
             */
        }
    }

    function setupNetworkMonitor() {
        window.addEventListener('offline', function () {
            wasOffline = true;
            showNetworkNotice(false);
        });

        window.addEventListener('online', function () {
            if (wasOffline) {
                showNetworkNotice(true);
                wasOffline = false;
            }
        });

        // Initial check on load: if browser starts offline
        if (typeof navigator.onLine === 'boolean' && !navigator.onLine) {
            wasOffline = true;
            showNetworkNotice(false);
        }
    }

    // Initialize all systems
    function init() {
        createElements();
        setupFormHooks();
        setupLinkNavigationHooks();
        setupNetworkMonitor();

        // Keyboard safety: Escape key always closes loader overlay
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                SubnextLoader.hide();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Anti-Stuck Guarantee: Reset overlay on back/forward browser navigation (bfcache) or page show
    window.addEventListener('pageshow', function () {
        SubnextLoader.hide();
        // Re-enable any submit buttons
        var buttons = document.querySelectorAll('button[disabled], input[type="submit"][disabled]');
        buttons.forEach(function (btn) {
            btn.disabled = false;
        });
    });

    window.addEventListener('pagehide', function () {
        SubnextLoader.hide();
    });

    window.SubnextLoader = SubnextLoader;

})(window, document);
