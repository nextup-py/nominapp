/**
 * =============================================================================
 * TERMINAL.JS — MÁQUINA DE ESTADOS DE PANTALLAS
 * =============================================================================
 *
 * @fileoverview Transición entre las pantallas del terminal (idle,
 * identificación, éxito, error, jornada completa, selección de tipo) y los
 * countdowns de auto-regreso. Extraído de terminal.js como parte de su
 * descomposición en módulos más chicos — mismo comportamiento que el código
 * original.
 *
 * No importa ningún otro módulo de terminal/ que a su vez pueda necesitar
 * volver a llamar acá (identification-flow.js, mark-registration.js) —
 * evita ciclos de importación. `resetTerminal`/`showSuccessScreen`/
 * `showDayComplete` reciben `onReset`/`onCountdownComplete` como callback en
 * vez de llamar directamente al siguiente paso del flujo.
 */

import { setTerminalVideoState } from './ui-feedback.js';
import { buildDetailedError } from './text-helpers.js';
import { playBeep } from '../../shared/audio-feedback.js';

let countdownTimer = null;

/**
 * @param {Record<string, HTMLElement|null|undefined>} screens
 * @param {string} screenName
 */
export function showScreen(screens, screenName) {
    const current = Object.values(screens).find((s) => s && !s.classList.contains('hidden'));
    const next = screens[screenName];

    const activate = () => {
        Object.values(screens).forEach((s) => {
            if (s) { s.classList.remove('screen-leaving'); s.classList.add('hidden'); }
        });
        if (next) next.classList.remove('hidden');
    };

    if (current && current !== next) {
        current.classList.add('screen-leaving');
        setTimeout(activate, 150);
    } else {
        activate();
    }
}

/**
 * @param {Record<string, HTMLElement|null>} screens
 * @param {{successEmployeePhoto?, successEmployeeName, successEmployeeCI, successEventType, successTime, successQueuedNotice?}} dom
 * @param {{first_name?: string, last_name?: string, ci?: string, photo_url?: string}} employee
 * @param {{recorded_at?: string}} markData
 * @param {string} eventType
 * @param {{queued?: boolean}} opts - queued=true: la marcación se guardó localmente pero
 *        todavía no se confirmó con el servidor — se avisa sin asustar al empleado.
 * @param {() => void} onCountdownComplete
 */
export function showSuccessScreen(screens, dom, employee, markData, eventType, { queued = false } = {}, onCountdownComplete) {
    const eventTypeNames = { check_in: 'Entrada', break_start: 'Inicio descanso', break_end: 'Fin descanso', check_out: 'Salida' };
    const fullName = `${employee.first_name || ''} ${employee.last_name || ''}`.trim();

    if (dom.successEmployeePhoto) {
        dom.successEmployeePhoto.src = employee.photo_url || '';
        dom.successEmployeePhoto.alt = fullName;
    }
    dom.successEmployeeName.textContent = fullName || 'Empleado';
    dom.successEmployeeCI.textContent = employee.ci ? `CI: ${employee.ci}` : '';
    dom.successEventType.textContent = eventTypeNames[eventType] || eventType;

    const now = new Date();
    dom.successTime.textContent = now.toLocaleTimeString('es-BO', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });

    if (dom.successQueuedNotice) {
        dom.successQueuedNotice.style.display = queued ? '' : 'none';
    }

    playBeep('success');
    showScreen(screens, 'success');
    startCountdown({ countdownEl: dom.countdownEl, countdownFill: dom.countdownFill }, 5, onCountdownComplete);
}

/**
 * @param {Record<string, HTMLElement|null>} screens
 * @param {HTMLElement|null} errorMessageEl
 * @param {string} message
 */
export function showError(screens, errorMessageEl, message) {
    const detailed = buildDetailedError(message);
    if (errorMessageEl) errorMessageEl.textContent = detailed;
    setTerminalVideoState(null);
    playBeep('error');
    showScreen(screens, 'error');
}

