<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /** Tolerancias de tardanza (entrada) y de horas extra: hasta ese margen no cuentan. */
    public function up(): void
    {
        $this->migrator->add('general.attendance_late_tolerance_minutes', 5);
        $this->migrator->add('general.attendance_overtime_tolerance_minutes', 15);
    }

    public function down(): void
    {
        $this->migrator->delete('general.attendance_late_tolerance_minutes');
        $this->migrator->delete('general.attendance_overtime_tolerance_minutes');
    }
};
