import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { describeEmptyState, createEmptyStateController, RETRY_SECONDS } from './empty-state.js';

const base = { cachedCount: 0, syncedAt: 1700000000000, online: true, roster: null };

describe('describeEmptyState', () => {
    it('con empleados en caché no hay estado vacío', () => {
        expect(describeEmptyState({ ...base, cachedCount: 3 })).toBeNull();
    });

    it('primera sincronización con conexión: explica que está descargando', () => {
        const state = describeEmptyState({ ...base, syncedAt: null });

        expect(state.kind).toBe('syncing');
        expect(state.notifiesHr).toBe(false);
        expect(state.message).toContain('descargando');
    });

    it('nunca sincronizó y sin conexión: pide conectarse', () => {
        const state = describeEmptyState({ ...base, syncedAt: null, online: false });

        expect(state.kind).toBe('offline_no_data');
        expect(state.message).toContain('conexión a internet');
    });

    it('sucursal sin empleados activos', () => {
        const state = describeEmptyState({ ...base, roster: { active_employees: 0, enrolled_employees: 0 } });

        expect(state.kind).toBe('no_active');
        expect(state.detail).toContain('no tiene empleados activos');
    });

    it('empleados activos pero ninguno con rostro: con el conteo exacto', () => {
        const state = describeEmptyState({ ...base, roster: { active_employees: 12, enrolled_employees: 0 } });

        expect(state.kind).toBe('no_face');
        expect(state.detail).toContain('12 empleado(s) activo(s)');
        expect(state.detail).toContain('ninguno con rostro');
        expect(state.notifiesHr).toBe(true);
    });

    it('el servidor tiene enrolados pero el terminal no los descargó', () => {
        const state = describeEmptyState({ ...base, roster: { active_employees: 12, enrolled_employees: 9 } });

        expect(state.kind).toBe('out_of_sync');
        expect(state.detail).toContain('9 empleado(s) con rostro');
    });

    it('sin conteo del servidor cae a un mensaje genérico', () => {
        const state = describeEmptyState({ ...base, roster: null });

        expect(state.kind).toBe('unknown');
        expect(state.notifiesHr).toBe(true);
    });
});

describe('createEmptyStateController', () => {
    let inputs;
    let deps;

    beforeEach(() => {
        vi.useFakeTimers();
        inputs = { ...base, syncedAt: null };
        deps = {
            getInputs: vi.fn(async () => ({ ...inputs })),
            onBlock: vi.fn(),
            onUpdate: vi.fn(),
            onResume: vi.fn(),
            sync: vi.fn(async () => {}),
            onTick: vi.fn(),
        };
    });

    afterEach(() => {
        vi.clearAllTimers();
        vi.useRealTimers();
    });

    it('sin empleados bloquea una sola vez y muestra la causa', async () => {
        const controller = createEmptyStateController(deps);

        await controller.refresh();
        await controller.refresh();

        expect(controller.isBlocked()).toBe(true);
        expect(deps.onBlock).toHaveBeenCalledTimes(1);
        expect(deps.onBlock.mock.calls[0][0].kind).toBe('syncing');
    });

    it('con empleados no bloquea ni cuenta hacia atrás', async () => {
        inputs.cachedCount = 5;
        const controller = createEmptyStateController(deps);

        expect(await controller.refresh()).toBeNull();

        expect(controller.isBlocked()).toBe(false);
        expect(deps.onBlock).not.toHaveBeenCalled();
        expect(deps.onTick).not.toHaveBeenCalled();
    });

    it('reintenta la sincronización al terminar la cuenta regresiva y reanuda cuando llegan empleados', async () => {
        const controller = createEmptyStateController(deps);
        await controller.refresh();
        deps.sync.mockImplementation(async () => { inputs.cachedCount = 4; });

        await vi.advanceTimersByTimeAsync(RETRY_SECONDS * 1000);

        expect(deps.sync).toHaveBeenCalledTimes(1);
        expect(deps.onResume).toHaveBeenCalledTimes(1);
        expect(controller.isBlocked()).toBe(false);
    });

    it('si el reintento no trae empleados sigue bloqueado y vuelve a intentar', async () => {
        const controller = createEmptyStateController(deps);
        await controller.refresh();

        await vi.advanceTimersByTimeAsync(RETRY_SECONDS * 1000 * 2);

        expect(deps.sync).toHaveBeenCalledTimes(2);
        expect(controller.isBlocked()).toBe(true);
        expect(deps.onResume).not.toHaveBeenCalled();
    });

    it('un fallo del sync no corta el reintento ni deja el controlador trabado', async () => {
        const controller = createEmptyStateController(deps);
        await controller.refresh();
        deps.sync.mockRejectedValueOnce(new Error('red caída'));

        await controller.retryNow();
        await controller.retryNow();

        expect(deps.sync).toHaveBeenCalledTimes(2);
        expect(controller.isBlocked()).toBe(true);
    });

    it('sin conexión no intenta sincronizar', async () => {
        inputs.online = false;
        const controller = createEmptyStateController(deps);
        await controller.refresh();

        await controller.retryNow();

        expect(deps.sync).not.toHaveBeenCalled();
    });

    it('cuando la causa cambia mientras sigue bloqueado actualiza el texto', async () => {
        const controller = createEmptyStateController(deps);
        await controller.refresh();

        inputs.syncedAt = 1700000000000;
        inputs.roster = { active_employees: 3, enrolled_employees: 0 };
        await controller.refresh();

        expect(deps.onUpdate).toHaveBeenCalledTimes(1);
        expect(deps.onUpdate.mock.calls[0][0].kind).toBe('no_face');
        expect(deps.onBlock).toHaveBeenCalledTimes(1);
    });

    it('con otra condición que ya bloquea el terminal (sin vincular) no evalúa nada', async () => {
        const controller = createEmptyStateController({ ...deps, isSuspended: () => true });

        expect(await controller.refresh()).toBeNull();

        expect(deps.getInputs).not.toHaveBeenCalled();
        expect(deps.onBlock).not.toHaveBeenCalled();
    });

    it('dos reintentos simultáneos no sincronizan dos veces', async () => {
        const controller = createEmptyStateController(deps);
        await controller.refresh();
        let release;
        deps.sync.mockImplementation(() => new Promise((resolve) => { release = resolve; }));

        const first = controller.retryNow();
        await controller.retryNow();
        release();
        await first;

        expect(deps.sync).toHaveBeenCalledTimes(1);
    });
});
