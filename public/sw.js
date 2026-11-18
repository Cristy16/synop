// Synop service worker (ARCHITECTURE §8, D-052).
// Static assets are cached; pages and data always come from the network. When a
// page can't load (offline), the offline page is shown instead. No offline editing.

const VERSION = 'synop-v2';
const OFFLINE_URL = '/offline.html';
const PRECACHE = [OFFLINE_URL, '/icons/icon-192.png', '/favicon.svg'];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(VERSION).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== VERSION).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    // Pages: always the network; the offline page if that fails.
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match(OFFLINE_URL)));
        return;
    }

    // Built assets have the content hash in their name, so a cached copy is never stale.
    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/') || url.pathname.startsWith('/fonts/')) {
        event.respondWith(
            caches.match(request).then((cached) => cached || fetch(request).then((response) => {
                if (response.ok) {
                    const copy = response.clone();
                    caches.open(VERSION).then((cache) => cache.put(request, copy));
                }

                return response;
            })),
        );
    }

    // Everything else (Livewire requests, data): straight to the network, never cached.
});

// Morning summary push (D-053).
self.addEventListener('push', (event) => {
    let data = {};
    try { data = event.data ? event.data.json() : {}; } catch (e) { data = { title: 'Synop', body: event.data?.text() }; }

    event.waitUntil(self.registration.showNotification(data.title || 'Synop', {
        body: data.body || '',
        icon: data.icon || '/icons/icon-192.png',
        badge: data.badge || '/icons/icon-192.png',
        tag: data.tag,
        data: data.data || { url: '/today' },
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    // Only open pages of this app.
    const path = String(event.notification.data?.url || '/today');
    const url = new URL(path.startsWith('/') ? path : '/today', self.location.origin).href;

    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
        const open = windows.find((w) => w.url.startsWith(self.location.origin));
        return open ? open.navigate(url).then((w) => (w || open).focus()) : self.clients.openWindow(url);
    }));
});
