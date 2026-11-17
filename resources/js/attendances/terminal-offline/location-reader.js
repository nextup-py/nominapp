// resources/js/attendances/terminal-offline/location-reader.js
/**
 * @fileoverview Lectura de la ubicación del equipo para los terminales marcados como «Equipo móvil». El servidor la
 * compara con la de la sucursal y avisa si el equipo sale de su radio. Solo se lee cuando el servidor lo pide
 * (`config.location_check` del latido) y solo se guarda la última lectura: nunca un historial de recorridos.
 *
 * La lectura es **asíncrona y no bloquea el latido**: `refreshIfStale()` dispara una lectura en segundo plano si la
 * última tiene más de `LOCATION_INTERVAL_MS` y `snapshot()` devuelve lo último que se sabe.
 */

/** Cada cuánto se vuelve a leer la ubicación (ms). */
export const LOCATION_INTERVAL_MS = 10 * 60 * 1000;

/** Reintento tras un fallo que no es de permisos (ms). */
export const RETRY_AFTER_ERROR_MS = 60 * 1000;

/** Tiempo máximo que se espera a una lectura (ms). */
export const READ_TIMEOUT_MS = 20 * 1000;

/**
 * Motivo del error de geolocalización en los términos del servidor.
 * @param {{code?: number}} error
 * @returns {'denied'|'unavailable'|'timeout'}
 */
export function describeLocationError(error) {
    if (error?.code === 1) return 'denied';
    if (error?.code === 3) return 'timeout';

    return 'unavailable';
}

/**
 * @param {object} [deps]
 * @param {Geolocation|undefined} [deps.geolocation]
 * @param {() => number} [deps.now]
 * @returns {{refreshIfStale: () => void, snapshot: () => ({lat: number, lng: number, accuracy_m: number|null, read_at: string}|{error: string}|null)}}
 */
export function createLocationReader({ geolocation = globalThis.navigator?.geolocation, now = Date.now } = {}) {
    let last = null;
    let nextAt = 0;
    let reading = false;

    function refreshIfStale() {
        if (reading || now() < nextAt) return;

        if (!geolocation) {
            last = { error: 'unavailable' };
            nextAt = now() + LOCATION_INTERVAL_MS;

            return;
        }

        reading = true;

        try {
            geolocation.getCurrentPosition(
                (position) => {
                    reading = false;
                    last = {
                        lat: position.coords.latitude,
                        lng: position.coords.longitude,
                        accuracy_m: Number.isFinite(position.coords.accuracy) ? Math.round(position.coords.accuracy) : null,
                        read_at: new Date(now()).toISOString(),
                    };
                    nextAt = now() + LOCATION_INTERVAL_MS;
                },
                (error) => {
                    reading = false;
                    last = { error: describeLocationError(error) };
                    nextAt = now() + (last.error === 'denied' ? LOCATION_INTERVAL_MS : RETRY_AFTER_ERROR_MS);
                },
                { enableHighAccuracy: true, timeout: READ_TIMEOUT_MS, maximumAge: 0 },
            );
        } catch {
            reading = false;
            last = { error: 'unavailable' };
            nextAt = now() + LOCATION_INTERVAL_MS;
        }
    }

    return { refreshIfStale, snapshot: () => last };
}
