/**
 * =============================================================================
 * MARK.JS — HELPERS DE TEXTO (funciones puras, extraídas para poder testearlas)
 * =============================================================================
 *
 * @fileoverview Funciones sin dependencias del DOM usadas por mark.js para
 * traducir tipos de evento y traducir errores crudos del servidor/cliente en
 * mensajes accionables para el empleado. Primer paso de la descomposición de
 * mark.js en módulos más chicos — sin cambios de comportamiento respecto al
 * código original.
 */

/**
 * Traduce los tipos de eventos de inglés a español.
 * @param {string|null} eventType - Tipo de evento en inglés
 * @returns {string} Tipo de evento en español
 */
export function translateEventType(eventType) {
    const translations = {
        check_in: "Entrada",
        break_start: "Inicio de descanso",
        break_end: "Fin de descanso",
        check_out: "Salida",
    };

    return translations[eventType] || eventType || "—";
}

/**
 * Transforma un mensaje de error crudo del servidor en un mensaje amigable
 * con sugerencias concretas para el usuario.
 * @param {string|null} rawMessage
 * @returns {string}
 */
export function buildDetailedError(rawMessage) {
    if (!rawMessage) return "No se pudo completar la marcación. Por favor, intente nuevamente.";
    const msg = rawMessage.toLowerCase();
    // Chequeo primero — sin esto, el mensaje específico lanzado cuando el propio
    // perfil todavía no sincronizó (ver identifyEmployee(), "ownEmployee" null)
    // no matcheaba ninguna de las ramas de abajo y caía al genérico "error
    // inesperado", escondiendo que el problema real es de sincronización, no de
    // reconocimiento facial.
    if (msg.includes("sincronizó tu perfil") || msg.includes("conectate a internet")) {
        return "Tu perfil todavía no se sincronizó con este dispositivo. Conectate a internet y volvé a intentar en unos segundos.";
    }
    if (msg.includes("ambiguo") || msg.includes("ambiguous") || msg.includes("múltiple") || msg.includes("multiple face")) {
        return "Se detectaron múltiples rostros o el rostro no es claro. Asegúrese de estar solo frente a la cámara y reposicione su cara.";
    }
    if (msg.includes("no identificado") || msg.includes("not found") || msg.includes("no match")) {
        return "No se pudo reconocer su rostro. Asegúrese de estar frente a la cámara con buena iluminación, sin lentes de sol ni gorras, y mantenga el rostro quieto.";
    }
    if (msg.includes("descriptor") || msg.includes("muestra") || msg.includes("sample")) {
        return "No se detectó un rostro válido. Acerque el rostro a la cámara (30–60 cm) y asegúrese de tener buena iluminación frontal.";
    }
    if (msg.includes("conexión") || msg.includes("network") || msg.includes("fetch")) {
        return !navigator.onLine
            ? "Sin conexión a internet. Verifique la red del dispositivo y vuelva a intentar."
            : "Error de conexión al servidor. Verifique que el dispositivo tenga acceso a la red y vuelva a intentar.";
    }
    if (msg.includes("event") || msg.includes("evento") || msg.includes("allowed")) {
        return "No hay tipos de marcación disponibles para este empleado en este momento. Consulte con el departamento de RRHH.";
    }
    return "Ocurrió un error inesperado. Por favor, intente nuevamente.";
}
