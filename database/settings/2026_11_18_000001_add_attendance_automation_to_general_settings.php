<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /** Cierre automático de jornadas sin salida y aviso del mismo día, con su hora configurable. */
    public function up(): void
    {
        $this->migrator->add('general.attendance_auto_close_enabled', false);
        $this->migrator->add('general.attendance_auto_close_time', '05:30');
        $this->migrator->add('general.attendance_no_exit_notice_enabled', true);
        $this->migrator->add('general.attendance_no_exit_notice_time', '21:30');
    }

    public function down(): void
    {
        $this->migrator->delete('general.attendance_auto_close_enabled');
        $this->migrator->delete('general.attendance_auto_close_time');
        $this->migrator->delete('general.attendance_no_exit_notice_enabled');
        $this->migrator->delete('general.attendance_no_exit_notice_time');
    }
};
