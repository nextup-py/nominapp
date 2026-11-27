/**
 * @fileoverview Selector del motivo de las horas extra al marcar una salida (terminal y celular).
 *
 * Muestra un botón por motivo y una cuenta regresiva: elegir uno lo devuelve; si nadie toca nada, devuelve null y la
 * salida se registra igual sin motivo (no bloquea la marcación; RR.HH. lo ve como «Sin motivo»). Los textos se pintan
 * con `textContent`, nunca como HTML. Sin dependencias del resto del terminal para probarlo de forma aislada.
 */

import { OVERTIME_REASON_TIMEOUT_SECONDS } from './overtime-reason.js';

/**
 * @param {object} deps
 * @param {HTMLElement|null} deps.list - Contenedor donde se dibujan los botones.
 * @param {HTMLElement|null} [deps.countdown] - Texto de la cuenta regresiva.
 * @param {() => void} deps.show - Muestra la pantalla del selector.
 * @param {number} [deps.seconds] - Segundos de espera antes de registrar sin motivo.
 * @param {() => HTMLButtonElement} [deps.createButton] - Crea un botón (inyectable para pruebas sin DOM).
 * @returns {{ask: (reasons: Array<{key: string, label: string}>) => Promise<string|null>}}
 */
export function createOvertimeReasonPrompt({ list, countdown = null, show, seconds = OVERTIME_REASON_TIMEOUT_SECONDS, createButton = () => document.createElement('button') }) {
    /**
     * Pide el motivo y devuelve la clave elegida, o null si se venció la espera (o no hay dónde mostrarlo).
     * @param {Array<{key: string, label: string}>} reasons
     * @returns {Promise<string|null>}
     */
    function ask(reasons) {
        if (!list || !Array.isArray(reasons) || reasons.length === 0) return Promise.resolve(null);

        return new Promise((resolve) => {
            let finished = false;
            let remaining = seconds;
            let handle = null;

            const finish = (value) => {
                if (finished) return;
                finished = true;
                if (handle !== null) clearInterval(handle);
                list.replaceChildren();
                if (countdown) countdown.textContent = '';
                resolve(value);
            };

            const renderCountdown = () => {
                if (countdown) countdown.textContent = `Se registrará sin motivo en ${remaining} s`;
            };

            list.replaceChildren(...reasons.map(({ key, label }) => {
                const button = createButton();
                button.type = 'button';
                button.className = 'overtime-reason-btn';
                button.dataset.reason = key;
                button.textContent = label;
                button.addEventListener('click', () => finish(key));

                return button;
            }));

            show();
            renderCountdown();
            handle = setInterval(() => {
                remaining -= 1;
                if (remaining <= 0) finish(null);
                else renderCountdown();
            }, 1000);
        });
    }

    return { ask };
}
