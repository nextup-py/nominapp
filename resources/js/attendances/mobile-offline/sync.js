/**
 * =============================================================================
 * SINCRONIZACIÓN CON LA API MÓVIL (routes/api.php, Sanctum)
 * =============================================================================
 *
 * @fileoverview Cliente delgado para /api/v1/mobile/* — heartbeat (que además
 *               devuelve el descriptor facial propio, no hay endpoint de
 *               "sync de empleados" separado como en el terminal), estado del
 *               propio empleado, y envío de eventos. Requiere que el dispositivo
 *               ya haya sido vinculado (token en mobile_meta.api_token, ver
 *               MobileLinkController / device-link.blade.php).
 */

import { getMeta, setMeta, setOwnEmployee, logSync } from './db.js';

const API_BASE = '/api/v1/mobile';

/** Error específico de token ausente/inválido — el caller decide cómo mostrarlo. */
export class MobileAuthError extends Error {}

/**
 * fetch autenticado con el token Sanctum del dispositivo.
 * @param {string} path - Path relativo a /api/v1/mobile (ej. '/heartbeat').
 * @param {RequestInit} [options]
 * @returns {Promise<any>} Cuerpo JSON de la respuesta.
 */
export async function apiFetch(path, options = {}) {
    const token = await getMeta('api_token');
    if (!token) {
        throw new MobileAuthError('Este dispositivo no está vinculado — falta identificarse en /vincular-dispositivo.');
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
        throw new MobileAuthError('El token de este dispositivo fue revocado o expiró — necesita vincularse de nuevo.');
    }

    return response.json();
}

/**
 * Heartbeat: refresca la configuración de reconocimiento facial (umbral/gap)
 * y el descriptor facial propio (propaga automáticamente una re-inscripción
 * sin que el empleado tenga que re-vincular el dispositivo) — no existe un
 * endpoint separado de "sync de empleados" como en el terminal, porque acá solo
 * hace falta cachear un único descriptor: el del dueño del dispositivo.
 * @returns {Promise<void>}
 */
export async function heartbeat() {
    try {
        const data = await apiFetch('/heartbeat', { method: 'POST' });
        if (!data.ok) throw new Error(data.message || 'Error en heartbeat');

        await setOwnEmployee(data.employee);
        await setMeta('face_threshold', data.config.face_threshold);
        await setMeta('face_min_confidence_gap', data.config.face_min_confidence_gap);
        if (data.config.overtime_reasons && typeof data.config.overtime_reasons === 'object') {
            await setMeta('overtime_reasons', data.config.overtime_reasons);
        }
        if (Number.isFinite(data.config.overtime_tolerance_minutes)) {
            await setMeta('overtime_tolerance_minutes', data.config.overtime_tolerance_minutes);
        }
        if (typeof data.config.overtime_reason_required === 'boolean') {
            await setMeta('overtime_reason_required', data.config.overtime_reason_required);
        }
        if (Number.isFinite(data.config.overtime_reason_timeout_seconds)) {
            await setMeta('overtime_reason_timeout_seconds', data.config.overtime_reason_timeout_seconds);
        }
        await setMeta('server_clock_offset_ms', new Date(data.server_time).getTime() - Date.now());
        await setMeta('last_heartbeat_at', Date.now());

        await logSync('heartbeat', true);
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
 * Estado del día (último evento / eventos permitidos) del propio empleado —
 * a diferencia del terminal, no recibe `employeeId` por parámetro: el servidor
 * lo resuelve implícitamente del token. Requiere red — el caller (`queue.js`,
 * `getOwnStatus()`) cae a una resolución local si esta consulta falla.
 * @returns {Promise<{last_event: string|null, last_event_time: string|null, allowed_events: string[]}>}
 */
export async function fetchStatus() {
    const data = await apiFetch('/status');
    if (!data.ok) throw new Error(data.message || 'No se pudo obtener el estado de marcación.');
    return data;
}

/**
 * Pedidos de horas extra propios (recientes), motivos disponibles y si hoy ya tiene un pedido aprobado. Requiere red.
 * @returns {Promise<{ok: boolean, requests: Array<object>, reasons: Object<string, string>, covered_today: boolean}>}
 */
export async function fetchOvertimeRequests() {
    return apiFetch('/overtime-requests');
}

/**
 * Crea un pedido de horas extra (o una regularización de las de hoy). Devuelve la respuesta del servidor, incluso
 * ante un 422 con el mensaje de la regla que no se cumple.
 * @param {{date_from: string, max_hours: number, reason: string, notes?: string, regularization?: boolean}} payload
 * @returns {Promise<{ok: boolean, message?: string}>}
 */
export async function submitOvertimeRequest(payload) {
    return apiFetch('/overtime-requests', { method: 'POST', body: JSON.stringify(payload) });
}

/**
 * Auto-desvinculación: el propio empleado decide desvincular este dispositivo
 * (ej. antes de venderlo o prestarlo) desde /marcar — a diferencia de
 * "Revocar sesión móvil" en EmployeeResource (accionada por un admin). El
 * caller es responsable de vaciar la caché local (ver `resetDb()` en
 * `db.js`) tras una respuesta exitosa.
 * @returns {Promise<void>}
 */
export async function unlinkDevice() {
    const data = await apiFetch('/unlink', { method: 'POST' });
    if (!data.ok) throw new Error(data.message || 'No se pudo desvincular el dispositivo.');
}

/**
 * Envía un lote de eventos de marcación — uno solo si se llama justo tras
 * capturar la marcación con red disponible, o varios si se está vaciando la
 * cola offline acumulada (ver mobile-offline/queue.js). A diferencia del
 * terminal, cada evento NO lleva `employee_id` — el empleado es implícito (el
 * dueño del token).
 * @param {Array<{client_event_id: string, event_type: string, recorded_at: string, location?: object|null}>} events
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
