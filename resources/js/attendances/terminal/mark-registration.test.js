import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../terminal-offline/queue.js', () => ({
    enqueueMark: vi.fn(),
    flushQueue: vi.fn(),
    getExpectedCheckOut: vi.fn(),
}));
vi.mock('./sync-status-ui.js', () => ({
    refreshIdleSyncStatus: vi.fn().mockResolvedValue(undefined),
}));

import { enqueueMark, flushQueue, getExpectedCheckOut } from '../terminal-offline/queue.js';
import { registerMark, registerExitWithoutEntry, showTypeSelectionForEmployee, getPendingEmployee, clearPendingEmployee, isEntryOrExitPending } from './mark-registration.js';

describe('registerMark', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        enqueueMark.mockResolvedValue({ client_event_id: 'abc-123', recorded_at: '2026-09-11T10:00:00Z' });
        getExpectedCheckOut.mockResolvedValue(null);
    });

    it('tras una entrada pasa la salida esperada para el recordatorio; con otro tipo de marcación no', async () => {
        flushQueue.mockResolvedValue({ results: [{ client_event_id: 'abc-123', status: 'synced' }] });
        getExpectedCheckOut.mockResolvedValue('17:30');
        const onSuccess = vi.fn();

        await registerMark({ id: 1 }, 'check_in', { onStatusUpdate: vi.fn(), onSuccess, onError: vi.fn() }, { onLine: true });
        await registerMark({ id: 1 }, 'check_out', { onStatusUpdate: vi.fn(), onSuccess, onError: vi.fn() }, { onLine: true });

        expect(onSuccess).toHaveBeenNthCalledWith(1, { id: 1 }, { recorded_at: '2026-09-11T10:00:00Z' }, 'check_in', { queued: false, expectedCheckOut: '17:30' });
        expect(onSuccess).toHaveBeenNthCalledWith(2, { id: 1 }, { recorded_at: '2026-09-11T10:00:00Z' }, 'check_out', { queued: false });
    });

    it('sin conexión: muestra éxito encolado sin intentar flushQueue', async () => {
        const onStatusUpdate = vi.fn();
        const onSuccess = vi.fn();
        const onError = vi.fn();
        const fakeNav = { onLine: false };

        await registerMark({ id: 1 }, 'check_in', { onStatusUpdate, onSuccess, onError }, fakeNav);

        expect(flushQueue).not.toHaveBeenCalled();
        expect(onSuccess).toHaveBeenCalledWith({ id: 1 }, { recorded_at: '2026-09-11T10:00:00Z' }, 'check_in', { queued: true });
        expect(onError).not.toHaveBeenCalled();
    });

    it('con conexión y sync exitoso: muestra éxito sin encolar', async () => {
        flushQueue.mockResolvedValue({ results: [{ client_event_id: 'abc-123', status: 'synced' }] });
        const onSuccess = vi.fn();
        const fakeNav = { onLine: true };

        await registerMark({ id: 1 }, 'check_in', { onStatusUpdate: vi.fn(), onSuccess, onError: vi.fn() }, fakeNav);

        expect(onSuccess).toHaveBeenCalledWith({ id: 1 }, { recorded_at: '2026-09-11T10:00:00Z' }, 'check_in', { queued: false });
    });

    it('el servidor rechaza la marcación puntual: llama a onError', async () => {
        flushQueue.mockResolvedValue({ results: [{ client_event_id: 'abc-123', status: 'rejected', message: 'Secuencia inválida' }] });
        const onError = vi.fn();
        const fakeNav = { onLine: true };

        await registerMark({ id: 1 }, 'check_in', { onStatusUpdate: vi.fn(), onSuccess: vi.fn(), onError }, fakeNav);

        expect(onError).toHaveBeenCalledWith('Secuencia inválida');
    });

    it('flushQueue falla por red: la marcación ya encolada se muestra como éxito igual', async () => {
        flushQueue.mockRejectedValue(new Error('network down'));
        const onSuccess = vi.fn();
        const fakeNav = { onLine: true };

        await registerMark({ id: 1 }, 'check_in', { onStatusUpdate: vi.fn(), onSuccess, onError: vi.fn() }, fakeNav);

        expect(onSuccess).toHaveBeenCalledWith({ id: 1 }, { recorded_at: '2026-09-11T10:00:00Z' }, 'check_in', { queued: true });
    });
});

