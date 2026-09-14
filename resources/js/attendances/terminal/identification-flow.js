/**
 * =============================================================================
 * TERMINAL.JS — ORQUESTACIÓN DEL RECONOCIMIENTO FACIAL
 * =============================================================================
 *
 * @fileoverview El corazón del flujo: arranca cámara + loop de detección
 * (camera.js), captura un descriptor cuando hay rostro, lo compara contra la
 * caché local de empleados (identifyEmployeeFromDescriptor — mismo algoritmo
 * de distancia euclidiana + umbral/gap que corre en el servidor, ver
 * terminal-offline/matcher.js), y decide el siguiente paso: registrar
 * directo, mostrar selección de tipo, o mostrar jornada completa. Extraído
 * de terminal.js como parte de su descomposición en módulos más chicos —
 * mismo comportamiento que el código original.
 *
 * Importa screen-state.js y mark-registration.js directamente (sin ciclos:
 * ninguno de los dos importa identification-flow.js de vuelta). Solo recibe
 * `onIdleTimeout` como callback porque ese destino (enterIdle) vive en
 * terminal.js, que coordina idle-detection + screen-state + este módulo a
 * la vez.
 */

import * as camera from './camera.js';
import { resetIdleTimer } from './idle-detection.js';
import { showScreen, showSuccessScreen, showError, showDayComplete } from './screen-state.js';
import { showTypeSelectionForEmployee, registerMark, clearPendingEmployee } from './mark-registration.js';
import { identifyEmployee as matchDescriptor } from '../terminal-offline/matcher.js';
import { getCachedEmployees } from '../terminal-offline/db.js';
import { getFaceConfig, TerminalAuthError } from '../terminal-offline/sync.js';
import { getEmployeeStatus } from '../terminal-offline/queue.js';
import { setTerminalVideoState, setIdStatusDot, showCaptureProgress, updateCaptureProgress, finishCaptureProgress } from './ui-feedback.js';
import { hasUserInteracted } from '../../shared/audio-feedback.js';
import { showManualSearchLink, hideManualSearchLink, closeManualSearch } from './manual-search.js';

const CONSECUTIVE_FAILURES_FOR_MANUAL_SEARCH = 2;

let identifyInterval = null;
let isProcessing = false;
let consecutiveFailures = 0;
let manualCandidate = null;

/** @param {object|null} employee */
export function setManualCandidate(employee) {
    manualCandidate = employee;
    // Elegir un candidato manual es un reinicio implícito del contador de
    // fallos — mismo comportamiento que el terminal.js original, que
    // reseteaba consecutiveFailures junto con manualCandidate en el mismo
    // callback onSelect.
    consecutiveFailures = 0;
}

/** @returns {number} */
export function getConsecutiveFailures() {
    return consecutiveFailures;
}

/**
 * Identifica al empleado comparando el descriptor capturado contra la caché
 * local de empleados, con el mismo algoritmo que el servidor.
 * @param {Float32Array} descriptor
 * @param {object|null} manualCandidateOverride - si se pasa, acota el match a este único candidato (búsqueda manual por CI)
 * @returns {Promise<{ok: boolean, employee?, distance?, last_event?, last_event_time?, allowed_events?, message?: string, reason?: string, needsProvisioning?: boolean}>}
 */
