// resources/js/attendances/terminal/bootstrap.test.js
/**
 * @fileoverview Cobertura puntual de startBackgroundSync() — específicamente
 * que un TerminalAuthError (token revocado durante el sync en segundo plano)
 * se reporte de forma visible (updateIdleSyncStatus) en vez de quedar solo en
 * consola. No cubre el resto de bootstrap.js (carga de modelos, wake lock,
 * etc.) — fuera de alcance de este fix puntual.
 *
 * `startBackgroundSync()` usa un flag module-level (`backgroundSyncStarted`)
 * para arrancar una sola vez — cada test necesita su propia instancia limpia
 * del módulo (`vi.resetModules()` + re-import dinámico de todo, mocks
 * incluidos, porque los factories de `vi.mock` se re-evalúan y generan
 * nuevos `vi.fn()` en cada reset).
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

vi.mock('./camera.js', () => ({ loadModels: vi.fn() }));
vi.mock('./idle-detection.js', () => ({ acquireWakeLock: vi.fn(), hasWakeLock: vi.fn(), getWakeLockError: vi.fn() }));
vi.mock('../terminal-offline/db.js', () => ({
    migrateTokenFromLocalStorage: vi.fn(),
    getMeta: vi.fn(),
    setMeta: vi.fn(),
    clearTerminalState: vi.fn(),
}));
vi.mock('../../shared/audio-feedback.js', () => ({ markUserInteracted: vi.fn() }));

class TerminalAuthErrorImpl extends Error {}
vi.mock('../terminal-offline/sync.js', () => ({
    heartbeat: vi.fn(),
    syncEmployees: vi.fn(),
    rebuildEmployeeCache: vi.fn(),
    setCommandHandler: vi.fn(),
    setAppVersionHandler: vi.fn(),
    setWakeLockProbe: vi.fn(),
    queueCommandAck: vi.fn(),
    TerminalAuthError: TerminalAuthErrorImpl,
}));
vi.mock('../terminal-offline/queue.js', () => ({ flushQueue: vi.fn() }));
vi.mock('./sync-status-ui.js', () => ({
    updateIdleSyncStatus: vi.fn(),
    refreshIdleSyncStatus: vi.fn().mockResolvedValue(undefined),
    refreshLastSyncLabel: vi.fn().mockResolvedValue(undefined),
}));

async function loadFreshBootstrap() {
    vi.resetModules();
    const syncMod = await import('../terminal-offline/sync.js');
    const queueMod = await import('../terminal-offline/queue.js');
    const statusMod = await import('./sync-status-ui.js');
    const dbMod = await import('../terminal-offline/db.js');
    const { startBackgroundSync, initializeOfflineSync, initializeSystem, checkLegacyTerminalMigration, setBusyProbe, startOfflineSyncEarly } = await import('./bootstrap.js');
    const cameraMod = await import('./camera.js');
    return { startBackgroundSync, initializeOfflineSync, initializeSystem, checkLegacyTerminalMigration, setBusyProbe, startOfflineSyncEarly, ...cameraMod, ...syncMod, ...queueMod, ...statusMod, ...dbMod };
}

describe('startBackgroundSync — reporte de TerminalAuthError', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.useFakeTimers();
        vi.stubGlobal('window', { addEventListener: vi.fn() });
        vi.stubGlobal('navigator', { onLine: true });
    });

    afterEach(() => {
        // Sin esto, los setInterval que startBackgroundSync() registra en un test
        // (bajo el reloj fake de ESE test) quedan huérfanos en el motor de fake
        // timers y pueden seguir disparando en el próximo test, contaminando sus
        // asserts — startBackgroundSync() es un singleton module-level que no
        // expone forma de detener sus intervalos.
        vi.clearAllTimers();
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it('heartbeat revocado: avisa visiblemente en vez de solo loguear', async () => {
        const { startBackgroundSync, heartbeat, syncEmployees, flushQueue, updateIdleSyncStatus, TerminalAuthError } = await loadFreshBootstrap();
        heartbeat.mockRejectedValue(new TerminalAuthError('revocado'));
        syncEmployees.mockResolvedValue(undefined);
        flushQueue.mockResolvedValue({ results: [] });

        startBackgroundSync();
        await vi.advanceTimersByTimeAsync(90 * 1000);

        expect(updateIdleSyncStatus).toHaveBeenCalledWith('Terminal sin configurar — necesita re-provisión');
    });

    it('sync de empleados revocado: avisa visiblemente', async () => {
        const { startBackgroundSync, heartbeat, syncEmployees, flushQueue, updateIdleSyncStatus, TerminalAuthError } = await loadFreshBootstrap();
        heartbeat.mockResolvedValue(undefined);
        syncEmployees.mockRejectedValue(new TerminalAuthError('revocado'));
        flushQueue.mockResolvedValue({ results: [] });

        startBackgroundSync();
        await vi.advanceTimersByTimeAsync(5 * 60 * 1000);

        expect(updateIdleSyncStatus).toHaveBeenCalledWith('Terminal sin configurar — necesita re-provisión');
    });

    it('flushQueue revocado: avisa visiblemente', async () => {
        const { startBackgroundSync, heartbeat, syncEmployees, flushQueue, updateIdleSyncStatus, TerminalAuthError } = await loadFreshBootstrap();
        heartbeat.mockResolvedValue(undefined);
        syncEmployees.mockResolvedValue(undefined);
        flushQueue.mockRejectedValue(new TerminalAuthError('revocado'));

        startBackgroundSync();
        await vi.advanceTimersByTimeAsync(30 * 1000);

        expect(updateIdleSyncStatus).toHaveBeenCalledWith('Terminal sin configurar — necesita re-provisión');
    });

    it('heartbeat revocado: además bloquea la marcación vía onUnlinked', async () => {
        const { startBackgroundSync, heartbeat, syncEmployees, flushQueue, TerminalAuthError } = await loadFreshBootstrap();
        heartbeat.mockRejectedValue(new TerminalAuthError('revocado'));
        syncEmployees.mockResolvedValue(undefined);
        flushQueue.mockResolvedValue({ results: [] });
        const onUnlinked = vi.fn();

        startBackgroundSync({ onUnlinked });
        await vi.advanceTimersByTimeAsync(90 * 1000);

        expect(onUnlinked).toHaveBeenCalledWith('revoked');
    });

    it('un fallo de red normal (no auth) NO dispara onUnlinked', async () => {
        const { startBackgroundSync, heartbeat, syncEmployees, flushQueue } = await loadFreshBootstrap();
        heartbeat.mockRejectedValue(new Error('network down'));
        syncEmployees.mockResolvedValue(undefined);
        flushQueue.mockResolvedValue({ results: [] });
        const onUnlinked = vi.fn();

        startBackgroundSync({ onUnlinked });
        await vi.advanceTimersByTimeAsync(90 * 1000);

        expect(onUnlinked).not.toHaveBeenCalled();
    });

    it('un fallo de red normal (no auth) NO dispara el mensaje de re-provisión', async () => {
        const { startBackgroundSync, heartbeat, syncEmployees, flushQueue, updateIdleSyncStatus } = await loadFreshBootstrap();
        heartbeat.mockRejectedValue(new Error('network down'));
        syncEmployees.mockResolvedValue(undefined);
        flushQueue.mockResolvedValue({ results: [] });

        startBackgroundSync();
        await vi.advanceTimersByTimeAsync(90 * 1000);

        expect(updateIdleSyncStatus).not.toHaveBeenCalledWith('Terminal sin configurar — necesita re-provisión');
    });
});

describe('startBackgroundSync — sincronización al despertar el equipo', () => {
    let listeners;

    beforeEach(() => {
        vi.clearAllMocks();
        vi.useFakeTimers();
        listeners = {};
        vi.stubGlobal('window', { addEventListener: vi.fn((name, cb) => { listeners[name] = cb; }) });
        vi.stubGlobal('navigator', { onLine: true });
        vi.stubGlobal('document', { visibilityState: 'visible' });
    });

    afterEach(() => {
        vi.clearAllTimers();
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it('al volver a estar visible envía el latido y vacía la cola sin esperar al ciclo', async () => {
        const { startBackgroundSync, heartbeat, flushQueue } = await loadFreshBootstrap();
        heartbeat.mockResolvedValue(undefined);
        flushQueue.mockResolvedValue({ results: [] });

        startBackgroundSync();
        listeners.visibilitychange();
        await vi.advanceTimersByTimeAsync(0);

        expect(heartbeat).toHaveBeenCalledTimes(1);
        expect(flushQueue).toHaveBeenCalledTimes(1);
    });

    it('no sincroniza si la pestaña pasa a segundo plano', async () => {
        const { startBackgroundSync, heartbeat } = await loadFreshBootstrap();
        heartbeat.mockResolvedValue(undefined);
        document.visibilityState = 'hidden';

        startBackgroundSync();
        listeners.visibilitychange();
        await vi.advanceTimersByTimeAsync(0);

        expect(heartbeat).not.toHaveBeenCalled();
    });

    it('varios eventos seguidos (focus + visibilitychange) generan un solo latido', async () => {
        const { startBackgroundSync, heartbeat, flushQueue } = await loadFreshBootstrap();
        heartbeat.mockResolvedValue(undefined);
        flushQueue.mockResolvedValue({ results: [] });

        startBackgroundSync();
        listeners.visibilitychange();
        listeners.focus();
        listeners.pageshow();
        await vi.advanceTimersByTimeAsync(0);

        expect(heartbeat).toHaveBeenCalledTimes(1);
    });

    it('sin conexión no intenta sincronizar', async () => {
        const { startBackgroundSync, heartbeat } = await loadFreshBootstrap();
        vi.stubGlobal('navigator', { onLine: false });

        startBackgroundSync();
        listeners.visibilitychange();
        await vi.advanceTimersByTimeAsync(0);

        expect(heartbeat).not.toHaveBeenCalled();
    });
});

describe('recarga por versión nueva — cuándo es seguro interrumpir', () => {
    let hidden;

    /** Deja visible solo la pantalla indicada (las demás con la clase `hidden`). */
    const showOnly = (name) => {
        Object.keys(hidden).forEach((id) => { hidden[id] = id !== name; });
    };

    beforeEach(() => {
        vi.clearAllMocks();
        vi.useFakeTimers();
        hidden = { startGateScreen: true, idleScreen: true, identificationScreen: true, successScreen: true, typeSelectionScreen: true, emptyScreen: true };
        vi.stubGlobal('document', {
            getElementById: (id) => (id in hidden ? { classList: { contains: () => hidden[id] } } : null),
        });
        vi.stubGlobal('window', {
            addEventListener: vi.fn(),
            confirm: vi.fn(),
            location: { reload: vi.fn() },
            terminalData: { id: 1, code: 'x', app_version: 'vieja' },
        });
        vi.stubGlobal('navigator', { onLine: true, storage: undefined });
        vi.stubGlobal('sessionStorage', { getItem: vi.fn().mockReturnValue(null), setItem: vi.fn() });
    });

    afterEach(() => {
        vi.clearAllTimers();
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    async function bootWithVersionHandler() {
        const mod = await loadFreshBootstrap();
        mod.getMeta.mockImplementation((key) => Promise.resolve(key === 'api_token' ? 'un-token' : null));
        mod.heartbeat.mockResolvedValue(undefined);
        mod.syncEmployees.mockResolvedValue(undefined);
        mod.flushQueue.mockResolvedValue({ results: [] });
        await mod.initializeOfflineSync();

        return { ...mod, versionHandler: mod.setAppVersionHandler.mock.calls[0][0] };
    }

    it('en reposo recarga de inmediato', async () => {
        const mod = await bootWithVersionHandler();
        showOnly('idleScreen');

        await mod.versionHandler('nueva');

        expect(window.location.reload).toHaveBeenCalledTimes(1);
    });

    it('en identificación esperando un rostro (nadie siendo atendido) también recarga', async () => {
        const mod = await bootWithVersionHandler();
        showOnly('identificationScreen');
        mod.setBusyProbe(() => false);

        await mod.versionHandler('nueva');

        expect(window.location.reload).toHaveBeenCalledTimes(1);
    });

    it('en "Toque para comenzar" también recarga (nadie está marcando)', async () => {
        const mod = await bootWithVersionHandler();
        showOnly('startGateScreen');

        await mod.versionHandler('nueva');

        expect(window.location.reload).toHaveBeenCalledTimes(1);
    });

    it('en la pantalla de "sin empleados" también recarga (no hay nadie a quien interrumpir)', async () => {
        const mod = await bootWithVersionHandler();
        showOnly('emptyScreen');
        mod.setBusyProbe(() => true);

        await mod.versionHandler('nueva');

        expect(window.location.reload).toHaveBeenCalledTimes(1);
    });

    it('con una identificación en curso espera y recarga cuando termina', async () => {
        const mod = await bootWithVersionHandler();
        showOnly('identificationScreen');
        let busy = true;
        mod.setBusyProbe(() => busy);

        const pending = mod.versionHandler('nueva');
        await vi.advanceTimersByTimeAsync(10000);
        expect(window.location.reload).not.toHaveBeenCalled();

        busy = false;
        await vi.advanceTimersByTimeAsync(5000);
        await pending;

        expect(window.location.reload).toHaveBeenCalledTimes(1);
    });

    it.each(['typeSelectionScreen', 'successScreen'])('durante %s no recarga (espera hasta agotar el tiempo)', async (screen) => {
        const mod = await bootWithVersionHandler();
        showOnly(screen);

        const pending = mod.versionHandler('nueva');
        await vi.advanceTimersByTimeAsync(7 * 60 * 1000);
        await pending;

        expect(window.location.reload).not.toHaveBeenCalled();
    });
});

