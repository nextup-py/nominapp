/**
 * =============================================================================
 * TERMINAL SIN EMPLEADOS PARA IDENTIFICAR
 * =============================================================================
 *
 * @fileoverview Cuando el terminal no tiene a nadie en su caché de empleados, la
 * cámara no puede reconocer a nadie: en vez de dejarla encendida sin sentido, se
 * muestra una pantalla que explica la causa (en dos niveles: un mensaje simple
 * para el empleado y un detalle técnico para quien instala), reintenta la
 * sincronización cada `RETRY_SECONDS` y vuelve sola a identificación apenas
 * llegan empleados.
 *
 * - `describeEmptyState()` es una función pura (causa → textos).
 * - `createEmptyStateController()` coordina el bloqueo/reanudación con dependencias
 *   inyectadas, para probarlo sin navegador (mismo patrón que remote-commands.js).
 *
 * Las causas se distinguen con lo que el navegador sabe (empleados en caché, si
 * alguna vez sincronizó, si hay conexión) y con el conteo que informa el servidor
 * en el heartbeat (`roster`: empleados activos y con rostro de la sucursal).
 */

/** Segundos entre reintentos automáticos de sincronización mientras el terminal no tiene empleados. */
export const RETRY_SECONDS = 30;

/** Aviso fijo cuando la causa requiere que RR.HH. actúe (el panel ya avisa de estos casos). */
export const HR_NOTICE = 'RR.HH. recibe un aviso en el panel por esta situación.';

/**
 * @typedef {object} EmptyStateInputs
 * @property {number} cachedCount - empleados en la caché local.
 * @property {number|null|undefined} syncedAt - marca de la última sincronización de empleados (null si nunca).
 * @property {boolean} online - hay conexión.
 * @property {{active_employees: number, enrolled_employees: number}|null|undefined} roster - conteo del servidor.
 */

/**
 * @typedef {object} EmptyState
 * @property {'syncing'|'offline_no_data'|'no_active'|'no_face'|'out_of_sync'|'unknown'} kind
 * @property {string} headline - título corto.
 * @property {string} message - mensaje simple, pensado para el empleado.
 * @property {string} detail - detalle técnico, para quien instala (se muestra al abrir "Ver detalle").
 * @property {boolean} notifiesHr - el panel ya avisa a RR.HH. de esta causa.
 */

/**
 * Decide si el terminal está "sin empleados" y por qué.
 * @param {EmptyStateInputs} inputs
 * @returns {EmptyState|null} null si hay empleados en caché (funcionamiento normal).
 */
export function describeEmptyState({ cachedCount, syncedAt, online, roster }) {
    if (cachedCount > 0) return null;

    if (!syncedAt) {
        return online
            ? {
                kind: 'syncing',
                headline: 'Preparando el terminal',
                message: 'Estamos descargando la lista de empleados. Toma unos segundos.',
                detail: 'Es la primera sincronización de este terminal: todavía no descargó ningún empleado.',
                notifiesHr: false,
            }
            : {
                kind: 'offline_no_data',
                headline: 'Sin conexión',
                message: 'Este terminal necesita conexión a internet para empezar a funcionar.',
                detail: 'Todavía no descargó ningún empleado y no hay conexión. Se reintenta solo al volver internet.',
                notifiesHr: false,
            };
    }

    const message = 'Este terminal todavía no está listo para marcar. Avise a RR.HH.';
    const active = Number(roster?.active_employees);
    const enrolled = Number(roster?.enrolled_employees);

    if (roster && Number.isFinite(active) && active === 0) {
        return { kind: 'no_active', headline: 'Terminal sin empleados', message, detail: 'La sucursal no tiene empleados activos.', notifiesHr: false };
    }

    if (roster && Number.isFinite(active) && Number.isFinite(enrolled) && enrolled === 0) {
        return {
            kind: 'no_face',
            headline: 'Terminal sin empleados',
            message,
            detail: `La sucursal tiene ${active} empleado(s) activo(s), pero ninguno con rostro registrado.`,
            notifiesHr: true,
        };
    }

    if (roster && Number.isFinite(enrolled) && enrolled > 0) {
        return {
            kind: 'out_of_sync',
            headline: 'Empleados sin descargar',
            message: 'Este terminal todavía no está listo para marcar. Se está sincronizando; si sigue así, avise a RR.HH.',
            detail: `La sucursal tiene ${enrolled} empleado(s) con rostro registrado, pero este terminal no los descargó.`,
            notifiesHr: true,
        };
    }

    return { kind: 'unknown', headline: 'Terminal sin empleados', message, detail: 'Este terminal no tiene empleados descargados.', notifiesHr: true };
}

