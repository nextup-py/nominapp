/**
 * @fileoverview Regresión para el `ReferenceError: faceapi is not defined`
 * detectado en producción (humo manual, 2026-09-15): `camera.js` construía
 * `faceapi.TinyFaceDetectorOptions(...)` a nivel de módulo, lo cual asumía
 * que `face-api.min.js` (cargado vía `<script defer>`, global `faceapi`) ya
 * había corrido antes de que el módulo se evaluara — una carrera que depende
 * del orden de los `<script>` en el HTML, no garantizada. El fix hace la
 * construcción perezosa (primer uso real). Este test reproduce la condición
 * exacta que rompía en producción: importar el módulo ANTES de que
 * `faceapi` exista.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

describe('camera.js — no debe depender de que faceapi exista al momento de importar', () => {
    const originalFaceapi = globalThis.faceapi;

    beforeEach(() => {
        vi.resetModules();
        delete globalThis.faceapi;
    });

    afterEach(() => {
        globalThis.faceapi = originalFaceapi;
    });

    it('el import no explota aunque faceapi todavía no esté definido (simula face-api.min.js sin cargar aún)', async () => {
        await expect(import('./camera.js')).resolves.toBeDefined();
    });

    it('una vez definido faceapi, las funciones que sí lo usan funcionan con normalidad', async () => {
        const camera = await import('./camera.js');
        globalThis.faceapi = {
            TinyFaceDetectorOptions: class TinyFaceDetectorOptions {
                constructor(options) {
                    Object.assign(this, options);
                }
            },
            detectSingleFace: vi.fn().mockResolvedValue(null),
        };

        const videoEl = { readyState: 2, videoWidth: 640, videoHeight: 480 };
        const overlayEl = { width: 640, height: 480 };
        const ctx = { clearRect: vi.fn() };
        const onFrame = vi.fn();

        camera.startDrawLoop(videoEl, overlayEl, ctx, { isProcessing: () => false, onFrame });
        await new Promise((resolve) => setTimeout(resolve, 10));
        camera.stopDrawLoop();

        expect(globalThis.faceapi.detectSingleFace).toHaveBeenCalled();
        expect(onFrame).toHaveBeenCalled();
    });
});
