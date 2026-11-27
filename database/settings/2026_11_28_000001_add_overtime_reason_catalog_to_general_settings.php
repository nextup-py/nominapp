<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.attendance_overtime_reasons', [
            ['key' => 'cash_close', 'label' => 'Cierre de caja', 'active' => true],
            ['key' => 'cover_coworker', 'label' => 'Cubrir a un compañero', 'active' => true],
            ['key' => 'boss_request', 'label' => 'Pedido del jefe', 'active' => true],
            ['key' => 'pending_work', 'label' => 'Trabajo pendiente', 'active' => true],
            ['key' => 'other', 'label' => 'Otro', 'active' => true],
        ]);
        $this->migrator->add('general.attendance_overtime_reason_required', false);
        $this->migrator->add('general.attendance_overtime_reason_timeout_seconds', 20);
    }

    public function down(): void
    {
        $this->migrator->delete('general.attendance_overtime_reasons');
        $this->migrator->delete('general.attendance_overtime_reason_required');
        $this->migrator->delete('general.attendance_overtime_reason_timeout_seconds');
    }
};
