<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Carga el catálogo oficial de departamentos y ciudades de Paraguay (`py_departments` / `py_cities`)
     * en instalaciones que ya estaban desplegadas y nunca corrieron `ParaguayRegionsSeeder`: el deploy
     * solo ejecuta migraciones, así que sin esto el selector de ciudad de Empresas caería a la lista fija.
     *
     * Respeta los ids de `database/data/paraguay/*.json` (los mismos que usa el seeder), por lo que las
     * direcciones de empleados que ya apunten a `py_city_id` siguen siendo válidas. `insertOrIgnore`
     * hace que sea idempotente y que no pise filas existentes. Autocontenida: no depende de clases de la
     * aplicación. Se mantiene en sincronía con `ParaguayRegionsSeeder` (instalaciones nuevas).
     */
    public function up(): void
    {
        if (! Schema::hasTable('py_departments') || ! Schema::hasTable('py_cities')) {
            return;
        }

        $path = database_path('data/paraguay');
        $departments = json_decode(file_get_contents("{$path}/departments.json"), true);
        $cities = json_decode(file_get_contents("{$path}/cities.json"), true);
        $now = now()->toDateTimeString();

        DB::table('py_departments')->insertOrIgnore(array_map(fn (array $d) => [
            'id' => $d['id'],
            'name' => $d['name'],
            'capital' => $d['capital'],
            'created_at' => $now,
            'updated_at' => $now,
        ], $departments));

        foreach (array_chunk($cities, 100) as $chunk) {
            DB::table('py_cities')->insertOrIgnore(array_map(fn (array $c) => [
                'id' => $c['id'],
                'py_department_id' => $c['department_id'],
                'name' => $c['name'],
                'population' => $c['population'],
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }
    }

    /** No revierte: las direcciones de empleados pueden referenciar estas filas. */
    public function down(): void {}
};
