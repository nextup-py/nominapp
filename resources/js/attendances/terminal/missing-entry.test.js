import { describe, it, expect } from 'vitest';
import { shouldAskEntryOrExit, ENTRY_OR_EXIT_OPTIONS } from './missing-entry.js';

/** Fecha local a la hora indicada. */
const at = (h, m = 0) => new Date(2026, 9, 19, h, m);

const emptyDay = { allowedEvents: ['check_in'], lastEvent: null };

describe('shouldAskEntryOrExit', () => {
    it('pregunta cuando la hora está más cerca del fin que del inicio del turno', () => {
        expect(shouldAskEntryOrExit({ ...emptyDay, expectedCheckIn: '08:00', expectedCheckOut: '17:00', now: at(16, 59) })).toBe(true);
        expect(shouldAskEntryOrExit({ ...emptyDay, expectedCheckIn: '08:00', expectedCheckOut: '16:00', now: at(15, 53) })).toBe(true);
        expect(shouldAskEntryOrExit({ ...emptyDay, expectedCheckIn: '08:00', expectedCheckOut: '18:00', now: at(17, 53) })).toBe(true);
    });

    it('no pregunta a la hora de entrada ni a una llegada tarde', () => {
        expect(shouldAskEntryOrExit({ ...emptyDay, expectedCheckIn: '08:00', expectedCheckOut: '17:00', now: at(8, 5) })).toBe(false);
        expect(shouldAskEntryOrExit({ ...emptyDay, expectedCheckIn: '08:00', expectedCheckOut: '17:00', now: at(10, 0) })).toBe(false);
        expect(shouldAskEntryOrExit({ ...emptyDay, expectedCheckIn: '20:00', expectedCheckOut: '04:00', now: at(23, 9) })).toBe(false);
    });

    it('en el punto medio del turno no pregunta', () => {
        expect(shouldAskEntryOrExit({ ...emptyDay, expectedCheckIn: '08:00', expectedCheckOut: '16:00', now: at(12, 0) })).toBe(false);
        expect(shouldAskEntryOrExit({ ...emptyDay, expectedCheckIn: '08:00', expectedCheckOut: '16:00', now: at(12, 1) })).toBe(true);
    });

    it('un turno que cruza la medianoche se compara por el camino corto', () => {
        const shift = { expectedCheckIn: '17:30', expectedCheckOut: '02:00' };
        expect(shouldAskEntryOrExit({ ...emptyDay, ...shift, now: at(1, 5) })).toBe(true);
        expect(shouldAskEntryOrExit({ ...emptyDay, ...shift, now: at(17, 40) })).toBe(false);
        expect(shouldAskEntryOrExit({ ...emptyDay, ...shift, now: at(21, 0) })).toBe(false);
        expect(shouldAskEntryOrExit({ ...emptyDay, ...shift, now: at(22, 0) })).toBe(true);
    });

    it('sin horario o con horas inválidas mantiene el comportamiento de siempre', () => {
        expect(shouldAskEntryOrExit({ ...emptyDay, expectedCheckIn: null, expectedCheckOut: '17:00', now: at(16, 59) })).toBe(false);
        expect(shouldAskEntryOrExit({ ...emptyDay, expectedCheckIn: '08:00', expectedCheckOut: null, now: at(16, 59) })).toBe(false);
        expect(shouldAskEntryOrExit({ ...emptyDay, expectedCheckIn: '25:00', expectedCheckOut: 'x', now: at(16, 59) })).toBe(false);
    });

    it('solo con el día vacío y la entrada como única opción', () => {
        const base = { expectedCheckIn: '08:00', expectedCheckOut: '17:00', now: at(16, 59) };
        expect(shouldAskEntryOrExit({ ...base, allowedEvents: ['check_in', 'check_out'], lastEvent: 'check_in' })).toBe(false);
        expect(shouldAskEntryOrExit({ ...base, allowedEvents: ['check_in'], lastEvent: 'check_out' })).toBe(false);
        expect(shouldAskEntryOrExit({ ...base, allowedEvents: ['check_in', 'check_out'], lastEvent: null })).toBe(false);
        expect(shouldAskEntryOrExit({ ...base, allowedEvents: [], lastEvent: null })).toBe(false);
    });

    it('ofrece entrada y salida', () => {
        expect(ENTRY_OR_EXIT_OPTIONS).toEqual(['check_in', 'check_out']);
    });
});
