<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // false = cuenta los días hábiles globales (vacation_business_days); true = según el horario de cada empleado
        $this->migrator->add('payroll.vacation_count_by_schedule', false);
    }

    public function down(): void
    {
        $this->migrator->delete('payroll.vacation_count_by_schedule');
    }
};
