<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.attendance_overtime_allowed_default', true);
    }

    public function down(): void
    {
        $this->migrator->delete('general.attendance_overtime_allowed_default');
    }
};
