// resources/js/attendances/terminal/sync-control.js
/**
 * @fileoverview Control único de "Sincronizar" del terminal. Antes había tres
 * botones (reposo, menú ⋮, pantalla sin empleados) con la misma lógica copiada
 * y sin un resultado claro. Acá vive una sola ejecución con control de
 * reentrada, el resultado (al día / pendientes / error con motivo) y los
 * contadores que se muestran; el DOM lo pinta `terminal.js` con el resumen que
 * entrega `onChange`. Sin DOM ni red propios: recibe todo por dependencias.
 */

/**
 * Texto relativo corto ("hace 3 min").
 * @param {number} timestamp - ms epoch
 * @param {number} now - ms epoch
 * @returns {string}
 */
export function formatAgo(timestamp, now) {
    const seconds = Math.max(0, Math.round((now - timestamp) / 1000));
    if (seconds < 60) return 'hace un momento';
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `hace ${minutes} min`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `hace ${hours} h`;
    return `hace ${Math.floor(hours / 24)} d`;
}

/**
 * Elige singular o plural (el plural de "marcación" no se arma agregando "es").
 * @param {number} n
 * @param {string} one
 * @param {string} many
 * @returns {string}
 */
function plural(n, one, many) {
    return n === 1 ? one : many;
}

/**
 * Motivo de un intento fallido, en palabras simples para quien está frente al terminal.
 * @param {{isAuth: boolean, online: boolean}} input
 * @returns {{kind: 'offline'|'unlinked'|'server', message: string}}
 */
export function classifySyncError({ isAuth, online }) {
    if (isAuth) return { kind: 'unlinked', message: 'el terminal ya no está vinculado' };
    if (!online) return { kind: 'offline', message: 'sin conexión' };
    return { kind: 'server', message: 'el servidor no respondió' };
}

/**
 * ¿El terminal corre una versión distinta de la vigente? Mismo criterio que la
 * recarga automática (`auto-reload.js`): 'dev' o datos faltantes no cuentan.
 * @param {string|null|undefined} currentVersion
 * @param {string|null|undefined} serverVersion
 * @returns {boolean}
 */
export function isAppOutdated(currentVersion, serverVersion) {
    if (!currentVersion || !serverVersion || currentVersion === 'dev' || serverVersion === 'dev') return false;
    return currentVersion !== serverVersion;
}

/**
 * Arma lo que se muestra: titular, detalle y tono.
 * @param {{
 *   syncing: boolean,
 *   error: {message: string}|null,
 *   pending: number, conflicts: number, employees: number,
 *   lastSyncAt: number|null, now: number, appOutdated: boolean,
 * }} input
 * @returns {{tone: 'busy'|'ok'|'warn'|'error', headline: string, detail: string, badge: 'pending'|'error'|null, appOutdated: boolean}}
 */
export function summarizeSync({ syncing, error, pending, conflicts, employees, lastSyncAt, now, appOutdated }) {
    const parts = [`${employees} ${plural(employees, 'empleado descargado', 'empleados descargados')}`];
    if (pending > 0) parts.push(`${pending} ${plural(pending, 'marcación pendiente', 'marcaciones pendientes')} de enviar`);
    const detail = parts.join(' · ');
    const ago = lastSyncAt ? ` · ${formatAgo(lastSyncAt, now)}` : '';

    if (syncing) return { tone: 'busy', headline: 'Sincronizando…', detail, badge: null, appOutdated };
    if (error) return { tone: 'error', headline: `No se pudo sincronizar: ${error.message}`, detail, badge: 'error', appOutdated };
    if (conflicts > 0) {
        return { tone: 'warn', headline: `${conflicts} ${plural(conflicts, 'marcación requiere', 'marcaciones requieren')} revisión`, detail, badge: 'error', appOutdated };
    }
    if (pending > 0) return { tone: 'warn', headline: `${pending} ${plural(pending, 'pendiente', 'pendientes')} de enviar${ago}`, detail, badge: 'pending', appOutdated };
    return { tone: 'ok', headline: `Al día${ago}`, detail, badge: null, appOutdated };
}

/**
 * @param {{
 *   run: () => Promise<void>,
 *   getCounts: () => Promise<{pending: number, conflicts: number, employees: number, lastSyncAt: number|null, serverVersion?: string|null}>,
 *   onChange: (summary: ReturnType<typeof summarizeSync>) => void,
 *   isAuthError?: (error: unknown) => boolean,
 *   onAuthError?: () => void,
 *   currentVersion?: string|null,
 *   isOnline?: () => boolean,
 *   now?: () => number,
 * }} deps
 */
export function createSyncControl({
    run, getCounts, onChange, isAuthError = () => false, onAuthError = () => {},
    currentVersion = null, isOnline = () => navigator.onLine, now = () => Date.now(),
}) {
    let syncing = false;
    let failure = null;

    /** Recalcula y publica el resumen con los contadores actuales. */
    async function refresh() {
        const counts = await getCounts();
        // Un latido posterior al fallo (sync de fondo) lo deja sin efecto: el error solo vale hasta el siguiente contacto.
        const error = failure && (!counts.lastSyncAt || failure.at >= counts.lastSyncAt) ? failure : null;
        onChange(summarizeSync({
            syncing, error, now: now(),
            pending: counts.pending, conflicts: counts.conflicts, employees: counts.employees, lastSyncAt: counts.lastSyncAt,
            appOutdated: isAppOutdated(currentVersion, counts.serverVersion),
        }));
    }

    return {
        refresh,

        /** @returns {boolean} true mientras hay una sincronización en curso. */
        isSyncing: () => syncing,

        /** Ejecuta una sincronización completa; ignora los toques mientras hay una en curso. */
        async sync() {
            if (syncing) return;
            syncing = true;
            await refresh();
            try {
                await run();
                failure = null;
            } catch (error) {
                const auth = isAuthError(error);
                failure = { ...classifySyncError({ isAuth: auth, online: isOnline() }), at: now() };
                if (auth) onAuthError();
            } finally {
                syncing = false;
                await refresh();
            }
        },
    };
}
