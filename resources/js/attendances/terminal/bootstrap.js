// resources/js/attendances/terminal/bootstrap.js
/**
 * =============================================================================
 * TERMINAL.JS — ARRANQUE DEL SISTEMA
 * =============================================================================
 *
 * @fileoverview Secuencia de arranque: verificación de compatibilidad, carga
 * de modelos (reusa camera.js — antes duplicaba la misma carga de 3 modelos
 * que loadModels(), ambas controladas por el mismo flag interno, así que
 * unificar acá no cambia comportamiento), wake lock, sync inicial, y sync en
 * segundo plano periódico. Extraído de terminal.js como parte de su
 * descomposición en módulos más chicos — mismo comportamiento que el código
 * original (salvo la deduplicación de carga de modelos, ver arriba).
 *
 * No decide qué pantalla mostrar al terminar — recibe `onReady`/`onError`/
 * `onUnlinked` como callbacks, ese destino (enterIdle/showError/pantalla
 * "sin vincular") vive en terminal.js.
 */

import * as camera from './camera.js';
import { acquireWakeLock, hasWakeLock } from './idle-detection.js';
import { migrateTokenFromLocalStorage, getMeta, setMeta, clearTerminalState } from '../terminal-offline/db.js';
import { heartbeat, syncEmployees, rebuildEmployeeCache, setCommandHandler, setAppVersionHandler, setWakeLockProbe, queueCommandAck, TerminalAuthError } from '../terminal-offline/sync.js';
import { reloadIfOutdated } from '../terminal-offline/auto-reload.js';
import { markPendingReload, settlePendingReload } from '../terminal-offline/pending-reload.js';
import { runRemoteCommands } from '../terminal-offline/remote-commands.js';
import { flushQueue } from '../terminal-offline/queue.js';
import { updateIdleSyncStatus, refreshIdleSyncStatus, refreshLastSyncLabel } from './sync-status-ui.js';
import { markUserInteracted } from '../../shared/audio-feedback.js';

let backgroundSyncStarted = false;

/** Separación mínima entre dos sincronizaciones disparadas por el despertar del equipo (evita ráfagas focus + visibilitychange). */
const WAKE_SYNC_MIN_GAP_MS = 15 * 1000;

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/**
 * @param {{loadingProgress?, loadingPercentage?, loadingMessage?, loadingStep1?, loadingStep2?, loadingStep3?}} dom
 * @param {number} percentage
 * @param {string} message
 * @param {number} stepNumber
 */
export function updateLoadingProgress(dom, percentage, message, stepNumber) {
    if (dom.loadingProgress) dom.loadingProgress.style.width = `${percentage}%`;
    if (dom.loadingPercentage) dom.loadingPercentage.textContent = `${percentage}%`;
    if (dom.loadingMessage && message) dom.loadingMessage.textContent = message;

    const steps = [dom.loadingStep1, dom.loadingStep2, dom.loadingStep3];
    steps.forEach((step, index) => {
        if (!step) return;
        step.classList.remove('active', 'completed');
        if (index + 1 < stepNumber) step.classList.add('completed');
        else if (index + 1 === stepNumber) step.classList.add('active');
    });
}

/**
 * Si esta página es /terminal (legacy, sin código) y el dispositivo ya tiene
 * un terminal_code guardado en IndexedDB de una provisión anterior, arma la
 * URL correcta y avisa que hay que migrar — bootstrap.js no decide qué
 * pantalla mostrar (ver nota arriba), así que retorna el resultado y es
 * terminal.js quien reemplaza toda la pantalla por la de migración,
 * bloqueando el arranque normal: la ruta legacy va a darse de baja, no debe
 * poder seguir usándose para marcar una vez detectado el dispositivo.
 * @returns {Promise<{needsMigration: boolean, url?: string}>}
 */
export async function checkLegacyTerminalMigration() {
    if (window.terminalData) return { needsMigration: false }; // ya estamos en /terminal/{code}, nada que migrar

    try {
        const code = await getMeta('terminal_code');
        if (!code) return { needsMigration: false };

        return { needsMigration: true, url: `${window.location.origin}/terminal/${code}` };
    } catch (error) {
        console.warn('No se pudo verificar el código de terminal guardado localmente:', error);
        return { needsMigration: false };
    }
}

