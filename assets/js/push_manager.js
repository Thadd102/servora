/**
 * Subnext Client Web Push Manager
 * Handles permission requests, VAPID subscription, and server sync.
 */

(function (window) {
    'use strict';

    function urlB64ToUint8Array(base64String) {
        var padding = '='.repeat((4 - (base64String.length % 4)) % 4);
        var base64 = (base64String + padding).replace(/\-/g, '+').replace(/_/g, '/');
        var rawData = window.atob(base64);
        var outputArray = new Uint8Array(rawData.length);
        for (var i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    }

    function getBasePath() {
        return window.location.pathname.includes('/client/') ? '../' : './';
    }

    var SubnextPush = {
        isSupported: function () {
            return ('serviceWorker' in navigator) && ('PushManager' in window) && ('Notification' in window);
        },

        getRegistration: async function () {
            if (!this.isSupported()) return null;
            return await navigator.serviceWorker.ready;
        },

        getSubscription: async function () {
            var reg = await this.getRegistration();
            if (!reg) return null;
            return await reg.pushManager.getSubscription();
        },

        getPermissionState: function () {
            if (!('Notification' in window)) return 'unsupported';
            return Notification.permission;
        },

        subscribe: async function () {
            if (!this.isSupported()) {
                throw new Error('Push notifications are not supported by this browser.');
            }

            var permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                throw new Error('Notification permission was not granted.');
            }

            var basePath = getBasePath();
            var configRes = await fetch(basePath + 'api/push_config.php');
            var config = await configRes.json();
            if (!config.ok || !config.publicKey) {
                throw new Error('Failed to retrieve VAPID configuration from server.');
            }

            var reg = await this.getRegistration();
            var sub = await reg.pushManager.getSubscription();

            // If already subscribed, re-sync with server
            if (!sub) {
                var appServerKey = urlB64ToUint8Array(config.publicKey);
                sub = await reg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: appServerKey
                });
            }

            var rawSub = JSON.parse(JSON.stringify(sub));
            var syncRes = await fetch(basePath + 'api/push_subscribe.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ subscription: rawSub })
            });
            var syncData = await syncRes.json();

            if (!syncData.ok) {
                throw new Error(syncData.error || 'Failed to sync subscription on server.');
            }

            return sub;
        },

        unsubscribe: async function () {
            var sub = await this.getSubscription();
            if (!sub) return true;

            var rawSub = JSON.parse(JSON.stringify(sub));
            await sub.unsubscribe();

            var basePath = getBasePath();
            await fetch(basePath + 'api/push_unsubscribe.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ endpoint: rawSub.endpoint })
            }).catch(function (e) {
                console.warn('[Push] Unsubscribe sync error:', e);
            });

            return true;
        },

        initUI: function (btnId, statusId) {
            if (!this.isSupported()) {
                var btn = document.getElementById(btnId);
                if (btn) btn.style.display = 'none';
                var st = document.getElementById(statusId);
                if (st) st.textContent = 'Not supported in this browser';
                return;
            }

            var self = this;
            var updateStatus = async function () {
                var btn = document.getElementById(btnId);
                var st = document.getElementById(statusId);
                var sub = await self.getSubscription();

                if (sub && Notification.permission === 'granted') {
                    if (st) st.textContent = 'Active (Subscribed)';
                    if (btn) {
                        btn.textContent = 'Disable Notifications';
                        btn.dataset.subscribed = '1';
                        btn.classList.add('bg-slate-200', 'text-slate-700');
                        btn.classList.remove('bg-servora-600', 'text-white', 'brand-gradient');
                    }
                } else {
                    if (st) st.textContent = (Notification.permission === 'denied') ? 'Blocked in browser settings' : 'Not subscribed';
                    if (btn) {
                        btn.textContent = 'Enable Phone Notifications';
                        btn.dataset.subscribed = '0';
                        btn.classList.remove('bg-slate-200', 'text-slate-700');
                        btn.classList.add('brand-gradient', 'text-white');
                    }
                }
            };

            var btn = document.getElementById(btnId);
            if (btn) {
                btn.addEventListener('click', async function () {
                    btn.disabled = true;
                    btn.style.opacity = '0.6';
                    try {
                        if (btn.dataset.subscribed === '1') {
                            await self.unsubscribe();
                        } else {
                            await self.subscribe();
                        }
                    } catch (err) {
                        alert(err.message || 'Notification setup error');
                    } finally {
                        btn.disabled = false;
                        btn.style.opacity = '1';
                        updateStatus();
                    }
                });
            }

            updateStatus();
        }
    };

    // Auto-register service worker on page load
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            var basePath = getBasePath();
            navigator.serviceWorker.register(basePath + 'sw.js').then(function (reg) {
                // Service worker active
            }).catch(function (err) {
                console.warn('[SW] Registration warning:', err);
            });
        });
    }

    window.SubnextPush = SubnextPush;
})(window);

