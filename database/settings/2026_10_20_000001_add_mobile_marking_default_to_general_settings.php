<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /** Si los empleados pueden marcar desde su celular cuando ni ellos, ni su sucursal ni su empresa lo definen. */
    public function up(): void
    {
        $this->migrator->add('general.attendance_mobile_marking_default', false);
    }

    public function down(): void
    {
        $this->migrator->delete('general.attendance_mobile_marking_default');
    }
};
