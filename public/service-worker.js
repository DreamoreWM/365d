// Force l'activation immédiate sans attendre la fermeture des onglets
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', event => event.waitUntil(self.clients.claim()));

self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);
    // N'ajouter le header ngrok que pour les requêtes same-origin
    if (url.origin !== self.location.origin) {
        return;
    }
    const headers = new Headers(event.request.headers);
    headers.set('ngrok-skip-browser-warning', '1');
    const modified = new Request(event.request, { headers });
    event.respondWith(fetch(modified));
});
