<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Confirmación explícita de salida y descansos en el terminal (activada) y segundos que espera la
     * pantalla de selección/confirmación antes de volver a la cámara sin registrar nada (15; 0 = sin límite).
     */
    public function up(): void
    {
        $this->migrator->add('general.terminal_confirm_exit', true);
        $this->migrator->add('general.terminal_selection_timeout_seconds', 15);
    }

    public function down(): void
    {
        $this->migrator->delete('general.terminal_confirm_exit');
        $this->migrator->delete('general.terminal_selection_timeout_seconds');
    }
};
