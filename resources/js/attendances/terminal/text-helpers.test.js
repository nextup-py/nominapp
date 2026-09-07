import { describe, expect, it, vi } from 'vitest';
import { buildDetailedError } from './text-helpers.js';

describe('buildDetailedError', () => {
    it('devuelve el mensaje genérico cuando no hay mensaje crudo', () => {
        expect(buildDetailedError(null)).toBe('No se pudo completar la marcación. Por favor, intente nuevamente.');
    });

    it('detecta rostro no identificado', () => {
        expect(buildDetailedError('No identificado'))
            .toContain('No se pudo reconocer su rostro');
    });

    it('detecta fallas de captura de descriptor', () => {
        expect(buildDetailedError('No se pudo capturar suficientes muestras del rostro.'))
            .toContain('No se detectó un rostro válido');
    });

    it('distingue sin red vs error de servidor en fallas de conexión', () => {
        vi.stubGlobal('navigator', { onLine: false });
        expect(buildDetailedError('Network error')).toBe('Sin conexión a internet. Verifique la red del dispositivo y vuelva a intentar.');

        vi.stubGlobal('navigator', { onLine: true });
        expect(buildDetailedError('Network error')).toBe('Error de conexión al servidor. Verifique que el dispositivo tenga acceso a la red y vuelva a intentar.');

        vi.unstubAllGlobals();
    });

    it('detecta sesión expirada (CSRF/419)', () => {
        expect(buildDetailedError('419 CSRF token mismatch'))
            .toBe('La sesión expiró. Por favor, recargue la página para continuar.');
    });

    it('detecta falta de eventos permitidos', () => {
        expect(buildDetailedError('No allowed events for this employee'))
            .toContain('No hay tipos de marcación disponibles');
    });

    it('devuelve el mensaje crudo tal cual para errores no reconocidos (a diferencia de mark.js)', () => {
        expect(buildDetailedError('algo totalmente inesperado')).toBe('algo totalmente inesperado');
    });
});
