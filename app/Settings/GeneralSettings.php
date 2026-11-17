<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class GeneralSettings extends Settings
{
    public ?string $timezone;

    public int $contract_alert_days;

    /** La segunda renovación de un contrato a plazo fijo pasa a indefinido (Art. 53 CLT); si es false no cambia solo. */
    public bool $contract_force_indefinite_on_second_renewal;

    public int $face_enrollment_expiry_hours;

    public int $absence_threshold_minutes;

    /** Minutos mínimos entre una salida y una nueva entrada del mismo día (evita reentradas por error); 0 = sin mínimo. */
    public int $attendance_min_reentry_minutes;

    /** Minutos mínimos entre una entrada y la salida siguiente (evita salidas por toque equivocado); 0 = sin mínimo. */
    public int $attendance_min_exit_minutes;

    /** Horas máximas desde la última marcación de una jornada abierta de ayer para seguir tratándola como turno nocturno en curso; 0 = sin límite. */
    public int $attendance_overnight_max_hours;

    /** Cierra solo, cada madrugada, las jornadas abiertas de días anteriores (ver `attendance:auto-close-open-shifts`). */
    public bool $attendance_auto_close_enabled;

    /** Hora ("HH:MM") a la que corre el cierre automático. */
    public string $attendance_auto_close_time;

    /** Avisa cada noche a quienes ven asistencias de quién se fue sin marcar la salida (ver `attendance:notify-no-exit-today`). */
    public bool $attendance_no_exit_notice_enabled;

    /** Hora ("HH:MM") a la que se envía el aviso del mismo día. */
    public string $attendance_no_exit_notice_time;

    public float $face_threshold;

    public float $face_min_confidence_gap;

    public int $terminal_stale_threshold_hours;

    /** Minutos con la cola offline sin vaciarse antes de avisar que está atascada. */
    public int $terminal_queue_stuck_minutes;

    /** Porcentaje de batería (sin cargador) por debajo del cual se avisa. */
    public int $terminal_low_battery_percent;

    /** Segundos de desfase del reloj de un terminal (respecto del servidor) a partir de los cuales se avisa. */
    public int $terminal_clock_skew_max_seconds;

    /** Minutos tras los que la cámara apagada a mano en un terminal se reenciende sola; 0 = nunca. */
    public int $terminal_camera_resume_minutes;

    /** La salida y los descansos piden un toque de confirmación en el terminal en vez de registrarse solos al reconocer al empleado. */
    public bool $terminal_confirm_exit;

    /** Segundos que espera la pantalla de selección/confirmación del terminal antes de volver a la cámara sin registrar nada; 0 = sin límite. */
    public int $terminal_selection_timeout_seconds;

    /** Permiso de marcación desde el celular cuando ni el empleado, ni su sucursal ni su empresa lo definen. */
    public bool $attendance_mobile_marking_default;

    /** Radio (m) de marcación desde el celular cuando el empleado o la sucursal no definen el suyo. */
    public int $attendance_location_default_radius;

    /** Días de retención de la bitácora de terminales; 0 = conservar siempre. */
    public int $terminal_events_retention_days;

    /** Días de retención del resumen diario de confiabilidad de los terminales; 0 = conservar siempre. */
    public int $terminal_stats_retention_days;

    /** Disponibilidad semanal (%) por debajo de la cual se avisa de un terminal; 0 = no avisar. */
    public int $terminal_min_availability_percent;

    public bool $setup_completed;

    /** Meses de retención del historial de auditoría; 0 = conservar siempre. */
    public int $audit_retention_months;

    /** Envío de correo configurado desde el panel; si es false rige el `.env` (ver `MailConfigurator`). */
    public bool $mail_enabled;

    public string $mail_host;

    public int $mail_port;

    /** `tls` (STARTTLS, puerto 587 habitual) o `ssl` (conexión cifrada desde el inicio, puerto 465). */
    public string $mail_encryption;

    public string $mail_username;

    /** Se guarda cifrada (ver `encrypted()`) y nunca se envía al navegador. */
    public string $mail_password;

    public string $mail_from_address;

    public string $mail_from_name;

    /**
     * Propiedades que Spatie guarda cifradas en la base.
     *
     * @return array<int, string>
     */
    public static function encrypted(): array
    {
        return ['mail_password'];
    }

    public static function group(): string
    {
        return 'general';
    }
}
