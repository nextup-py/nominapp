<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * La segunda renovación de un plazo fijo pasa a indefinido (Art. 53 CLT) salvo que se desactive aquí o se
     * mantenga el plazo fijo con un motivo en esa renovación. Activado por defecto: no cambia el comportamiento.
     */
    public function up(): void
    {
        $this->migrator->add('general.contract_force_indefinite_on_second_renewal', true);
    }

    public function down(): void
    {
        $this->migrator->delete('general.contract_force_indefinite_on_second_renewal');
    }
};
