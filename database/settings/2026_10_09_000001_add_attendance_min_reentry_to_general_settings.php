<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /** Minutos mínimos entre una salida y la nueva entrada del mismo día (segundo turno). */
    public function up(): void
    {
        $this->migrator->add('general.attendance_min_reentry_minutes', 5);
    }

    public function down(): void
    {
        $this->migrator->delete('general.attendance_min_reentry_minutes');
    }
};