/**
 * Controlador del bloqueo "sin empleados": evalúa, bloquea la identificación, cuenta
 * hacia atrás y reintenta la sincronización, y reanuda cuando llegan empleados.
 *
 * @param {object} deps
 * @param {() => Promise<EmptyStateInputs>} deps.getInputs - lee el estado local actual.
 * @param {(state: EmptyState) => void} deps.onBlock - se invoca al pasar a sin empleados (detener cámara y mostrar la pantalla).
 * @param {(state: EmptyState) => void} deps.onUpdate - se invoca si cambia la causa mientras sigue bloqueado.
 * @param {() => void} deps.onResume - se invoca al llegar empleados (volver a identificación).
 * @param {() => Promise<void>} deps.sync - sincroniza (latido + empleados); puede lanzar.
 * @param {(secondsLeft: number, syncing: boolean) => void} [deps.onTick] - para mostrar la cuenta regresiva.
 * @param {() => boolean} [deps.isSuspended] - true si otra condición bloquea el terminal (ej. sin vincular): no se evalúa.
 * @param {number} [deps.retrySeconds]
 * @returns {{refresh: () => Promise<EmptyState|null>, retryNow: () => Promise<void>, isBlocked: () => boolean}}
 */
export function createEmptyStateController({ getInputs, onBlock, onUpdate, onResume, sync, onTick, isSuspended, retrySeconds = RETRY_SECONDS }) {
    let blocked = false;
    let lastKind = null;
    let secondsLeft = retrySeconds;
    let timer = null;
    let syncing = false;

    const stopTimer = () => {
        if (timer) clearInterval(timer);
        timer = null;
    };

    const startTimer = () => {
        if (timer) return;
        secondsLeft = retrySeconds;
        onTick?.(secondsLeft, false);
        timer = setInterval(() => {
            secondsLeft -= 1;
            if (secondsLeft <= 0) {
                secondsLeft = retrySeconds;
                retryNow();
            }
            onTick?.(secondsLeft, syncing);
        }, 1000);
    };

    /** Reevalúa el estado local y bloquea, actualiza o reanuda según corresponda. */
    async function refresh() {
        if (isSuspended?.()) return null;

        const state = describeEmptyState(await getInputs());

        if (!state) {
            if (blocked) {
                blocked = false;
                lastKind = null;
                stopTimer();
                onResume();
            }
            return null;
        }

        if (!blocked) {
            blocked = true;
            lastKind = state.kind;
            onBlock(state);
            startTimer();
        } else if (state.kind !== lastKind) {
            lastKind = state.kind;
            onUpdate(state);
        }

        return state;
    }

    /** Sincroniza ya (botón o fin de cuenta regresiva) y reevalúa; un fallo de red no corta el reintento. */
    async function retryNow() {
        if (syncing) return;
        syncing = true;
        onTick?.(secondsLeft, true);
        try {
            const { online } = await getInputs();
            if (online) await sync();
        } catch {
            // se reintenta en el próximo ciclo; un error de autenticación lo maneja quien inyecta `sync`
        } finally {
            syncing = false;
        }
        secondsLeft = retrySeconds;
        await refresh();
        onTick?.(secondsLeft, false);
    }

    return { refresh, retryNow, isBlocked: () => blocked };
}
