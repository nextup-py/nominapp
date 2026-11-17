<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /** Días de anticipación del aviso de documentos por vencer (0 = apagado). */
    public function up(): void
    {
        $this->migrator->add('general.document_alert_days', 30);
    }

    public function down(): void
    {
        $this->migrator->delete('general.document_alert_days');
    }
};
