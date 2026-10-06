/**
 * =============================================================================
 * SINCRONIZACIÓN CON LA API DE TERMINALES (routes/api.php, Sanctum)
 * =============================================================================
 *
 * @fileoverview Cliente delgado para /api/v1/terminal/* — delta de empleados,
 *               heartbeat, y helper de fetch autenticado reutilizado por el
 *               envío de eventos (ver terminal.js). Requiere que el terminal
 *               ya haya sido provisionado (token en terminal_meta.api_token,
 *               ver TerminalSetupController / terminal-setup.blade.php).
 */

import { getMeta, setMeta, clearRebuildableCaches, applyEmployeesDelta, applyBreakFlags, logSync, countPendingEvents, countConflictEvents, countCachedEmployees } from './db.js';
import { collectDeviceReport } from './device-report.js';

const API_BASE = '/api/v1/terminal';

/** Error específico de token ausente/inválido — el caller decide cómo mostrarlo. */
export class TerminalAuthError extends Error {}

/**
 * fetch autenticado con el token Sanctum del terminal.
 * @param {string} path - Path relativo a /api/v1/terminal (ej. '/heartbeat').
 * @param {RequestInit} [options]
 * @returns {Promise<any>} Cuerpo JSON de la respuesta.
 */
export async function apiFetch(path, options = {}) {
    const token = await getMeta('api_token');
    if (!token) {
        throw new TerminalAuthError('Este terminal no está configurado — falta vincular el dispositivo.');
    }

    const response = await fetch(`${API_BASE}${path}`, {
        ...options,
        headers: {
            Authorization: `Bearer ${token}`,
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...(options.headers || {}),
        },
    });

    if (response.status === 401 || response.status === 403) {
        throw new TerminalAuthError('El token de este terminal fue revocado o expiró — necesita re-provisión.');
    }

    return response.json();
}

/**
 * Trae el delta de empleados/descriptores desde la última sincronización y
 * lo aplica a la caché local, junto con `break_flags` (siempre completo,
 * ver EmployeeDescriptorSyncService::breakFlagsForBranch()).
 * @returns {Promise<{employees: number, tombstones: number}>}
 */
export async function syncEmployees() {
    try {
        const since = await getMeta('last_employee_sync_version');
        const query = since ? `?since=${encodeURIComponent(since)}` : '';
        const data = await apiFetch(`/employees/sync${query}`);

        if (!data.ok) throw new Error(data.message || 'Error al sincronizar empleados');

        await applyEmployeesDelta(data.employees, data.tombstones);
        await applyBreakFlags(data.break_flags);
        await setMeta('last_employee_sync_version', data.sync_version);
        await setMeta('employees_synced_at', Date.now());

        await logSync('employees_sync', true, `${data.employees.length} empleados, ${data.tombstones.length} tombstones`);
        return { employees: data.employees.length, tombstones: data.tombstones.length };
    } catch (error) {
        await logSync('employees_sync', false, error.message);
        throw error;
    }
}

/**
 * Reconstruye la caché de empleados desde cero: primero baja el set completo (si falla,
 * la caché actual queda intacta) y recién entonces borra y vuelve a aplicar. No toca la cola
 * de marcaciones ni el token (ver `clearRebuildableCaches()`).
 * @returns {Promise<{employees: number}>}
 */
export async function rebuildEmployeeCache() {
    const data = await apiFetch('/employees/sync');
    if (!data.ok) throw new Error(data.message || 'No se pudo reconstruir la caché de empleados.');

    await clearRebuildableCaches();
    await applyEmployeesDelta(data.employees, []);
    await applyBreakFlags(data.break_flags);
    await setMeta('last_employee_sync_version', data.sync_version);
    await setMeta('employees_synced_at', Date.now());

    await logSync('employees_sync', true, `caché reconstruida: ${data.employees.length} empleados`);
    return { employees: data.employees.length };
}

/** Confirmaciones de comandos remotos pendientes de viajar en el próximo heartbeat. */
let pendingCommandAcks = [];

/** @type {((commands: Array<{id: number, command: string}>) => unknown)|null} */
let commandHandler = null;

/**
 * Registra el manejador que ejecuta los comandos remotos recibidos en el heartbeat.
 * @param {((commands: Array<{id: number, command: string}>) => unknown)|null} handler
 */
export function setCommandHandler(handler) {
    commandHandler = handler;
}

