// resources/js/attendances/terminal/low-battery-alert.js
/**
 * @fileoverview Cartel visible en cualquier pantalla del terminal cuando la batería está baja y el equipo no está
 * enchufado. Quien está frente al equipo rara vez puede enchufarlo: el texto le pide avisar a la encargada, y el
 * cartel no se descarta para siempre (reaparece a los `DISMISS_MINUTES` minutos). Con la batería crítica no se puede ocultar.
 *
 * Nunca impide marcar: solo informa. El nivel de «baja» lo define Configuración General
 * (`terminal_low_battery_percent`, que llega en el latido; 0 = apagado) y es el mismo que usa el panel para su alerta.
 */

/** Cada cuánto se vuelve a leer el estado de la batería (ms). */
export const CHECK_INTERVAL_MS = 60 * 1000;

/** Minutos que dura el cartel oculto tras tocar «Entendido» (solo con la batería baja, no crítica). */
export const DISMISS_MINUTES = 5;

/** Nivel (%) a partir del cual el cartel es crítico y no se puede ocultar. */
export const CRITICAL_PERCENT = 10;

/**
 * Texto del cartel, o null si no corresponde mostrarlo.
 * @param {object} state
 * @param {boolean|null} state.charging - true si está enchufado, false si no, null si el navegador no informa.
 * @param {number|null} state.level - nivel de batería (0-100) o null.
 * @param {number} state.lowPercent - nivel por debajo del cual se avisa (0 = apagado).
 * @param {number} state.now - instante actual (ms).
 * @param {number} state.dismissedUntil - instante (ms) hasta el que el cartel está oculto.
 * @returns {{text: string, critical: boolean}|null}
 */
export function describeLowBattery({ charging, level, lowPercent, now, dismissedUntil }) {
    if (charging !== false || !Number.isFinite(level) || !(lowPercent > 0) || level >= lowPercent) return null;

    const critical = level <= CRITICAL_PERCENT;
    if (!critical && now < dismissedUntil) return null;

    const text = critical
        ? `Batería casi agotada (${level}%). Avise ahora a la encargada para enchufar el equipo: está por apagarse y dejar de marcar.`
        : `Batería baja (${level}%). Avise a la encargada para enchufar el equipo.`;

    return { text, critical };
}

/**
 * Conecta el cartel con la pantalla y revisa la batería cada minuto. Las dependencias llegan inyectadas.
 * @param {object} deps
 * @param {() => Promise<{charging: boolean|null, level: number|null}>} deps.readBattery - estado actual de la batería.
 * @param {() => Promise<number>} deps.getLowPercent - nivel configurado (0 = apagado).
 * @param {() => number} [deps.now]
 * @param {{banner: HTMLElement|null, text: HTMLElement|null, dismissButton: HTMLElement|null}} deps.elements
 * @returns {{check: () => Promise<void>, start: () => () => void, dismissAlert: () => void, isShowing: () => boolean}}
 */
export function createLowBatteryAlert({ readBattery, getLowPercent, now = Date.now, elements }) {
    let dismissedUntil = 0;
    let last = { charging: null, level: null };
    let lowPercent = 0;
    let showing = false;

    /** Repinta el cartel con el último estado conocido. */
    function render() {
        const alert = describeLowBattery({ ...last, lowPercent, now: now(), dismissedUntil });

        showing = alert !== null;
        elements.banner?.classList.toggle('hidden', !showing);
        elements.banner?.classList.toggle('is-critical', Boolean(alert?.critical));
        elements.dismissButton?.classList.toggle('hidden', Boolean(alert?.critical));
        if (alert && elements.text) elements.text.textContent = alert.text;
    }

    /** Lee la batería y la configuración; nunca lanza (un navegador sin Battery API simplemente no avisa). */
    async function check() {
        try {
            last = await readBattery();
            lowPercent = Number(await getLowPercent()) || 0;
        } catch {
            last = { charging: null, level: null };
        }

        render();
    }

    function dismissAlert() {
        dismissedUntil = now() + DISMISS_MINUTES * 60 * 1000;
        render();
    }

    elements.dismissButton?.addEventListener('click', dismissAlert);

    /** Empieza a revisar (una vez ya y luego cada minuto); devuelve la función que lo detiene. */
    function start() {
        check();
        const timer = setInterval(check, CHECK_INTERVAL_MS);

        return () => clearInterval(timer);
    }

    return { check, start, dismissAlert, isShowing: () => showing };
}
