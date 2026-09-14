import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

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

// Mocks exclusivos de la suite `startIdentificationFlow` de más abajo: ese
// flujo orquesta cámara/pantallas/búsqueda-manual/feedback además del
// matching ya mockeado arriba. Se mockean acá (nivel de módulo, por el
// hoisting de vi.mock) aunque solo los use una suite — las 5 pruebas de
// `identifyEmployeeFromDescriptor` no tocan ninguno de estos módulos.
vi.mock('./camera.js', () => ({
    loadModels: vi.fn().mockResolvedValue({ ok: true }),
    startCamera: vi.fn().mockResolvedValue({ ok: true }),
    stopCamera: vi.fn(),
    startDrawLoop: vi.fn(),
    stopDrawLoop: vi.fn(),
    captureDescriptor: vi.fn(),
    isFaceDetected: vi.fn().mockReturnValue(false),
    isInCooldown: vi.fn().mockReturnValue(false),
    setNotRecognizedCooldown: vi.fn(),
}));
vi.mock('./idle-detection.js', () => ({ resetIdleTimer: vi.fn() }));
vi.mock('./screen-state.js', () => ({
    showScreen: vi.fn(),
    showSuccessScreen: vi.fn(),
    showError: vi.fn(),
    showDayComplete: vi.fn(),
}));
vi.mock('./mark-registration.js', () => ({
    showTypeSelectionForEmployee: vi.fn(),
    registerMark: vi.fn(),
    clearPendingEmployee: vi.fn(),
}));
vi.mock('./ui-feedback.js', () => ({
    setTerminalVideoState: vi.fn(),
    setIdStatusDot: vi.fn(),
    showCaptureProgress: vi.fn(),
    updateCaptureProgress: vi.fn(),
    finishCaptureProgress: vi.fn().mockResolvedValue(undefined),
}));
vi.mock('./manual-search.js', () => ({
    showManualSearchLink: vi.fn(),
    hideManualSearchLink: vi.fn(),
    closeManualSearch: vi.fn(),
}));

import { getCachedEmployees } from '../terminal-offline/db.js';
import { identifyEmployee as matchDescriptor } from '../terminal-offline/matcher.js';
import { getFaceConfig, TerminalAuthError } from '../terminal-offline/sync.js';
import { getEmployeeStatus } from '../terminal-offline/queue.js';
import * as camera from './camera.js';
import {
    identifyEmployeeFromDescriptor,
    setManualCandidate,
    startIdentificationFlow,
    stopAutoIdentification,
    getConsecutiveFailures,
} from './identification-flow.js';

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

        await identifyEmployeeFromDescriptor(new Float32Array(128));

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

describe('startIdentificationFlow', () => {
    /**
     * Refs mínimas que aceptan todos los módulos mockeados (screen-state.js,
     * ui-feedback.js, etc.) sin acceder a propiedades específicas del DOM.
     */
    const refs = {
        screens: {},
        video: {},
        overlay: {},
        ctx: {},
        identificationStatus: null,
        successDom: {},
        errorMessageEl: {},
        dayCompleteDom: {},
        typeSelectionDom: {},
    };

    afterEach(() => {
        // Limpia el setInterval real de captura para que no siga vivo entre
        // tests (startAutoIdentification arranca uno de 1500ms al llegar a
        // startDrawLoop, y esta suite lo alcanza deliberadamente).
        stopAutoIdentification(refs.video);
    });

    it('resetea isProcessing y consecutiveFailures de un ciclo previo', async () => {
        let capturedIsProcessing = null;
        camera.startDrawLoop.mockImplementation((video, overlay, ctx, { isProcessing }) => {
            capturedIsProcessing = isProcessing;
        });

        startIdentificationFlow(refs, { onIdleTimeout: vi.fn() });

        // startAutoIdentification es async y no se espera desde
        // startIdentificationFlow (fire-and-forget) — hay que dejar correr
        // las dos promesas resueltas (loadModels, startCamera) antes de que
        // llegue a startDrawLoop().
        await vi.waitFor(() => expect(camera.startDrawLoop).toHaveBeenCalled());

        expect(capturedIsProcessing).not.toBeNull();
        expect(capturedIsProcessing()).toBe(false);
        expect(getConsecutiveFailures()).toBe(0);
    });
});

describe('startIdentificationFlow — reset tras un ciclo que queda "trabado"', () => {
    /**
     * Refs mínimas — mismas que la suite de arriba, copia local para no
     * acoplar ambas suites entre sí.
     */
    const refs = {
        screens: {},
        video: {},
        overlay: {},
        ctx: {},
        identificationStatus: null,
        successDom: {},
        errorMessageEl: {},
        dayCompleteDom: {},
        typeSelectionDom: {},
    };

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('libera isProcessing en un segundo ciclo aunque el anterior haya terminado en needsProvisioning sin pasar por el finally normal', async () => {
        let capturedInterval = null;
        vi.stubGlobal(
            'setInterval',
            vi.fn((cb) => {
                capturedInterval = cb;
                return 999;
            })
        );
        vi.stubGlobal('clearInterval', vi.fn());

        camera.isFaceDetected.mockReturnValue(true);
        camera.isInCooldown.mockReturnValue(false);
        camera.captureDescriptor.mockResolvedValue(new Float32Array(128));
        getFaceConfig.mockRejectedValue(new TerminalAuthError('sin token'));

        const capturedIsProcessingFns = [];
        camera.startDrawLoop.mockImplementation((video, overlay, ctx, { isProcessing }) => {
            capturedIsProcessingFns.push(isProcessing);
        });

        startIdentificationFlow(refs, { onIdleTimeout: vi.fn() });
        await vi.waitFor(() => expect(capturedInterval).not.toBeNull());

        // Ejecutar manualmente el callback del interval (simula que pasaron
        // 1500ms) — identifyEmployeeFromDescriptor rechaza con
        // TerminalAuthError, lo que dispara la rama needsProvisioning: llama
        // stopAutoIdentification() (limpia identifyInterval a null) ANTES de
        // que el finally intente resetear isProcessing — reproduce el
        // estado "trabado" del bug original.
        await capturedInterval();

        expect(capturedIsProcessingFns[0]()).toBe(true);

        // Segundo ciclo: startIdentificationFlow debe liberar isProcessing.
        startIdentificationFlow(refs, { onIdleTimeout: vi.fn() });
        await vi.waitFor(() => expect(capturedIsProcessingFns.length).toBe(2));

        expect(capturedIsProcessingFns[1]()).toBe(false);
        expect(getConsecutiveFailures()).toBe(0);
    });
});
