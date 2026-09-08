/**
 * =============================================================================
 * SERVICE WORKER — MARCACIÓN DE ASISTENCIA (offline shell)
 * =============================================================================
 *
 * Hand-rolled, sin Workbox/vite-plugin-pwa. Un mismo script compartido entre
 * dos flujos con scope de registro distinto:
 * - Terminal de sucursal: /terminal/* (registrado en terminal.blade.php).
 * - Dispositivo personal del empleado: /marcar (registrado en mark.blade.php,
 *   Parte C Fase 2 — la vinculación en /vincular-dispositivo NO se cachea, es
 *   una acción intrínsecamente online).
 *
 * Responsabilidad: que el shell de cada página, sus assets de Vite y los
 * modelos de face-api.js queden disponibles offline. El matching client-side
 * y la cola de eventos viven en JS (terminal-offline/ y mobile-offline/), no acá.
 */

const CACHE_VERSION = 'nominapp-attendance-v2';

/**
 * Contenido estático que nunca cambia sin un deploy — cache-first.
 * `/build/assets/` se cachea completo (no solo `terminal-*`/`mark-*`): Vite
 * separa en chunks compartidos los módulos importados por varios entrypoints
 * (ej. face-capture-core.js, usado tanto por terminal.js como por mark.js),
 * así que filtrar por nombre del entrypoint deja afuera esos chunks y rompe
 * el offline.
 */
const CACHE_FIRST_PATTERNS = [
    /^\/models\//, // modelos de face-api.js (tinyFaceDetector, faceLandmark68, faceRecognition)
    /^\/js\/face-api\.min\.js$/,
    /^\/build\/assets\//, // todos los bundles JS/CSS de Vite (nombres con hash de contenido — seguros de cachear indefinidamente)
    /^\/icons\//, // favicon + set de íconos PWA (Task 6) — deben estar disponibles offline en el launcher
    /^\/images\//, // ej. default-avatar.png, usado como fallback de foto en terminal.js
];

/** Shell HTML del terminal y del dispositivo — stale-while-revalidate para que un reload offline funcione. */
const SHELL_PATTERNS = [
    /^\/terminal\/?$/,
    /^\/terminal\/[a-z0-9]+\/?$/,
    /^\/marcar\/?$/,
];

self.addEventListener('install', () => {
    // Terminal de un solo propósito: aplicar la versión nueva del SW sin esperar
    // a que se cierren todas las pestañas abiertas.
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys.filter((key) => key !== CACHE_VERSION).map((key) => caches.delete(key))
            ))
            .then(() => self.clients.claim())
    );
});

function matchesAny(pathname, patterns) {
    return patterns.some((pattern) => pattern.test(pathname));
}

async function cacheFirst(request) {
    const cache = await caches.open(CACHE_VERSION);
    const cached = await cache.match(request);
    if (cached) return cached;

    const response = await fetch(request);
    if (response.ok) cache.put(request, response.clone());
    return response;
}

async function staleWhileRevalidate(request) {
    const cache = await caches.open(CACHE_VERSION);
    const cached = await cache.match(request);

    const networkUpdate = fetch(request)
        .then((response) => {
            if (response.ok) cache.put(request, response.clone());
            return response;
        })
        .catch(() => null);

    if (cached) {
        // Responder al instante con la copia cacheada; la red se actualiza en segundo plano.
        networkUpdate.catch(() => {});
        return cached;
    }

    const fresh = await networkUpdate;
    return fresh || Response.error();
}

self.addEventListener('fetch', (event) => {
    const { request } = event;

    // Solo GET — POST/PUT/DELETE (ej. /marcar, /api/*) pasan directo a la red sin intervención.
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    const { pathname } = url;

    if (matchesAny(pathname, CACHE_FIRST_PATTERNS)) {
        event.respondWith(cacheFirst(request));
        return;
    }

    if (matchesAny(pathname, SHELL_PATTERNS)) {
        event.respondWith(staleWhileRevalidate(request));
        return;
    }

    // Todo lo demás no se intercepta — comportamiento normal del navegador.
});
