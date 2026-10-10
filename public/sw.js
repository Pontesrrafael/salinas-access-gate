/*
 * Salinas Access Gate — service worker da portaria (só é registrado com HTTPS).
 * Mantém a tela /portaria e os arquivos estáticos disponíveis quando o servidor cai,
 * para o porteiro continuar registrando na fila offline (IndexedDB). Não guarda respostas de API.
 */
'use strict';
const VERSAO = 'sag-v1';
const ESTATICOS = 'sag-estaticos-' + VERSAO;
const PAGINAS = 'sag-paginas';
const BASE = self.registration.scope.replace(/\/$/, '');

self.addEventListener('install', (ev) => {
  ev.waitUntil(caches.open(ESTATICOS).then((c) => c.addAll([
    BASE + '/assets/css/app.css', BASE + '/assets/js/app.js',
    BASE + '/assets/js/vendor/jsqr-1.4.0.js', BASE + '/assets/js/vendor/qrcode-generator-1.4.4.js',
  ]).catch(() => null)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (ev) => {
  ev.waitUntil(caches.keys().then((ks) => Promise.all(
    ks.filter((k) => k.startsWith('sag-estaticos-') && k !== ESTATICOS).map((k) => caches.delete(k))
  )).then(() => self.clients.claim()));
});

self.addEventListener('fetch', (ev) => {
  const req = ev.request;
  if (req.method !== 'GET') { return; }
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) { return; }
  const caminho = url.pathname.slice(BASE.replace(/^https?:\/\/[^/]+/, '').length);

  // Tela da portaria: rede primeiro; sem rede, a última versão aberta neste posto
  if (req.mode === 'navigate' && caminho === '/portaria') {
    ev.respondWith(fetch(req).then((r) => {
      if (r.ok && !r.redirected) { const copia = r.clone(); caches.open(PAGINAS).then((c) => c.put(BASE + '/portaria', copia)); }
      return r;
    }).catch(() => caches.match(BASE + '/portaria').then((r) => r || Response.error())));
    return;
  }
  // Arquivos estáticos: cache com atualização em segundo plano
  if (caminho.startsWith('/assets/')) {
    ev.respondWith(caches.open(ESTATICOS).then((c) => c.match(req, { ignoreSearch: true }).then((emCache) => {
      const rede = fetch(req).then((r) => { if (r.ok) { c.put(req, r.clone()); } return r; }).catch(() => emCache);
      return emCache || rede;
    })));
  }
});
