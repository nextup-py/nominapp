/**
 * @fileoverview El secreto de polling viaja en el header Authorization (nunca
 * en la URL ni en el cuerpo) y los errores del servidor se traducen a
 * PairingError con el `code` estable.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { requestPairing, pollPairing, PairingError } from './pairing-api.js';

function stubFetch(status, body) {
    const fetchMock = vi.fn().mockResolvedValue({ status, ok: status >= 200 && status < 300, json: () => Promise.resolve(body) });
    vi.stubGlobal('fetch', fetchMock);
    return fetchMock;
}

describe('pairing-api', () => {
    beforeEach(() => vi.clearAllMocks());
    afterEach(() => vi.unstubAllGlobals());

    it('requestPairing: manda el código del terminal por POST y devuelve la solicitud', async () => {
        const fetchMock = stubFetch(201, { ok: true, pairing_code: 'K7M2QX', poll_secret: 's' });

        const data = await requestPairing('abc12345', 'Pixel 7');

        expect(fetchMock).toHaveBeenCalledWith('/api/v1/terminal-pairing', expect.objectContaining({ method: 'POST' }));
        expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({ terminal_code: 'abc12345', device_model_hint: 'Pixel 7' });
        expect(data.pairing_code).toBe('K7M2QX');
    });

    it('requestPairing: terminal desactivado -> PairingError terminal_inactive', async () => {
        stubFetch(403, { ok: false, code: 'terminal_inactive', message: 'Desactivado' });

        await expect(requestPairing('abc12345')).rejects.toMatchObject({ code: 'terminal_inactive', status: 403 });
    });

    it('requestPairing: 429 -> PairingError throttled', async () => {
        stubFetch(429, { message: 'Too Many Attempts.' });

        await expect(requestPairing('abc12345')).rejects.toMatchObject({ code: 'throttled' });
    });

    it('requestPairing: terminal inexistente -> PairingError con el code del servidor', async () => {
        stubFetch(404, { ok: false, code: 'terminal_not_found', message: 'No existe' });

        await expect(requestPairing('x')).rejects.toBeInstanceOf(PairingError);
    });

    it('pollPairing: el secreto va solo en el header Authorization, sin query ni cuerpo', async () => {
        const fetchMock = stubFetch(200, { ok: true, status: 'pending' });

        await pollPairing('mi-secreto');

        const [url, options] = fetchMock.mock.calls[0];
        expect(url).toBe('/api/v1/terminal-pairing/status');
        expect(url).not.toContain('mi-secreto');
        expect(options.headers.Authorization).toBe('Bearer mi-secreto');
        expect(options.body).toBeUndefined();
    });

    it('pollPairing: secreto desconocido (404) se interpreta como estado invalid, no como excepción', async () => {
        stubFetch(404, { ok: false, status: 'invalid' });

        await expect(pollPairing('x')).resolves.toMatchObject({ status: 'invalid' });
    });

    it('pollPairing: terminal desactivado -> PairingError terminal_inactive', async () => {
        stubFetch(403, { ok: false, code: 'terminal_inactive', message: 'Desactivado' });

        await expect(pollPairing('x')).rejects.toMatchObject({ code: 'terminal_inactive' });
    });
});
