import { describe, it, expect, vi } from 'vitest';
import { derivePin, verifyAdminPin, createAdminPinGate, MAX_FAILED_ATTEMPTS, LOCKOUT_MS } from './admin-pin.js';

/** Vector calculado con PHP: hash_pbkdf2('sha256', '1234', 'abcdef0123456789abcdef0123456789', 1000, 64). */
const VECTOR = { pin: '1234', salt: 'abcdef0123456789abcdef0123456789', iterations: 1000, hash: '8ae6c237832642f0dcc4fa07f41821ab4b1f88f7d3e0559cfff7836c76128262' };

describe('derivePin / verifyAdminPin', () => {
    it('deriva igual que PHP (sal como texto, hex de 64 caracteres)', async () => {
        const hash = await derivePin(VECTOR.pin, VECTOR);
        expect(hash).toHaveLength(64);
        expect(hash).toBe(VECTOR.hash);
    });

    it('acepta el PIN correcto (con espacios alrededor) y rechaza uno incorrecto', async () => {
        const config = { salt: VECTOR.salt, iterations: VECTOR.iterations, hash: VECTOR.hash };
        expect(await verifyAdminPin(' 1234 ', config)).toBe(true);
        expect(await verifyAdminPin('1235', config)).toBe(false);
    });

    it('sin configuración válida no verifica nada', async () => {
        expect(await verifyAdminPin('1234', null)).toBe(false);
        expect(await verifyAdminPin('1234', { salt: 'x' })).toBe(false);
    });
});

describe('createAdminPinGate', () => {
    const config = { salt: VECTOR.salt, iterations: VECTOR.iterations, hash: VECTOR.hash };

    it('sin PIN configurado deja pasar sin preguntar', async () => {
        const ask = vi.fn();
        const gate = createAdminPinGate({ getConfig: async () => null, ask });
        expect(await gate.requireAccess()).toEqual({ allowed: true });
        expect(ask).not.toHaveBeenCalled();
    });

    it('deja pasar con el PIN correcto', async () => {
        const gate = createAdminPinGate({ getConfig: async () => config, ask: async () => '1234' });
        expect(await gate.requireAccess()).toEqual({ allowed: true });
    });

    it('cancelar no deja pasar', async () => {
        const gate = createAdminPinGate({ getConfig: async () => config, ask: async () => null });
        expect(await gate.requireAccess()).toEqual({ allowed: false, reason: 'cancelled' });
    });

    it('un PIN incorrecto vuelve a preguntar con el error y luego deja pasar si acierta', async () => {
        const ask = vi.fn().mockResolvedValueOnce('0000').mockResolvedValueOnce('1234');
        const gate = createAdminPinGate({ getConfig: async () => config, ask });
        expect(await gate.requireAccess()).toEqual({ allowed: true });
        expect(ask).toHaveBeenNthCalledWith(1, { error: null });
        expect(ask).toHaveBeenNthCalledWith(2, { error: 'PIN incorrecto.' });
    });

    it('bloquea tras los intentos fallidos y vuelve a permitir pasado el tiempo', async () => {
        let now = 1_000_000;
        const ask = vi.fn().mockResolvedValue('0000');
        const gate = createAdminPinGate({ getConfig: async () => config, ask, now: () => now });

        const locked = await gate.requireAccess();
        expect(ask).toHaveBeenCalledTimes(MAX_FAILED_ATTEMPTS);
        expect(locked).toMatchObject({ allowed: false, reason: 'locked', retryInSeconds: LOCKOUT_MS / 1000 });

        ask.mockClear();
        expect(await gate.requireAccess()).toMatchObject({ allowed: false, reason: 'locked' });
        expect(ask).not.toHaveBeenCalled();

        now += LOCKOUT_MS + 1;
        ask.mockResolvedValue('1234');
        expect(await gate.requireAccess()).toEqual({ allowed: true });
    });
});
