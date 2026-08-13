const CACHE = 'nk-croatia-v3';
const ASSETS = [
  '/',
  '/index.html',
  '/style.css',
  '/logo.png',
  '/favicon.ico',
  '/apple-touch-icon.png',
  '/hero-video.mp4'
];

self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(CACHE).then(c => c.addAll(ASSETS)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', e => {
  e.respondWith(
    fetch(e.request).then(res => {
      if (res && res.ok && e.request.method === 'GET') {
        var resClone = res.clone();
        caches.open(CACHE).then(c => c.put(e.request, resClone));
      }
      return res;
    }).catch(() => caches.match(e.request).then(cached => cached || caches.match('/index.html')))
  );
});
