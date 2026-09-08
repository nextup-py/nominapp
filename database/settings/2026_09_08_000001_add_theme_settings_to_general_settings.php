<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Agrega los campos de tema (color primario y tipografía) a GeneralSettings.
     * Los valores iniciales coinciden con los hardcodeados actualmente en AdminPanelProvider.php y fonts.css.
     */
    public function up(): void
    {
        $this->migrator->add('general.primary_color', 'teal');
        $this->migrator->add('general.font', 'poppins');
    }

    /**
     * Revierte la migración eliminando los campos de tema.
     */
    public function down(): void
    {
        $this->migrator->delete('general.primary_color');
        $this->migrator->delete('general.font');
    }
};
