/**
 * Agendarte UY - Service Worker
 * Cache básico para la app web progresiva.
 */
const CACHE = 'agendarte-v13';
const ASSET_RE = /\.(?:css|js|mjs|png|jpe?g|gif|webp|svg|ico|woff2?|ttf|otf)(?:\?.*)?$/i;

self.addEventListener('install', (event) => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => {
      return Promise.all(
        keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))
      );
    })
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  const accept = request.headers.get('accept') || '';
  const isNavigation = request.mode === 'navigate' || accept.includes('text/html');
  const isApi = url.pathname.includes('/src/API/')
    || url.pathname.includes('/admin/api/')
    || url.pathname.includes('/template/src/API/')
    || url.pathname.endsWith('.php');
  if (isNavigation || isApi) {
    event.respondWith(fetch(request).catch(() => new Response('Offline', { status: 503 })));
    return;
  }

  if (!ASSET_RE.test(url.pathname + url.search)) {
    event.respondWith(fetch(request).catch(() => caches.match(request).then((cached) => cached || new Response('Offline', { status: 503 }))));
    return;
  }

  // Network-first for static assets to ensure instant updates on production deployments
  event.respondWith(
    fetch(request).then((response) => {
      if (response && response.ok && response.type === 'basic') {
        const clone = response.clone();
        caches.open(CACHE).then((cache) => cache.put(request, clone));
      }
      return response;
    }).catch(() => caches.match(request).then((cached) => cached || new Response('Offline', { status: 503 })))
  );
});