export async function identifyEmployeeFromDescriptor(descriptor, manualCandidateOverride = manualCandidate) {
    try {
        const { threshold, minGap } = await getFaceConfig();
        if (threshold == null || minGap == null) {
            return { ok: false, message: 'Terminal sincronizando por primera vez, espere un momento.' };
        }

        const candidates = manualCandidateOverride ? [manualCandidateOverride] : await getCachedEmployees();
        const { employee, distance, reason } = matchDescriptor(descriptor, candidates, threshold, minGap);

        if (!employee) {
            const messages = {
                no_candidates: 'No hay empleados sincronizados en este terminal.',
                ambiguous: 'Rostro ambiguo. Por favor, reposicione su cara e intente de nuevo.',
                no_match: 'No se pudo identificar el rostro. Intente nuevamente.',
            };
            return { ok: false, message: messages[reason] || 'No identificado', reason };
        }

        const status = await getEmployeeStatus(employee.id);

        return {
            ok: true,
            employee: {
                id: employee.id,
                first_name: employee.first_name,
                last_name: employee.last_name,
                ci: employee.ci,
                photo_url: employee.photo_thumbnail || '/images/default-avatar.png',
            },
            distance,
            last_event: status.last_event,
            last_event_time: status.last_event_time,
            allowed_events: status.allowed_events,
        };
    } catch (error) {
        if (error instanceof TerminalAuthError) {
            return { ok: false, message: error.message, needsProvisioning: true };
        }
        console.error('Error en identificación:', error);
        return { ok: false, message: 'Error de conexión' };
    }
}

function updateStatus(identificationStatusEl, text) {
    if (!identificationStatusEl) return;
    const statusTextEl = identificationStatusEl.querySelector('.id-status-text');
    if (statusTextEl) {
        statusTextEl.textContent = text;
    } else {
        identificationStatusEl.innerHTML = `<span class="id-status-dot" id="idStatusDot"></span><span class="id-status-text">${text}</span>`;
    }
}

/**
 * Punto de entrada principal del flujo — resetea el estado de intentos
 * previos, oculta la búsqueda manual, muestra la pantalla de identificación
 * y arranca la auto-identificación.
 *
 * Único punto de re-entrada común a todo path de finalización/cancelación/
 * reintento/salida-de-reposo (directamente, o vía el wrapper local
 * `resetTerminal()` de terminal.js) — por eso también es el lugar correcto
 * para resetear `isProcessing` (bug: quedaba en `true` para siempre tras un
 * ciclo exitoso, porque el `finally` del intervalo que lo resetea está
 * guardado por `if (identifyInterval)`, y `stopAutoIdentification()` ya lo
 * anuló antes de que ese `finally` corra) y para limpiar el empleado
 * pendiente de selección de tipo (`clearPendingEmployee()` — antes solo se
 * limpiaba en Cancelar/Reintentar/Marcar otra persona, nunca en un path de
 * éxito).
 * @param {{screens, video, overlay, ctx, identificationStatus}} refs
 * @param {{onIdleTimeout: () => void}} callbacks
 */
export function startIdentificationFlow(refs, { onIdleTimeout }) {
    resetIdleTimer(onIdleTimeout);
    isProcessing = false;
    consecutiveFailures = 0;
    manualCandidate = null;
    clearPendingEmployee();
    hideManualSearchLink();
    closeManualSearch();
    showScreen(refs.screens, 'identification');
    startAutoIdentification(refs);
    setTerminalVideoState('detecting');
}

/**
 * @param {{screens, video: HTMLVideoElement, overlay: HTMLCanvasElement, ctx: CanvasRenderingContext2D, identificationStatus, successDom, errorMessageEl, dayCompleteDom, typeSelectionDom}} refs
 */
