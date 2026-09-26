<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class ModuleSettings extends Settings
{
    public bool $loans_enabled;

    public bool $advances_enabled;

    public bool $merchandise_withdrawals_enabled;

    public bool $vacations_enabled;

    public bool $aguinaldo_enabled;

    public bool $warnings_enabled;

    public bool $employee_leaves_enabled;

    public bool $biometric_attendance_enabled;

    public bool $disbursement_batches_enabled;

    public static function group(): string
    {
        return 'modules';
    }
}
