/**
 * @fileoverview Cobertura del flujo de vinculación por código: sin token pide
 * un código y espera, al aprobarse guarda SOLO el token/identidad (la cola
 * offline y la caché sobreviven a la revinculación), y maneja denegación,
 * vencimiento, terminal desactivado, falta de red y límite de intentos.
 *
 * `../terminal-offline/db.js` se mockea sin `clearTerminalState`: si el flujo
 * llegara a importarla/usarla, el test fallaría — es la garantía de que no
 * se borra la cola de marcaciones pendientes.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

vi.mock('../terminal-offline/db.js', () => ({ getMeta: vi.fn(), setMeta: vi.fn().mockResolvedValue(undefined) }));
vi.mock('../terminal-offline/pairing-api.js', () => {
    class MockPairingError extends Error {
        constructor(message, code = null, status = null) {
            super(message);
            this.code = code;
            this.status = status;
        }
    }
    return { requestPairing: vi.fn(), pollPairing: vi.fn(), PairingError: MockPairingError };
});

async function loadFresh() {
    vi.resetModules();
    const flow = await import('./pairing-flow.js');
    const db = await import('../terminal-offline/db.js');
    const api = await import('../terminal-offline/pairing-api.js');
    return { flow, db, api };
}

function makeUi() {
    return {
        showCode: vi.fn(),
        setStatus: vi.fn(),
        setCountdown: vi.fn(),
        showRetry: vi.fn(),
        showInactive: vi.fn(),
        onLinked: vi.fn(),
    };
}

const created = () => ({
    ok: true,
    pairing_code: 'K7M2QX',
    poll_secret: 'secreto-de-polling',
    expires_at: new Date(Date.now() + 10 * 60 * 1000).toISOString(),
});

describe('pairing-flow', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.clearAllTimers();
        vi.useRealTimers();
    });

    it('sin sesión guardada: pide un código, lo muestra y lo persiste', async () => {
        const { flow, db, api } = await loadFresh();
        const ui = makeUi();
        db.getMeta.mockResolvedValue(null);
        api.requestPairing.mockResolvedValue(created());

        await flow.startPairing(ui, { terminalCode: 'abc12345', deviceModelHint: 'Pixel 7' });

        expect(api.requestPairing).toHaveBeenCalledWith('abc12345', 'Pixel 7');
        expect(ui.showCode).toHaveBeenCalledWith('K7M2QX');
        expect(db.setMeta).toHaveBeenCalledWith('pairing_session', expect.objectContaining({ code: 'K7M2QX', secret: 'secreto-de-polling', terminal_code: 'abc12345' }));
        expect(flow.isPairingActive()).toBe(true);
        flow.stopPairing();
    });

    it('con una sesión guardada vigente del mismo terminal la retoma sin pedir otro código', async () => {
        const { flow, db, api } = await loadFresh();
        const ui = makeUi();
        db.getMeta.mockResolvedValue({ terminal_code: 'abc12345', code: 'AAAAAA', secret: 's', expires_at: Date.now() + 5 * 60 * 1000 });

        await flow.startPairing(ui, { terminalCode: 'abc12345' });

        expect(api.requestPairing).not.toHaveBeenCalled();
        expect(ui.showCode).toHaveBeenCalledWith('AAAAAA');
        flow.stopPairing();
    });

    it('descarta una sesión guardada de otro terminal o a punto de vencer', async () => {
        const { flow, db, api } = await loadFresh();
        db.getMeta.mockResolvedValueOnce({ terminal_code: 'otro', code: 'AAAAAA', secret: 's', expires_at: Date.now() + 5 * 60 * 1000 });
        api.requestPairing.mockResolvedValue(created());

        await flow.startPairing(makeUi(), { terminalCode: 'abc12345' });

        expect(api.requestPairing).toHaveBeenCalledTimes(1);
        flow.stopPairing();
    });

    it('es idempotente: un segundo startPairing mientras hay flujo activo no pide otro código', async () => {
        const { flow, db, api } = await loadFresh();
        db.getMeta.mockResolvedValue(null);
        api.requestPairing.mockResolvedValue(created());

        await flow.startPairing(makeUi(), { terminalCode: 'abc12345' });
        await flow.startPairing(makeUi(), { terminalCode: 'abc12345' });

        expect(api.requestPairing).toHaveBeenCalledTimes(1);
        flow.stopPairing();
    });

    it('aprobado: guarda token e identidad, NO borra la cola ni la caché, y avisa onLinked', async () => {
        const { flow, db, api } = await loadFresh();
        const ui = makeUi();
        db.getMeta.mockResolvedValue(null);
        api.requestPairing.mockResolvedValue(created());
        api.pollPairing.mockResolvedValue({ status: 'claimed', token: 'TOKEN-NUEVO', terminal: { id: 9, code: 'abc12345', name: 'T', branch_id: 1 } });

        await flow.startPairing(ui, { terminalCode: 'abc12345' });
        await vi.advanceTimersByTimeAsync(3000);

        expect(api.pollPairing).toHaveBeenCalledWith('secreto-de-polling');
        expect(db.setMeta).toHaveBeenCalledWith('api_token', 'TOKEN-NUEVO');
        expect(db.setMeta).toHaveBeenCalledWith('terminal_id', 9);
        expect(db.setMeta).toHaveBeenCalledWith('terminal_code', 'abc12345');
        expect(ui.onLinked).toHaveBeenCalledTimes(1);
        expect(flow.isPairingActive()).toBe(false);

        // Solo se escriben estas claves de meta: nada más del estado local se toca.
        const keys = db.setMeta.mock.calls.map(([key]) => key);
        expect(keys.every((key) => ['pairing_session', 'api_token', 'terminal_id', 'terminal_code'].includes(key))).toBe(true);
    });

    it('consulta el estado cada 3 segundos, no antes', async () => {
        const { flow, db, api } = await loadFresh();
        db.getMeta.mockResolvedValue(null);
        api.requestPairing.mockResolvedValue(created());
        api.pollPairing.mockResolvedValue({ status: 'pending' });

        await flow.startPairing(makeUi(), { terminalCode: 'abc12345' });
        await vi.advanceTimersByTimeAsync(2000);
        expect(api.pollPairing).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(1000);
        expect(api.pollPairing).toHaveBeenCalledTimes(1);
        flow.stopPairing();
    });

    it('rechazado: avisa, detiene el flujo y ofrece pedir un código nuevo', async () => {
        const { flow, db, api } = await loadFresh();
        const ui = makeUi();
        db.getMeta.mockResolvedValue(null);
        api.requestPairing.mockResolvedValue(created());
        api.pollPairing.mockResolvedValue({ status: 'denied' });

        await flow.startPairing(ui, { terminalCode: 'abc12345' });
        await vi.advanceTimersByTimeAsync(3000);

        expect(ui.setStatus).toHaveBeenLastCalledWith('El administrador rechazó la solicitud.');
        expect(ui.showRetry).toHaveBeenLastCalledWith(true);
        expect(ui.onLinked).not.toHaveBeenCalled();
        expect(flow.isPairingActive()).toBe(false);
    });

    it('vencido en el servidor: ofrece pedir un código nuevo y descarta la sesión guardada', async () => {
        const { flow, db, api } = await loadFresh();
        const ui = makeUi();
        db.getMeta.mockResolvedValue(null);
        api.requestPairing.mockResolvedValue(created());
        api.pollPairing.mockResolvedValue({ status: 'expired' });

        await flow.startPairing(ui, { terminalCode: 'abc12345' });
        await vi.advanceTimersByTimeAsync(3000);

        expect(ui.showRetry).toHaveBeenLastCalledWith(true);
        expect(db.setMeta).toHaveBeenLastCalledWith('pairing_session', null);
    });

    it('ya reclamado sin token (respuesta perdida): pide un código nuevo en vez de quedar trabado', async () => {
        const { flow, db, api } = await loadFresh();
        const ui = makeUi();
        db.getMeta.mockResolvedValue(null);
        api.requestPairing.mockResolvedValue(created());
        api.pollPairing.mockResolvedValue({ status: 'claimed' });

        await flow.startPairing(ui, { terminalCode: 'abc12345' });
        await vi.advanceTimersByTimeAsync(3000);

        expect(db.setMeta).not.toHaveBeenCalledWith('api_token', expect.anything());
        expect(ui.onLinked).not.toHaveBeenCalled();
        expect(ui.showRetry).toHaveBeenLastCalledWith(true);
    });

    it('terminal desactivado al pedir el código: muestra el mensaje y no hay flujo activo', async () => {
        const { flow, db, api } = await loadFresh();
        const ui = makeUi();
        db.getMeta.mockResolvedValue(null);
        api.requestPairing.mockRejectedValue(new api.PairingError('Este terminal fue desactivado.', 'terminal_inactive', 403));

        await flow.startPairing(ui, { terminalCode: 'abc12345' });

        expect(ui.showInactive).toHaveBeenCalledWith('Este terminal fue desactivado.');
        expect(ui.showCode).not.toHaveBeenCalled();
        expect(flow.isPairingActive()).toBe(false);
    });

    it('terminal desactivado durante la espera: corta el polling y lo informa', async () => {
        const { flow, db, api } = await loadFresh();
        const ui = makeUi();
        db.getMeta.mockResolvedValue(null);
        api.requestPairing.mockResolvedValue(created());
        api.pollPairing.mockRejectedValue(new api.PairingError('Este terminal fue desactivado.', 'terminal_inactive', 403));

        await flow.startPairing(ui, { terminalCode: 'abc12345' });
        await vi.advanceTimersByTimeAsync(3000);

        expect(ui.showInactive).toHaveBeenCalled();
        expect(flow.isPairingActive()).toBe(false);
    });

    it('sin red al pedir el código: reintenta solo cada 5 segundos', async () => {
        const { flow, db, api } = await loadFresh();
        const ui = makeUi();
        db.getMeta.mockResolvedValue(null);
        api.requestPairing.mockRejectedValueOnce(new TypeError('Failed to fetch')).mockResolvedValue(created());

        await flow.startPairing(ui, { terminalCode: 'abc12345' });
        expect(ui.setStatus).toHaveBeenLastCalledWith('Sin conexión con el servidor — reintentando…');

        await vi.advanceTimersByTimeAsync(5000);

        expect(api.requestPairing).toHaveBeenCalledTimes(2);
        expect(ui.showCode).toHaveBeenCalledWith('K7M2QX');
        flow.stopPairing();
    });

    it('sin red durante la espera: sigue consultando y no pierde el código', async () => {
        const { flow, db, api } = await loadFresh();
        const ui = makeUi();
        db.getMeta.mockResolvedValue(null);
        api.requestPairing.mockResolvedValue(created());
        api.pollPairing.mockRejectedValueOnce(new TypeError('Failed to fetch')).mockResolvedValue({ status: 'pending' });

        await flow.startPairing(ui, { terminalCode: 'abc12345' });
        await vi.advanceTimersByTimeAsync(6000);

        expect(api.pollPairing).toHaveBeenCalledTimes(2);
        expect(flow.isPairingActive()).toBe(true);
        flow.stopPairing();
    });

    it('límite de intentos al pedir el código: ofrece reintentar manualmente (no en bucle)', async () => {
        const { flow, db, api } = await loadFresh();
        const ui = makeUi();
        db.getMeta.mockResolvedValue(null);
        api.requestPairing.mockRejectedValue(new api.PairingError('Demasiados intentos.', 'throttled', 429));

        await flow.startPairing(ui, { terminalCode: 'abc12345' });
        await vi.advanceTimersByTimeAsync(20000);

        expect(api.requestPairing).toHaveBeenCalledTimes(1);
        expect(ui.showRetry).toHaveBeenLastCalledWith(true);
    });

    it('restartPairing descarta la sesión guardada y pide un código nuevo', async () => {
        const { flow, db, api } = await loadFresh();
        const ui = makeUi();
        db.getMeta.mockResolvedValue(null);
        api.requestPairing.mockResolvedValue(created());

        await flow.startPairing(ui, { terminalCode: 'abc12345' });
        await flow.restartPairing(ui, { terminalCode: 'abc12345' });

        expect(db.setMeta).toHaveBeenCalledWith('pairing_session', null);
        expect(api.requestPairing).toHaveBeenCalledTimes(2);
        flow.stopPairing();
    });

    it('actualiza la cuenta regresiva cada segundo', async () => {
        const { flow, db, api } = await loadFresh();
        const ui = makeUi();
        db.getMeta.mockResolvedValue(null);
        api.requestPairing.mockResolvedValue(created());
        api.pollPairing.mockResolvedValue({ status: 'pending' });

        await flow.startPairing(ui, { terminalCode: 'abc12345' });
        ui.setCountdown.mockClear();
        await vi.advanceTimersByTimeAsync(2000);

        expect(ui.setCountdown).toHaveBeenCalledTimes(2);
        expect(ui.setCountdown.mock.calls[1][0]).toBeLessThan(ui.setCountdown.mock.calls[0][0]);
        flow.stopPairing();
    });
});
