/**
 * =============================================================================
 * IDENTIDAD DEL DISPOSITIVO
 * =============================================================================
 *
 * @fileoverview Un identificador aleatorio que el propio dispositivo genera la primera vez y conserva. Sirve para
 * distinguir dos equipos que comparten IP y navegador (por ejemplo una PC y un celular abiertos en la misma URL de un
 * terminal): el servidor lo guarda en el historial de dispositivos del terminal y lo compara al pedir una vinculación.
 *
 * Identifica al EQUIPO, no a una persona, y es aleatorio: no deriva de la huella del navegador, del número de serie ni
 * de nada que el navegador no entregue por sí solo. Se guarda en dos lugares (IndexedDB `terminal_meta` y
 * `localStorage`) para sobrevivir a que se pierda uno solo; si se pierden los dos, el equipo pasa a ser un dispositivo
 * nuevo para el servidor, que es lo correcto.
 *
 * Nunca lanza: si no hay dónde guardarlo devuelve un identificador de esta carga, sin persistir.
 */

/** Clave compartida por IndexedDB (`terminal_meta`) y `localStorage`. */
const KEY = 'terminal_device_id';

/** @type {Promise<string>|null} Se resuelve una sola vez por carga de página. */
let cached = null;

/**
 * Genera un identificador aleatorio (UUID v4).
 * @param {Crypto|undefined} [cryptoApi]
 * @returns {string}
 */
export function generateDeviceId(cryptoApi = globalThis.crypto) {
    if (typeof cryptoApi?.randomUUID === 'function') return cryptoApi.randomUUID();

    const bytes = new Uint8Array(16);
    if (typeof cryptoApi?.getRandomValues === 'function') {
        cryptoApi.getRandomValues(bytes);
    } else {
        for (let i = 0; i < bytes.length; i += 1) bytes[i] = Math.floor(Math.random() * 256);
    }

    return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('').replace(/^(.{8})(.{4})(.{4})(.{4})(.{12})$/, '$1-$2-$3-$4-$5');
}

/** @param {unknown} value @returns {boolean} */
function isValid(value) {
    return typeof value === 'string' && /^[A-Za-z0-9-]{8,64}$/.test(value);
}

/**
 * Devuelve el identificador del dispositivo, creándolo y guardándolo la primera vez. Si solo uno de los dos
 * almacenes lo tiene, lo copia al otro.
 * @param {object} deps
 * @param {(key: string) => Promise<any>} deps.getMeta
 * @param {(key: string, value: any) => Promise<void>} deps.setMeta
 * @param {Storage|null} [deps.local]
 * @param {() => string} [deps.generate]
 * @returns {Promise<string>}
 */
export async function resolveDeviceId({ getMeta, setMeta, local = typeof localStorage !== 'undefined' ? localStorage : null, generate = generateDeviceId }) {
    let fromMeta = null;
    let fromLocal = null;

    try { fromMeta = await getMeta(KEY); } catch { /* sin IndexedDB */ }
    try { fromLocal = local?.getItem(KEY) ?? null; } catch { /* sin localStorage */ }

    const id = [fromMeta, fromLocal].find(isValid) ?? generate();

    if (fromMeta !== id) {
        try { await setMeta(KEY, id); } catch { /* se informa igual, aunque no se pueda guardar */ }
    }
    if (fromLocal !== id) {
        try { local?.setItem(KEY, id); } catch { /* idem */ }
    }

    return id;
}

/**
 * Identificador de este dispositivo (una sola resolución por carga de página).
 * @param {Parameters<typeof resolveDeviceId>[0]} deps
 * @returns {Promise<string>}
 */
export function getDeviceId(deps) {
    cached ??= resolveDeviceId(deps).catch(() => generateDeviceId());

    return cached;
}

/** Solo para tests: descarta el identificador resuelto en esta carga. */
export function resetDeviceIdCache() {
    cached = null;
}
