<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /** Retención del resumen diario de confiabilidad y disponibilidad mínima antes de avisar. */
    public function up(): void
    {
        $this->migrator->add('general.terminal_stats_retention_days', 90);
        $this->migrator->add('general.terminal_min_availability_percent', 90);
    }

    public function down(): void
    {
        $this->migrator->delete('general.terminal_stats_retention_days');
        $this->migrator->delete('general.terminal_min_availability_percent');
    }
};
