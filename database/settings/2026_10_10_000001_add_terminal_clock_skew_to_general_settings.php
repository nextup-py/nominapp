<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /** Segundos de desfase del reloj del dispositivo (respecto del servidor) a partir de los cuales se avisa. */
    public function up(): void
    {
        $this->migrator->add('general.terminal_clock_skew_max_seconds', 120);
    }

    public function down(): void
    {
        $this->migrator->delete('general.terminal_clock_skew_max_seconds');
    }
};
