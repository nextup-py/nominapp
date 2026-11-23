<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * El umbral de desconexión global pasa de horas enteras a minutos (antes no podía bajar de 60 minutos).
     * Conserva el valor que ya tenía cada instalación: 2 horas pasan a 120 minutos.
     */
    public function up(): void
    {
        $this->migrator->rename('general.terminal_stale_threshold_hours', 'general.terminal_stale_threshold_minutes');
        $this->migrator->update('general.terminal_stale_threshold_minutes', fn (int $hours): int => max(5, $hours * 60));
    }

    /** Vuelve a horas enteras (mínimo 1 hora), redondeando hacia arriba. */
    public function down(): void
    {
        $this->migrator->update('general.terminal_stale_threshold_minutes', fn (int $minutes): int => max(1, (int) ceil($minutes / 60)));
        $this->migrator->rename('general.terminal_stale_threshold_minutes', 'general.terminal_stale_threshold_hours');
    }
};
