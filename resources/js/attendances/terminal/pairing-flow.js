/**
 * =============================================================================
 * TERMINAL.JS — VINCULACIÓN POR CÓDIGO DE EMPAREJAMIENTO
 * =============================================================================
 *
 * @fileoverview Cuando el terminal no tiene token (nunca se vinculó, otro
 * navegador/perfil, datos borrados, token revocado) no se queda mudo: pide un
 * código corto al servidor, lo muestra en pantalla y espera que un admin lo
 * apruebe desde el panel. Al aprobarse, el servidor entrega el token Sanctum
 * (una sola vez) y la página se recarga ya vinculada.
 *
 * Solo escribe `api_token`, `terminal_id` y `terminal_code` en terminal_meta:
 * NUNCA llama a clearTerminalState() — las marcaciones pendientes de la cola
 * offline (`outbound_events`) y la caché de empleados sobreviven a la
 * revinculación. No toca el DOM: recibe callbacks (`ui`) desde terminal.js.
 *
 * La sesión de pairing (código + secreto + vencimiento) se persiste en
 * terminal_meta para sobrevivir a una recarga de página sin pedir otro código
 * (cada código nuevo dispara una notificación a los admins).
 */

import { getMeta, setMeta } from '../terminal-offline/db.js';
import { requestPairing, pollPairing, PairingError } from '../terminal-offline/pairing-api.js';

/** Se consulta el estado cada N ticks de 1 s (3 s en total). */
const POLL_EVERY_TICKS = 3;
/** Reintento al fallar el pedido del código por falta de red. */
const START_RETRY_MS = 5000;
/** Una sesión guardada con menos de esto de vida se descarta y se pide un código nuevo. */
const MIN_REMAINING_MS = 15000;

const SESSION_KEY = 'pairing_session';

let timer = null;
let retryTimer = null;
let active = false;
let polling = false;
let tick = 0;
/** @type {{terminal_code: string, code: string, secret: string, expires_at: number}|null} */
let session = null;

/** @returns {boolean} true mientras hay un código pidiéndose o esperando aprobación. */
export function isPairingActive() {
    return active;
}

/** Detiene los timers y el estado del flujo (no borra la sesión guardada). */
export function stopPairing() {
    if (timer) clearInterval(timer);
    if (retryTimer) clearTimeout(retryTimer);
    timer = null;
    retryTimer = null;
    active = false;
    polling = false;
}

/**
 * Modelo real del dispositivo, solo disponible vía Client Hints en Chromium
 * sobre Android — null en el resto (el servidor cae al parseo del User-Agent).
 * @returns {Promise<string|null>}
 */
export async function getClientHintModel() {
    if (!navigator.userAgentData?.getHighEntropyValues) return null;
    try {
        const hints = await navigator.userAgentData.getHighEntropyValues(['model']);
        return hints.model || null;
    } catch {
        return null;
    }
}

/**
 * @typedef {object} PairingUi
 * @property {(code: string) => void} showCode - Muestra el código a verificar por el admin.
 * @property {(text: string) => void} setStatus - Texto de estado bajo el código.
 * @property {(secondsLeft: number) => void} setCountdown - Segundos restantes de vigencia.
 * @property {(visible: boolean) => void} showRetry - Muestra/oculta "Pedir código nuevo".
 * @property {(message: string) => void} showInactive - El terminal está desactivado: no hay nada que vincular.
 * @property {() => void} onLinked - Token guardado; el caller recarga la página.
 */

/**
 * Pide (o retoma) un código y empieza a esperar la aprobación. Idempotente: si
 * ya hay un flujo activo no hace nada (showUnlinked() puede dispararse varias
 * veces, ej. por el sync de fondo).
 * @param {PairingUi} ui
 * @param {{terminalCode: string, deviceModelHint?: string|null}} options
 * @returns {Promise<void>}
 */
export async function startPairing(ui, { terminalCode, deviceModelHint = null }) {
    if (active) return;
    active = true;

    ui.showRetry(false);
    ui.setStatus('Solicitando código…');

    try {
        session = await loadOrCreateSession(terminalCode, deviceModelHint);
    } catch (error) {
        handleStartError(ui, error, { terminalCode, deviceModelHint });
        return;
    }

    ui.showCode(session.code);
    ui.setStatus('Esperando la aprobación del administrador…');
    ui.setCountdown(secondsLeft());

    tick = 0;
    timer = setInterval(() => onTick(ui), 1000);
}

/**
 * Descarta el código actual y pide uno nuevo (botón "Pedir código nuevo").
 * @param {PairingUi} ui
 * @param {{terminalCode: string, deviceModelHint?: string|null}} options
 * @returns {Promise<void>}
 */