describe('initializeOfflineSync — confirmación antes de limpiar el estado de otro terminal', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    function stubWindow({ confirmReturns, terminalCode = 'nuevo-code', terminalId = 99 } = {}) {
        vi.stubGlobal('window', {
            addEventListener: vi.fn(),
            confirm: vi.fn().mockReturnValue(confirmReturns),
            terminalData: { id: terminalId, code: terminalCode },
        });
        vi.stubGlobal('navigator', { onLine: true, storage: undefined });
    }

    it('sin datos locales previos (primera provisión): no pregunta nada', async () => {
        const { initializeOfflineSync, getMeta, clearTerminalState, heartbeat, syncEmployees, flushQueue } = await loadFreshBootstrap();
        stubWindow({ confirmReturns: true });
        getMeta.mockImplementation((key) => Promise.resolve(key === 'api_token' ? 'un-token' : null));
        heartbeat.mockResolvedValue(undefined);
        syncEmployees.mockResolvedValue(undefined);
        flushQueue.mockResolvedValue({ results: [] });

        const result = await initializeOfflineSync();

        expect(window.confirm).not.toHaveBeenCalled();
        expect(clearTerminalState).not.toHaveBeenCalled();
        expect(result).toEqual({ linked: true });
    });

    it('con una recarga pendiente: el primer latido la confirma y envía otro de inmediato', async () => {
        const { initializeOfflineSync, getMeta, setMeta, setAppVersionHandler, queueCommandAck, heartbeat, syncEmployees, flushQueue } = await loadFreshBootstrap();
        stubWindow({ confirmReturns: true });
        window.terminalData.app_version = 'nueva';
        getMeta.mockImplementation((key) => Promise.resolve(
            key === 'api_token' ? 'un-token' : key === 'pending_reload_command' ? { id: 7, at: Date.now(), pageId: 'pagina-anterior' } : null,
        ));
        heartbeat.mockResolvedValue(undefined);
        syncEmployees.mockResolvedValue(undefined);
        flushQueue.mockResolvedValue({ results: [] });

        await initializeOfflineSync();
        const versionHandler = setAppVersionHandler.mock.calls[0][0];
        heartbeat.mockClear();
        await versionHandler('nueva');

        expect(queueCommandAck).toHaveBeenCalledWith({ id: 7, status: 'done' });
        expect(setMeta).toHaveBeenCalledWith('pending_reload_command', null);
        expect(heartbeat).toHaveBeenCalledTimes(1);
    });

    it('sin recarga pendiente: el manejador de versión no dispara un latido extra', async () => {
        const { initializeOfflineSync, getMeta, setAppVersionHandler, heartbeat, syncEmployees, flushQueue } = await loadFreshBootstrap();
        stubWindow({ confirmReturns: true });
        window.terminalData.app_version = 'nueva';
        getMeta.mockImplementation((key) => Promise.resolve(key === 'api_token' ? 'un-token' : null));
        heartbeat.mockResolvedValue(undefined);
        syncEmployees.mockResolvedValue(undefined);
        flushQueue.mockResolvedValue({ results: [] });

        await initializeOfflineSync();
        heartbeat.mockClear();
        await setAppVersionHandler.mock.calls[0][0]('nueva');

        expect(heartbeat).not.toHaveBeenCalled();
    });

    it('sin token: retorna linked:false (no_token), no sincroniza ni arranca el sync en segundo plano', async () => {
        const { initializeOfflineSync, getMeta, heartbeat, syncEmployees, flushQueue, updateIdleSyncStatus } = await loadFreshBootstrap();
        stubWindow({ confirmReturns: true });
        getMeta.mockResolvedValue(null);

        const result = await initializeOfflineSync();

        expect(result).toEqual({ linked: false, reason: 'no_token' });
        expect(updateIdleSyncStatus).toHaveBeenCalledWith('Terminal sin configurar');
        expect(heartbeat).not.toHaveBeenCalled();
        expect(syncEmployees).not.toHaveBeenCalled();
        expect(flushQueue).not.toHaveBeenCalled();
    });

    it('terminal distinto + confirma: limpia el estado anterior y sincroniza como el nuevo', async () => {
        const { initializeOfflineSync, getMeta, clearTerminalState, heartbeat, syncEmployees, flushQueue } = await loadFreshBootstrap();
        stubWindow({ confirmReturns: true, terminalCode: 'nuevo-code', terminalId: 99 });
        getMeta.mockImplementation((key) => Promise.resolve({
            terminal_id: 1,
            terminal_code: 'viejo-code',
            api_token: 'token-viejo',
        }[key] ?? null));
        heartbeat.mockResolvedValue(undefined);
        syncEmployees.mockResolvedValue(undefined);
        flushQueue.mockResolvedValue({ results: [] });

        const result = await initializeOfflineSync();

        expect(window.confirm).toHaveBeenCalledTimes(1);
        expect(clearTerminalState).toHaveBeenCalledTimes(1);
        expect(heartbeat).toHaveBeenCalled();
        expect(result).toEqual({ linked: true });
    });

    it('terminal distinto + cancela: NO limpia nada y no intenta sincronizar', async () => {
        const { initializeOfflineSync, getMeta, clearTerminalState, heartbeat, syncEmployees, flushQueue, updateIdleSyncStatus } = await loadFreshBootstrap();
        stubWindow({ confirmReturns: false, terminalCode: 'nuevo-code', terminalId: 99 });
        getMeta.mockImplementation((key) => Promise.resolve({
            terminal_id: 1,
            terminal_code: 'viejo-code',
            api_token: 'token-viejo',
        }[key] ?? null));

        const result = await initializeOfflineSync();

        expect(result).toEqual({ linked: false, reason: 'other_terminal', storedCode: 'viejo-code' });
        expect(window.confirm).toHaveBeenCalledTimes(1);
        expect(clearTerminalState).not.toHaveBeenCalled();
        expect(heartbeat).not.toHaveBeenCalled();
        expect(syncEmployees).not.toHaveBeenCalled();
        expect(flushQueue).not.toHaveBeenCalled();
        expect(updateIdleSyncStatus).toHaveBeenCalledWith('Este es otro terminal — volvé a /terminal/viejo-code');
    });
});

