// resources/js/attendances/terminal/device-checklist.js
/**
 * @fileoverview Cartel único «Cómo dejar el equipo» de la pantalla de reposo: junta en una lista corta lo que la
 * persona del local puede corregir para que el terminal no deje de marcar (conexión, app instalada, cargador, pantalla
 * que se apaga, permiso de la cámara, marcaciones sin enviar, app vieja, navegador que no guarda datos). Cada fila que
 * está bien lleva una marca de visto; el cartel aparece solo si algo falla y desaparece cuando todo está en orden.
 *
 * Nunca impide marcar: solo informa. «Ocultar» lo esconde `DISMISS_MINUTES` minutos (no para siempre: quien lo toca
 * suele ser un empleado y el aviso es para quien administra el equipo). El cartel de batería baja sigue aparte porque
 * debe verse en cualquier pantalla.
 */

/** Minutos que dura el cartel oculto tras tocar «Ocultar». */
export const DISMISS_MINUTES = 30;

/** Cada cuánto se vuelve a evaluar el estado (ms). */
export const REFRESH_INTERVAL_MS = 30 * 1000;

/** Cantidad de marcaciones sin enviar a partir de la cual se avisa. */
export const PENDING_THRESHOLD = 20;

/** Textos de las filas que fallan sin un texto propio de otro módulo. */
export const TEXTS = {
    offline: 'Sin conexión a internet. Las marcaciones se guardan en el equipo y se envían al volver la conexión.',
    screen: 'La pantalla puede apagarse y el equipo suspenderse. Revise los ajustes de energía y deje la pantalla encendida.',
    camera: 'La cámara no tiene permiso. Permítala desde el candado de la barra del navegador y recargue.',
    outdated: 'Hay una versión nueva de la app. Use «Actualizar app» (en el menú ⋮).',
};

/** Etiquetas de las filas que están bien. */
export const OK_LABELS = {
    connection: 'Conectado a internet',
    app: 'Instalado como app',
    power: 'Enchufado al cargador',
    screen: 'Pantalla encendida: el equipo no se suspende',
    camera: 'Cámara lista',
};

/**
 * Arma las filas del cartel a partir del estado del equipo. Una fila sin dato (el navegador no informa) no aparece.
 * @param {object} state
 * @param {boolean|null} state.online - `navigator.onLine`.
 * @param {string|null} state.displayMode - `browser`, `standalone`, `fullscreen`... o null si no se sabe.
 * @param {string|null} state.installText - texto de «instalar la app» si corresponde avisar (viene de install-notice.js).
 * @param {string|null} state.chargerText - texto de «enchufar el equipo» si corresponde avisar (viene de charger-notice.js).
 * @param {boolean|null} state.charging - true si está enchufado, false si no, null si el navegador no informa.
 * @param {{active: boolean|null, error: string|null}|null} state.wakeLock - estado del bloqueo de pantalla.
 * @param {'granted'|'denied'|'prompt'|null} state.cameraPermission
 * @param {string|null} state.storageText - texto de «el navegador no conserva datos» si corresponde.
 * @param {number} state.pending - marcaciones sin enviar.
 * @param {boolean} state.outdated - hay una versión nueva de la app.
 * @returns {{rows: Array<{key: string, ok: boolean, text: string}>, needsAttention: boolean}}
 */
export function buildChecklist(state) {
    const rows = [];
    const add = (key, ok, text) => rows.push({ key, ok, text });

    if (state.online === false) add('connection', false, TEXTS.offline);
    else if (state.online === true) add('connection', true, OK_LABELS.connection);

    if (state.installText) add('app', false, state.installText);
    else if (state.displayMode && state.displayMode !== 'browser') add('app', true, OK_LABELS.app);

    if (state.chargerText) add('power', false, state.chargerText);
    else if (state.charging === true) add('power', true, OK_LABELS.power);

    if (state.wakeLock?.active === false && state.wakeLock.error !== 'hidden') add('screen', false, TEXTS.screen);
    else if (state.wakeLock?.active === true) add('screen', true, OK_LABELS.screen);

    if (state.cameraPermission === 'denied') add('camera', false, TEXTS.camera);
    else if (state.cameraPermission === 'granted') add('camera', true, OK_LABELS.camera);

    if (state.storageText) add('storage', false, state.storageText);

    if (state.pending >= PENDING_THRESHOLD) {
        add('pending', false, `Hay ${state.pending} marcaciones sin enviar. Conecte el equipo a internet para que se sincronicen.`);
    }

    if (state.outdated) add('outdated', false, TEXTS.outdated);

    return { rows, needsAttention: rows.some((row) => !row.ok) };
}

/**
 * Conecta el cartel con la pantalla y lo reevalúa cada `REFRESH_INTERVAL_MS`. Las dependencias llegan inyectadas.
 * @param {object} deps
 * @param {() => Promise<object>} deps.collect - estado actual del equipo (ver `buildChecklist`); nunca debe lanzar.
 * @param {() => number} [deps.now]
 * @param {Document} [deps.doc] - inyectable para pruebas (se usa solo para crear las filas).
 * @param {{card: HTMLElement|null, list: HTMLElement|null, dismissButton: HTMLElement|null}} deps.elements
 * @returns {{refresh: () => Promise<void>, start: () => () => void, dismissCard: () => void}}
 */
export function createDeviceChecklist({ collect, now = Date.now, doc = globalThis.document, elements }) {
    let dismissedUntil = 0;
    let lastResult = { rows: [], needsAttention: false };

    /** Pinta las filas con `textContent` (los textos no se interpretan como HTML). */
    function paint(result) {
        const visible = result.needsAttention && now() >= dismissedUntil;

        elements.card?.classList.toggle('hidden', !visible);
        if (!visible || !elements.list) return;

        elements.list.replaceChildren(...result.rows.map((row) => {
            const item = doc.createElement('li');
            item.className = `device-checklist-row device-checklist-row--${row.ok ? 'ok' : 'warn'}`;
            item.dataset.check = row.key;

            const mark = doc.createElement('span');
            mark.className = 'device-checklist-mark';
            mark.setAttribute('aria-hidden', 'true');

            const text = doc.createElement('span');
            text.textContent = row.text;

            item.append(mark, text);

            return item;
        }));
    }

    /** Vuelve a leer el estado y repinta; un error al leer deja el cartel como estaba. */
    async function refresh() {
        try {
            lastResult = buildChecklist(await collect());
        } catch {
            return;
        }

        paint(lastResult);
    }

    function dismissCard() {
        dismissedUntil = now() + DISMISS_MINUTES * 60 * 1000;
        paint(lastResult);
    }

    elements.dismissButton?.addEventListener('click', (event) => {
        event.stopPropagation();
        dismissCard();
    });

    /** Empieza a evaluar (una vez ya y luego periódicamente); devuelve la función que lo detiene. */
    function start() {
        refresh();
        const timer = setInterval(refresh, REFRESH_INTERVAL_MS);

        return () => clearInterval(timer);
    }

    return { refresh, start, dismissCard };
}
