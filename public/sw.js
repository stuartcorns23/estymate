/*
 * Estymate service worker.
 *
 * Kept deliberately small. It makes the app installable and quick to open,
 * without ever storing anyone's invoices or client details on the device:
 *  - Built assets (/build/*, hashed by Vite) and icons are cached, so the app
 *    shell loads fast on a phone.
 *  - Pages always come from the network. If there is no connection, a branded
 *    offline page is shown instead of the browser's error.
 *  - Everything else (Inertia requests, POSTs, PDFs, Stripe) is left alone.
 */
const VERSION = 'v1';
const STATIC_CACHE = `estymate-static-${VERSION}`;
const OFFLINE_URL = '/offline.html';

const PRECACHE = [OFFLINE_URL, '/favicon.svg', '/icons/icon-192.png', '/icons/icon-512.png'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(STATIC_CACHE)
            .then((cache) => cache.addAll(PRECACHE))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(keys.filter((key) => key.startsWith('estymate-') && key !== STATIC_CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

function isStaticAsset(url) {
    return url.pathname.startsWith('/build/assets/') || url.pathname.startsWith('/icons/') || url.pathname === '/favicon.svg';
}

async function cacheFirst(request) {
    const cache = await caches.open(STATIC_CACHE);
    const cached = await cache.match(request);
    if (cached) {
        return cached;
    }

    const response = await fetch(request);
    if (response.ok) {
        cache.put(request, response.clone());
    }

    return response;
}

async function networkWithOfflineFallback(request) {
    try {
        return await fetch(request);
    } catch (error) {
        const offline = await caches.match(OFFLINE_URL);

        return offline ?? Response.error();
    }
}

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(networkWithOfflineFallback(request));

        return;
    }

    if (isStaticAsset(url)) {
        event.respondWith(cacheFirst(request));
    }
});
