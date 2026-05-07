self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);
    // N'ajouter le header ngrok que pour les requêtes same-origin
    if (url.origin !== self.location.origin) {
        return; // laisser le navigateur gérer les requêtes cross-origin normalement
    }
    const headers = new Headers(event.request.headers);
    headers.set('ngrok-skip-browser-warning', '1');
    const modified = new Request(event.request, { headers });
    event.respondWith(fetch(modified));
});
