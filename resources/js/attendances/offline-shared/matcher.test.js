/**
 * @fileoverview Tests de caracterización de matcher.js contra su
 * implementación ACTUAL, sin modificar — red de seguridad para la extracción
 * a offline-shared/matcher.js (sub-proyecto C).
 */
import { describe, it, expect } from 'vitest';
import { euclideanDistance, identifyEmployee } from './matcher.js';

function descriptor(fillValue) {
    return Array(128).fill(fillValue);
}

describe('euclideanDistance', () => {
    it('retorna 0 para descriptores idénticos', () => {
        expect(euclideanDistance(descriptor(0.5), descriptor(0.5))).toBe(0);
    });

    it('calcula la distancia euclidiana real entre dos descriptores', () => {
        const a = [0, 0, 0];
        const b = [3, 4, 0];
        // sqrt(3^2 + 4^2 + 0^2) = 5, pero euclideanDistance siempre itera 128 posiciones
        // (a[i]??0 - b[i]??0) — con arrays cortos, las posiciones faltantes valen 0.
        expect(euclideanDistance(a, b)).toBe(5);
    });

    it('trata posiciones faltantes como 0', () => {
        expect(euclideanDistance([], [])).toBe(0);
    });
});

describe('identifyEmployee', () => {
    it('retorna no_candidates si la lista está vacía', () => {
        const result = identifyEmployee(descriptor(0), [], 0.5, 0.1);
        expect(result).toEqual({ employee: null, distance: Infinity, reason: 'no_candidates' });
    });

    it('retorna no_candidates si candidates es null', () => {
        const result = identifyEmployee(descriptor(0), null, 0.5, 0.1);
        expect(result.reason).toBe('no_candidates');
    });

    it('identifica al candidato más cercano si está dentro del umbral y supera el gap', () => {
        const candidates = [
            { id: 1, face_descriptor: descriptor(0) },
            { id: 2, face_descriptor: descriptor(1) },
        ];
        const result = identifyEmployee(descriptor(0), candidates, 0.5, 0.1);
        expect(result.employee.id).toBe(1);
        expect(result.distance).toBe(0);
        expect(result.reason).toBeNull();
    });

    it('retorna no_match si el mejor candidato supera el umbral', () => {
        const candidates = [{ id: 1, face_descriptor: descriptor(5) }];
        const result = identifyEmployee(descriptor(0), candidates, 0.5, 0.1);
        expect(result.employee).toBeNull();
        expect(result.reason).toBe('no_match');
    });

    it('retorna ambiguous si el gap con el segundo candidato es menor al mínimo', () => {
        const candidates = [
            { id: 1, face_descriptor: Array(128).fill(0).map((_, i) => (i === 0 ? 0.1 : 0)) },
            { id: 2, face_descriptor: Array(128).fill(0).map((_, i) => (i === 0 ? 0.15 : 0)) },
        ];
        // liveDescriptor a distancia ~0.1 del candidato 1 y ~0.15 del candidato 2 — gap chico
        const live = Array(128).fill(0);
        const result = identifyEmployee(live, candidates, 0.5, 0.5);
        expect(result.employee).toBeNull();
        expect(result.reason).toBe('ambiguous');
    });

    it('ignora candidatos con face_descriptor inválido (no array de 128)', () => {
        const candidates = [
            { id: 1, face_descriptor: [1, 2, 3] }, // longitud inválida
            { id: 2, face_descriptor: descriptor(0) },
        ];
        const result = identifyEmployee(descriptor(0), candidates, 0.5, 0.1);
        expect(result.employee.id).toBe(2);
    });

    it('con un único candidato, no evalúa el gap (secondBestDist queda Infinity)', () => {
        const candidates = [{ id: 1, face_descriptor: descriptor(0) }];
        const result = identifyEmployee(descriptor(0), candidates, 0.5, 0.01);
        expect(result.employee.id).toBe(1);
        expect(result.reason).toBeNull();
    });
});
