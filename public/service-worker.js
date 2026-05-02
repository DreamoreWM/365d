self.addEventListener('fetch', event => {
    const headers = new Headers(event.request.headers);
    headers.set('ngrok-skip-browser-warning', '1');
    const modified = new Request(event.request, { headers });
    event.respondWith(fetch(modified));
});
