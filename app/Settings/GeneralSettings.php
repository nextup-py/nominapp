<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class GeneralSettings extends Settings
{
    public ?string $timezone;

    public int $contract_alert_days;

    public int $face_enrollment_expiry_hours;

    public int $absence_threshold_minutes;

    /** Minutos mínimos entre una salida y una nueva entrada del mismo día (evita reentradas por error); 0 = sin mínimo. */
    public int $attendance_min_reentry_minutes;

    public float $face_threshold;

    public float $face_min_confidence_gap;

    public int $terminal_stale_threshold_hours;

    /** Minutos con la cola offline sin vaciarse antes de avisar que está atascada. */
    public int $terminal_queue_stuck_minutes;

    /** Porcentaje de batería (sin cargador) por debajo del cual se avisa. */
    public int $terminal_low_battery_percent;

    /** Días de retención de la bitácora de terminales; 0 = conservar siempre. */
    public int $terminal_events_retention_days;

    public bool $setup_completed;

    /** Meses de retención del historial de auditoría; 0 = conservar siempre. */
    public int $audit_retention_months;

    public static function group(): string
    {
        return 'general';
    }
}