export async function restartPairing(ui, options) {
    stopPairing();
    session = null;
    await setMeta(SESSION_KEY, null);
    await startPairing(ui, options);
}

/** @returns {number} */
function secondsLeft() {
    return session ? Math.max(0, Math.round((session.expires_at - Date.now()) / 1000)) : 0;
}

/**
 * @param {string} terminalCode
 * @param {string|null} deviceModelHint
 * @returns {Promise<NonNullable<typeof session>>}
 */
async function loadOrCreateSession(terminalCode, deviceModelHint) {
    const saved = await getMeta(SESSION_KEY);
    if (saved && saved.terminal_code === terminalCode && saved.expires_at - Date.now() > MIN_REMAINING_MS) {
        return saved;
    }

    const data = await requestPairing(terminalCode, deviceModelHint);
    const created = {
        terminal_code: terminalCode,
        code: data.pairing_code,
        secret: data.poll_secret,
        expires_at: Date.parse(data.expires_at),
    };
    // Persistir la sesión es solo para sobrevivir a una recarga: si IndexedDB falla no se
    // reintenta el pedido (cada código nuevo notifica a los admins) — se sigue con la sesión en memoria.
    try {
        await setMeta(SESSION_KEY, created);
    } catch (error) {
        console.warn('No se pudo guardar la sesión de vinculación:', error.message);
    }

    return created;
}

/**
 * @param {PairingUi} ui
 * @param {unknown} error
 * @param {{terminalCode: string, deviceModelHint?: string|null}} options
 */
function handleStartError(ui, error, options) {
    active = false;

    if (error instanceof PairingError && error.code === 'terminal_inactive') {
        ui.showInactive(error.message);
        return;
    }

    if (error instanceof PairingError) {
        ui.setStatus(error.code === 'throttled' ? 'Demasiados intentos. Esperá un minuto y pedí un código nuevo.' : error.message);
        ui.showRetry(true);
        return;
    }

    // Sin red: reintenta solo, hay un dispositivo esperando ser vinculado.
    ui.setStatus('Sin conexión con el servidor — reintentando…');
    retryTimer = setTimeout(() => startPairing(ui, options), START_RETRY_MS);
}

/** @param {PairingUi} ui */
async function onTick(ui) {
    ui.setCountdown(secondsLeft());
    tick += 1;

    if (tick % POLL_EVERY_TICKS !== 0 || polling || !session) return;

    polling = true;
    try {
        await handlePoll(ui, await pollPairing(session.secret));
    } catch (error) {
        handlePollError(ui, error);
    } finally {
        polling = false;
    }
}

/**
 * @param {PairingUi} ui
 * @param {{status: string, token?: string, terminal?: {id: number, code: string}}} data
 */
async function handlePoll(ui, data) {
    switch (data.status) {
        case 'claimed':
            if (data.token && data.terminal) {
                await setMeta('api_token', data.token);
                await setMeta('terminal_id', data.terminal.id);
                await setMeta('terminal_code', data.terminal.code);
                await setMeta(SESSION_KEY, null);
                stopPairing();
                ui.setStatus('Terminal vinculado. Iniciando…');
                ui.onLinked();
            } else {
                finish(ui, 'La vinculación ya se había completado en otro intento. Pedí un código nuevo.');
            }
            break;
        case 'denied':
            finish(ui, 'El administrador rechazó la solicitud.');
            break;
        case 'expired':
        case 'invalid':
            finish(ui, 'El código venció. Pedí uno nuevo.');
            break;
        default:
            if (secondsLeft() === 0) finish(ui, 'El código venció. Pedí uno nuevo.');
    }
}

/**
 * Cierra el flujo actual (sin red de seguridad: hay que pedir otro código) y
 * descarta la sesión guardada.
 * @param {PairingUi} ui
 * @param {string} message
 */
function finish(ui, message) {
    stopPairing();
    session = null;
    setMeta(SESSION_KEY, null).catch(() => {});
    ui.setStatus(message);
    ui.setCountdown(0);
    ui.showRetry(true);
}

/**
 * @param {PairingUi} ui
 * @param {unknown} error
 */
function handlePollError(ui, error) {
    if (error instanceof PairingError && error.code === 'terminal_inactive') {
        stopPairing();
        ui.showInactive(error.message);
        return;
    }

    if (error instanceof PairingError && error.code === 'throttled') {
        // Se salta unos ciclos para dejar respirar al limitador del servidor.
        tick = -POLL_EVERY_TICKS * 5;
        ui.setStatus('Demasiadas consultas — reintentando en unos segundos…');
        return;
    }

    ui.setStatus('Sin conexión con el servidor — reintentando…');
}
