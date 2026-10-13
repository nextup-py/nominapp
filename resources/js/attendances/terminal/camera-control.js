// resources/js/attendances/terminal/camera-control.js
/**
 * @fileoverview Apagado manual de la cámara del terminal. Mientras está apagada
 * no se identifica a nadie; vuelve a encenderse sola pasado el tiempo configurado
 * (Configuración General → `terminal_camera_resume_minutes`, 0 = nunca) para que un
 * olvido no deje el terminal inutilizado. Sin DOM ni cámara propios: recibe
 * callbacks, igual que `empty-state.js`.
 */

/**
 * @param {{
 *   getResumeMinutes: () => Promise<number>|number,
 *   onChange: (state: {paused: boolean, resumeAt: number|null}) => void,
 *   now?: () => number,
 * }} deps
 */
export function createCameraControl({ getResumeMinutes, onChange, now = () => Date.now() }) {
    let paused = false;
    let resumeTimer = null;
    let resumeAt = null;

    function clearTimer() {
        if (resumeTimer) clearTimeout(resumeTimer);
        resumeTimer = null;
        resumeAt = null;
    }

    return {
        /** @returns {boolean} */
        isPaused: () => paused,

        /** Apaga la cámara y agenda el reencendido automático si está configurado. */
        async pause() {
            if (paused) return;
            paused = true;
            clearTimer();
            const minutes = Number(await getResumeMinutes());
            if (Number.isFinite(minutes) && minutes > 0) {
                resumeAt = now() + minutes * 60_000;
                resumeTimer = setTimeout(() => this.resume(), minutes * 60_000);
            }
            onChange({ paused: true, resumeAt });
        },

        /** Enciende la cámara de nuevo (a mano o por el temporizador). */
        resume() {
            if (!paused) return;
            paused = false;
            clearTimer();
            onChange({ paused: false, resumeAt: null });
        },

        /** Alterna entre apagada y encendida. */
        async toggle() {
            if (paused) this.resume();
            else await this.pause();
        },
    };
}
