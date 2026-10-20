/* =========================================================
   CANA CONTROL — Service Worker (Motorista PWA)
   Estratégias:
   - API (/api/...)        → network-only (a fila local cuida do offline)
   - Navegação (HTML)      → network-first com fallback para cache
   - Estáticos (CSS/JS/fontes/CDN) → stale-while-revalidate
   ========================================================= */

const CACHE_VERSION = 'v1.0.0';
const PRECACHE = `cana-pre-${CACHE_VERSION}`;
const RUNTIME  = `cana-run-${CACHE_VERSION}`;

const PRECACHE_URLS = [
  './motorista.html',
  './manifest.json',
  './icon.svg',
  // FontAwesome (CSS + webfonts usados)
  'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css',
  'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/webfonts/fa-solid-900.woff2',
  'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/webfonts/fa-regular-400.woff2',
  'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/webfonts/fa-brands-400.woff2'
];

/* ---------- INSTALL: pré-cacheia o essencial ---------- */
self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(PRECACHE);
    // addAll aborta tudo se 1 falhar; usamos Promise.allSettled p/ ser resiliente
    await Promise.allSettled(
      PRECACHE_URLS.map(url => cache.add(new Request(url, { cache: 'reload' })))
    );
    self.skipWaiting();
  })());
});

/* ---------- ACTIVATE: limpa caches antigos ---------- */
self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(
      keys
        .filter(k => k !== PRECACHE && k !== RUNTIME)
        .map(k => caches.delete(k))
    );
    await self.clients.claim();
  })());
});

/* ---------- MENSAGENS ---------- */
self.addEventListener('message', (event) => {
  if (event.data === 'SKIP_WAITING') self.skipWaiting();
  if (event.data === 'CLEAR_RUNTIME') caches.delete(RUNTIME);
});

/* ---------- FETCH ---------- */
self.addEventListener('fetch', (event) => {
  const req = event.request;
  const url = new URL(req.url);

  // Só GET
  if (req.method !== 'GET') return;

  // 1) API → deixa passar direto (nunca cacheia)
  if (url.pathname.includes('/api/')) return;

  // 2) Navegação → network-first com fallback para HTML cacheado
  if (req.mode === 'navigate') {
    event.respondWith((async () => {
      try {
        const fresh = await fetch(req);
        const cache = await caches.open(PRECACHE);
        // Guarda a versão fresca do HTML sob a URL pedida e sob o start_url
        cache.put(req, fresh.clone());
        cache.put('./motorista.html', fresh.clone());
        return fresh;
      } catch {
        const cache = await caches.open(PRECACHE);
        return (
          (await cache.match(req)) ||
          (await cache.match('./motorista.html')) ||
          new Response(
            '<!doctype html><meta charset="utf-8"><title>Offline</title>' +
            '<body style="font-family:sans-serif;background:#0e1a26;color:#e6f0f9;text-align:center;padding:60px 20px">' +
            '<h2>Você está offline</h2><p>Abra o app pela tela inicial ou aguarde a conexão.</p>',
            { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
          )
        );
      }
    })());
    return;
  }

  // 3) Estáticos (mesmo domínio ou CDNs conhecidos) → stale-while-revalidate
  const isStatic =
    url.origin === self.location.origin ||
    /(^|\.)cdnjs\.cloudflare\.com$/.test(url.hostname) ||
    /(^|\.)cdn\.jsdelivr\.net$/.test(url.hostname) ||
    /(^|\.)fonts\.googleapis\.com$/.test(url.hostname) ||
    /(^|\.)fonts\.gstatic\.com$/.test(url.hostname);

  if (isStatic) {
    event.respondWith((async () => {
      const cache = await caches.open(RUNTIME);
      const cached = await cache.match(req);

      const network = fetch(req).then((res) => {
        if (res && res.ok) cache.put(req, res.clone());
        return res;
      }).catch(() => null);

      // Se tem cache → devolve já e atualiza em background
      if (cached) {
        event.waitUntil(network);
        return cached;
      }

      // Sem cache → aguarda rede
      const fresh = await network;
      if (fresh) return fresh;

      return new Response('', { status: 504, statusText: 'Gateway Timeout (offline)' });
    })());
  }
});