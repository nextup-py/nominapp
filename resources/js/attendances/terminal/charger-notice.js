// resources/js/attendances/terminal/charger-notice.js
/**
 * @fileoverview Aviso discreto en la pantalla de reposo cuando el equipo lleva un rato sin cargador. Un equipo sin
 * enchufar acaba apagándose o suspendiéndose y deja de marcar; la recomendación va a quien está frente al equipo.
 * Sirve también para un equipo que se usa para otra cosa (por ejemplo un celular de ventas) y es terminal a ratos.
 *
 * Nunca impide marcar: solo informa. El tiempo lo define Configuración General (`terminal_no_charger_minutes`, que
 * llega en el latido; 0 = apagado) y el aviso se descarta por `DISMISS_MINUTES` minutos, no para siempre.
 */

/** Cada cuánto se vuelve a leer el estado de la batería (ms). */
export const CHECK_INTERVAL_MS = 60 * 1000;

/** Minutos que dura el aviso oculto tras tocar «Ocultar». */
export const DISMISS_MINUTES = 30;

/**
 * Texto del aviso, o null si no corresponde mostrarlo.
 * @param {object} state
 * @param {boolean|null} state.charging - true si está enchufado, false si no, null si el navegador no informa.
 * @param {number|null} state.level - nivel de batería (0-100) o null.
 * @param {number|null} state.unchargedSince - instante (ms) desde que se lo ve sin cargador, o null.
 * @param {number} state.now - instante actual (ms).
 * @param {number} state.minMinutes - minutos sin cargador a partir de los cuales avisar (0 = apagado).
 * @param {number} state.dismissedUntil - instante (ms) hasta el que el aviso está oculto.
 * @returns {string|null}
 */
export function describeChargerNotice({ charging, level, unchargedSince, now, minMinutes, dismissedUntil }) {
    if (charging !== false || !(minMinutes > 0) || unchargedSince === null || now < dismissedUntil) return null;
    if (now - unchargedSince < minMinutes * 60 * 1000) return null;

    const battery = Number.isFinite(level) ? ` (batería al ${level}%)` : '';

    return `Este equipo no está enchufado${battery}. Conéctelo al cargador y deje la pantalla encendida y esta app visible para que no deje de marcar.`;
}

/**
 * Conecta el aviso con la pantalla y revisa la batería cada minuto. Las dependencias llegan inyectadas.
 * @param {object} deps
 * @param {() => Promise<{charging: boolean|null, level: number|null}>} deps.readBattery - estado actual de la batería.
 * @param {() => Promise<number>} deps.getMinutes - minutos configurados (0 = apagado).
 * @param {() => number} [deps.now]
 * @param {() => boolean} [deps.isSuppressed] - true mientras otro cartel más urgente (batería baja) ya está a la vista.
 * @param {{notice: HTMLElement|null, text: HTMLElement|null, dismissButton: HTMLElement|null}} deps.elements
 * @param {(text: string|null, battery: {charging: boolean|null, level: number|null}) => void} [deps.onChange] - se llama en cada repintado con el texto (null si no corresponde) y el último estado de la batería: lo usa el cartel único de estado del equipo.
 * @returns {{check: () => Promise<void>, start: () => () => void, dismissNotice: () => void}}
 */
export function createChargerNotice({ readBattery, getMinutes, now = Date.now, isSuppressed = () => false, elements, onChange = () => {} }) {
    let unchargedSince = null;
    let dismissedUntil = 0;
    let last = { charging: null, level: null };
    let minutes = 0;

    /** Repinta el aviso con el último estado conocido. */
    function render() {
        const text = isSuppressed() ? null : describeChargerNotice({ ...last, unchargedSince, now: now(), minMinutes: minutes, dismissedUntil });

        elements.notice?.classList.toggle('hidden', text === null);
        if (text !== null && elements.text) elements.text.textContent = text;
        onChange(text, last);
    }

    /** Lee la batería y la configuración; nunca lanza (un navegador sin Battery API simplemente no avisa). */
    async function check() {
        try {
            last = await readBattery();
            minutes = Number(await getMinutes()) || 0;
        } catch {
            last = { charging: null, level: null };
        }

        if (last.charging === false) {
            unchargedSince ??= now();
        } else {
            unchargedSince = null;
        }

        render();
    }

    function dismissNotice() {
        dismissedUntil = now() + DISMISS_MINUTES * 60 * 1000;
        render();
    }

    elements.dismissButton?.addEventListener('click', (event) => {
        event.stopPropagation();
        dismissNotice();
    });

    /** Empieza a revisar (una vez ya y luego cada minuto); devuelve la función que lo detiene. */
    function start() {
        check();
        const timer = setInterval(check, CHECK_INTERVAL_MS);

        return () => clearInterval(timer);
    }

    return { check, start, dismissNotice };
}
