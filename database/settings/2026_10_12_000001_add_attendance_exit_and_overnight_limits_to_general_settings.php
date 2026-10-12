<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /** Mínimo entrada→salida (minutos) y límite de antigüedad de una jornada nocturna abierta (horas). */
    public function up(): void
    {
        $this->migrator->add('general.attendance_min_exit_minutes', 2);
        $this->migrator->add('general.attendance_overnight_max_hours', 16);
    }

    public function down(): void
    {
        $this->migrator->delete('general.attendance_min_exit_minutes');
        $this->migrator->delete('general.attendance_overnight_max_hours');
    }
};
