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
vi.mock('./idle-detection.js', () => ({ acquireWakeLock: vi.fn() }));
vi.mock('../terminal-offline/db.js', () => ({
    migrateTokenFromLocalStorage: vi.fn(),
    getMeta: vi.fn(),
    clearTerminalState: vi.fn(),
}));
vi.mock('../../shared/audio-feedback.js', () => ({ markUserInteracted: vi.fn() }));

class TerminalAuthErrorImpl extends Error {}
vi.mock('../terminal-offline/sync.js', () => ({
    heartbeat: vi.fn(),
    syncEmployees: vi.fn(),
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
    const { startBackgroundSync } = await import('./bootstrap.js');
    return { startBackgroundSync, ...syncMod, ...queueMod, ...statusMod };
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