/**
 * @param {{loadingProgress?, loadingPercentage?, loadingMessage?, loadingStep1?, loadingStep2?, loadingStep3?}} loadingDom
 * @param {{onReady: () => void, onError: (message: string) => void, onUnlinked?: (reason: string, storedCode?: string|null) => void}} callbacks
 *        `onUnlinked` reemplaza a `onReady` cuando el terminal no puede marcar (sin token,
 *        o el navegador pertenece a otro terminal): no se llega a la pantalla idle.
 */
export async function initializeSystem(loadingDom, { onReady, onError, onUnlinked }) {
    try {
        updateLoadingProgress(loadingDom, 10, 'Verificando compatibilidad del navegador...', 1);
        await sleep(300);

        if (typeof faceapi === 'undefined') {
            throw new Error('La biblioteca face-api.js no está disponible');
        }
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            throw new Error('Tu navegador no soporta acceso a la cámara');
        }

        updateLoadingProgress(loadingDom, 30, 'Navegador compatible ✓', 1);
        await sleep(200);

        updateLoadingProgress(loadingDom, 40, 'Cargando modelos de reconocimiento facial...', 2);
        const modelsResult = await camera.loadModels();
        if (!modelsResult.ok) {
            throw new Error(modelsResult.message);
        }

        updateLoadingProgress(loadingDom, 80, 'Modelos cargados correctamente ✓', 2);
        await sleep(300);

        updateLoadingProgress(loadingDom, 90, 'Preparando sistema de marcación...', 3);
        await sleep(300);

        updateLoadingProgress(loadingDom, 100, 'Sistema listo ✓', 3);
        await sleep(500);

        await acquireWakeLock();
        // Si el arranque temprano ya dejó el terminal sincronizando, no se repite (ver `startOfflineSyncEarly()`).
        const early = earlySync ? await earlySync : null;
        const sync = early?.linked ? early : await initializeOfflineSync({ onUnlinked });

        if (!sync.linked) {
            console.warn(`Terminal sin vincular (${sync.reason}) — marcación bloqueada.`);
            onUnlinked?.(sync.reason, sync.storedCode);
            return;
        }

        console.log('Sistema inicializado correctamente');
        onReady();
    } catch (error) {
        console.error('Error en la inicialización:', error);
        if (loadingDom.loadingMessage) {
            loadingDom.loadingMessage.textContent = `Error: ${error.message}`;
            loadingDom.loadingMessage.style.color = 'var(--c-danger)';
        }
        await sleep(3000);
        onError('Error al inicializar el sistema. ' + error.message + ' Por favor, recargue la página.');
    }
}

/**
 * Pide almacenamiento persistente — reduce el riesgo de que el navegador
 * borre IndexedDB bajo presión de espacio en disco. Best-effort.
 */
export async function requestPersistentStorage() {
    if (!navigator.storage?.persist) return;
    try {
        const granted = await navigator.storage.persist();
        console.log(granted ? 'Almacenamiento persistente concedido' : 'Almacenamiento persistente no concedido (best-effort)');
    } catch (error) {
        console.warn('No se pudo solicitar almacenamiento persistente:', error.message);
    }
}

