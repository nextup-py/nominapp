<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Todos arrancan en true (activado): los módulos son "opt-out", no
        // "opt-in" — así ningún Resource desaparece si el wizard de
        // configuración inicial se salta o falla antes de llegar al paso de
        // módulos. El único flag que realmente bloquea el panel es
        // general.setup_completed (ver migración hermana).
        $this->migrator->add('modules.loans_enabled', true);
        $this->migrator->add('modules.advances_enabled', true);
        $this->migrator->add('modules.merchandise_withdrawals_enabled', true);
        $this->migrator->add('modules.vacations_enabled', true);
        $this->migrator->add('modules.aguinaldo_enabled', true);
        $this->migrator->add('modules.warnings_enabled', true);
        $this->migrator->add('modules.employee_leaves_enabled', true);
        $this->migrator->add('modules.biometric_attendance_enabled', true);
        $this->migrator->add('modules.disbursement_batches_enabled', true);
    }

    public function down(): void
    {
        $this->migrator->delete('modules.loans_enabled');
        $this->migrator->delete('modules.advances_enabled');
        $this->migrator->delete('modules.merchandise_withdrawals_enabled');
        $this->migrator->delete('modules.vacations_enabled');
        $this->migrator->delete('modules.aguinaldo_enabled');
        $this->migrator->delete('modules.warnings_enabled');
        $this->migrator->delete('modules.employee_leaves_enabled');
        $this->migrator->delete('modules.biometric_attendance_enabled');
        $this->migrator->delete('modules.disbursement_batches_enabled');
    }
};
