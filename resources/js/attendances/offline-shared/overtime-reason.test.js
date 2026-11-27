import { describe, it, expect } from 'vitest';
import { shouldAskOvertimeReason, normalizeOvertimeReasons } from './overtime-reason.js';

const reasons = [{ key: 'cash_close', label: 'Cierre de caja' }];
const at = (h, m) => new Date(2026, 10, 27, h, m);

describe('normalizeOvertimeReasons', () => {
    it('convierte el mapa del servidor en una lista', () => {
        expect(normalizeOvertimeReasons({ cash_close: 'Cierre de caja', other: 'Otro' })).toEqual([
            { key: 'cash_close', label: 'Cierre de caja' },
            { key: 'other', label: 'Otro' },
        ]);
    });

    it('devuelve lista vacía sin dato o con dato inválido', () => {
        expect(normalizeOvertimeReasons(null)).toEqual([]);
        expect(normalizeOvertimeReasons('x')).toEqual([]);
        expect(normalizeOvertimeReasons({ a: '' })).toEqual([]);
    });
});

describe('shouldAskOvertimeReason', () => {
    const base = { eventType: 'check_out', expectedCheckOut: '17:00', toleranceMinutes: 15, reasons };

    it('pregunta cuando la salida pasa la hora del horario más la tolerancia', () => {
        expect(shouldAskOvertimeReason({ ...base, now: at(17, 30) })).toBe(true);
    });

    it('no pregunta dentro de la tolerancia ni exactamente en ella', () => {
        expect(shouldAskOvertimeReason({ ...base, now: at(17, 10) })).toBe(false);
        expect(shouldAskOvertimeReason({ ...base, now: at(17, 15) })).toBe(false);
        expect(shouldAskOvertimeReason({ ...base, now: at(17, 16) })).toBe(true);
    });

    it('no pregunta antes de la hora de salida', () => {
        expect(shouldAskOvertimeReason({ ...base, now: at(16, 0) })).toBe(false);
    });

    it('no pregunta para otro tipo de evento', () => {
        expect(shouldAskOvertimeReason({ ...base, eventType: 'break_start', now: at(18, 0) })).toBe(false);
    });

    it('no pregunta sin horario, sin motivos o con una hora inválida', () => {
        expect(shouldAskOvertimeReason({ ...base, expectedCheckOut: null, now: at(18, 0) })).toBe(false);
        expect(shouldAskOvertimeReason({ ...base, expectedCheckOut: 'x', now: at(18, 0) })).toBe(false);
        expect(shouldAskOvertimeReason({ ...base, reasons: [], now: at(18, 0) })).toBe(false);
    });

    it('resuelve un turno que cruza la medianoche', () => {
        const night = { ...base, expectedCheckOut: '06:00' };
        expect(shouldAskOvertimeReason({ ...night, now: at(6, 40) })).toBe(true);
        expect(shouldAskOvertimeReason({ ...night, now: at(5, 50) })).toBe(false);
        expect(shouldAskOvertimeReason({ ...base, expectedCheckOut: '23:30', now: at(0, 20) })).toBe(true);
    });

    it('no pregunta pasadas 12 horas de la salida (otro turno)', () => {
        expect(shouldAskOvertimeReason({ ...base, expectedCheckOut: '06:00', now: at(19, 0) })).toBe(false);
    });

    it('sin tolerancia definida cuenta cualquier minuto de más', () => {
        expect(shouldAskOvertimeReason({ ...base, toleranceMinutes: undefined, now: at(17, 1) })).toBe(true);
    });
});