describe('showTypeSelectionForEmployee / getPendingEmployee / clearPendingEmployee', () => {
    it('guarda el empleado pendiente y lo expone via getPendingEmployee', () => {
        const typeButtons = [{ getAttribute: () => 'check_in', style: {}, disabled: true }];
        const typeGrid = { style: {} };
        // `showScreen` (real, not mocked) reads/mutates `classList` on every screen —
        // needed here even though this test only asserts pendingEmployee/title text.
        const typeSelectionScreen = { querySelector: () => typeGrid, classList: { contains: () => false, add: vi.fn(), remove: vi.fn() } };
        const screens = { typeSelection: typeSelectionScreen };
        const dom = {
            typeButtons,
            screenTitleEl: { textContent: '' },
            screenEyebrowEl: { textContent: '' },
            lastMarkEl: { textContent: '', classList: { add: vi.fn(), remove: vi.fn() } },
        };
        const employee = { id: 5, first_name: 'Juan', last_name: 'Pérez' };

        showTypeSelectionForEmployee(screens, dom, employee, ['check_in'], null, null);

        expect(getPendingEmployee()).toEqual(employee);
        expect(dom.screenTitleEl.textContent).toBe('Hola, Juan Pérez');
        // re-entrancy guard: showTypeSelectionForEmployee reabilita los botones
        // para el nuevo empleado, aunque hayan quedado deshabilitados por un
        // doble-tap del empleado anterior.
        expect(typeButtons[0].disabled).toBe(false);

        clearPendingEmployee();
        expect(getPendingEmployee()).toBeNull();
    });
});

describe('registerExitWithoutEntry', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        enqueueMark.mockResolvedValue({ client_event_id: 'xyz-1', recorded_at: '2026-10-19T20:00:00Z' });
        flushQueue.mockResolvedValue({ results: [{ client_event_id: 'xyz-1', status: 'conflict' }] });
    });

    it('encola la salida sin tocar el estado local, sincroniza y avisa que queda para revisión (nunca error)', async () => {
        const onSuccess = vi.fn();
        const onError = vi.fn();

        await registerExitWithoutEntry({ id: 1 }, { onStatusUpdate: vi.fn(), onSuccess, onError }, { onLine: true });

        expect(enqueueMark).toHaveBeenCalledWith(1, 'check_out', { optimistic: false });
        expect(flushQueue).toHaveBeenCalledTimes(1);
        expect(onSuccess).toHaveBeenCalledWith({ id: 1 }, { recorded_at: '2026-10-19T20:00:00Z' }, 'check_out', { queued: false, pendingReview: true });
        expect(onError).not.toHaveBeenCalled();
    });

    it('sin conexión no intenta sincronizar y avisa igual', async () => {
        const onSuccess = vi.fn();

        await registerExitWithoutEntry({ id: 1 }, { onStatusUpdate: vi.fn(), onSuccess }, { onLine: false });

        expect(flushQueue).not.toHaveBeenCalled();
        expect(onSuccess).toHaveBeenCalledWith({ id: 1 }, expect.anything(), 'check_out', { queued: false, pendingReview: true });
    });

    it('si la sincronización falla igual avisa: la salida ya quedó guardada en la cola', async () => {
        flushQueue.mockRejectedValue(new Error('sin red'));
        const onSuccess = vi.fn();

        await registerExitWithoutEntry({ id: 1 }, { onStatusUpdate: vi.fn(), onSuccess }, { onLine: true });

        expect(onSuccess).toHaveBeenCalledTimes(1);
    });
});

describe('selección «¿Entrada o salida?»', () => {
    function setup() {
        const typeButtons = ['check_in', 'check_out', 'break_start'].map((type) => ({ getAttribute: () => type, style: {}, disabled: true }));
        const typeSelectionScreen = { querySelector: () => ({ style: {} }), classList: { contains: () => false, add: vi.fn(), remove: vi.fn() } };

        return {
            typeButtons,
            screens: { typeSelection: typeSelectionScreen },
            dom: { typeButtons, screenTitleEl: { textContent: '' }, screenEyebrowEl: { textContent: '' }, lastMarkEl: { textContent: '', classList: { add: vi.fn(), remove: vi.fn() } } },
        };
    }

    it('ofrece solo Entrada y Salida y recuerda que es esta pregunta hasta limpiar', () => {
        const { typeButtons, screens, dom } = setup();

        showTypeSelectionForEmployee(screens, dom, { id: 5 }, ['check_in'], null, null, { entryOrExit: true });

        expect(typeButtons.map((b) => b.style.display)).toEqual(['', '', 'none']);
        expect(isEntryOrExitPending()).toBe(true);

        clearPendingEmployee();
        expect(isEntryOrExitPending()).toBe(false);
    });

    it('la selección normal no es la pregunta', () => {
        const { screens, dom } = setup();

        showTypeSelectionForEmployee(screens, dom, { id: 5 }, ['check_in'], null, null);

        expect(isEntryOrExitPending()).toBe(false);
    });
});