describe('initializeSystem — terminal sin vincular', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.clearAllTimers();
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    function stubBrowser() {
        vi.stubGlobal('window', { addEventListener: vi.fn(), terminalData: { id: 1, code: 'abc' } });
        vi.stubGlobal('navigator', { onLine: true, storage: undefined, mediaDevices: { getUserMedia: vi.fn() } });
    }

    it('sin token: llama a onUnlinked("no_token") y NO a onReady', async () => {
        const { initializeSystem, loadModels, getMeta } = await loadFreshBootstrap();
        stubBrowser();
        loadModels.mockResolvedValue({ ok: true });
        getMeta.mockResolvedValue(null);
        const callbacks = { onReady: vi.fn(), onError: vi.fn(), onUnlinked: vi.fn() };

        const done = initializeSystem({}, callbacks);
        await vi.advanceTimersByTimeAsync(5000);
        await done;

        expect(callbacks.onUnlinked).toHaveBeenCalledWith('no_token', undefined);
        expect(callbacks.onReady).not.toHaveBeenCalled();
        expect(callbacks.onError).not.toHaveBeenCalled();
    });

    it('con token: llama a onReady y no a onUnlinked', async () => {
        const { initializeSystem, loadModels, getMeta, heartbeat, syncEmployees, flushQueue } = await loadFreshBootstrap();
        stubBrowser();
        loadModels.mockResolvedValue({ ok: true });
        getMeta.mockImplementation((key) => Promise.resolve(key === 'api_token' ? 'un-token' : null));
        heartbeat.mockResolvedValue(undefined);
        syncEmployees.mockResolvedValue(undefined);
        flushQueue.mockResolvedValue({ results: [] });
        const callbacks = { onReady: vi.fn(), onError: vi.fn(), onUnlinked: vi.fn() };

        const done = initializeSystem({}, callbacks);
        await vi.advanceTimersByTimeAsync(5000);
        await done;

        expect(callbacks.onReady).toHaveBeenCalledTimes(1);
        expect(callbacks.onUnlinked).not.toHaveBeenCalled();
    });
});

