import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { showScreen, startCountdown, startDayCompleteCountdown, stopCountdown, resetTerminal } from './screen-state.js';

function fakeScreenEl() {
    const classes = new Set();
    return {
        classList: {
            contains: (c) => classes.has(c),
            add: (c) => classes.add(c),
            remove: (c) => classes.delete(c),
        },
        _classes: classes,
    };
}

describe('showScreen', () => {
    it('oculta todas las pantallas y muestra solo la indicada', () => {
        vi.useFakeTimers();
        const idle = fakeScreenEl();
        const identification = fakeScreenEl();
        // simular que "idle" está visible (sin clase "hidden") al arrancar
        const screens = { idle, identification };

        showScreen(screens, 'identification');

        // showScreen usa un setTimeout de 150ms para la transición porque había una
        // pantalla ("idle") visible antes de la llamada — hay que avanzar el timer
        // para que el cambio de clases se aplique.
        vi.advanceTimersByTime(150);

        expect(identification._classes.has('hidden')).toBe(false);
        expect(idle._classes.has('hidden')).toBe(true);
        vi.useRealTimers();
    });

    it('no revienta si una pantalla del mapa es null', () => {
        const screens = { idle: fakeScreenEl(), identification: null };
        expect(() => showScreen(screens, 'identification')).not.toThrow();
    });
});

describe('startCountdown / stopCountdown', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('decrementa cada segundo y llama a onComplete al llegar a 0', () => {
        const countdownEl = { textContent: '' };
        const countdownFill = { style: {}, offsetWidth: 0 };
        const onComplete = vi.fn();

        startCountdown({ countdownEl, countdownFill }, 3, onComplete);

        expect(countdownEl.textContent).toBe(3);
        vi.advanceTimersByTime(1000);
        expect(countdownEl.textContent).toBe(2);
        expect(onComplete).not.toHaveBeenCalled();
        vi.advanceTimersByTime(1000);
        expect(countdownEl.textContent).toBe(1);
        vi.advanceTimersByTime(1000);
        expect(countdownEl.textContent).toBe(0);
        expect(onComplete).toHaveBeenCalledTimes(1);
    });

    it('stopCountdown cancela el intervalo antes de que llegue a 0', () => {
        const countdownEl = { textContent: '' };
        const countdownFill = { style: {}, offsetWidth: 0 };
        const onComplete = vi.fn();

        startCountdown({ countdownEl, countdownFill }, 3, onComplete);
        vi.advanceTimersByTime(1000);
        stopCountdown();
        vi.advanceTimersByTime(5000);
        expect(onComplete).not.toHaveBeenCalled();
    });
});

describe('startDayCompleteCountdown', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('decrementa y llama a onComplete al llegar a 0', () => {
        const dayCompleteCountdownEl = { textContent: '' };
        const dayCompleteCountdownFill = { style: {} };
        const onComplete = vi.fn();

        startDayCompleteCountdown({ dayCompleteCountdownEl, dayCompleteCountdownFill }, 2, onComplete);
        vi.advanceTimersByTime(1000);
        expect(onComplete).not.toHaveBeenCalled();
        vi.advanceTimersByTime(1000);
        expect(onComplete).toHaveBeenCalledTimes(1);
    });
});

describe('resetTerminal', () => {
    afterEach(() => vi.unstubAllGlobals());

    it('restaura visibilidad de los botones de tipo y llama a onReset', () => {
        // resetTerminal llama a setTerminalVideoState(null) (terminal/ui-feedback.js),
        // que usa document.getElementById — se stubea localmente porque el proyecto
        // no tiene jsdom y otros módulos (ej. device-link.js) dependen deliberadamente
        // de que `document` quede undefined fuera de este stub puntual.
        vi.stubGlobal('document', { getElementById: () => null });

        const btn1 = { style: {}, disabled: true };
        const btn2 = { style: { display: 'none' }, disabled: true };
        const typeButtons = [btn1, btn2];
        const screenTitleEl = { textContent: 'otro texto' };
        const typeSelectionScreen = { querySelector: () => screenTitleEl };
        const screens = { typeSelection: typeSelectionScreen };
        const onReset = vi.fn();

        resetTerminal(screens, typeButtons, onReset);

        expect(btn1.style.display).toBe('');
        expect(btn2.style.display).toBe('');
        // re-entrancy guard: un doble-tap deshabilita los botones antes de registrar
        // la marcación — resetTerminal debe reabilitarlos para el próximo empleado.
        expect(btn1.disabled).toBe(false);
        expect(btn2.disabled).toBe(false);
        expect(screenTitleEl.textContent).toBe('Marcación');
        expect(onReset).toHaveBeenCalledTimes(1);
    });
});
