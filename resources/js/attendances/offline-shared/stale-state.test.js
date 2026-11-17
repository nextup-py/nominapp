import { describe, it, expect } from 'vitest';
import { isClosedStateFromAnotherDay, localDateOfInstant } from './stale-state.js';

const localNoon = (y, m, d) => new Date(y, m - 1, d, 12, 0, 0).toISOString();

describe('isClosedStateFromAnotherDay', () => {
    it('una salida de ayer no cuenta hoy', () => {
        expect(isClosedStateFromAnotherDay({ last_event: 'check_out', last_event_at: localNoon(2026, 11, 16) }, '2026-11-17')).toBe(true);
    });

    it('una salida de hoy sí cuenta', () => {
        expect(isClosedStateFromAnotherDay({ last_event: 'check_out', last_event_at: localNoon(2026, 11, 17) }, '2026-11-17')).toBe(false);
    });

    it('sin last_event_at usa la fecha de la última consulta', () => {
        expect(isClosedStateFromAnotherDay({ last_event: 'check_out', shift_date: '2026-11-16' }, '2026-11-17')).toBe(true);
        expect(isClosedStateFromAnotherDay({ last_event: 'check_out', shift_date: '2026-11-17' }, '2026-11-17')).toBe(false);
    });

    it('la hora de la marcación manda sobre la fecha de la consulta (salida de hoy sin conexión)', () => {
        expect(isClosedStateFromAnotherDay({ last_event: 'check_out', last_event_at: localNoon(2026, 11, 17), shift_date: '2026-11-16' }, '2026-11-17')).toBe(false);
    });

    it('una salida sin ninguna fecha se descarta', () => {
        expect(isClosedStateFromAnotherDay({ last_event: 'check_out' }, '2026-11-17')).toBe(true);
    });

    it.each([
        ['sin caché', undefined],
        ['jornada abierta', { last_event: 'check_in', last_event_at: localNoon(2026, 11, 10) }],
        ['sin evento', { last_event: null }],
    ])('no descarta: %s', (_name, cached) => {
        expect(isClosedStateFromAnotherDay(cached, '2026-11-17')).toBe(false);
    });
});

describe('localDateOfInstant', () => {
    it('devuelve null con valores inválidos', () => {
        expect(localDateOfInstant(null)).toBeNull();
        expect(localDateOfInstant('no es fecha')).toBeNull();
    });
});