describe('checkLegacyTerminalMigration', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('en /terminal/{code} (window.terminalData presente): no hay nada que migrar', async () => {
        const { checkLegacyTerminalMigration, getMeta } = await loadFreshBootstrap();
        vi.stubGlobal('window', { terminalData: { id: 1, code: 'algo' }, location: { origin: 'https://ej.com' } });

        const result = await checkLegacyTerminalMigration();

        expect(result).toEqual({ needsMigration: false });
        expect(getMeta).not.toHaveBeenCalled();
    });

    it('en /terminal (legacy) sin terminal_code guardado: no hay nada que migrar', async () => {
        const { checkLegacyTerminalMigration, getMeta } = await loadFreshBootstrap();
        vi.stubGlobal('window', { terminalData: undefined, location: { origin: 'https://ej.com' } });
        getMeta.mockResolvedValue(null);

        const result = await checkLegacyTerminalMigration();

        expect(result).toEqual({ needsMigration: false });
    });

    it('en /terminal (legacy) con terminal_code guardado: pide migrar con la URL correcta', async () => {
        const { checkLegacyTerminalMigration, getMeta } = await loadFreshBootstrap();
        vi.stubGlobal('window', { terminalData: undefined, location: { origin: 'https://ej.com' } });
        getMeta.mockResolvedValue('mi-code');

        const result = await checkLegacyTerminalMigration();

        expect(result).toEqual({ needsMigration: true, url: 'https://ej.com/terminal/mi-code' });
    });

    it('si getMeta falla, no bloquea el arranque (needsMigration: false)', async () => {
        const { checkLegacyTerminalMigration, getMeta } = await loadFreshBootstrap();
        vi.stubGlobal('window', { terminalData: undefined, location: { origin: 'https://ej.com' } });
        getMeta.mockRejectedValue(new Error('indexeddb down'));

        const result = await checkLegacyTerminalMigration();

        expect(result).toEqual({ needsMigration: false });
    });
});

