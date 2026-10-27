/**
 * =============================================================================
 * AUTO-REGISTRO FACIAL - EMPLEADOS
 * =============================================================================
 *
 * @fileoverview Punto de entrada para el auto-registro facial por empleados.
 *               Extiende FaceCaptureApp con comportamiento específico:
 *               - No redirige a /employees al cerrar modal
 *               - Deshabilita controles después de enviar exitosamente
 *               - Mensaje de éxito diferente (pendiente de aprobación)
 *
 * @requires ../shared/FaceCaptureApp.js
 * @requires face-api.js (cargado via CDN en la vista)
 */

import { FaceCaptureApp } from '../shared/FaceCaptureApp.js';

class EnrollmentCaptureApp extends FaceCaptureApp {

    /**
     * Override: después de guardar exitosamente, deshabilita todo.
     * El empleado no puede reintentar ni navegar a /employees.
     */
    async handleSaveDescriptor(event) {
        if (event) {
            event.preventDefault();
        }

        if (!this.currentDescriptor) {
            this.updateStatus("No hay descriptor para guardar");
            return;
        }

        try {
            this.setButtonState(this.btnSave, false, "Enviando...");
            this.setButtonState(this.btnStart, false);
            this.updateStatus("Enviando registro facial...");

            await this.saveDescriptorAjax();

            // Deshabilitar todos los controles
            this.setButtonState(this.btnCapture, false);
            this.setButtonState(this.btnStart, false);
            this.setButtonState(this.btnSave, false);
            await this.stopCamera();

            this.showSuccessModal();
        } catch (error) {
            this.handleError("Error al enviar el registro", error);
            this.setButtonState(this.btnSave, true, "Enviar Registro");
        }
    }

    /**
     * Override: si la empresa cargó un aviso de privacidad, la cámara no arranca hasta que se acepte.
     */
    async handleStartCamera() {
        const consent = document.getElementById("privacyConsentCheck");
        if (consent && !consent.checked) {
            this.updateStatus("Para continuar, acepte el aviso de privacidad");
            consent.focus();
            return;
        }
        return super.handleStartCamera();
    }

    /**
     * Override: no redirige a /employees, solo cierra el modal.
     */
    hideModal() {
        if (this.modal) {
            this.modal.style.display = "none";
            this.modal.style.opacity = "0";
            this.modal.style.visibility = "hidden";
        }
    }

    /**
     * Override: al cerrar modal redirigir al mismo URL.
     * El servidor detectará status=pending_approval y mostrará la vista "ya enviado".
     */
    handleCloseModal() {
        window.location.reload();
    }
}

// =============================================================================
// INICIALIZACIÓN
// =============================================================================

document.addEventListener("DOMContentLoaded", () => {
    try {
        if (typeof faceapi === "undefined") {
            console.error("face-api.js no está cargado");
            document.getElementById("status").textContent =
                "Error: No se pudo cargar la biblioteca de reconocimiento facial";
            return;
        }

        window.enrollmentApp = new EnrollmentCaptureApp();
        console.log("Aplicación de auto-registro facial inicializada");

        window.enrollmentApp.initializeSystem();
    } catch (error) {
        console.error("Error al inicializar la aplicación:", error);
        document.getElementById(
            "status"
        ).textContent = `Error de inicialización: ${error.message}`;
    }
});
