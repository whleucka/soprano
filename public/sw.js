// Soprano service worker.
// Scope: site-wide. Caches static assets only — never HTML, HTMX partials, or audio streams.
// Bump CACHE_VERSION to invalidate old caches after a deploy.

const CACHE_VERSION = '2026-06-09';
const STATIC_CACHE  = `soprano-static-${CACHE_VERSION}`;

const STATIC_PATH = /^\/(css|js|fonts|icons|images|covers)\//;

self.addEventListener('install', () => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys.filter((k) => k !== STATIC_CACHE).map((k) => caches.delete(k))
      )
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  if (!STATIC_PATH.test(url.pathname)) return;

  event.respondWith(staleWhileRevalidate(req));
});

async function staleWhileRevalidate(request) {
  const cache  = await caches.open(STATIC_CACHE);
  const cached = await cache.match(request);
  const network = fetch(request).then((res) => {
    if (res && res.ok && res.type === 'basic') {
      cache.put(request, res.clone());
    }
    return res;
  }).catch(() => cached);
  return cached || network;
}
