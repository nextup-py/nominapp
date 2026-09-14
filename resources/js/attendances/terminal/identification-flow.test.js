import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../terminal-offline/db.js', () => ({ getCachedEmployees: vi.fn() }));
vi.mock('../terminal-offline/matcher.js', () => ({ identifyEmployee: vi.fn() }));
vi.mock('../terminal-offline/sync.js', () => {
    // Nombre de clase local distinto de `TerminalAuthError` a propósito: si
    // coincide con el nombre que este archivo importa más abajo desde el
    // mismo módulo mockeado, el transform de hoisting de Vitest confunde
    // ambos bindings y lanza "Cannot access '__vi_import_N__' before
    // initialization" al cargar el archivo (reproducido de forma aislada
    // antes de este fix). Mismo comportamiento, solo cambia el nombre local.
    class MockTerminalAuthError extends Error {}
    return { getFaceConfig: vi.fn(), TerminalAuthError: MockTerminalAuthError };
});
vi.mock('../terminal-offline/queue.js', () => ({ getEmployeeStatus: vi.fn() }));

import { getCachedEmployees } from '../terminal-offline/db.js';
import { identifyEmployee as matchDescriptor } from '../terminal-offline/matcher.js';
import { getFaceConfig, TerminalAuthError } from '../terminal-offline/sync.js';
import { getEmployeeStatus } from '../terminal-offline/queue.js';
import { identifyEmployeeFromDescriptor, setManualCandidate } from './identification-flow.js';

describe('identifyEmployeeFromDescriptor', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        setManualCandidate(null);
    });

    it('retorna mensaje de sincronización si threshold/minGap no están listos', async () => {
        getFaceConfig.mockResolvedValue({ threshold: null, minGap: null });

        const result = await identifyEmployeeFromDescriptor(new Float32Array(128), null);

        expect(result).toEqual({ ok: false, message: 'Terminal sincronizando por primera vez, espere un momento.' });
        expect(getCachedEmployees).not.toHaveBeenCalled();
    });

    it('con manualCandidate seteado, acota el matching a ese único candidato', async () => {
        getFaceConfig.mockResolvedValue({ threshold: 0.5, minGap: 0.1 });
        const candidate = { id: 7, first_name: 'Ana' };
        setManualCandidate(candidate);
        matchDescriptor.mockReturnValue({ employee: null, distance: null, reason: 'no_match' });

        await identifyEmployeeFromDescriptor(new Float32Array(128), candidate);

        expect(getCachedEmployees).not.toHaveBeenCalled();
        expect(matchDescriptor).toHaveBeenCalledWith(expect.any(Float32Array), [candidate], 0.5, 0.1);
    });

    it('sin match: retorna el mensaje correcto según el reason', async () => {
        getFaceConfig.mockResolvedValue({ threshold: 0.5, minGap: 0.1 });
        getCachedEmployees.mockResolvedValue([]);
        matchDescriptor.mockReturnValue({ employee: null, distance: null, reason: 'no_candidates' });

        const result = await identifyEmployeeFromDescriptor(new Float32Array(128), null);

        expect(result).toEqual({ ok: false, message: 'No hay empleados sincronizados en este terminal.', reason: 'no_candidates' });
    });

    it('con match: retorna ok:true con los datos del empleado y su estado del día', async () => {
        getFaceConfig.mockResolvedValue({ threshold: 0.5, minGap: 0.1 });
        const employee = { id: 3, first_name: 'Juan', last_name: 'Pérez', ci: '1234567', photo_thumbnail: null };
        getCachedEmployees.mockResolvedValue([employee]);
        matchDescriptor.mockReturnValue({ employee, distance: 0.3, reason: null });
        getEmployeeStatus.mockResolvedValue({ last_event: 'check_in', last_event_time: '08:00', allowed_events: ['break_start', 'check_out'] });

        const result = await identifyEmployeeFromDescriptor(new Float32Array(128), null);

        expect(result.ok).toBe(true);
        expect(result.employee).toEqual({ id: 3, first_name: 'Juan', last_name: 'Pérez', ci: '1234567', photo_url: '/images/default-avatar.png' });
        expect(result.allowed_events).toEqual(['break_start', 'check_out']);
    });

    it('TerminalAuthError: retorna needsProvisioning', async () => {
        getFaceConfig.mockRejectedValue(new TerminalAuthError('sin token'));

        const result = await identifyEmployeeFromDescriptor(new Float32Array(128), null);

        expect(result).toEqual({ ok: false, message: 'sin token', needsProvisioning: true });
    });
});