describe('startOfflineSyncEarly — sincronizar sin esperar el toque de "Comenzar"', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.useFakeTimers();
        vi.stubGlobal('window', { addEventListener: vi.fn(), confirm: vi.fn(), terminalData: { id: 1, code: 'abc' } });
        vi.stubGlobal('navigator', { onLine: true, storage: undefined, mediaDevices: { getUserMedia: vi.fn() } });
    });

    afterEach(() => {
        vi.clearAllTimers();
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it('con token y sin tocar nada: hace el latido, sincroniza y vacía la cola', async () => {
        const { startOfflineSyncEarly, getMeta, heartbeat, syncEmployees, flushQueue } = await loadFreshBootstrap();
        getMeta.mockImplementation((key) => Promise.resolve(key === 'api_token' ? 'un-token' : null));
        heartbeat.mockResolvedValue(undefined);
        syncEmployees.mockResolvedValue(undefined);
        flushQueue.mockResolvedValue({ results: [] });

        const result = await startOfflineSyncEarly();

        expect(result.linked).toBe(true);
        expect(heartbeat).toHaveBeenCalledTimes(1);
        expect(syncEmployees).toHaveBeenCalledTimes(1);
    });

    it('se ejecuta una sola vez por carga aunque se llame de nuevo', async () => {
        const { startOfflineSyncEarly, getMeta, heartbeat, syncEmployees, flushQueue } = await loadFreshBootstrap();
        getMeta.mockImplementation((key) => Promise.resolve(key === 'api_token' ? 'un-token' : null));
        heartbeat.mockResolvedValue(undefined);
        syncEmployees.mockResolvedValue(undefined);
        flushQueue.mockResolvedValue({ results: [] });

        await Promise.all([startOfflineSyncEarly(), startOfflineSyncEarly()]);

        expect(heartbeat).toHaveBeenCalledTimes(1);
    });

    it('un navegador de otro terminal: no pregunta ni borra nada ni sincroniza', async () => {
        const { startOfflineSyncEarly, getMeta, clearTerminalState, heartbeat } = await loadFreshBootstrap();
        getMeta.mockImplementation((key) => Promise.resolve({ terminal_id: 99, terminal_code: 'otro', api_token: 'x' }[key] ?? null));

        const result = await startOfflineSyncEarly();

        expect(result).toMatchObject({ linked: false, reason: 'other_terminal' });
        expect(window.confirm).not.toHaveBeenCalled();
        expect(clearTerminalState).not.toHaveBeenCalled();
        expect(heartbeat).not.toHaveBeenCalled();
    });

    it('sin token: no sincroniza', async () => {
        const { startOfflineSyncEarly, getMeta, heartbeat } = await loadFreshBootstrap();
        getMeta.mockResolvedValue(null);

        const result = await startOfflineSyncEarly();

        expect(result).toMatchObject({ linked: false, reason: 'no_token' });
        expect(heartbeat).not.toHaveBeenCalled();
    });

    it('al tocar Comenzar no repite la sincronización que ya hizo el arranque temprano', async () => {
        const { startOfflineSyncEarly, initializeSystem, loadModels, getMeta, heartbeat, syncEmployees, flushQueue } = await loadFreshBootstrap();
        loadModels.mockResolvedValue({ ok: true });
        getMeta.mockImplementation((key) => Promise.resolve(key === 'api_token' ? 'un-token' : null));
        heartbeat.mockResolvedValue(undefined);
        syncEmployees.mockResolvedValue(undefined);
        flushQueue.mockResolvedValue({ results: [] });
        await startOfflineSyncEarly();
        const callbacks = { onReady: vi.fn(), onError: vi.fn(), onUnlinked: vi.fn() };

        const done = initializeSystem({}, callbacks);
        await vi.advanceTimersByTimeAsync(5000);
        await done;

        expect(callbacks.onReady).toHaveBeenCalledTimes(1);
        expect(heartbeat).toHaveBeenCalledTimes(1);
    });
});
