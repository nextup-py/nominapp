import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { resetIdleTimer, clearIdleTimer } from './idle-detection.js';

describe('idle-detection.js — no debe depender de que faceapi exista al momento de importar', () => {
    // Regresión del `ReferenceError: faceapi is not defined` en producción
    // (humo manual, 2026-09-15) — ver la misma nota en camera.test.js.
    const originalFaceapi = globalThis.faceapi;

    beforeEach(() => {
        vi.resetModules();
        delete globalThis.faceapi;
    });

    afterEach(() => {
        globalThis.faceapi = originalFaceapi;
    });

    it('el import no explota aunque faceapi todavía no esté definido', async () => {
        await expect(import('./idle-detection.js')).resolves.toBeDefined();
    });
});

describe('resetIdleTimer / clearIdleTimer', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('llama a onIdle tras el timeout configurado', () => {
        const onIdle = vi.fn();
        resetIdleTimer(onIdle, 1000);

        vi.advanceTimersByTime(999);
        expect(onIdle).not.toHaveBeenCalled();

        vi.advanceTimersByTime(1);
        expect(onIdle).toHaveBeenCalledTimes(1);
    });

    it('reinicia el timer al llamar resetIdleTimer de nuevo antes de que expire', () => {
        const onIdle = vi.fn();
        resetIdleTimer(onIdle, 1000);
        vi.advanceTimersByTime(600);
        resetIdleTimer(onIdle, 1000);
        vi.advanceTimersByTime(600);
        expect(onIdle).not.toHaveBeenCalled();
        vi.advanceTimersByTime(400);
        expect(onIdle).toHaveBeenCalledTimes(1);
    });

    it('clearIdleTimer cancela el timeout pendiente', () => {
        const onIdle = vi.fn();
        resetIdleTimer(onIdle, 1000);
        clearIdleTimer();
        vi.advanceTimersByTime(2000);
        expect(onIdle).not.toHaveBeenCalled();
    });

    it('usa 5 minutos por defecto si no se pasa timeoutMs', () => {
        const onIdle = vi.fn();
        resetIdleTimer(onIdle);
        vi.advanceTimersByTime(5 * 60 * 1000 - 1);
        expect(onIdle).not.toHaveBeenCalled();
        vi.advanceTimersByTime(1);
        expect(onIdle).toHaveBeenCalledTimes(1);
    });
});

/** Módulo limpio: el bloqueo y el vigía guardan estado a nivel de módulo. */
async function freshModule() {
    vi.resetModules();
    return import('./idle-detection.js');
}

/** Navegador falso con `wakeLock.request` y la tapa para soltar el bloqueo desde el test. */
function fakeWakeLockNav(requestImpl) {
    const locks = [];
    const request = requestImpl ?? vi.fn(async () => {
        const handlers = {};
        const lock = { addEventListener: (event, handler) => { handlers[event] = handler; }, release: () => handlers.release?.() };
        locks.push(lock);
        return lock;
    });
    return { nav: { wakeLock: { request } }, request, locks };
}

/** Documento falso con visibilidad y listeners. */
function fakeDoc(visibilityState = 'visible') {
    const listeners = {};
    return {
        visibilityState,
        addEventListener: vi.fn((event, handler) => { (listeners[event] ??= []).push(handler); }),
        removeEventListener: vi.fn((event, handler) => { listeners[event] = (listeners[event] ?? []).filter((h) => h !== handler); }),
        emit: (event) => (listeners[event] ?? []).forEach((handler) => handler()),
        listenerCount: (event) => (listeners[event] ?? []).length,
    };
}

describe('acquireWakeLock', () => {
    it('devuelve false y marca el motivo si navigator.wakeLock no existe', async () => {
        const mod = await freshModule();

        await expect(mod.acquireWakeLock({})).resolves.toBe(false);
        expect(mod.getWakeLockError()).toBe('unsupported');
        expect(mod.hasWakeLock()).toBe(false);
    });

    it('solicita el wake lock si la API está disponible', async () => {
        const mod = await freshModule();
        const { nav, request } = fakeWakeLockNav();

        await expect(mod.acquireWakeLock(nav)).resolves.toBe(true);

        expect(request).toHaveBeenCalledWith('screen');
        expect(mod.hasWakeLock()).toBe(true);
        expect(mod.getWakeLockError()).toBeNull();
    });

    it('no revienta si wakeLock.request() rechaza y guarda el nombre del error', async () => {
        const mod = await freshModule();
        const error = Object.assign(new Error('denied'), { name: 'NotAllowedError' });
        const { nav } = fakeWakeLockNav(vi.fn().mockRejectedValue(error));

        await expect(mod.acquireWakeLock(nav)).resolves.toBe(false);

        expect(mod.hasWakeLock()).toBe(false);
        expect(mod.getWakeLockError()).toBe('NotAllowedError');
    });

    it('es idempotente: con el bloqueo activo no vuelve a pedirlo', async () => {
        const mod = await freshModule();
        const { nav, request } = fakeWakeLockNav();

        await mod.acquireWakeLock(nav);
        await mod.acquireWakeLock(nav);

        expect(request).toHaveBeenCalledTimes(1);
    });

    it('dos pedidos simultáneos comparten la misma solicitud', async () => {
        const mod = await freshModule();
        const { nav, request } = fakeWakeLockNav();

        await Promise.all([mod.acquireWakeLock(nav), mod.acquireWakeLock(nav)]);

        expect(request).toHaveBeenCalledTimes(1);
    });

    it('al soltarse el bloqueo (release) deja de figurar como activo y se puede volver a pedir', async () => {
        const mod = await freshModule();
        const { nav, request, locks } = fakeWakeLockNav();

        await mod.acquireWakeLock(nav);
        locks[0].release();
        expect(mod.hasWakeLock()).toBe(false);

        await mod.acquireWakeLock(nav);
        expect(request).toHaveBeenCalledTimes(2);
        expect(mod.hasWakeLock()).toBe(true);
    });

    it('un éxito posterior limpia el motivo del fallo anterior', async () => {
        const mod = await freshModule();
        const request = vi.fn()
            .mockRejectedValueOnce(Object.assign(new Error('x'), { name: 'NotAllowedError' }))
            .mockResolvedValue({ addEventListener: vi.fn() });
        const nav = { wakeLock: { request } };

        await mod.acquireWakeLock(nav);
        expect(mod.getWakeLockError()).toBe('NotAllowedError');

        await mod.acquireWakeLock(nav);
        expect(mod.getWakeLockError()).toBeNull();
    });
});

