/**
 * @fileoverview Setup global de Vitest. `face-api.js` se carga vía <script>
 * en producción (variable global `faceapi`, nunca importada como módulo).
 * Varios módulos (`terminal/camera.js`, `terminal/idle-detection.js`)
 * instancian `new faceapi.TinyFaceDetectorOptions(...)` a nivel de módulo,
 * por lo que basta con importarlos para que `faceapi` deba existir — este
 * stub mínimo evita un `ReferenceError` en el entorno de test sin alterar
 * el comportamiento real (nunca se usan sus métodos de detección en estos
 * tests, solo el constructor de opciones).
 */
if (typeof globalThis.faceapi === 'undefined') {
    globalThis.faceapi = {
        TinyFaceDetectorOptions: class TinyFaceDetectorOptions {
            constructor(options) {
                Object.assign(this, options);
            }
        },
    };
}

/**
 * No hay `jsdom`/`happy-dom` instalado en el proyecto — Vitest corre en
 * entorno `node` puro por defecto, sin `requestAnimationFrame` global.
 * Algunos módulos de `terminal/` (ej. `screen-state.js`) lo usan para animar
 * transiciones; es seguro como no-op en test. Se agrega el stub mínimo
 * necesario para que esas llamadas no exploten con `ReferenceError`.
 *
 * Nota: a propósito NO se stubea `globalThis.document` acá — varios módulos
 * (ej. `attendances/device-link.js`) usan `typeof document !== 'undefined'`
 * como guard deliberado para que su código top-level no corra en el entorno
 * de test (Node, sin DOM real); definir un `document` global rompería esa
 * convención existente. Los tests que necesiten `document` deben stubearlo
 * localmente con `vi.stubGlobal('document', ...)` / `afterEach(() =>
 * vi.unstubAllGlobals())`.
 */
if (typeof globalThis.requestAnimationFrame === 'undefined') {
    globalThis.requestAnimationFrame = (callback) => setTimeout(callback, 0);
}
