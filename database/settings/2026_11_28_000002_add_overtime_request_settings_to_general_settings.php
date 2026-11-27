<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.attendance_overtime_request_required_default', false);
        $this->migrator->add('general.attendance_overtime_request_min_notice_hours', 0);
    }

    public function down(): void
    {
        $this->migrator->delete('general.attendance_overtime_request_required_default');
        $this->migrator->delete('general.attendance_overtime_request_min_notice_hours');
    }
};
