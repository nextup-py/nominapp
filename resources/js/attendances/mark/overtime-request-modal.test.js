import { describe, it, expect } from 'vitest';
import { buildRequestPayload, reasonsToOptions, todayLocal } from './overtime-request-modal.js';

describe('overtime-request-modal', () => {
    it('arma el cuerpo del pedido y descarta lo vacío', () => {
        expect(buildRequestPayload({ date: '2026-03-16', hours: '2,5', reason: 'cash_close', notes: '  ', regularization: false })).toEqual({
            date_from: '2026-03-16',
            max_hours: 2.5,
            reason: 'cash_close',
        });
    });

    it('incluye el detalle y la marca de regularización cuando corresponden', () => {
        expect(buildRequestPayload({ date: '2026-03-16', hours: '1', reason: 'other', notes: ' cierre ', regularization: true })).toEqual({
            date_from: '2026-03-16',
            max_hours: 1,
            reason: 'other',
            notes: 'cierre',
            regularization: true,
        });
    });

    it('convierte los motivos del servidor en opciones y tolera datos inválidos', () => {
        expect(reasonsToOptions({ cash_close: 'Cierre de caja' })).toEqual([{ key: 'cash_close', label: 'Cierre de caja' }]);
        expect(reasonsToOptions(null)).toEqual([]);
    });

    it('formatea la fecha local de hoy', () => {
        expect(todayLocal(new Date(2026, 2, 5))).toBe('2026-03-05');
    });
});
