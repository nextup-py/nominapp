import { describe, expect, it, vi } from 'vitest';
import {
    captureInstallPrompt,
    triggerInstallPrompt,
    isStandalone,
    isIOS,
    isDismissed,
    dismiss,
} from './install-prompt.js';

describe('isStandalone', () => {
    it('true si navigator.standalone es true (iOS instalado)', () => {
        expect(isStandalone(true, false)).toBe(true);
    });

    it('true si display-mode: standalone matchea (Android/Chrome instalado)', () => {
        expect(isStandalone(undefined, true)).toBe(true);
    });

    it('false si ninguna de las dos señales indica instalado', () => {
        expect(isStandalone(false, false)).toBe(false);
        expect(isStandalone(undefined, false)).toBe(false);
    });
});

describe('isIOS', () => {
    it('detecta iPhone por user agent', () => {
        expect(isIOS('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)')).toBe(true);
    });

    it('detecta iPad clásico por user agent', () => {
        expect(isIOS('Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X)')).toBe(true);
    });

    it('detecta iPadOS moderno (se anuncia como Macintosh con soporte táctil)', () => {
        expect(isIOS('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', 5)).toBe(true);
    });

    it('no confunde un Mac de escritorio (sin touch) con iPadOS', () => {
        expect(isIOS('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', 0)).toBe(false);
    });

    it('no detecta Android como iOS', () => {
        expect(isIOS('Mozilla/5.0 (Linux; Android 14)')).toBe(false);
    });
});

describe('isDismissed / dismiss', () => {
    function fakeStorage() {
        const store = new Map();
        return {
            getItem: (key) => store.get(key) ?? null,
            setItem: (key, value) => store.set(key, value),
        };
    }

    it('no está descartado por defecto', () => {
        const storage = fakeStorage();
        expect(isDismissed('mark', storage)).toBe(false);
    });

    it('queda descartado después de llamar dismiss()', () => {
        const storage = fakeStorage();
        dismiss('mark', storage);
        expect(isDismissed('mark', storage)).toBe(true);
    });

    it('el dismiss de un modo no afecta al otro (namespaced)', () => {
        const storage = fakeStorage();
        dismiss('mark', storage);
        expect(isDismissed('terminal', storage)).toBe(false);
    });
});

describe('captureInstallPrompt / triggerInstallPrompt', () => {
    it('captura el evento, bloquea el default, y lo dispara bajo demanda', async () => {
        const target = new EventTarget();
        const onAvailable = vi.fn();

        captureInstallPrompt(onAvailable, target);

        const preventDefault = vi.fn();
        const prompt = vi.fn();
        const event = new Event('beforeinstallprompt', { cancelable: true });
        event.preventDefault = preventDefault;
        event.prompt = prompt;
        event.userChoice = Promise.resolve({ outcome: 'accepted' });

        target.dispatchEvent(event);

        expect(preventDefault).toHaveBeenCalled();
        expect(onAvailable).toHaveBeenCalledWith(event);

        const outcome = await triggerInstallPrompt();

        expect(prompt).toHaveBeenCalled();
        expect(outcome).toBe('accepted');
    });

    it('devuelve unavailable si nunca se capturó un evento', async () => {
        // Independiente del orden de ejecución: usa una instancia fresca del
        // módulo (vi.resetModules + import dinámico) en vez de depender de que
        // el test anterior ya haya consumido el deferredPrompt module-level.
        vi.resetModules();
        const fresh = await import('./install-prompt.js');

        const outcome = await fresh.triggerInstallPrompt();
        expect(outcome).toBe('unavailable');
    });
});
