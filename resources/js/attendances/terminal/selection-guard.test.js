import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import {
    DEFAULT_SELECTION_TIMEOUT_SECONDS,
    shouldConfirmEvent,
    normalizeSelectionTimeout,
    dismissEmployee,
    isEmployeeDismissed,
    clearDismissed,
    createSelectionTimer,
} from './selection-guard.js';

describe('shouldConfirmEvent', () => {
    it('la entrada se registra sola; salida y descansos piden confirmación', () => {
        expect(shouldConfirmEvent('check_in', true)).toBe(false);
        expect(shouldConfirmEvent('check_out', true)).toBe(true);
        expect(shouldConfirmEvent('break_start', true)).toBe(true);
        expect(shouldConfirmEvent('break_end', true)).toBe(true);
    });

    it('sin dato del servidor se asume activada', () => {
        expect(shouldConfirmEvent('check_out', undefined)).toBe(true);
        expect(shouldConfirmEvent('check_out', null)).toBe(true);
    });

    it('desactivada en Configuración General: nada pide confirmación', () => {
        expect(shouldConfirmEvent('check_out', false)).toBe(false);
        expect(shouldConfirmEvent('break_start', false)).toBe(false);
    });
});

describe('normalizeSelectionTimeout', () => {
    it('sin dato o inválido usa el valor por defecto', () => {
        expect(normalizeSelectionTimeout(undefined)).toBe(DEFAULT_SELECTION_TIMEOUT_SECONDS);
        expect(normalizeSelectionTimeout(null)).toBe(DEFAULT_SELECTION_TIMEOUT_SECONDS);
        expect(normalizeSelectionTimeout('')).toBe(DEFAULT_SELECTION_TIMEOUT_SECONDS);
        expect(normalizeSelectionTimeout('abc')).toBe(DEFAULT_SELECTION_TIMEOUT_SECONDS);
        expect(normalizeSelectionTimeout(-3)).toBe(DEFAULT_SELECTION_TIMEOUT_SECONDS);
    });

    it('acepta el valor configurado, incluido 0 (sin tiempo límite)', () => {
        expect(normalizeSelectionTimeout(20)).toBe(20);
        expect(normalizeSelectionTimeout('45')).toBe(45);
        expect(normalizeSelectionTimeout(0)).toBe(0);
    });
});

describe('empleados descartados', () => {
    beforeEach(() => clearDismissed());

    it('queda ignorado hasta que pasa el tiempo', () => {
        dismissEmployee(7, 30_000, 1_000);

        expect(isEmployeeDismissed(7, 1_001)).toBe(true);
        expect(isEmployeeDismissed(7, 30_999)).toBe(true);
        expect(isEmployeeDismissed(7, 31_000)).toBe(false);
    });

    it('otro empleado no se ve afectado', () => {
        dismissEmployee(7, 30_000, 1_000);

        expect(isEmployeeDismissed(8, 1_001)).toBe(false);
    });

    it('clearDismissed los olvida a todos', () => {
        dismissEmployee(7, 30_000, 1_000);
        clearDismissed();

        expect(isEmployeeDismissed(7, 1_001)).toBe(false);
    });
});

describe('createSelectionTimer', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('cuenta regresiva visible y vence una sola vez', () => {
        const onTick = vi.fn();
        const onExpire = vi.fn();
        const timer = createSelectionTimer({ seconds: 3, onTick, onExpire });

        timer.start();
        expect(onTick).toHaveBeenLastCalledWith(3);
        expect(timer.isRunning()).toBe(true);

        vi.advanceTimersByTime(1000);
        expect(onTick).toHaveBeenLastCalledWith(2);
        vi.advanceTimersByTime(1000);
        expect(onTick).toHaveBeenLastCalledWith(1);
        expect(onExpire).not.toHaveBeenCalled();

        vi.advanceTimersByTime(1000);
        expect(onExpire).toHaveBeenCalledTimes(1);
        expect(timer.isRunning()).toBe(false);

        vi.advanceTimersByTime(5000);
        expect(onExpire).toHaveBeenCalledTimes(1);
    });

    it('stop() cancela el vencimiento', () => {
        const onExpire = vi.fn();
        const timer = createSelectionTimer({ seconds: 3, onExpire });

        timer.start();
        timer.stop();
        vi.advanceTimersByTime(10_000);

        expect(onExpire).not.toHaveBeenCalled();
        expect(timer.isRunning()).toBe(false);
    });

    it('con 0 segundos no hay temporizador (sin tiempo límite)', () => {
        const onExpire = vi.fn();
        const timer = createSelectionTimer({ seconds: 0, onExpire });

        timer.start();
        vi.advanceTimersByTime(60_000);

        expect(timer.isRunning()).toBe(false);
        expect(onExpire).not.toHaveBeenCalled();
    });

    it('volver a iniciar reinicia la cuenta', () => {
        const onExpire = vi.fn();
        const timer = createSelectionTimer({ seconds: 2, onExpire });

        timer.start();
        vi.advanceTimersByTime(1000);
        timer.start();
        vi.advanceTimersByTime(1500);

        expect(onExpire).not.toHaveBeenCalled();
        vi.advanceTimersByTime(500);
        expect(onExpire).toHaveBeenCalledTimes(1);
    });
});