/**
 * Migra el token de configuración y hace la primera sincronización. Si el
 * terminal no está provisionado, no bloquea el arranque — solo informa en
 * la pantalla idle.
 *
 * Regresión de seguridad prevenida acá: compara terminal_id/terminal_code
 * guardados contra los del terminal actual — si no coinciden, este
 * navegador ya reclamó el token de sincronización de OTRO terminal (ver
 * clearTerminalState() en db.js). Antes de limpiar (lo que además borra el
 * token/empleados/cola de ESE otro terminal, dejándolo desprovisionado) se
 * pide confirmación explícita — abrir la URL equivocada por error (un link
 * viejo, una pestaña confundida) no debe destruir en silencio un terminal
 * que venía funcionando bien. Si cancela, el arranque se detiene acá sin
 * tocar nada — el dispositivo sigue funcionando como el terminal que ya
 * tenía configurado.
 *
 * Retorna si el terminal quedó en condiciones de marcar: sin token (nunca
 * reclamó el enlace de setup, otro navegador/perfil, datos borrados) o con
 * confirmación cancelada, `linked: false` — el caller bloquea la marcación en
 * vez de dejar arrancar la cámara hacia un terminal que no puede identificar
 * ni sincronizar a nadie.
 * @param {{onUnlinked?: (reason: string, storedCode?: string|null) => void, confirmSwitch?: boolean}} [callbacks] - `onUnlinked` se propaga al sync en segundo plano para avisar de una revocación en caliente; con `confirmSwitch: false` un navegador de otro terminal no se limpia ni se pregunta (arranque sin toque)
 * @returns {Promise<{linked: boolean, reason?: 'no_token'|'other_terminal', storedCode?: string|null}>}
 */
export async function initializeOfflineSync({ onUnlinked, confirmSwitch = true } = {}) {
    await migrateTokenFromLocalStorage();

    const storedId = await getMeta('terminal_id');
    const storedCode = await getMeta('terminal_code');
    const currentId = window.terminalData?.id;
    const currentCode = window.terminalData?.code;

    const belongsToOtherTerminal = (storedId != null && currentId != null)
        ? storedId !== currentId
        : (storedCode != null && currentCode != null && storedCode !== currentCode);

    if (belongsToOtherTerminal) {
        // Sin toque del usuario no se pregunta ni se borra nada: lo resuelve el arranque normal tras "Comenzar".
        if (!confirmSwitch) return { linked: false, reason: 'other_terminal', storedCode: storedCode ?? null };

        const confirmed = window.confirm(
            `Este dispositivo ya está configurado como el terminal "${storedCode ?? storedId}". `
            + `¿Querés cambiarlo a este terminal ("${currentCode ?? currentId}")? `
            + 'Se va a perder toda la caché local del terminal anterior (empleados sincronizados y marcaciones pendientes de sincronizar).',
        );

        if (!confirmed) {
            updateIdleSyncStatus(`Este es otro terminal — volvé a /terminal/${storedCode ?? ''}`);
            return { linked: false, reason: 'other_terminal', storedCode: storedCode ?? null };
        }

        console.warn(`Datos locales pertenecen a otro terminal (id ${storedId ?? 'desconocido'}, code "${storedCode}") — limpiando antes de continuar.`);
        await clearTerminalState();
    }

    const token = await getMeta('api_token');

    if (!token) {
        console.warn('Terminal sin token de sincronización — falta provisión.');
        updateIdleSyncStatus('Terminal sin configurar');
        return { linked: false, reason: 'no_token' };
    }

    await requestPersistentStorage();

    // Antes del primer heartbeat: si ya trae comandos pendientes, que no se pierdan.
    setCommandHandler(handleRemoteCommands);
    setWakeLockProbe(hasWakeLock);
    setAppVersionHandler(async (serverVersion) => {
        const settled = await settlePendingReload({
            getMeta,
            setMeta,
            ack: queueCommandAck,
            currentVersion: window.terminalData?.app_version,
            serverVersion,
        });

        // Sin esperar al próximo ciclo de 90 s: el pendiente ya se limpió, así que este latido no vuelve a entrar aquí.
        if (settled) heartbeat().catch((error) => console.warn('No se pudo enviar la confirmación de recarga:', error.message));

        return reloadIfOutdated({
            currentVersion: window.terminalData?.app_version,
            serverVersion,
            waitForIdle,
            reload: () => window.location.reload(),
        });
    });

    try {
        updateIdleSyncStatus('Sincronizando...');
        await heartbeat();
        await syncEmployees();
        await flushQueue();
        await refreshIdleSyncStatus();
    } catch (error) {
        console.warn('Sincronización inicial falló (se reintentará en segundo plano):', error.message);
        updateIdleSyncStatus(navigator.onLine ? 'Error al sincronizar' : 'Sin conexión — usando datos locales');
        await refreshLastSyncLabel();
    }

    startBackgroundSync({ onUnlinked });
    return { linked: true };
}

