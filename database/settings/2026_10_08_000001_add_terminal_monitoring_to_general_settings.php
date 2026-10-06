<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /** Umbrales de las alertas de terminales y retención de su bitácora. */
    public function up(): void
    {
        $this->migrator->add('general.terminal_queue_stuck_minutes', 15);
        $this->migrator->add('general.terminal_low_battery_percent', 20);
        $this->migrator->add('general.terminal_events_retention_days', 90);
    }

    public function down(): void
    {
        $this->migrator->delete('general.terminal_queue_stuck_minutes');
        $this->migrator->delete('general.terminal_low_battery_percent');
        $this->migrator->delete('general.terminal_events_retention_days');
    }
};
