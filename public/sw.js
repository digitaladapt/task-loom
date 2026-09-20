// TaskLoom service worker — installable PWA surface.
//
// v1 strategy: network-first for navigation (admin UI must be current),
// cache-first for hashed static assets only. No offline write queue in v1
// (offline depth-1 is a penn-track/vital-pulse pattern, not applicable to
// approving task drafts — that is a human gate that must never replay stale).

const CACHE = 'taskloom-v1';
const PRECACHE = [
    '/manifest.webmanifest',
    '/icon.svg',
];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((c) => c.addAll(PRECACHE)));
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    // Hashed build assets only (cache-first).
    if (url.origin === self.location.origin && url.pathname.startsWith('/assets/')) {
        event.respondWith(
            caches.match(event.request).then((hit) => hit || fetch(event.request)),
        );
        return;
    }

    // Everything else: network, no cache fallback (no stale approvals).
});