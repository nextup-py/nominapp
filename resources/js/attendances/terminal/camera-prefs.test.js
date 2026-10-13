import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { getCameraPrefs, setCameraPrefs, buildVideoConstraints, openCameraStream, listVideoInputs } from './camera-prefs.js';

describe('camera-prefs', () => {
    /** El entorno de Vitest es `node`: no trae `localStorage`, se simula uno mínimo. */
    beforeEach(() => {
        const store = new Map();
        vi.stubGlobal('localStorage', {
            getItem: (k) => (store.has(k) ? store.get(k) : null),
            setItem: (k, v) => store.set(k, String(v)),
            clear: () => store.clear(),
        });
    });
    afterEach(() => vi.unstubAllGlobals());

    it('por defecto usa la frontal y sin espejo', () => {
        expect(getCameraPrefs()).toEqual({ deviceId: null, mirror: false });
        expect(buildVideoConstraints()).toEqual({ video: { facingMode: 'user' }, audio: false });
    });

    it('guarda cambios parciales y arma las restricciones con la cámara elegida', () => {
        setCameraPrefs({ deviceId: 'cam-2' });
        setCameraPrefs({ mirror: true });

        expect(getCameraPrefs()).toEqual({ deviceId: 'cam-2', mirror: true });
        expect(buildVideoConstraints().video).toEqual({ deviceId: { exact: 'cam-2' } });
    });

    it('un valor guardado corrupto vuelve a los valores por defecto', () => {
        localStorage.setItem('terminal-camera-prefs', '{no es json');
        expect(getCameraPrefs()).toEqual({ deviceId: null, mirror: false });
    });

    it('si la cámara elegida ya no existe, la olvida y usa la frontal', async () => {
        setCameraPrefs({ deviceId: 'cam-vieja', mirror: true });
        const stream = { id: 'ok' };
        const overconstrained = Object.assign(new Error('x'), { name: 'OverconstrainedError' });
        const getUserMedia = vi.fn().mockRejectedValueOnce(overconstrained).mockResolvedValueOnce(stream);

        await expect(openCameraStream({ getUserMedia })).resolves.toBe(stream);

        expect(getUserMedia).toHaveBeenLastCalledWith({ video: { facingMode: 'user' }, audio: false });
        expect(getCameraPrefs()).toEqual({ deviceId: null, mirror: true });
    });

    it('los demás errores (permiso denegado) se propagan', async () => {
        const denied = Object.assign(new Error('x'), { name: 'NotAllowedError' });
        await expect(openCameraStream({ getUserMedia: vi.fn().mockRejectedValue(denied) })).rejects.toBe(denied);
    });

    it('lista solo las cámaras y da nombre a las que no informan etiqueta', async () => {
        const enumerateDevices = vi.fn().mockResolvedValue([
            { kind: 'videoinput', deviceId: 'a', label: 'Frontal' },
            { kind: 'audioinput', deviceId: 'm', label: 'Mic' },
            { kind: 'videoinput', deviceId: 'b', label: '' },
        ]);

        expect(await listVideoInputs({ enumerateDevices })).toEqual([
            { id: 'a', label: 'Frontal' },
            { id: 'b', label: 'Cámara 2' },
        ]);
    });
});
