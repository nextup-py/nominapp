import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../terminal-offline/queue.js', () => ({
    enqueueMark: vi.fn(),
    flushQueue: vi.fn(),
}));
vi.mock('./sync-status-ui.js', () => ({
    refreshIdleSyncStatus: vi.fn().mockResolvedValue(undefined),
}));

import { enqueueMark, flushQueue } from '../terminal-offline/queue.js';
import { registerMark, showTypeSelectionForEmployee, getPendingEmployee, clearPendingEmployee } from './mark-registration.js';

describe('registerMark', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        enqueueMark.mockResolvedValue({ client_event_id: 'abc-123', recorded_at: '2026-09-11T10:00:00Z' });
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