/**
 * @param {Record<string, HTMLElement|null>} screens
 * @param {{dayCompleteEmployeePhoto?, dayCompleteEmployeeName?, dayCompleteCountdownEl, dayCompleteCountdownFill}} dom
 * @param {{first_name?: string, last_name?: string, photo_url?: string}} employee
 * @param {() => void} onCountdownComplete
 */
export function showDayComplete(screens, dom, employee, onCountdownComplete) {
    const fullName = `${employee.first_name || ''} ${employee.last_name || ''}`.trim();
    if (dom.dayCompleteEmployeePhoto) {
        dom.dayCompleteEmployeePhoto.src = employee.photo_url || '';
        dom.dayCompleteEmployeePhoto.alt = fullName;
    }
    if (dom.dayCompleteEmployeeName) dom.dayCompleteEmployeeName.textContent = fullName || 'Empleado';
    setTerminalVideoState(null);
    playBeep('success');
    showScreen(screens, 'dayComplete');
    startDayCompleteCountdown({ dayCompleteCountdownEl: dom.dayCompleteCountdownEl, dayCompleteCountdownFill: dom.dayCompleteCountdownFill }, 5, onCountdownComplete);
}

/**
 * @param {{dayCompleteCountdownEl?, dayCompleteCountdownFill?}} dom
 * @param {number} seconds
 * @param {() => void} onComplete
 */
export function startDayCompleteCountdown(dom, seconds, onComplete) {
    let remaining = seconds;
    if (dom.dayCompleteCountdownEl) dom.dayCompleteCountdownEl.textContent = remaining;
    if (dom.dayCompleteCountdownFill) {
        dom.dayCompleteCountdownFill.style.transition = 'none';
        dom.dayCompleteCountdownFill.style.width = '100%';
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                dom.dayCompleteCountdownFill.style.transition = `width ${seconds}s linear`;
                dom.dayCompleteCountdownFill.style.width = '0%';
            });
        });
    }
    countdownTimer = setInterval(() => {
        remaining--;
        if (dom.dayCompleteCountdownEl) dom.dayCompleteCountdownEl.textContent = remaining;
        if (remaining <= 0) {
            clearInterval(countdownTimer);
            onComplete();
        }
    }, 1000);
}

/**
 * @param {{countdownEl?, countdownFill?}} dom
 * @param {number} seconds
 * @param {() => void} onComplete
 */
export function startCountdown(dom, seconds, onComplete) {
    let remaining = seconds;
    if (dom.countdownEl) dom.countdownEl.textContent = remaining;
    if (dom.countdownFill) {
        dom.countdownFill.style.transition = 'none';
        dom.countdownFill.style.width = '100%';
        dom.countdownFill.offsetWidth; // forzar reflow para que la transición arranque desde 100%
        dom.countdownFill.style.transition = `width ${seconds}s linear`;
        dom.countdownFill.style.width = '0%';
    }

    countdownTimer = setInterval(() => {
        remaining--;
        if (dom.countdownEl) dom.countdownEl.textContent = remaining;
        if (remaining <= 0) {
            clearInterval(countdownTimer);
            onComplete();
        }
    }, 1000);
}

/** Cancela el countdown activo (success o day-complete), si hay uno. */
export function stopCountdown() {
    if (countdownTimer) {
        clearInterval(countdownTimer);
        countdownTimer = null;
    }
}

/**
 * @param {Record<string, HTMLElement|null>} screens
 * @param {NodeListOf<Element>|Element[]} typeButtons
 * @param {() => void} onReset
 */
export function resetTerminal(screens, typeButtons, onReset) {
    stopCountdown();

    typeButtons.forEach((btn) => { btn.style.display = ''; btn.disabled = false; });

    const screenTitle = screens.typeSelection?.querySelector('.screen-title');
    if (screenTitle) screenTitle.textContent = 'Marcación';

    setTerminalVideoState(null);

    onReset();
}
