<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /** Meses de retención del historial de auditoría (0 = conservar siempre). */
    public function up(): void
    {
        $this->migrator->add('general.audit_retention_months', 24);
    }

    public function down(): void
    {
        $this->migrator->delete('general.audit_retention_months');
    }
};
