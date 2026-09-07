/**
 * =============================================================================
 * TERMINAL.JS — HELPERS DE TEXTO (mensajes de error detallados)
 * =============================================================================
 *
 * @fileoverview Traduce mensajes de error crudos del servidor/cliente en
 * mensajes accionables para quien opera el terminal. Extraído de terminal.js
 * como parte de su descomposición en módulos más chicos — mismo
 * comportamiento que el código original.
 *
 * No es el mismo módulo que mark/text-helpers.js: el terminal no tiene el
 * caso de "perfil todavía no sincronizado" (el terminal cachea *todos* los
 * empleados de la sucursal, no un único descriptor propio como el celular),
 * ni distingue rostro ambiguo de rostro no identificado; en cambio sí
 * distingue sesión expirada (CSRF/419) y, a falta de un mensaje reconocido,
 * devuelve el mensaje crudo tal cual en vez de un genérico.
 */

/**
 * Transforma un mensaje de error crudo del servidor en un mensaje amigable
 * con sugerencias concretas para quien opera el terminal.
 * @param {string|null} rawMessage
 * @returns {string}
 */
export function buildDetailedError(rawMessage) {
    if (!rawMessage) return "No se pudo completar la marcación. Por favor, intente nuevamente.";
    const msg = rawMessage.toLowerCase();
    if (msg.includes("no identificado") || msg.includes("not found") || msg.includes("no match")) {
        return "No se pudo reconocer su rostro. Asegúrese de estar frente a la cámara con buena iluminación, sin lentes de sol ni gorras, y mantenga el rostro quieto.";
    }
    if (msg.includes("descriptor") || msg.includes("muestra") || msg.includes("sample")) {
        return "No se detectó un rostro válido. Acerque el rostro a la cámara (30-60 cm) y asegúrese de tener buena iluminación frontal.";
    }
    if (msg.includes("conexión") || msg.includes("network") || msg.includes("fetch")) {
        return !navigator.onLine
            ? "Sin conexión a internet. Verifique la red del dispositivo y vuelva a intentar."
            : "Error de conexión al servidor. Verifique que el dispositivo tenga acceso a la red y vuelva a intentar.";
    }
    if (msg.includes("csrf") || msg.includes("419")) {
        return "La sesión expiró. Por favor, recargue la página para continuar.";
    }
    if (msg.includes("event") || msg.includes("evento") || msg.includes("allowed")) {
        return "No hay tipos de marcación disponibles para este empleado en este momento. Consulte con el departamento de RRHH.";
    }
    return rawMessage;
}
