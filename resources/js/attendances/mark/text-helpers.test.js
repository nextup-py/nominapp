import { describe, expect, it, vi } from 'vitest';
import { translateEventType, buildDetailedError } from './text-helpers.js';

describe('translateEventType', () => {
    it('traduce cada tipo de evento conocido', () => {
        expect(translateEventType('check_in')).toBe('Entrada');
        expect(translateEventType('break_start')).toBe('Inicio de descanso');
        expect(translateEventType('break_end')).toBe('Fin de descanso');
        expect(translateEventType('check_out')).toBe('Salida');
    });

    it('devuelve el valor recibido cuando el tipo no es reconocido', () => {
        expect(translateEventType('unknown_type')).toBe('unknown_type');
    });

    it('devuelve un guion largo cuando el valor es null/undefined', () => {
        expect(translateEventType(null)).toBe('—');
        expect(translateEventType(undefined)).toBe('—');
    });
});

describe('buildDetailedError', () => {
    it('devuelve el mensaje genérico cuando no hay mensaje crudo', () => {
        expect(buildDetailedError(null)).toBe('No se pudo completar la marcación. Por favor, intente nuevamente.');
    });

    it('prioriza el aviso de perfil no sincronizado sobre cualquier otra rama', () => {
        expect(buildDetailedError('Todavía no se sincronizó tu perfil — conectate a internet al menos una vez.'))
            .toBe('Tu perfil todavía no se sincronizó con este dispositivo. Conectate a internet y volvé a intentar en unos segundos.');
    });

    it('detecta rostro ambiguo', () => {
        expect(buildDetailedError('Rostro ambiguo. Por favor, reposicione su cara e intente de nuevo.'))
            .toContain('múltiples rostros');
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

    it('detecta falta de eventos permitidos', () => {
        expect(buildDetailedError('No allowed events for this employee'))
            .toContain('No hay tipos de marcación disponibles');
    });

    it('cae al mensaje genérico para errores no reconocidos', () => {
        expect(buildDetailedError('algo totalmente inesperado')).toBe('Ocurrió un error inesperado. Por favor, intente nuevamente.');
    });
});
