import { beforeEach, describe, expect, it } from 'vitest';
import {
    COUNTED_FAILURE_REASONS,
    MAX_PLAUSIBLE_MS,
    MAX_SAMPLES,
    getIdentificationStats,
    getIdentificationTotals,
    recordIdentification,
    recordIdentificationFailure,
    resetIdentificationStats,
} from './identification-stats.js';

describe('identification-stats', () => {
    beforeEach(() => resetIdentificationStats());

    it('sin muestras no informa nada', () => {
        expect(getIdentificationStats()).toBeNull();
    });

    it('con una muestra, mediana y p90 son esa muestra', () => {
        recordIdentification(2400);

        expect(getIdentificationStats()).toEqual({ samples: 1, median_ms: 2400, p90_ms: 2400 });
    });

    it('calcula mediana y percentil 90 sobre las muestras ordenadas', () => {
        [1000, 9000, 2000, 3000, 4000, 5000, 6000, 7000, 8000, 10000].forEach(recordIdentification);

        const stats = getIdentificationStats();

        expect(stats.samples).toBe(10);
        expect(stats.median_ms).toBe(5000);
        expect(stats.p90_ms).toBe(9000);
    });

    it('una identificación lenta aislada no mueve la mediana', () => {
        [1500, 1600, 1700, 1800, 60000].forEach(recordIdentification);

        expect(getIdentificationStats().median_ms).toBe(1700);
    });

    it('conserva solo las últimas muestras', () => {
        for (let i = 0; i < MAX_SAMPLES + 5; i++) recordIdentification(1000 + i);

        const stats = getIdentificationStats();

        expect(stats.samples).toBe(MAX_SAMPLES);
        // Quedaron las 20 más recientes: 1005 a 1024.
        expect(stats.median_ms).toBe(1014);
    });

    it('descarta valores que no son una medición válida', () => {
        [NaN, undefined, null, -5, MAX_PLAUSIBLE_MS + 1, Infinity].forEach(recordIdentification);

        expect(getIdentificationStats()).toBeNull();
    });

    it('redondea a milisegundos enteros', () => {
        recordIdentification(1234.6);

        expect(getIdentificationStats().median_ms).toBe(1235);
    });

    describe('contadores acumulados', () => {
        it('sin intentos no informa nada', () => {
            expect(getIdentificationTotals()).toBeNull();
        });

        it('cuenta aciertos con su tiempo y fallos por rostro no reconocido o ambiguo', () => {
            recordIdentification(2000);
            recordIdentification(3000);
            recordIdentificationFailure('no_match');
            recordIdentificationFailure('ambiguous');

            expect(getIdentificationTotals()).toEqual({ attempts: 4, failures: 2, successes: 2, msTotal: 5000 });
        });

        it('los fallos que no hablan de la cámara no cuentan', () => {
            ['no_candidates', 'invalid_descriptor', undefined, null, ''].forEach(recordIdentificationFailure);

            expect(getIdentificationTotals()).toBeNull();
            expect(COUNTED_FAILURE_REASONS).toEqual(['no_match', 'ambiguous']);
        });

        it('una medición inválida no cuenta como intento', () => {
            [NaN, -1, MAX_PLAUSIBLE_MS + 1].forEach(recordIdentification);

            expect(getIdentificationTotals()).toBeNull();
        });

        it('los contadores no se acotan a las últimas muestras', () => {
            for (let i = 0; i < MAX_SAMPLES + 10; i++) recordIdentification(1000);

            expect(getIdentificationTotals().successes).toBe(MAX_SAMPLES + 10);
            expect(getIdentificationStats().samples).toBe(MAX_SAMPLES);
        });

        it('devuelve una copia: modificarla no altera los contadores', () => {
            recordIdentification(1000);
            getIdentificationTotals().attempts = 99;

            expect(getIdentificationTotals().attempts).toBe(1);
        });
    });
});
