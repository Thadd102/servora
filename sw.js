/**
 * Subnext Service Worker
 * Handles PWA offline caching, background web push notifications,
 * and seamless deep-link notification opening.
 */

const CACHE_NAME = 'subnext-v1.0.1';
const PRECACHE_ASSETS = [
    './',
    './assets/css/style.css',
    './assets/css/landing.css',
    './assets/images/favicon.svg',
    './assets/images/favicon-32x32.png',
    './assets/images/favicon-192x192.png',
    './assets/images/favicon-512x512.png',
    './manifest.json'
];

// Install Event: Precaches core static assets
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(PRECACHE_ASSETS).catch((err) => {
                console.warn('[SW] Precache non-critical warning:', err);
            });
        }).then(() => self.skipWaiting())
    );
});

// Activate Event: Cleans outdated caches
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((name) => {
                    if (name !== CACHE_NAME) {
                        return caches.delete(name);
                    }
                })
            );
        }).then(() => self.clients.claim())
    );
});

// Fetch Event: Network first with cache fallback for dynamic pages
self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    // Only intercept same-origin HTTP(S) requests
    if (request.method !== 'GET' || !url.protocol.startsWith('http')) {
        return;
    }

    // Never cache API endpoints, POST requests, or admin pages
    if (url.pathname.includes('/api/') || url.pathname.includes('/admin/')) {
        return;
    }

    event.respondWith(
        fetch(request)
            .then((networkResponse) => {
                if (networkResponse && networkResponse.status === 200 && networkResponse.type === 'basic') {
                    const responseClone = networkResponse.clone();
                    caches.open(CACHE_NAME).then((cache) => {
                        cache.put(request, responseClone);
                    });
                }
                return networkResponse;
            })
            .catch(() => {
                return caches.match(request).then((cachedResponse) => {
                    if (cachedResponse) {
                        return cachedResponse;
                    }
                    if (request.headers.get('accept') && request.headers.get('accept').includes('text/html')) {
                        return caches.match('./index.php');
                    }
                });
            })
    );
});

// =========================================================================
// REAL-TIME WEB PUSH NOTIFICATION HANDLERS
// =========================================================================

self.addEventListener('push', (event) => {
    let payload = {
        title: 'Subnext Notification',
        body: 'You have a new update from Subnext.',
        icon: './assets/images/favicon-192x192.png',
        badge: './assets/images/favicon-32x32.png',
        url: './client/dashboard.php',
        data: { url: './client/dashboard.php' }
    };

    if (event.data) {
        try {
            const json = event.data.json();
            payload.title = json.title || payload.title;
            payload.body = json.body || payload.body;
            payload.icon = json.icon || payload.icon;
            payload.badge = json.badge || payload.badge;
            payload.url = json.url || json.data?.url || payload.url;
            payload.data = json.data || { url: payload.url };
        } catch (e) {
            payload.body = event.data.text() || payload.body;
        }
    }

    const options = {
        body: payload.body,
        icon: payload.icon,
        badge: payload.badge,
        tag: 'subnext-notification-' + Date.now(),
        renotify: true,
        requireInteraction: false,
        vibrate: [200, 100, 200],
        data: payload.data,
        actions: [
            { action: 'open', title: 'Open Subnext' },
            { action: 'close', title: 'Dismiss' }
        ]
    };

    event.waitUntil(
        self.registration.showNotification(payload.title, options)
    );
});

// Notification Click: Deep link directly into the client page
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    if (event.action === 'close') {
        return;
    }

    const targetUrl = (event.notification.data && event.notification.data.url) 
        ? event.notification.data.url 
        : './client/dashboard.php';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
            // If already open, focus and navigate
            for (let i = 0; i < clientList.length; i++) {
                const client = clientList[i];
                if ('focus' in client) {
                    client.navigate(targetUrl);
                    return client.focus();
                }
            }
            // Otherwise open a new window
            if (clients.openWindow) {
                return clients.openWindow(targetUrl);
            }
        })
    );
});

