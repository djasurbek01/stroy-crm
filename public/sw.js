// Сервис-воркер: приложение открывается и без связи, а данные всегда берутся только с сервера.
// Кэшируется лишь оболочка (страница, скрипты, стили, значки). Ответы API не кэшируются никогда:
// так нельзя увидеть чужие или устаревшие данные.
const CACHE = 'stroy-shell-v2';

// При установке сразу кладём в кэш всю оболочку: иначе приложение не откроется без связи,
// если связь пропала до того, как все файлы успели загрузиться через воркер.
self.addEventListener('install', (event) => {
  self.skipWaiting();
  event.waitUntil((async () => {
    try {
      const list = await (await fetch('/shell-files', { cache: 'no-store' })).json();
      await (await caches.open(CACHE)).addAll(list);
    } catch (error) {
      /* не вышло сейчас: оболочка доберётся при обычной работе */
    }
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    for (const key of await caches.keys()) if (key !== CACHE) await caches.delete(key);
    await self.clients.claim();
  })());
});

// Скрипты и стили собирает Vite в /build: имена файлов меняются с каждой сборкой.
const isShell = (url) =>
  url.pathname === '/' ||
  url.pathname === '/manifest.webmanifest' ||
  url.pathname.startsWith('/build/') ||
  url.pathname.startsWith('/icons/');
const isPublicPage = () => false;

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin || !isShell(url) || isPublicPage(url)) return;
  event.respondWith((async () => {
    try {
      const fresh = await fetch(request);
      if (fresh.ok) (await caches.open(CACHE)).put(request, fresh.clone());
      return fresh;
    } catch (error) {
      const cached = await caches.match(request);
      if (cached) return cached;
      if (request.mode === 'navigate') {
        const page = await caches.match('/');
        if (page) return page;
      }
      throw error;
    }
  })());
});
