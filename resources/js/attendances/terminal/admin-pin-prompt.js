/**
 * Diálogo para tipear el PIN de administrador (vista `components/attendance/terminal-admin-pin.blade.php`).
 * Devuelve una función `ask({error})` que abre el diálogo y se resuelve con el PIN tipeado, o con null si cancelan.
 */

/**
 * @param {{modal: HTMLElement|null, input: HTMLInputElement|null, error: HTMLElement|null, form: HTMLFormElement|null, cancel: HTMLElement|null}} dom
 * @returns {(info: {error: string|null}) => Promise<string|null>}
 */
export function createAdminPinPrompt(dom) {
    const { modal, input, error, form, cancel } = dom;

    return function ask({ error: message = null } = {}) {
        if (!modal || !input || !form) return Promise.resolve(null);

        return new Promise((resolve) => {
            const close = (value) => {
                modal.classList.add('hidden');
                modal.setAttribute('aria-hidden', 'true');
                form.removeEventListener('submit', onSubmit);
                cancel?.removeEventListener('click', onCancel);
                modal.removeEventListener('keydown', onKey);
                input.value = '';
                resolve(value);
            };
            const onSubmit = (event) => {
                event.preventDefault();
                const value = input.value.trim();
                if (value === '') return;
                close(value);
            };
            const onCancel = () => close(null);
            const onKey = (event) => {
                if (event.key === 'Escape') close(null);
            };

            if (error) {
                error.textContent = message ?? '';
                error.classList.toggle('hidden', !message);
            }
            modal.classList.remove('hidden');
            modal.setAttribute('aria-hidden', 'false');
            form.addEventListener('submit', onSubmit);
            cancel?.addEventListener('click', onCancel);
            modal.addEventListener('keydown', onKey);
            input.value = '';
            input.focus();
        });
    };
}
