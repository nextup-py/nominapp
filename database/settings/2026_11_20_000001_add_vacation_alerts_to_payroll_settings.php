<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Días que una solicitud puede esperar sin decisión antes de avisar (0 = no avisar)
        $this->migrator->add('payroll.vacation_alert_pending_days', 3);
        // Aviso de vacaciones aprobadas con inicio hace más de una semana que no figuran pagadas
        $this->migrator->add('payroll.vacation_alert_unpaid_enabled', true);
        // Aviso semanal (lunes) de empleados con el derecho anual completo sin tomar
        $this->migrator->add('payroll.vacation_alert_unused_enabled', true);
        // Días de anticipación para avisar que alguien cumple su primer año (0 = no avisar)
        $this->migrator->add('payroll.vacation_alert_anniversary_days', 30);
    }

    public function down(): void
    {
        $this->migrator->delete('payroll.vacation_alert_pending_days');
        $this->migrator->delete('payroll.vacation_alert_unpaid_enabled');
        $this->migrator->delete('payroll.vacation_alert_unused_enabled');
        $this->migrator->delete('payroll.vacation_alert_anniversary_days');
    }
};
