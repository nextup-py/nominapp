<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /** Días de anticipación del aviso de fin de período de prueba (0 = apagado). */
    public function up(): void
    {
        $this->migrator->add('general.contract_trial_alert_days', 7);
    }

    public function down(): void
    {
        $this->migrator->delete('general.contract_trial_alert_days');
    }
};
