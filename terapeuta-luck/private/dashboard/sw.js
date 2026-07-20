const ADMIN_PWA_CACHE = 'admin-pwa-v1';
const DEFAULT_ICON = '/agenda/src/media/logo/logo.png';

self.addEventListener('install', (event) => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('push', (event) => {
  let payload = {};
  if (event.data) {
    try {
      payload = event.data.json();
    } catch (_) {
      payload = { body: event.data.text() };
    }
  }
  const title = payload.title || 'Nueva notificación';
  const body = payload.body || 'Tienes novedades en tu agenda.';
  const options = {
    body,
    icon: payload.icon || DEFAULT_ICON,
    badge: payload.badge || DEFAULT_ICON,
    data: payload.data || {},
    tag: payload.tag || 'admin-reservas',
    renotify: true,
  };
  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const targetUrl = (event.notification.data && event.notification.data.url)
    ? event.notification.data.url
    : '/agenda/template/private/dashboard/admin/index.php';
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
      for (const client of clients) {
        if (client.url.includes('/template/private/dashboard') && 'focus' in client) {
          client.navigate(targetUrl);
          return client.focus();
        }
      }
      if (self.clients.openWindow) {
        return self.clients.openWindow(targetUrl);
      }
      return null;
    })
  );
});

self.addEventListener('pushsubscriptionchange', (event) => {
  // Inform clients so they can resuscribe with the fresh keys.
  event.waitUntil(
    self.clients.matchAll({ includeUncontrolled: true, type: 'window' })
      .then((clients) => {
        clients.forEach((client) => {
          client.postMessage({ type: 'PUSH_SUBSCRIPTION_CHANGE' });
        });
      })
  );
});
