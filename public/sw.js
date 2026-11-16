self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', (event) => {
    let payload = {};

    try {
        payload = event.data ? event.data.json() : {};
    } catch (error) {
        payload = {};
    }

    const title = payload.title || 'Yak';

    event.waitUntil(
        self.registration.showNotification(title, {
            body: payload.body || '',
            tag: payload.tag || undefined,
            data: { url: payload.url || '/' },
            icon: '/icon-192.png',
            badge: '/icon-192.png',
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = (event.notification.data && event.notification.data.url) || '/';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) => {
            const existing = windowClients[0];

            if (existing) {
                return existing.focus().then((focused) => (focused || existing).navigate(url));
            }

            return self.clients.openWindow(url);
        }),
    );
});
