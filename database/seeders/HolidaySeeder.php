<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Siembra los feriados nacionales oficiales de Paraguay para el año en curso.
 *
 * Idempotente: usa insertOrIgnore sobre la fecha (columna única) para poder
 * correrse múltiples veces sin duplicar registros ni pisar ediciones manuales
 * que el usuario haya hecho desde el panel.
 */
class HolidaySeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $year = (int) date('Y');

        $easter = $this->getEasterDate($year);

        $holidays = [
            ['date' => "$year-01-01", 'name' => 'Año Nuevo'],
            ['date' => "$year-02-03", 'name' => 'San Blas — Patrono del Paraguay'],
            ['date' => "$year-03-01", 'name' => 'Día de los Héroes'],
            ['date' => $easter->copy()->subDays(3)->toDateString(), 'name' => 'Jueves Santo'],
            ['date' => $easter->copy()->subDays(2)->toDateString(), 'name' => 'Viernes Santo'],
            ['date' => "$year-05-01", 'name' => 'Día del Trabajador'],
            ['date' => "$year-05-14", 'name' => 'Independencia Nacional (día 1)'],
            ['date' => "$year-05-15", 'name' => 'Independencia Nacional (día 2)'],
            ['date' => "$year-06-12", 'name' => 'Paz del Chaco'],
            ['date' => "$year-08-15", 'name' => 'Fundación de Asunción'],
            ['date' => "$year-09-29", 'name' => 'Victoria de Boquerón'],
            ['date' => "$year-12-08", 'name' => 'Virgen de Caacupé'],
            ['date' => "$year-12-25", 'name' => 'Navidad'],
        ];

        DB::table('holidays')->insertOrIgnore(
            collect($holidays)->map(fn ($h) => array_merge($h, [
                'created_at' => $now,
                'updated_at' => $now,
            ]))->toArray()
        );
    }

    /**
     * Calcula la fecha de Domingo de Pascua usando easter_days() de PHP.
     */
    private function getEasterDate(int $year): Carbon
    {
        $base = Carbon::createFromDate($year, 3, 21);

        return $base->addDays(easter_days($year));
    }
}
