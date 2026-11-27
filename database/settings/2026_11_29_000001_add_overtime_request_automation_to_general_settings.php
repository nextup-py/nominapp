<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.attendance_overtime_request_auto_approve_hours', 0);
        $this->migrator->add('general.attendance_overtime_request_boss_escalation_hours', 0);
        $this->migrator->add('general.attendance_overtime_weekly_alert_hours', 0);
        $this->migrator->add('general.attendance_overtime_monthly_alert_hours', 0);
    }

    public function down(): void
    {
        foreach (['request_auto_approve_hours', 'request_boss_escalation_hours', 'weekly_alert_hours', 'monthly_alert_hours'] as $key) {
            $this->migrator->delete("general.attendance_overtime_{$key}");
        }
    }
};
