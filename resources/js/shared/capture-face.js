/**
 * =============================================================================
 * CAPTURA FACIAL — ENTRY POINT UNIFICADO
 * =============================================================================
 *
 * Lee `data-mode` del body para instanciar la clase correcta:
 *   - "employee"   → FaceCaptureApp        (admin, guarda directo)
 *   - "enrollment" → EnrollmentCaptureApp  (auto-registro, sin redirect)
 *
 * @requires ./FaceCaptureApp.js
 * @requires face-api.js (cargado via CDN en la vista)
 */

import { FaceCaptureApp } from './FaceCaptureApp.js';
import { initThemeToggle } from './theme-toggle.js';

/* ============================================================
   CLASE ESPECÍFICA PARA AUTO-REGISTRO (ENROLLMENT)
   ============================================================ */

class EnrollmentCaptureApp extends FaceCaptureApp {

    /**
     * Override: después de guardar exitosamente, deshabilita todo
     * y no muestra opción de reintento (el registro ya fue enviado).
     */
    async handleSaveDescriptor(event) {
        if (event) event.preventDefault();

        if (!this.currentDescriptor) {
            this.updateStatus("No hay descriptor para guardar");
            return;
        }

        try {
            this.setButtonState(this.btnSave, false, "Enviando...");
            this.setButtonState(this.btnStart, false);
            this.updateStatus("Enviando registro facial...");

            await this.saveDescriptorAjax();

            this.setButtonState(this.btnCapture, false);
            this.setButtonState(this.btnStart, false);
            this.setButtonState(this.btnSave, false);
            await this.stopCamera();

            this.showSuccessModal();
        } catch (error) {
            this.handleError("Error al enviar el registro", error);
            this.setButtonState(this.btnSave, true, "Guardar");
        }
    }

    /**
     * Override: no redirige a /employees, solo oculta el modal.
     */
    hideModal() {
        if (this.modal) {
            this.modal.classList.add("hidden");
        }
    }

    /**
     * Override: al cerrar el modal redirigir al mismo URL.
     * El servidor detectará status=pending_approval y mostrará la vista "ya enviado".
     */
    handleCloseModal() {
        window.location.reload();
    }
}

/* ============================================================
   INICIALIZACIÓN
   ============================================================ */

document.addEventListener("DOMContentLoaded", () => {
    initThemeToggle('capture-face-theme');

    try {
        if (typeof faceapi === "undefined") {
            console.error("face-api.js no está cargado");
            document.getElementById("status").textContent =
                "Error: No se pudo cargar la biblioteca de reconocimiento facial";
            return;
        }

        const mode = document.body.dataset.mode || "employee";
        const AppClass = mode === "enrollment" ? EnrollmentCaptureApp : FaceCaptureApp;

        window.faceCaptureApp = new AppClass();
        console.log(`Aplicación de captura facial inicializada (modo: ${mode})`);

        window.faceCaptureApp.initializeSystem();
    } catch (error) {
        console.error("Error al inicializar la aplicación:", error);
        document.getElementById("status").textContent =
            `Error de inicialización: ${error.message}`;
    }
});