/** @type {Promise<{linked: boolean, reason?: string, storedCode?: string|null}>|null} Arranque temprano en curso (una sola vez por carga de página). */
let earlySync = null;

/**
 * Arranca la sincronización (heartbeat, empleados, cola y comandos remotos) apenas carga la página, sin esperar el
 * toque de "Comenzar". Ese toque solo existe para que el navegador permita el sonido y la cámara; antes, un terminal
 * recién recargado (comando "Recargar app", corte de luz, reinicio) no hablaba con el servidor hasta que alguien lo
 * tocaba: el panel lo veía desconectado y la confirmación del comando llegaba tarde. Si el terminal no está vinculado
 * o el navegador era de otro terminal, no hace nada: lo resuelve el arranque normal.
 * @param {{onUnlinked?: (reason: string) => void}} [callbacks]
 * @returns {Promise<{linked: boolean, reason?: string}>}
 */
export function startOfflineSyncEarly(callbacks = {}) {
    earlySync ??= initializeOfflineSync({ ...callbacks, confirmSwitch: false }).catch((error) => {
        console.warn('Arranque temprano de la sincronización falló:', error.message);
        return { linked: false, reason: 'error' };
    });

    return earlySync;
}

/** Máximo que un comando de recarga/limpieza espera a que el terminal pueda recargarse sin interrumpir a nadie. */
const IDLE_WAIT_MS = 6 * 60 * 1000;

/** Evita ejecutar dos tandas de comandos a la vez (el heartbeat de confirmación podría traer más): las que llegan mientras se ejecuta quedan en cola. */
let executingCommands = false;

/** @type {Array<{id: number, command: string}>} */
let queuedCommands = [];

/** @type {() => boolean} Indica si hay una marcación en curso (rostro en proceso o empleado eligiendo el tipo); lo registra terminal.js. */
let busyProbe = () => false;

/**
 * Registra cómo saber si el terminal está atendiendo a alguien.
 * @param {() => boolean} probe
 */
export function setBusyProbe(probe) {
    busyProbe = probe;
}

/**
 * Se puede recargar sin interrumpir a nadie: en "Toque para comenzar" (nadie está marcando), en reposo, en la pantalla de "sin empleados", o en la pantalla de identificación esperando un rostro
 * (sin identificación en proceso ni empleado eligiendo el tipo). Éxito, error, jornada completa y selección de
 * tipo terminan solas en segundos y vuelven a identificación. La cola de marcaciones vive en IndexedDB, así que
 * recargar nunca pierde una marcación ya registrada.
 */
function isTerminalIdle() {
    const visible = (id) => document.getElementById(id) && !document.getElementById(id).classList.contains('hidden');

    return visible('startGateScreen') || visible('idleScreen') || visible('emptyScreen') || (visible('identificationScreen') && !busyProbe());
}

/**
 * Espera a que el terminal pueda recargarse sin interrumpir a nadie (ver `isTerminalIdle()`), hasta `IDLE_WAIT_MS`.
 * @returns {Promise<boolean>} false si venció el tiempo sin llegar al reposo
 */
async function waitForIdle() {
    const deadline = Date.now() + IDLE_WAIT_MS;
    while (!isTerminalIdle()) {
        if (Date.now() >= deadline) return false;
        await new Promise((resolve) => setTimeout(resolve, 5000));
    }
    return true;
}

/**
 * Ejecuta los comandos remotos recibidos en el heartbeat (ver remote-commands.js).
 * @param {Array<{id: number, command: string}>} commands
 */
