<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /** Radio (metros) de marcación desde el celular cuando el empleado o la sucursal no definen el suyo. */
    public function up(): void
    {
        $this->migrator->add('general.attendance_location_default_radius', 150);
    }

    public function down(): void
    {
        $this->migrator->delete('general.attendance_location_default_radius');
    }
};