describe('startWakeLockKeeper', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('pide el bloqueo apenas arranca, sin esperar ningún toque', async () => {
        const mod = await freshModule();
        const { nav, request } = fakeWakeLockNav();

        mod.startWakeLockKeeper({ nav, doc: fakeDoc() });
        await vi.advanceTimersByTimeAsync(0);

        expect(request).toHaveBeenCalledTimes(1);
        expect(mod.hasWakeLock()).toBe(true);
    });

    it('reintenta cada minuto mientras el bloqueo falla y para al conseguirlo', async () => {
        const mod = await freshModule();
        const request = vi.fn()
            .mockRejectedValueOnce(Object.assign(new Error('x'), { name: 'NotAllowedError' }))
            .mockRejectedValueOnce(Object.assign(new Error('x'), { name: 'NotAllowedError' }))
            .mockResolvedValue({ addEventListener: vi.fn() });

        mod.startWakeLockKeeper({ nav: { wakeLock: { request } }, doc: fakeDoc() });
        await vi.advanceTimersByTimeAsync(0);
        expect(request).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(mod.WAKE_LOCK_RETRY_MS);
        expect(request).toHaveBeenCalledTimes(2);

        await vi.advanceTimersByTimeAsync(mod.WAKE_LOCK_RETRY_MS);
        expect(request).toHaveBeenCalledTimes(3);
        expect(mod.hasWakeLock()).toBe(true);

        await vi.advanceTimersByTimeAsync(mod.WAKE_LOCK_RETRY_MS * 3);
        expect(request).toHaveBeenCalledTimes(3);
    });

    it('recupera el bloqueo perdido (ej. tras suspenderse el equipo) en el siguiente intento', async () => {
        const mod = await freshModule();
        const { nav, request, locks } = fakeWakeLockNav();

        mod.startWakeLockKeeper({ nav, doc: fakeDoc() });
        await vi.advanceTimersByTimeAsync(0);
        locks[0].release();

        await vi.advanceTimersByTimeAsync(mod.WAKE_LOCK_RETRY_MS);

        expect(request).toHaveBeenCalledTimes(2);
        expect(mod.hasWakeLock()).toBe(true);
    });

    it('no pide el bloqueo con la pestaña oculta, pero sí al volver a estar visible', async () => {
        const mod = await freshModule();
        const { nav, request } = fakeWakeLockNav();
        const doc = fakeDoc('hidden');

        mod.startWakeLockKeeper({ nav, doc });
        await vi.advanceTimersByTimeAsync(mod.WAKE_LOCK_RETRY_MS);
        expect(request).not.toHaveBeenCalled();

        doc.visibilityState = 'visible';
        doc.emit('visibilitychange');
        await vi.advanceTimersByTimeAsync(0);
        expect(request).toHaveBeenCalledTimes(1);
    });

    it('reintenta en la primera interacción (pointerdown / keydown)', async () => {
        const mod = await freshModule();
        const request = vi.fn()
            .mockRejectedValueOnce(Object.assign(new Error('x'), { name: 'NotAllowedError' }))
            .mockResolvedValue({ addEventListener: vi.fn() });
        const doc = fakeDoc();

        mod.startWakeLockKeeper({ nav: { wakeLock: { request } }, doc });
        await vi.advanceTimersByTimeAsync(0);
        expect(mod.hasWakeLock()).toBe(false);

        doc.emit('pointerdown');
        await vi.advanceTimersByTimeAsync(0);

        expect(mod.hasWakeLock()).toBe(true);
    });

    it('es idempotente: una segunda llamada no duplica timers ni listeners', async () => {
        const mod = await freshModule();
        const { nav } = fakeWakeLockNav();
        const doc = fakeDoc();

        const stop = mod.startWakeLockKeeper({ nav, doc });
        expect(mod.startWakeLockKeeper({ nav, doc })).toBe(stop);

        expect(doc.listenerCount('visibilitychange')).toBe(1);
    });

    it('al detenerlo quita los listeners y deja de reintentar', async () => {
        const mod = await freshModule();
        const request = vi.fn().mockRejectedValue(Object.assign(new Error('x'), { name: 'NotAllowedError' }));
        const doc = fakeDoc();

        const stop = mod.startWakeLockKeeper({ nav: { wakeLock: { request } }, doc });
        await vi.advanceTimersByTimeAsync(0);
        stop();
        const calls = request.mock.calls.length;
        await vi.advanceTimersByTimeAsync(mod.WAKE_LOCK_RETRY_MS * 3);

        expect(request.mock.calls.length).toBe(calls);
        expect(doc.listenerCount('visibilitychange')).toBe(0);
        expect(doc.listenerCount('pointerdown')).toBe(0);
        expect(doc.listenerCount('keydown')).toBe(0);
    });
});
