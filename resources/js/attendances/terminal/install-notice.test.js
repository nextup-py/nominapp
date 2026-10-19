import { describe, it, expect, vi } from 'vitest';
import { describeInstallNotice, createInstallNotice, NOTICE_TEXTS } from './install-notice.js';

/** Elemento falso con classList y listeners. */
function fakeEl(dataset = {}) {
    const classes = new Set(['hidden']);
    const handlers = {};
    return {
        dataset,
        textContent: '',
        classList: {
            toggle: (name, force) => { (force ? classes.add(name) : classes.delete(name)); },
            contains: (name) => classes.has(name),
        },
        addEventListener: (event, handler) => { handlers[event] = handler; },
        click: () => handlers.click?.({ stopPropagation: () => {} }),
    };
}

function setup({ mode = 'browser', dismissed = false, outcome = 'accepted' } = {}) {
    const state = { dismissed };
    const elements = { notice: fakeEl(), text: fakeEl(), installButtons: [fakeEl({ scope: 'notice' }), fakeEl({ scope: 'menu' })], dismissButton: fakeEl() };
    const triggerInstall = vi.fn(async () => outcome);
    const dismiss = vi.fn(() => { state.dismissed = true; });
    const controller = createInstallNotice({ getDisplayMode: () => mode, isDismissed: () => state.dismissed, dismiss, triggerInstall, elements });

    return { controller, elements, triggerInstall, dismiss };
}

describe('describeInstallNotice', () => {
    it('avisa solo en una pestaña del navegador', () => {
        expect(describeInstallNotice({ displayMode: 'browser', canInstall: false, dismissed: false })).toEqual({ text: NOTICE_TEXTS.manual, showInstall: false });
        expect(describeInstallNotice({ displayMode: 'browser', canInstall: true, dismissed: false })).toEqual({ text: NOTICE_TEXTS.installable, showInstall: true });
    });

    it.each(['standalone', 'fullscreen', 'minimal-ui', null])('no avisa con el modo %s', (displayMode) => {
        expect(describeInstallNotice({ displayMode, canInstall: true, dismissed: false })).toBeNull();
    });

    it('no avisa si ya se ocultó en esta sesión', () => {
        expect(describeInstallNotice({ displayMode: 'browser', canInstall: true, dismissed: true })).toBeNull();
    });
});

describe('createInstallNotice', () => {
    it('en una pestaña muestra el aviso con el texto manual mientras el navegador no ofrezca instalar', () => {
        const { controller, elements } = setup();

        controller.render();

        expect(elements.notice.classList.contains('hidden')).toBe(false);
        expect(elements.text.textContent).toBe(NOTICE_TEXTS.manual);
        expect(elements.installButtons.every((button) => button.classList.contains('hidden'))).toBe(true);
    });

    it('cuando el navegador ofrece instalar muestra el botón del aviso y el del menú', () => {
        const { controller, elements } = setup();

        controller.setCanInstall(true);

        expect(elements.text.textContent).toBe(NOTICE_TEXTS.installable);
        expect(elements.installButtons.every((button) => !button.classList.contains('hidden'))).toBe(true);
    });

    it('no muestra nada si ya corre como app, aunque el navegador ofrezca instalar', () => {
        const { controller, elements } = setup({ mode: 'standalone' });

        controller.setCanInstall(true);

        expect(elements.notice.classList.contains('hidden')).toBe(true);
        expect(elements.installButtons.every((button) => button.classList.contains('hidden'))).toBe(true);
    });

    it('«Ocultar» esconde el aviso pero el botón del menú sigue disponible', () => {
        const { controller, elements, dismiss } = setup();
        controller.setCanInstall(true);

        elements.dismissButton.click();

        expect(dismiss).toHaveBeenCalledTimes(1);
        expect(elements.notice.classList.contains('hidden')).toBe(true);
        expect(elements.installButtons[0].classList.contains('hidden')).toBe(true); // el del aviso
        expect(elements.installButtons[1].classList.contains('hidden')).toBe(false); // el del menú
    });

    it('al aceptar la instalación deja de ofrecerla; si la rechaza, puede volver a intentarlo', async () => {
        const accepted = setup({ outcome: 'accepted' });
        accepted.controller.setCanInstall(true);
        await accepted.elements.installButtons[1].click();
        await Promise.resolve();
        expect(accepted.triggerInstall).toHaveBeenCalledTimes(1);
        expect(accepted.elements.installButtons[1].classList.contains('hidden')).toBe(true);

        const rejected = setup({ outcome: 'dismissed' });
        rejected.controller.setCanInstall(true);
        await rejected.controller.install();
        expect(rejected.elements.installButtons[1].classList.contains('hidden')).toBe(false);
    });
});
