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
