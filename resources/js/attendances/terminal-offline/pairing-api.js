/**
 * =============================================================================
 * API PÚBLICA DE VINCULACIÓN POR CÓDIGO (routes/api.php, v1/terminal-pairing)
 * =============================================================================
 *
 * @fileoverview Cliente delgado de los dos endpoints públicos con los que un
 * terminal SIN token pide vincularse: crear la solicitud (devuelve el código a
 * mostrar y un secreto de polling) y consultar su estado. El secreto viaja en
 * el header `Authorization` — nunca en la URL ni en la query (quedarían en los
 * access logs del servidor). A diferencia de sync.js no usa el token Sanctum,
 * que justamente todavía no existe.
 */

const API_BASE = '/api/v1/terminal-pairing';

/** Error de la API de vinculación con el `code` estable del servidor (ej. `terminal_inactive`) y el status HTTP. */
export class PairingError extends Error {
    /**
     * @param {string} message
     * @param {string|null} [code]
     * @param {number|null} [status]
     */
    constructor(message, code = null, status = null) {
        super(message);
        this.code = code;
        this.status = status;
    }
}

/**
 * Convierte respuestas no exitosas en PairingError. 404/422 de `status` no lo son:
 * el caller los interpreta como estado (`invalid`).
 * @param {Response} response
 * @param {any} data
 */
function throwIfFailed(response, data) {
    if (response.status === 429) {
        throw new PairingError('Demasiados intentos. Esperá un momento.', 'throttled', 429);
    }
    if (response.status === 403 && data?.code === 'terminal_inactive') {
        throw new PairingError(data.message || 'Este terminal fue desactivado.', 'terminal_inactive', 403);
    }
    if (response.status >= 500) {
        throw new PairingError('El servidor no está disponible.', 'server_error', response.status);
    }
}

/**
 * Crea una solicitud de vinculación para el terminal.
 * @param {string} terminalCode - Código público del terminal (el de la URL /terminal/{code}).
 * @param {string|null} [deviceModelHint] - Modelo detectado vía Client Hints (opcional).
 * @returns {Promise<{ok: boolean, pairing_code: string, poll_secret: string, expires_at: string, poll_interval_seconds: number}>}
 * @throws {PairingError}
 */
export async function requestPairing(terminalCode, deviceModelHint = null) {
    const response = await fetch(API_BASE, {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({ terminal_code: terminalCode, device_model_hint: deviceModelHint }),
    });
    const data = await response.json().catch(() => ({}));

    throwIfFailed(response, data);
    if (!response.ok) {
        throw new PairingError(data.message || 'No se pudo pedir el código de vinculación.', data.code ?? null, response.status);
    }

    return data;
}

/**
 * Consulta el estado de la solicitud. Si fue aprobada, la respuesta trae el
 * token Sanctum (una sola vez) y el terminal.
 * @param {string} secret - Secreto de polling devuelto al crear la solicitud.
 * @returns {Promise<{status: 'pending'|'claimed'|'denied'|'expired'|'invalid', token?: string, terminal?: {id: number, code: string, name: string, branch_id: number|null}, expires_at?: string}>}
 * @throws {PairingError}
 */
export async function pollPairing(secret) {
    const response = await fetch(`${API_BASE}/status`, {
        method: 'POST',
        headers: { Accept: 'application/json', Authorization: `Bearer ${secret}` },
    });
    const data = await response.json().catch(() => ({}));

    throwIfFailed(response, data);

    return { status: 'invalid', ...data };
}
