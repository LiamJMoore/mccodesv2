/* MCCodes engine v3: makes the game installable on a phone or desktop, and shows a friendly page when offline.
   It never stores game pages: what a player sees always comes fresh from the server. */
const SHELL = 'mcc-shell-1';
self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(SHELL).then((cache) => cache.addAll(['offline.html', 'images/app-icon.svg'])).then(() => self.skipWaiting()));
});
self.addEventListener('activate', (event) => {
    event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== SHELL).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', (event) => {
    if (event.request.mode !== 'navigate') { return; }                 // only whole-page loads; everything else is left alone
    event.respondWith(fetch(event.request).catch(() => caches.match('offline.html')));
});
