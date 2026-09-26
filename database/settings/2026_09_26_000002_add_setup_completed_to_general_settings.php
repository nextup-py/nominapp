<?php

use App\Support\InstallationDetector;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Si ya existe alguna Company, esta es una instalación existente que
        // corre la migración por primera vez tras el deploy de este feature
        // (ej. Macro, Bar777, Arca) — nunca debe forzársele el wizard. Si
        // `companies` está vacía, es una instalación genuinamente nueva
        // (ProductionSeeder recién corrido) y debe quedar pendiente.
        $this->migrator->add('general.setup_completed', InstallationDetector::hasExistingCompany());
    }

    public function down(): void
    {
        $this->migrator->delete('general.setup_completed');
    }
};
