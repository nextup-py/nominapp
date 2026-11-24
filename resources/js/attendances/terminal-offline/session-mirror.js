// resources/js/attendances/terminal-offline/session-mirror.js
/**
 * =============================================================================
 * RESPALDO DEL VÍNCULO DEL TERMINAL EN LOCALSTORAGE
 * =============================================================================
 *
 * @fileoverview El token y la identidad del terminal viven en IndexedDB (`terminal_meta`). En algunos celulares esa
 * base se pierde o se corrompe (apagado brusco, batería agotada) mientras `localStorage` sigue intacto: el equipo
 * conserva su `device_id` y su marcador de «estuvo vinculado», pero arranca sin token y pide un código nuevo cada
 * mañana (caso real: un terminal con 4 `link_lost` en una semana, siempre `no_token` con almacenamiento persistente).
 *
 * Este módulo guarda una copia de esas claves en `localStorage` para que `db.js` las restaure si IndexedDB aparece
 * vacía. No amplía la exposición: cualquier script del mismo origen ya podía leer el token en IndexedDB. No guarda
 * empleados, descriptores faciales ni la cola de marcaciones (eso solo vive en IndexedDB).
 *
 * Nunca lanza: sin `localStorage` (o con cuota llena) simplemente no respalda.
 */

/** Clave de `localStorage` con el respaldo (un solo JSON). */
export const MIRROR_KEY = 'nominapp_terminal_session';

/** Claves de `terminal_meta` que se respaldan: token e identidad del terminal al que pertenece este equipo. */
export const MIRRORED_KEYS = ['api_token', 'terminal_id', 'terminal_code', 'terminal_name'];

/**
 * Lee el respaldo completo, o `{}` si no hay o está dañado.
 * @param {Storage|undefined} storage
 * @returns {Record<string, any>}
 */
function readAll(storage) {
    try {
        const raw = storage?.getItem(MIRROR_KEY);
        const parsed = raw ? JSON.parse(raw) : null;

        return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
    } catch {
        return {};
    }
}

/**
 * Refleja un cambio de `terminal_meta` en el respaldo (ignora las claves que no se respaldan). Un valor nulo borra la clave.
 * @param {string} key
 * @param {any} value
 * @param {Storage|undefined} [storage]
 */
export function mirrorSet(key, value, storage = globalThis.localStorage) {
    if (!MIRRORED_KEYS.includes(key)) return;

    try {
        const data = readAll(storage);

        if (value === null || value === undefined) {
            delete data[key];
        } else {
            data[key] = value;
        }

        storage.setItem(MIRROR_KEY, JSON.stringify(data));
    } catch {
        // Sin localStorage o con la cuota llena: no se respalda y el vínculo sigue funcionando desde IndexedDB.
    }
}

/**
 * Devuelve el respaldo solo si tiene token (un respaldo sin token no sirve para restaurar nada).
 * @param {Storage|undefined} [storage]
 * @returns {Record<string, any>|null}
 */
export function mirrorSnapshot(storage = globalThis.localStorage) {
    const data = readAll(storage);

    return typeof data.api_token === 'string' && data.api_token !== ''
        ? Object.fromEntries(MIRRORED_KEYS.filter((key) => data[key] !== undefined && data[key] !== null).map((key) => [key, data[key]]))
        : null;
}

/**
 * Borra el respaldo (cambio de identidad del equipo: ya no debe resucitar el vínculo anterior).
 * @param {Storage|undefined} [storage]
 */
export function mirrorClear(storage = globalThis.localStorage) {
    try {
        storage?.removeItem(MIRROR_KEY);
    } catch {
        // Sin localStorage: nada que borrar.
    }
}
