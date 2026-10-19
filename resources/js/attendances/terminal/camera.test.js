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

/**
 * Stream falso con una pista de video; `state` permite "matar" la pista como lo haría una suspensión del equipo.
 * @param {'live'|'ended'} [state]
 */
function fakeStream(state = 'live') {
    const track = { readyState: state, stop: vi.fn() };
    return {
        active: state === 'live',
        track,
        getVideoTracks: () => [track],
        getTracks: () => [track],
    };
}

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

        camera.adoptStream(fakeStream());
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

describe('camera.js — recuperación de la cámara tras una suspensión', () => {
    let camera;
    let openCameraStream;

    /** @returns {{srcObject: any, readyState: number, videoWidth: number, videoHeight: number, onloadedmetadata: any}} */
    function fakeVideo() {
        const video = { srcObject: null, readyState: 2, videoWidth: 640, videoHeight: 480, onloadedmetadata: null };
        Object.defineProperty(video, 'srcObject', {
            get: () => video._src,
            set: (value) => { video._src = value; queueMicrotask(() => video.onloadedmetadata?.()); },
        });
        return video;
    }

    beforeEach(async () => {
        vi.resetModules();
        openCameraStream = vi.fn();
        vi.doMock('./camera-prefs.js', () => ({ openCameraStream }));
        camera = await import('./camera.js');
    });

    afterEach(() => {
        vi.doUnmock('./camera-prefs.js');
        vi.useRealTimers();
    });

    it('isStreamLive distingue un stream vivo de uno cortado o inexistente', () => {
        expect(camera.isStreamLive(fakeStream('live'))).toBe(true);
        expect(camera.isStreamLive(fakeStream('ended'))).toBe(false);
        expect(camera.isStreamLive(null)).toBe(false);
    });

    it('hasStream descarta un stream cortado y suelta sus pistas', () => {
        const dead = fakeStream('ended');
        camera.adoptStream(dead);

        expect(camera.hasStream()).toBe(false);
        expect(dead.track.stop).toHaveBeenCalled();
    });

    it('hasStream conserva un stream vivo', () => {
        camera.adoptStream(fakeStream('live'));
        expect(camera.hasStream()).toBe(true);
    });

    it('startCamera reabre la cámara si la que tenía se cortó (en vez de devolver ok con una imagen congelada)', async () => {
        const fresh = fakeStream('live');
        openCameraStream.mockResolvedValue(fresh);
        camera.adoptStream(fakeStream('ended'));
        const video = fakeVideo();
        const overlay = { width: 0, height: 0 };

        const result = await camera.startCamera(video, overlay);

        expect(result).toEqual({ ok: true });
        expect(openCameraStream).toHaveBeenCalledTimes(1);
        expect(video.srcObject).toBe(fresh);
        expect(overlay).toEqual({ width: 640, height: 480 });
    });

    it('startCamera no vuelve a pedir la cámara si el stream sigue vivo', async () => {
        camera.adoptStream(fakeStream('live'));

        await camera.startCamera(fakeVideo(), { width: 0, height: 0 });

        expect(openCameraStream).not.toHaveBeenCalled();
    });

    it('ensureLiveStream reabre la cámara cortada y devuelve true', async () => {
        const fresh = fakeStream('live');
        openCameraStream.mockResolvedValue(fresh);
        camera.adoptStream(fakeStream('ended'));

        expect(await camera.ensureLiveStream(fakeVideo(), { width: 0, height: 0 })).toBe(true);
        expect(camera.hasStream()).toBe(true);
    });

    it('ensureLiveStream espera entre reintentos si la cámara no se puede reabrir', async () => {
        vi.useFakeTimers();
        vi.spyOn(console, 'warn').mockImplementation(() => {});
        openCameraStream.mockRejectedValue(Object.assign(new Error('busy'), { name: 'NotReadableError' }));
        camera.adoptStream(fakeStream('ended'));

        expect(await camera.ensureLiveStream(fakeVideo())).toBe(false);
        expect(await camera.ensureLiveStream(fakeVideo())).toBe(false);
        expect(openCameraStream).toHaveBeenCalledTimes(1);

        vi.advanceTimersByTime(camera.CAMERA_RECOVERY_RETRY_MS + 1);
        await camera.ensureLiveStream(fakeVideo());
        expect(openCameraStream).toHaveBeenCalledTimes(2);
    });

    it('el loop de detección no analiza el cuadro congelado de una cámara cortada', async () => {
        vi.spyOn(console, 'warn').mockImplementation(() => {});
        openCameraStream.mockRejectedValue(new Error('sin cámara'));
        globalThis.faceapi = {
            TinyFaceDetectorOptions: class { constructor(o) { Object.assign(this, o); } },
            detectSingleFace: vi.fn().mockResolvedValue({ box: { width: 300, height: 300 } }),
        };
        camera.adoptStream(fakeStream('ended'));
        const onFrame = vi.fn();

        camera.startDrawLoop(
            { readyState: 2, videoWidth: 640, videoHeight: 480 },
            { width: 640, height: 480 },
            { clearRect: vi.fn() },
            { isProcessing: () => false, onFrame },
        );
        await new Promise((resolve) => setTimeout(resolve, 20));
        camera.stopDrawLoop();
        delete globalThis.faceapi;

        expect(onFrame).not.toHaveBeenCalled();
    });
});
