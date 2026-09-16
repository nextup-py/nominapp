/**
 * @fileoverview Setup global de Vitest. `face-api.js` se carga vía <script>
 * en producción (variable global `faceapi`, nunca importada como módulo).
 * `terminal/camera.js`/`terminal/idle-detection.js` construyen
 * `faceapi.TinyFaceDetectorOptions(...)` de forma perezosa (primer uso real,
 * no a nivel de módulo — ver la nota en esos archivos sobre el
 * `ReferenceError: faceapi is not defined` que esto causaba en producción
 * cuando el orden de los `<script>` no garantizaba que face-api.min.js ya
 * hubiera corrido). Este stub sigue siendo necesario igual: varios tests
 * (`camera.test.js`, `identification-flow.test.js`, etc.) sí invocan esas
 * funciones y necesitan que `faceapi` exista para no explotar.
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
