/**
 * PIN de administrador del terminal.
 *
 * El servidor guarda solo el PIN derivado (PBKDF2-SHA256, `Terminal::deriveAdminPin()`) con su sal y lo manda en el
 * latido (`config.admin_pin`); `sync.js` lo guarda en `terminal_meta.admin_pin`. Aquí se verifica sin conexión con
 * WebCrypto. Protege solo acciones deliberadas del lado del equipo (hoy, «Usar este equipo en este terminal»): quien
 * tenga acceso a las herramientas del navegador puede leer el derivado, así que no reemplaza a la seguridad física.
 */

/** Intentos fallidos seguidos antes del bloqueo temporal. */
export const MAX_FAILED_ATTEMPTS = 3;

/** Milisegundos de bloqueo tras agotar los intentos. */
export const LOCKOUT_MS = 60 * 1000;

/**
 * Deriva el PIN igual que PHP: la sal se usa como texto (sus caracteres hex), no decodificada.
 *
 * @param {string} pin - PIN tipeado.
 * @param {{salt: string, iterations: number}} config - Sal e iteraciones que mandó el servidor.
 * @returns {Promise<string>} Hex de 64 caracteres.
 */
export async function derivePin(pin, config) {
    const encoder = new TextEncoder();
    const key = await crypto.subtle.importKey('raw', encoder.encode(pin), 'PBKDF2', false, ['deriveBits']);
    const bits = await crypto.subtle.deriveBits(
        { name: 'PBKDF2', hash: 'SHA-256', salt: encoder.encode(config.salt), iterations: config.iterations },
        key,
        256,
    );

    return Array.from(new Uint8Array(bits), (byte) => byte.toString(16).padStart(2, '0')).join('');
}

/**
 * Compara dos cadenas del mismo largo sin cortar en la primera diferencia.
 *
 * @param {string} a
 * @param {string} b
 * @returns {boolean}
 */
function sameString(a, b) {
    if (a.length !== b.length) return false;
    let diff = 0;
    for (let i = 0; i < a.length; i += 1) diff |= a.charCodeAt(i) ^ b.charCodeAt(i);

    return diff === 0;
}

/**
 * Si el PIN tipeado es el configurado. Sin configuración válida devuelve false.
 *
 * @param {string} pin
 * @param {{salt: string, hash: string, iterations: number}|null} config
 * @returns {Promise<boolean>}
 */
export async function verifyAdminPin(pin, config) {
    if (!config?.salt || !config?.hash || !Number.isFinite(config.iterations)) return false;

    try {
        return sameString(await derivePin(String(pin).trim(), config), config.hash);
    } catch {
        return false;
    }
}

/**
 * Compuerta que pide el PIN antes de una acción protegida. Sin PIN configurado deja pasar.
 *
 * @param {object} deps
 * @param {() => Promise<{salt: string, hash: string, iterations: number}|null>} deps.getConfig - PIN guardado en el equipo.
 * @param {(info: {error: string|null}) => Promise<string|null>} deps.ask - Muestra el pedido; devuelve el PIN o null si cancelan.
 * @param {() => number} [deps.now] - Reloj (inyectable para pruebas).
 * @returns {{ requireAccess: () => Promise<{allowed: boolean, reason?: 'cancelled'|'locked', retryInSeconds?: number}> }}
 */
export function createAdminPinGate({ getConfig, ask, now = () => Date.now() }) {
    let failures = 0;
    let lockedUntil = 0;

    /** Segundos que faltan para que termine el bloqueo (0 si no hay). */
    const remainingSeconds = () => Math.max(0, Math.ceil((lockedUntil - now()) / 1000));

    return {
        async requireAccess() {
            const config = await getConfig();
            if (!config) return { allowed: true };

            let error = null;
            for (;;) {
                if (now() < lockedUntil) return { allowed: false, reason: 'locked', retryInSeconds: remainingSeconds() };

                const pin = await ask({ error });
                if (pin === null || pin === undefined) return { allowed: false, reason: 'cancelled' };

                if (await verifyAdminPin(pin, config)) {
                    failures = 0;
                    return { allowed: true };
                }

                failures += 1;
                if (failures >= MAX_FAILED_ATTEMPTS) {
                    failures = 0;
                    lockedUntil = now() + LOCKOUT_MS;
                    return { allowed: false, reason: 'locked', retryInSeconds: remainingSeconds() };
                }
                error = 'PIN incorrecto.';
            }
        },
    };
}