async function handleRemoteCommands(commands) {
    if (executingCommands) {
        queuedCommands.push(...commands);
        return;
    }
    executingCommands = true;
    try {
        await runRemoteCommands(commands, {
            forceSync: async () => {
                await syncEmployees();
                await flushQueue();
                await refreshIdleSyncStatus();
            },
            clearCache: async () => {
                if (globalThis.caches) {
                    await Promise.all((await caches.keys()).map((key) => caches.delete(key)));
                }
                await rebuildEmployeeCache();
            },
            waitForIdle,
            ack: queueCommandAck,
            flushAcks: heartbeat,
            deferReloadAck: (id) => markPendingReload(setMeta, id),
            reload: () => window.location.reload(),
        });
    } finally {
        executingCommands = false;
    }

    if (queuedCommands.length > 0) {
        const next = queuedCommands;
        queuedCommands = [];
        await handleRemoteCommands(next);
    }
}

/**
 * Heartbeat + sync de empleados + vaciado de la cola, periódicos mientras
 * haya conexión, más un intento al recuperarla.
 * @param {{onUnlinked?: (reason: string) => void}} [callbacks] - se invoca si el servidor rechaza el token (revocado en caliente)
 */
export function startBackgroundSync({ onUnlinked } = {}) {
    if (backgroundSyncStarted) return;
    backgroundSyncStarted = true;
    setCommandHandler(handleRemoteCommands);

    // Sin esto, un terminal revocado (re-provisión, baja) seguía intentando
    // sincronizar en loop cada 30-90s sin avisar a nadie más que la consola —
    // el mismo texto visible que ya usa initializeOfflineSync() cuando falta
    // el token por primera vez, ahora también cuando se pierde en caliente —
    // y además bloquea la marcación (onUnlinked), porque un terminal revocado no
    // puede sincronizar y seguiría aceptando marcaciones que nunca llegan al servidor.
    const reportAuthError = (error) => {
        if (error instanceof TerminalAuthError) {
            updateIdleSyncStatus('Terminal sin configurar — necesita re-provisión');
            onUnlinked?.('revoked');
            return true;
        }
        return false;
    };

    const runHeartbeat = () => {
        if (!navigator.onLine) return;
        heartbeat().catch((error) => {
            if (!reportAuthError(error)) console.warn('Heartbeat en segundo plano falló:', error.message);
        });
    };
    const runEmployeeSync = () => {
        if (!navigator.onLine) return;
        syncEmployees().catch((error) => {
            if (!reportAuthError(error)) console.warn('Sync de empleados en segundo plano falló:', error.message);
        });
    };
    const runQueueFlush = () => {
        if (!navigator.onLine) return;
        flushQueue().then(() => refreshIdleSyncStatus()).catch((error) => {
            if (!reportAuthError(error)) console.warn('Sincronización de cola en segundo plano falló:', error.message);
        });
    };

    setInterval(runHeartbeat, 90 * 1000);
    setInterval(runEmployeeSync, 5 * 60 * 1000);
    setInterval(runQueueFlush, 30 * 1000);

    window.addEventListener('online', () => {
        runHeartbeat();
        runEmployeeSync();
        runQueueFlush();
    });

    // Un equipo que se suspende congela los setInterval: al despertar, no esperar al próximo ciclo.
    let lastWakeSyncAt = 0;
    const syncOnWake = () => {
        if (typeof document !== 'undefined' && document.visibilityState === 'hidden') return;
        const now = Date.now();
        if (now - lastWakeSyncAt < WAKE_SYNC_MIN_GAP_MS) return;
        lastWakeSyncAt = now;
        runHeartbeat();
        runQueueFlush();
    };
    window.addEventListener('visibilitychange', syncOnWake);
    window.addEventListener('pageshow', syncOnWake);
    window.addEventListener('focus', syncOnWake);
}

/**
 * Marca la primera interacción del usuario (click o touch) para habilitar
 * la Vibration API — se dispara una sola vez.
 * @param {Document} [doc] - inyectable para tests; document en producción
 */
export function initInteractionTracking(doc = document) {
    const markInteraction = () => {
        markUserInteracted();
        doc.removeEventListener('click', markInteraction);
        doc.removeEventListener('touchstart', markInteraction);
    };
    doc.addEventListener('click', markInteraction, { once: true });
    doc.addEventListener('touchstart', markInteraction, { once: true });
}
