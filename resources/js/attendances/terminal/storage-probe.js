// resources/js/attendances/terminal/storage-probe.js
/**
 * @fileoverview Prueba de que el navegador realmente conserva los datos del terminal (IndexedDB) y marca local de
 * «este navegador estuvo vinculado». Un navegador en modo privado, con «borrar datos al cerrar» o con el perfil
 * restaurado en cada arranque guarda el token de vinculación y lo pierde enseguida: el terminal entra en un bucle de
 * vinculación sin explicación. La prueba lo detecta (escritura y lectura) y el resultado viaja al servidor junto con la
 * solicitud de vinculación para que el panel registre el motivo.
 *
 * Nunca lanza: si algo falla, el resultado es `available: false`.
 */

/** Clave de IndexedDB (`terminal_meta`) usada solo para la prueba de escritura y lectura. */
const PROBE_KEY = 'storage_probe';
/** Clave de `localStorage` con el código del terminal con el que se vinculó este navegador por última vez. */
const MARKER_KEY = 'terminal_link_marker';

/** Texto del aviso cuando el navegador no conserva datos. */
export const STORAGE_NOTICE_TEXT = 'Este navegador no conserva los datos del terminal: se desvinculará solo y habrá que volver a vincularlo. Revise que no sea una ventana privada ni borre los datos al cerrar.';

/**
 * @typedef {object} StorageProbe
 * @property {boolean} available - La escritura y la lectura de prueba coincidieron.
 * @property {boolean|null} persisted - El navegador marcó el almacenamiento como persistente (null si no informa).
 * @property {number|null} quota_mb - Cuota de almacenamiento en MB (null si no informa).
 */

/**
 * Escribe y lee un valor de prueba en IndexedDB y consulta el estado del almacenamiento del navegador.
 * @param {{getMeta: (key: string) => Promise<any>, setMeta: (key: string, value: any) => Promise<void>, storage?: StorageManager|null}} deps
 * @returns {Promise<StorageProbe>}
 */
export async function probeStorage({ getMeta, setMeta, storage = typeof navigator !== 'undefined' ? navigator.storage : null }) {
    let available = false;
    try {
        const value = `probe-${Date.now()}`;
        await setMeta(PROBE_KEY, value);
        available = (await getMeta(PROBE_KEY)) === value;
    } catch {
        available = false;
    }

    let persisted = null;
    let quotaMb = null;
    try {
        if (storage?.persisted) persisted = Boolean(await storage.persisted());
        if (storage?.estimate) {
            const estimate = await storage.estimate();
            if (typeof estimate?.quota === 'number') quotaMb = Math.round(estimate.quota / (1024 * 1024));
        }
    } catch {
        // El estado del almacenamiento es informativo: sin él se sigue con lo que ya se sabe.
    }

    return { available, persisted, quota_mb: quotaMb };
}

/**
 * Texto del aviso, o null si el navegador conserva los datos.
 * @param {StorageProbe|null} probe
 * @returns {string|null}
 */
export function describeStorageNotice(probe) {
    return probe && probe.available === false ? STORAGE_NOTICE_TEXT : null;
}

/**
 * Recuerda que este navegador se vinculó al terminal. Vive en `localStorage`, aparte del token (IndexedDB): si más
 * tarde el token falta pero la marca sigue, el navegador perdió datos en vez de no haber estado vinculado nunca.
 * @param {string|null|undefined} terminalCode
 * @param {Storage|null} [local]
 */
export function rememberLinked(terminalCode, local = typeof localStorage !== 'undefined' ? localStorage : null) {
    if (!terminalCode || !local) return;
    try {
        local.setItem(MARKER_KEY, JSON.stringify({ code: terminalCode, at: Date.now() }));
    } catch {
        // Sin localStorage no hay marca: el servidor igual detecta la pérdida porque sabe que tenía un token.
    }
}

/**
 * @param {string|null|undefined} terminalCode
 * @param {Storage|null} [local]
 * @returns {boolean} true si este navegador ya se había vinculado a ese terminal.
 */
export function hasLinkMarker(terminalCode, local = typeof localStorage !== 'undefined' ? localStorage : null) {
    if (!terminalCode || !local) return false;
    try {
        return JSON.parse(local.getItem(MARKER_KEY) ?? 'null')?.code === terminalCode;
    } catch {
        return false;
    }
}
