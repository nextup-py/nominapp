<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class GeneralSettings extends Settings
{
    public ?string $timezone;

    public int $contract_alert_days;

    public int $face_enrollment_expiry_hours;

    public int $absence_threshold_minutes;

    public float $face_threshold;

    public float $face_min_confidence_gap;

    public int $terminal_stale_threshold_hours;

    public static function group(): string
    {
        return 'general';
    }
}
