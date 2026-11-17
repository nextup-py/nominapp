<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /** Minutos sin cargador a partir de los cuales se recomienda y se avisa que un terminal se enchufe (0 = apagado). */
    public function up(): void
    {
        $this->migrator->add('general.terminal_no_charger_minutes', 30);
    }

    public function down(): void
    {
        $this->migrator->delete('general.terminal_no_charger_minutes');
    }
};
