// resources/js/attendances/offline-shared/submit-in-chunks.js
/**
 * =============================================================================
 * ENVÍO DE EVENTOS EN LOTES (compartido terminal/mobile)
 * =============================================================================
 *
 * @fileoverview El loop de `flushQueue()` que parte la cola en lotes de a lo
 * sumo `chunkSize` (por defecto 200, debe coincidir con el límite del
 * servidor — ver `TerminalEventSyncController`/`MobileEventSyncController`:
 * `'events' => [..., 'max:200']`). Sin este chunking, una cola con más de
 * 200 eventos pendientes se rechaza entera (422) y queda atascada
 * indefinidamente (confirmado con una prueba de carga de 250 eventos en el
 * terminal antes de este fix). Mecánicamente idéntico entre terminal y
 * mobile — solo cambia qué campos lleva cada evento (`employee_id`/`location`),
 * que resuelve el caller vía `submitFn`.
 *
 * No conoce `db.js` de ninguna de las dos carpetas — recibe callbacks para
 * persistir el resultado de cada evento, así el caller decide qué guardar
 * (removeQueuedEvent/markQueuedEventConflict/incrementQueuedEventAttempts,
 * cada uno según su propio `db.js`).
 */

/**
 * No decide qué hacer cuando un lote falla — ni siquiera sabe que
 * `MobileAuthError` existe. Si `submitFn` rechaza, `submitInChunks` relanza
 * el mismo error tal cual, pero le agrega `partialResults` (lo ya
 * sincronizado/en-conflicto de lotes previos) y `remainingEvents` (el lote
 * que falló + los que ni se intentaron) — cada `queue.js` decide en su
 * propio `catch` qué hacer con `remainingEvents` (ej. incrementar
 * `attempts`), porque terminal y mobile difieren ahí: terminal SIEMPRE
 * incrementa `attempts` en cualquier fallo; mobile NO lo hace cuando el
 * error es `MobileAuthError` (token revocado no es un fallo de red
 * reintentable, no tiene sentido contarlo como intento). Ver Steps 3 y 4.
 * @param {Array<object>} events - Eventos pendientes a enviar.
 * @param {(batch: Array<object>) => Promise<Array<{client_event_id: string, status: string, message?: string}>>} submitFn - Envía un lote, retorna los resultados del servidor.
 * @param {{onSynced: (clientEventId: string) => Promise<void>, onConflict: (clientEventId: string, message?: string) => Promise<void>}} callbacks
 * @param {number} [chunkSize]
 * @returns {Promise<{synced: number, conflicts: number, results: Array<object>}>}
 */
export async function submitInChunks(events, submitFn, { onSynced, onConflict }, chunkSize = 200) {
    let synced = 0;
    let conflicts = 0;
    const allResults = [];

    for (let offset = 0; offset < events.length; offset += chunkSize) {
        const batch = events.slice(offset, offset + chunkSize);

        let results;
        try {
            results = await submitFn(batch);
        } catch (error) {
            // El caller decide qué hacer con remainingEvents (ej. incrementar attempts) — acá
            // no se toca nada más que relanzar con el contexto acumulado hasta ahora.
            throw Object.assign(error, {
                partialResults: { synced, conflicts, results: allResults },
                remainingEvents: events.slice(offset),
            });
        }

        for (const result of results) {
            if (result.status === 'synced' || result.status === 'duplicate') {
                await onSynced(result.client_event_id);
                synced++;
            } else {
                await onConflict(result.client_event_id, result.message);
                conflicts++;
            }
        }
        allResults.push(...results);
    }

    return { synced, conflicts, results: allResults };
}
