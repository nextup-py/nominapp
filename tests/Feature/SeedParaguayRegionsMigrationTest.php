<?php

use App\Models\PyCity;
use App\Models\PyDepartment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** Ejecuta la migración de datos tal como lo haría `migrate` en un deploy. */
function runRegionsMigration(): void
{
    (require database_path('migrations/2026_10_10_100000_seed_paraguay_regions.php'))->up();
}

it('carga los 17 departamentos y las 257 ciudades oficiales', function () {
    PyCity::query()->delete();
    PyDepartment::query()->delete();

    runRegionsMigration();

    expect(PyDepartment::count())->toBe(17)
        ->and(PyCity::count())->toBe(257)
        ->and(PyCity::where('name', 'Luque')->first()->department->name)->toBe('Central');
});

it('es idempotente y no pisa filas existentes', function () {
    PyCity::query()->delete();
    PyDepartment::query()->delete();

    runRegionsMigration();
    PyCity::where('name', 'Luque')->update(['population' => 1]);
    runRegionsMigration();

    expect(PyCity::count())->toBe(257)->and(PyDepartment::count())->toBe(17)
        ->and(PyCity::where('name', 'Luque')->value('population'))->toBe(1);
});

it('conserva los ids para no invalidar direcciones ya cargadas', function () {
    PyCity::query()->delete();
    PyDepartment::query()->delete();
    $expected = collect(json_decode(file_get_contents(database_path('data/paraguay/cities.json')), true))
        ->pluck('name', 'id')->all();

    runRegionsMigration();

    expect(DB::table('py_cities')->pluck('name', 'id')->all())->toBe($expected);
});

it('completa una instalación sembrada a medias sin duplicar', function () {
    PyCity::query()->delete();
    PyDepartment::query()->delete();
    PyDepartment::insert(['id' => 1, 'name' => 'Central', 'capital' => 'Areguá', 'created_at' => now(), 'updated_at' => now()]);

    runRegionsMigration();

    expect(PyDepartment::count())->toBe(17)->and(PyCity::count())->toBe(257);
});