/**
 * Encola la confirmación de un comando para el próximo heartbeat.
 * @param {{id: number, status: 'done'|'failed', message?: string}} ack
 */
export function queueCommandAck(ack) {
    pendingCommandAcks.push(ack);
}

/**
 * Reporte de estado del dispositivo para el heartbeat. Un fallo al recolectarlo
 * nunca debe impedir el heartbeat: en ese caso viaja vacío.
 * @returns {Promise<Record<string, string|number|boolean>>}
 */
async function buildDeviceReport() {
    try {
        return await collectDeviceReport({
            appVersion: globalThis.window?.terminalData?.app_version ?? null,
            cachedEmployees: await countCachedEmployees(),
            clockOffsetMs: (await getMeta('server_clock_offset_ms')) ?? null,
        });
    } catch {
        return {};
    }
}

/**
 * Heartbeat: mantiene `last_seen_at` vivo en el servidor y refresca la
 * configuración de reconocimiento facial (umbral/gap) usada por el matcher
 * local. También reporta el tamaño actual de la cola offline (pendientes/en
 * conflicto) — así un admin puede detectar en Filament un terminal cuya cola
 * no se vacía, aunque el heartbeat en sí llegue con normalidad (ver
 * `Terminal::sync_queue_status`).
 * @returns {Promise<void>}
 */
export async function heartbeat() {
    try {
        const [pendingEvents, conflictEvents] = await Promise.all([countPendingEvents(), countConflictEvents()]);
        const device = await buildDeviceReport();
        const acks = pendingCommandAcks;
        pendingCommandAcks = [];
        let data;
        try {
            data = await apiFetch('/heartbeat', {
                method: 'POST',
                body: JSON.stringify({ pending_events: pendingEvents, conflict_events: conflictEvents, device, command_acks: acks }),
            });
            if (!data.ok) throw new Error(data.message || 'Error en heartbeat');
        } catch (error) {
            // Las confirmaciones no enviadas se reintentan en el próximo heartbeat.
            pendingCommandAcks = [...acks, ...pendingCommandAcks];
            throw error;
        }

        await setMeta('face_threshold', data.config.face_threshold);
        await setMeta('face_min_confidence_gap', data.config.face_min_confidence_gap);
        await setMeta('server_clock_offset_ms', new Date(data.server_time).getTime() - Date.now());
        await setMeta('last_heartbeat_at', Date.now());

        await logSync('heartbeat', true);

        if (Array.isArray(data.commands) && data.commands.length > 0 && commandHandler) {
            Promise.resolve(commandHandler(data.commands)).catch((error) => console.warn('Comandos remotos fallaron:', error.message));
        }
    } catch (error) {
        await logSync('heartbeat', false, error.message);
        throw error;
    }
}

/**
 * Config de reconocimiento facial vigente (sincronizada vía heartbeat).
 * @returns {Promise<{threshold: number|null, minGap: number|null}>} null si todavía no hubo un heartbeat exitoso.
 */
export async function getFaceConfig() {
    const threshold = await getMeta('face_threshold');
    const minGap = await getMeta('face_min_confidence_gap');
    return { threshold: threshold ?? null, minGap: minGap ?? null };
}

/**
 * Estado del día (último evento / eventos permitidos) para un empleado ya
 * identificado localmente. Requiere red — el caller (`queue.js`,
 * `getEmployeeStatus()`) cae a una resolución local si esta consulta falla.
 * @param {number} employeeId
 * @returns {Promise<{last_event: string|null, last_event_time: string|null, allowed_events: string[]}>}
 */
export async function fetchEmployeeStatus(employeeId) {
    const data = await apiFetch(`/employees/${employeeId}/status`);
    if (!data.ok) throw new Error(data.message || 'No se pudo obtener el estado del empleado.');
    return data;
}

/**
 * Envía un lote de eventos de marcación — uno solo si se llama justo tras
 * capturar la marcación con red disponible, o varios si se está vaciando la
 * cola offline acumulada (ver terminal-offline/queue.js).
 * @param {Array<{client_event_id: string, employee_id: number, event_type: string, recorded_at: string, location?: object|null}>} events
 * @returns {Promise<Array<{client_event_id: string, status: string, event_id?: number, message?: string}>>}
 */
export async function submitEvents(events) {
    const data = await apiFetch('/events/sync', {
        method: 'POST',
        body: JSON.stringify({ events }),
    });
    if (!data.ok) throw new Error(data.message || 'No se pudo registrar la marcación.');
    return data.results;
}
