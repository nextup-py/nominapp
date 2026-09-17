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
 * No decide qué pantalla mostrar al terminar — recibe `onReady`/`onError`
 * como callbacks, ese destino (enterIdle/showError) vive en terminal.js.
 */

import * as camera from './camera.js';
import { acquireWakeLock } from './idle-detection.js';
import { migrateTokenFromLocalStorage, getMeta, clearTerminalState } from '../terminal-offline/db.js';
import { heartbeat, syncEmployees, TerminalAuthError } from '../terminal-offline/sync.js';
import { flushQueue } from '../terminal-offline/queue.js';
import { updateIdleSyncStatus, refreshIdleSyncStatus, refreshLastSyncLabel } from './sync-status-ui.js';
import { markUserInteracted } from '../../shared/audio-feedback.js';

let backgroundSyncStarted = false;

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
 * un terminal_code guardado en IndexedDB de una provisión anterior, muestra
 * un banner con el link directo a /terminal/{code}.
 * @param {{banner: HTMLElement|null, link: HTMLElement|null}} dom
 */
export async function checkLegacyTerminalMigration(dom) {
    if (window.terminalData) return; // ya estamos en /terminal/{code}, nada que migrar
    if (!dom.banner || !dom.link) return;

    try {
        const code = await getMeta('terminal_code');
        if (!code) return;

        const url = `${window.location.origin}/terminal/${code}`;
        dom.link.href = url;
        dom.link.textContent = url;
        dom.banner.classList.add('is-visible');
        dom.banner.setAttribute('aria-hidden', 'false');
    } catch (error) {
        console.warn('No se pudo verificar el código de terminal guardado localmente:', error);
    }
}

/**
 * @param {{loadingProgress?, loadingPercentage?, loadingMessage?, loadingStep1?, loadingStep2?, loadingStep3?}} loadingDom
 * @param {{onReady: () => void, onError: (message: string) => void}} callbacks
 */
export async function initializeSystem(loadingDom, { onReady, onError }) {
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
        await initializeOfflineSync();

        console.log('Sistema inicializado correctamente');
        onReady();
    } catch (error) {
        console.error('Error en la inicialización:', error);
        if (loadingDom.loadingMessage) {
            loadingDom.loadingMessage.textContent = `Error: ${error.message}`;
            loadingDom.loadingMessage.style.color = '#ef4444';
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
 * guardados contra los del terminal actual — si no coinciden, limpia el
 * estado local antes de continuar (ver terminal.js original para el
 * contexto completo de esta protección).
 */
export async function initializeOfflineSync() {
    await migrateTokenFromLocalStorage();

    const storedId = await getMeta('terminal_id');
    const storedCode = await getMeta('terminal_code');
    const currentId = window.terminalData?.id;
    const currentCode = window.terminalData?.code;

    const belongsToOtherTerminal = (storedId != null && currentId != null)
        ? storedId !== currentId
        : (storedCode != null && currentCode != null && storedCode !== currentCode);

    if (belongsToOtherTerminal) {
        console.warn(`Datos locales pertenecen a otro terminal (id ${storedId ?? 'desconocido'}, code "${storedCode}") — limpiando antes de continuar.`);
        await clearTerminalState();
    }

    const token = await getMeta('api_token');

    if (!token) {
        console.warn('Terminal sin token de sincronización — falta provisión.');
        updateIdleSyncStatus('Terminal sin configurar');
        return;
    }

    await requestPersistentStorage();

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

    startBackgroundSync();
}

/**
 * Heartbeat + sync de empleados + vaciado de la cola, periódicos mientras
 * haya conexión, más un intento al recuperarla.
 */
export function startBackgroundSync() {
    if (backgroundSyncStarted) return;
    backgroundSyncStarted = true;

    // Sin esto, un terminal revocado (re-provisión, baja) seguía intentando
    // sincronizar en loop cada 30-90s sin avisar a nadie más que la consola —
    // el mismo texto visible que ya usa initializeOfflineSync() cuando falta
    // el token por primera vez, ahora también cuando se pierde en caliente.
    const reportAuthError = (error) => {
        if (error instanceof TerminalAuthError) {
            updateIdleSyncStatus('Terminal sin configurar — necesita re-provisión');
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
