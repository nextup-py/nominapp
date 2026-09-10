import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('./mobile-offline/db.js', () => ({
    getMeta: vi.fn(),
    getOwnEmployee: vi.fn(),
    migrateTokenFromLocalStorage: vi.fn(),
}));

import { handleLinkSubmit } from './device-link.js';

function fakeStorage() {
    const data = {};
    return { getItem: (k) => data[k] ?? null, setItem: (k, v) => { data[k] = v; }, _data: data };
}

describe('handleLinkSubmit', () => {
    let fetchMock;

    beforeEach(() => {
        fetchMock = vi.fn();
        globalThis.fetch = fetchMock;
    });

    it('en éxito guarda el token y employee_id y devuelve datos de branding', async () => {
        fetchMock.mockResolvedValue({
            json: () => Promise.resolve({
                ok: true,
                token: 'tok-123',
                employee: { id: 5, first_name: 'Juan', company_name: 'Empresa Test', company_logo: 'data:image/png;base64,xyz' },
            }),
        });
        const storage = fakeStorage();

        const result = await handleLinkSubmit({ ci: '1234567', birth_date: '1990-05-20' }, '/vincular-dispositivo', 'csrf-token', storage);

        expect(result.ok).toBe(true);
        expect(result.employee.company_name).toBe('Empresa Test');
        expect(storage.getItem('nominapp_mobile_token')).toBe('tok-123');
        expect(storage.getItem('nominapp_mobile_employee_id')).toBe('5');
    });

    it('en error devuelve ok:false con el mensaje del servidor', async () => {
        fetchMock.mockResolvedValue({
            json: () => Promise.resolve({ ok: false, message: 'CI o fecha de nacimiento incorrectos.' }),
        });
        const storage = fakeStorage();

        const result = await handleLinkSubmit({ ci: '0000000', birth_date: '2000-01-01' }, '/vincular-dispositivo', 'csrf-token', storage);

        expect(result.ok).toBe(false);
        expect(result.message).toBe('CI o fecha de nacimiento incorrectos.');
        expect(storage.getItem('nominapp_mobile_token')).toBeNull();
    });

    it('en fallo de red devuelve ok:false con mensaje de conexión', async () => {
        fetchMock.mockRejectedValue(new Error('network down'));
        const storage = fakeStorage();

        const result = await handleLinkSubmit({ ci: '1234567', birth_date: '1990-05-20' }, '/vincular-dispositivo', 'csrf-token', storage);

        expect(result.ok).toBe(false);
        expect(result.message).toBe('Error de conexión. Intente nuevamente.');
    });
});
