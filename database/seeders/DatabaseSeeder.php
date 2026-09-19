<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeder por defecto de `php artisan db:seed` (sin --class).
 *
 * Pregunta interactivamente qué sembrar y delega en DemoSeeder o
 * ProductionSeeder — evita que este archivo duplique su lógica y quede
 * desactualizado respecto a ellos, como ocurría antes.
 *
 * En modo no interactivo (--no-interaction, CI, etc.) usa Demo por defecto,
 * igual que el comportamiento previo de este comando.
 */
class DatabaseSeeder extends Seeder
{
    private const OPTION_DEMO = 'Demo — datos ficticios completos (empresa, empleados, nómina de ejemplo)';

    private const OPTION_PRODUCTION = 'Producción — solo datos base para un cliente nuevo (admin + catálogos obligatorios)';

    public function run(): void
    {
        $selected = $this->command->choice(
            '¿Qué querés sembrar?',
            [self::OPTION_DEMO, self::OPTION_PRODUCTION],
            self::OPTION_DEMO
        );

        $this->call($selected === self::OPTION_PRODUCTION ? ProductionSeeder::class : DemoSeeder::class);
    }
}
