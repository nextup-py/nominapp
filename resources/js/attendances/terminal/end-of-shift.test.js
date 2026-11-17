import { describe, it, expect } from 'vitest';
import { withoutBreakStartNearShiftEnd } from './end-of-shift.js';

/** Fecha local a la hora indicada. */
const at = (h, m = 0) => new Date(2026, 9, 19, h, m);

const options = ['break_start', 'check_out'];

describe('withoutBreakStartNearShiftEnd', () => {
    it('quita «Inicio descanso» desde una hora antes de la salida', () => {
        expect(withoutBreakStartNearShiftEnd(options, '18:00', at(17, 0))).toEqual(['check_out']);
        expect(withoutBreakStartNearShiftEnd(options, '18:00', at(17, 59))).toEqual(['check_out']);
    });

    it('lo mantiene durante el resto del turno', () => {
        expect(withoutBreakStartNearShiftEnd(options, '18:00', at(16, 59))).toEqual(options);
        expect(withoutBreakStartNearShiftEnd(options, '18:00', at(12, 0))).toEqual(options);
    });

    it('sigue sin ofrecerlo pasada la hora de salida, hasta 4 horas después', () => {
        expect(withoutBreakStartNearShiftEnd(options, '18:00', at(19, 30))).toEqual(['check_out']);
        expect(withoutBreakStartNearShiftEnd(options, '18:00', at(22, 0))).toEqual(['check_out']);
        expect(withoutBreakStartNearShiftEnd(options, '18:00', at(22, 1))).toEqual(options);
    });

    it('un turno que cruza la medianoche se compara por el camino corto', () => {
        expect(withoutBreakStartNearShiftEnd(options, '07:00', at(6, 30))).toEqual(['check_out']);
        expect(withoutBreakStartNearShiftEnd(options, '07:00', at(2, 0))).toEqual(options);
        expect(withoutBreakStartNearShiftEnd(options, '02:00', at(1, 30))).toEqual(['check_out']);
        expect(withoutBreakStartNearShiftEnd(options, '02:00', at(23, 30))).toEqual(options);
    });

    it('no toca nada sin horario o con una hora inválida', () => {
        expect(withoutBreakStartNearShiftEnd(options, null, at(17, 30))).toEqual(options);
        expect(withoutBreakStartNearShiftEnd(options, 'abc', at(17, 30))).toEqual(options);
    });

    it('no deja al empleado sin salida ni toca otros eventos', () => {
        expect(withoutBreakStartNearShiftEnd(['break_start'], '18:00', at(17, 30))).toEqual(['break_start']);
        expect(withoutBreakStartNearShiftEnd(['check_out'], '18:00', at(17, 30))).toEqual(['check_out']);
        expect(withoutBreakStartNearShiftEnd(['break_end'], '18:00', at(17, 30))).toEqual(['break_end']);
        expect(withoutBreakStartNearShiftEnd(['check_in', 'check_out'], '18:00', at(17, 30))).toEqual(['check_in', 'check_out']);
    });
});
