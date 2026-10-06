/**
 * Subnext Modern Loader & Progress System
 * Seamless, accessible visual feedback for form submissions and user actions.
 */
(function (window, document) {
    'use strict';

    var progressBar = null;
    var overlay = null;
    var titleEl = null;
    var subtitleEl = null;
    var progressTimer = null;
    var progressWidth = 0;

    function createElements() {
        if (overlay) return;

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
                    '<span id="subnext-loader-text">Processing</span>' +
                    '<span class="subnext-loader-dots"><span>.</span><span>.</span><span>.</span></span>' +
                '</h3>' +
                '<p class="subnext-loader-subtitle" id="subnext-loader-subtext">Please wait while we complete your request securely.</p>' +
            '</div>';

        document.body.appendChild(overlay);
        titleEl = document.getElementById('subnext-loader-text');
        subtitleEl = document.getElementById('subnext-loader-subtext');
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

            this.startProgress();
            overlay.classList.add('show');
            document.body.style.overflow = 'hidden';
        },

        hide: function () {
            if (!overlay) return;
            overlay.classList.remove('show');
            this.finishProgress();
            document.body.style.overflow = '';
        }
    };

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

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            createElements();
            setupFormHooks();
        });
    } else {
        createElements();
        setupFormHooks();
    }

    // Safety: Reset overlay on back/forward browser navigation (bfcache)
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            SubnextLoader.hide();
            // Re-enable any submit buttons
            var buttons = document.querySelectorAll('button[disabled], input[type="submit"][disabled]');
            buttons.forEach(function (btn) {
                btn.disabled = false;
            });
        }
    });

    window.SubnextLoader = SubnextLoader;

})(window, document);
