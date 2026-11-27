import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { createOvertimeReasonPrompt } from './overtime-reason-prompt.js';

const reasons = [
    { key: 'cash_close', label: 'Cierre de caja' },
    { key: 'other', label: 'Otro' },
];

/** Elemento mínimo sin DOM: guarda hijos, texto y el manejador de clic. */
function fakeElement() {
    const element = {
        children: [],
        dataset: {},
        textContent: '',
        handlers: {},
        addEventListener(type, handler) {
            this.handlers[type] = handler;
        },
        click() {
            this.handlers.click?.();
        },
        replaceChildren(...nodes) {
            this.children = nodes;
        },
    };

    return element;
}

describe('createOvertimeReasonPrompt', () => {
    let list;
    let countdown;
    let show;

    beforeEach(() => {
        vi.useFakeTimers();
        list = fakeElement();
        countdown = fakeElement();
        show = vi.fn();
    });

    afterEach(() => vi.useRealTimers());

    const build = (extra = {}) => createOvertimeReasonPrompt({ list, countdown, show, seconds: 5, createButton: fakeElement, ...extra });

    it('dibuja un botón por motivo y devuelve el elegido', async () => {
        const result = build().ask(reasons);

        expect(show).toHaveBeenCalledOnce();
        expect(list.children.map((b) => b.textContent)).toEqual(['Cierre de caja', 'Otro']);

        list.children.find((b) => b.dataset.reason === 'other').click();

        await expect(result).resolves.toBe('other');
        expect(list.children).toHaveLength(0);
    });

    it('devuelve null cuando se vence la espera, sin bloquear la marcación', async () => {
        const result = build({ seconds: 3 }).ask(reasons);

        expect(countdown.textContent).toBe('Se registrará sin motivo en 3 s');
        await vi.advanceTimersByTimeAsync(3000);

        await expect(result).resolves.toBeNull();
        expect(countdown.textContent).toBe('');
    });

    it('un toque después de vencer no cambia el resultado', async () => {
        const result = build({ seconds: 1 }).ask(reasons);
        const [first] = list.children;

        await vi.advanceTimersByTimeAsync(1000);
        first.click();

        await expect(result).resolves.toBeNull();
    });

    it('devuelve null sin mostrar nada si no hay motivos o dónde dibujarlos', async () => {
        await expect(build().ask([])).resolves.toBeNull();
        await expect(build({ list: null }).ask(reasons)).resolves.toBeNull();
        expect(show).not.toHaveBeenCalled();
    });

    it('pinta las etiquetas como texto, no como HTML', () => {
        build().ask([{ key: 'x', label: '<img src=x onerror=alert(1)>' }]);

        expect(list.children[0].textContent).toBe('<img src=x onerror=alert(1)>');
    });

    it('usa los segundos del ajuste cuando se indican', async () => {
        const result = build({ seconds: 30 }).ask(reasons, { seconds: 2 });

        await vi.advanceTimersByTimeAsync(2000);

        await expect(result).resolves.toBeNull();
    });

    it('si el motivo es obligatorio no vence: solo se sale eligiendo uno', async () => {
        const result = build({ seconds: 2 }).ask(reasons, { required: true });
        let settled = false;
        result.then(() => { settled = true; });

        await vi.advanceTimersByTimeAsync(600000);
        expect(settled).toBe(false);
        expect(countdown.textContent).toContain('Elija un motivo');

        list.children[0].click();

        await expect(result).resolves.toBe('cash_close');
    });
});
