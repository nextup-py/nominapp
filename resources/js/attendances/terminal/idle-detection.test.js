import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { resetIdleTimer, clearIdleTimer, acquireWakeLock } from './idle-detection.js';

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

describe('acquireWakeLock', () => {
    it('no hace nada si navigator.wakeLock no existe', async () => {
        const fakeNav = {};
        await expect(acquireWakeLock(fakeNav)).resolves.toBeUndefined();
    });

    it('solicita el wake lock si la API está disponible', async () => {
        const mockLock = { addEventListener: vi.fn() };
        const request = vi.fn().mockResolvedValue(mockLock);
        const fakeNav = { wakeLock: { request } };

        await acquireWakeLock(fakeNav);

        expect(request).toHaveBeenCalledWith('screen');
        expect(mockLock.addEventListener).toHaveBeenCalledWith('release', expect.any(Function));
    });

    it('no revienta si wakeLock.request() rechaza', async () => {
        const request = vi.fn().mockRejectedValue(new Error('denied'));
        const fakeNav = { wakeLock: { request } };

        await expect(acquireWakeLock(fakeNav)).resolves.toBeUndefined();
    });
});
