<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /** Minutos que una cámara apagada a mano en el terminal tarda en volver a encenderse sola (0 = nunca). */
    public function up(): void
    {
        $this->migrator->add('general.terminal_camera_resume_minutes', 2);
    }

    public function down(): void
    {
        $this->migrator->delete('general.terminal_camera_resume_minutes');
    }
};
