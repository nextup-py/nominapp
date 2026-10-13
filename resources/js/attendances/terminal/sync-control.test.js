import { describe, it, expect, vi } from 'vitest';
import { formatAgo, classifySyncError, isAppOutdated, summarizeSync, createSyncControl } from './sync-control.js';

const base = { syncing: false, error: null, pending: 0, conflicts: 0, employees: 4, lastSyncAt: 1_000_000, now: 1_000_000 + 120_000, appOutdated: false };

describe('formatAgo', () => {
    it.each([
        [10_000, 'hace un momento'],
        [90_000, 'hace 1 min'],
        [3 * 3_600_000, 'hace 3 h'],
        [2 * 86_400_000, 'hace 2 d'],
    ])('%d ms -> %s', (diff, text) => expect(formatAgo(0, diff)).toBe(text));
});

describe('classifySyncError', () => {
    it('distingue desvinculado, sin conexión y servidor', () => {
        expect(classifySyncError({ isAuth: true, online: true }).kind).toBe('unlinked');
        expect(classifySyncError({ isAuth: false, online: false }).kind).toBe('offline');
        expect(classifySyncError({ isAuth: false, online: true }).kind).toBe('server');
    });
});

describe('isAppOutdated', () => {
    it('solo cuenta versiones reales y distintas', () => {
        expect(isAppOutdated('a', 'b')).toBe(true);
        expect(isAppOutdated('a', 'a')).toBe(false);
        expect(isAppOutdated('dev', 'b')).toBe(false);
        expect(isAppOutdated(null, 'b')).toBe(false);
    });
});

describe('summarizeSync', () => {
    it('al día muestra hace cuánto y los empleados descargados', () => {
        const s = summarizeSync(base);
        expect(s).toMatchObject({ tone: 'ok', headline: 'Al día · hace 2 min', detail: '4 empleados descargados', badge: null });
    });

    it('con pendientes avisa y suma el detalle', () => {
        const s = summarizeSync({ ...base, pending: 3 });
        expect(s.tone).toBe('warn');
        expect(s.badge).toBe('pending');
        expect(s.detail).toBe('4 empleados descargados · 3 marcaciones pendientes de enviar');
    });

    it('los conflictos tienen prioridad sobre los pendientes', () => {
        expect(summarizeSync({ ...base, pending: 2, conflicts: 1 }).headline).toBe('1 marcación requiere revisión');
    });

    it('un error muestra el motivo y gana a todo lo demás', () => {
        const s = summarizeSync({ ...base, pending: 2, conflicts: 1, error: { message: 'sin conexión' } });
        expect(s).toMatchObject({ tone: 'error', headline: 'No se pudo sincronizar: sin conexión', badge: 'error' });
    });

    it('mientras sincroniza no muestra resultado', () => {
        expect(summarizeSync({ ...base, syncing: true, error: { message: 'x' } }).headline).toBe('Sincronizando…');
    });

    it('singular con un solo empleado', () => {
        expect(summarizeSync({ ...base, employees: 1 }).detail).toBe('1 empleado descargado');
    });
});

describe('createSyncControl', () => {
    function build(overrides = {}) {
        const counts = { pending: 0, conflicts: 0, employees: 2, lastSyncAt: 100, serverVersion: 'v1' };
        const onChange = vi.fn();
        const deps = {
            run: vi.fn().mockResolvedValue(undefined),
            getCounts: vi.fn().mockResolvedValue(counts),
            onChange,
            now: () => 200,
            isOnline: () => true,
            currentVersion: 'v1',
            ...overrides,
        };
        return { control: createSyncControl(deps), deps, onChange, counts };
    }

    it('publica "sincronizando" y luego el resultado', async () => {
        const { control, onChange } = build();
        await control.sync();
        expect(onChange.mock.calls[0][0].tone).toBe('busy');
        expect(onChange.mock.calls.at(-1)[0].tone).toBe('ok');
    });

    it('ignora un segundo toque mientras hay una en curso', async () => {
        let release;
        const { control, deps } = build({ run: vi.fn(() => new Promise((r) => { release = r; })) });
        const first = control.sync();
        await vi.waitFor(() => expect(release).toBeTypeOf('function'));
        await control.sync();
        release();
        await first;
        expect(deps.run).toHaveBeenCalledTimes(1);
    });

    it('un fallo deja el motivo y se puede reintentar', async () => {
        const run = vi.fn().mockRejectedValueOnce(new Error('x')).mockResolvedValueOnce(undefined);
        const { control, onChange } = build({ run, isOnline: () => false });
        await control.sync();
        expect(onChange.mock.calls.at(-1)[0].headline).toBe('No se pudo sincronizar: sin conexión');
        await control.sync();
        expect(onChange.mock.calls.at(-1)[0].tone).toBe('ok');
    });

    it('un error de autenticación avisa que quedó desvinculado', async () => {
        class AuthError extends Error {}
        const onAuthError = vi.fn();
        const { control, onChange } = build({ run: vi.fn().mockRejectedValue(new AuthError('x')), isAuthError: (e) => e instanceof AuthError, onAuthError });
        await control.sync();
        expect(onAuthError).toHaveBeenCalled();
        expect(onChange.mock.calls.at(-1)[0].headline).toContain('ya no está vinculado');
    });

    it('un contacto posterior (sync de fondo) borra el error', async () => {
        const { control, onChange, deps } = build({ run: vi.fn().mockRejectedValue(new Error('x')) });
        await control.sync();
        deps.getCounts.mockResolvedValue({ pending: 0, conflicts: 0, employees: 2, lastSyncAt: 500, serverVersion: 'v1' });
        await control.refresh();
        expect(onChange.mock.calls.at(-1)[0].tone).toBe('ok');
    });

    it('marca la app desactualizada cuando el servidor informa otra versión', async () => {
        const { control, onChange, deps } = build();
        deps.getCounts.mockResolvedValue({ pending: 0, conflicts: 0, employees: 2, lastSyncAt: 100, serverVersion: 'v2' });
        await control.refresh();
        expect(onChange.mock.calls.at(-1)[0].appOutdated).toBe(true);
    });
});
