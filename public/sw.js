/* Browser Web Push for Scalenow Bd (seller app).
   Shows the pushes sent by PushService and brings the seller to the
   linked page on click. Kept deliberately tiny. */
self.addEventListener('push', (event) => {
    let data = {};
    try { data = event.data ? event.data.json() : {}; } catch (e) { /* plain text push */ }
    const title = data.title || 'Scalenow Bd';
    const options = {
        body: data.body || '',
        icon: data.icon || '/favicon.ico',
        data: { url: data.url || '/' },
    };
    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = (event.notification.data && event.notification.data.url) || '/';
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
            for (const client of list) {
                if ('focus' in client) {
                    client.navigate(url);
                    return client.focus();
                }
            }
            return clients.openWindow(url);
        })
    );
});