export async function startAutoIdentification(refs) {
    const modelsResult = await camera.loadModels();
    if (!modelsResult.ok) {
        showError(refs.screens, refs.errorMessageEl, modelsResult.message);
        return;
    }

    updateStatus(refs.identificationStatus, 'Iniciando cámara...');
    const cameraResult = await camera.startCamera(refs.video, refs.overlay);
    if (!cameraResult.ok) {
        showError(refs.screens, refs.errorMessageEl, cameraResult.message);
        return;
    }

    camera.startDrawLoop(refs.video, refs.overlay, refs.ctx, {
        isProcessing: () => isProcessing,
        onFrame: (state) => {
            if (state.justExitedCooldown) {
                updateStatus(refs.identificationStatus, 'Posicione su rostro dentro del óvalo...');
            }
            if (state.detected) {
                setTerminalVideoState(state.tooSmall ? 'detecting' : 'face-found');
                setIdStatusDot(state.tooSmall ? 'detecting' : 'face-found');
                if (state.tooSmall) updateStatus(refs.identificationStatus, 'Acérquese un poco más a la cámara');
            } else {
                setTerminalVideoState('detecting');
                setIdStatusDot('detecting');
            }
        },
    });
    updateStatus(refs.identificationStatus, 'Posicione su rostro dentro del óvalo...');

    identifyInterval = setInterval(async () => {
        if (isProcessing) return;
        if (camera.isInCooldown()) return;
        if (!camera.isFaceDetected()) return;

        try {
            isProcessing = true;
            setTerminalVideoState('face-found');
            setIdStatusDot('face-found');
            updateStatus(refs.identificationStatus, 'Analizando rostro, mantenga la posición...');
            showCaptureProgress();

            const descriptor = await camera.captureDescriptor(refs.video, 5, 150, (count) => updateCaptureProgress(count));
            const result = await identifyEmployeeFromDescriptor(descriptor, manualCandidate);

            if (result.needsProvisioning) {
                stopAutoIdentification(refs.video);
                await finishCaptureProgress('error');
                showError(refs.screens, refs.errorMessageEl, result.message);
                return;
            }

            if (result.ok && result.employee) {
                consecutiveFailures = 0;
                manualCandidate = null;
                hideManualSearchLink();

                if (hasUserInteracted()) navigator.vibrate?.(80);
                stopAutoIdentification(refs.video);

                await finishCaptureProgress('success');
                setTerminalVideoState('success');
                setIdStatusDot('success');

                const allowedEvents = result.allowed_events || [];
                const onCountdownComplete = () => startIdentificationFlow(refs, { onIdleTimeout: refs.onIdleTimeout });
                const markCallbacks = {
                    onStatusUpdate: (text) => updateStatus(refs.identificationStatus, text),
                    onSuccess: (employee, markData, eventType, opts) => showSuccessScreen(refs.screens, refs.successDom, employee, markData, eventType, opts, onCountdownComplete),
                    onError: (message) => showError(refs.screens, refs.errorMessageEl, message),
                };

                if (allowedEvents.length === 1) {
                    await registerMark(result.employee, allowedEvents[0], markCallbacks);
                } else if (allowedEvents.length > 1) {
                    showTypeSelectionForEmployee(refs.screens, refs.typeSelectionDom, result.employee, allowedEvents, result.last_event, result.last_event_time);
                } else {
                    showDayComplete(refs.screens, refs.dayCompleteDom, result.employee, onCountdownComplete);
                }
            } else {
                await finishCaptureProgress('error');

                camera.setNotRecognizedCooldown(1200);
                setTerminalVideoState('detecting');
                setIdStatusDot('detecting');
                const idleStatusMessages = {
                    no_candidates: 'Terminal sin empleados sincronizados. Contacte al administrador.',
                    ambiguous: 'Rostro ambiguo. Reposicione su cara e intente de nuevo.',
                    no_match: 'Rostro no reconocido. Mantenga el rostro quieto frente a la cámara.',
                };
                updateStatus(refs.identificationStatus, idleStatusMessages[result.reason] || result.message || 'Rostro no reconocido. Mantenga el rostro quieto frente a la cámara.');
                console.log('No se pudo identificar', result.reason);

                consecutiveFailures++;
                if (consecutiveFailures >= CONSECUTIVE_FAILURES_FOR_MANUAL_SEARCH) {
                    showManualSearchLink();
                }
            }
        } catch (error) {
            await finishCaptureProgress('error');
            console.error('Error en auto-identificación:', error);
            updateStatus(refs.identificationStatus, 'Error al analizar. Asegúrese de tener buena iluminación.');
        } finally {
            if (identifyInterval) isProcessing = false;
        }
    }, 1500);
}

/** @param {HTMLVideoElement} videoEl */
export function stopAutoIdentification(videoEl) {
    if (identifyInterval) {
        clearInterval(identifyInterval);
        identifyInterval = null;
    }
    camera.stopCamera(videoEl);
}
