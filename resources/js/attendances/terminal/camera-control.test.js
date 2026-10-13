import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { createCameraControl } from './camera-control.js';

describe('createCameraControl', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    function build(minutes) {
        const onChange = vi.fn();
        const control = createCameraControl({ getResumeMinutes: () => minutes, onChange, now: () => Date.now() });
        return { control, onChange };
    }

    it('al apagar avisa con la hora de reencendido', async () => {
        const { control, onChange } = build(2);
        await control.pause();

        expect(control.isPaused()).toBe(true);
        expect(onChange).toHaveBeenCalledWith({ paused: true, resumeAt: Date.now() + 120_000 });
    });

    it('se reenciende sola pasado el tiempo configurado', async () => {
        const { control, onChange } = build(2);
        await control.pause();

        vi.advanceTimersByTime(119_000);
        expect(control.isPaused()).toBe(true);

        vi.advanceTimersByTime(1_000);
        expect(control.isPaused()).toBe(false);
        expect(onChange).toHaveBeenLastCalledWith({ paused: false, resumeAt: null });
    });

    it('con 0 minutos queda apagada hasta encenderla a mano', async () => {
        const { control, onChange } = build(0);
        await control.pause();

        vi.advanceTimersByTime(60 * 60_000);
        expect(control.isPaused()).toBe(true);
        expect(onChange).toHaveBeenCalledWith({ paused: true, resumeAt: null });

        control.resume();
        expect(control.isPaused()).toBe(false);
    });

    it('encender a mano cancela el temporizador', async () => {
        const { control, onChange } = build(2);
        await control.pause();
        control.resume();
        onChange.mockClear();

        vi.advanceTimersByTime(5 * 60_000);
        expect(onChange).not.toHaveBeenCalled();
    });

    it('toggle alterna y pausar dos veces no duplica el aviso', async () => {
        const { control, onChange } = build(2);
        await control.toggle();
        await control.pause();
        expect(onChange).toHaveBeenCalledTimes(1);

        await control.toggle();
        expect(control.isPaused()).toBe(false);
    });
});
