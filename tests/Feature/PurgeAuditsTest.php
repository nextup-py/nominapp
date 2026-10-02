<?php

use App\Console\Commands\PurgeAudits;
use App\Filament\Pages\ManageGeneralSettings;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * audits:purge — elimina el historial de auditoría más antiguo que la retención
 * configurada en Configuración General (0 = conservar siempre).
 */
afterEach(function () {
    Carbon\Carbon::setTestNow();
});

/** Inserta registros de auditoría creados hace `$monthsAgo` meses. */
function insertAudits(int $count, int $monthsAgo): void
{
    $createdAt = now()->subMonths($monthsAgo)->subDay();

    foreach (array_chunk(range(1, $count), 1000) as $chunk) {
        DB::table('audits')->insert(array_map(fn () => [
            'event' => 'updated',
            'auditable_type' => 'App\\Models\\Warning',
            'auditable_id' => 1,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ], $chunk));
    }
}

it('la retención por defecto es de 24 meses', function () {
    expect(app(GeneralSettings::class)->audit_retention_months)->toBe(24);
});

it('elimina solo los registros más antiguos que la retención configurada', function () {
    insertAudits(3, 25);
    insertAudits(2, 23);

    Artisan::call('audits:purge');

    expect(DB::table('audits')->count())->toBe(2);
});

it('usa la retención de Configuración General', function () {
    insertAudits(2, 7);
    insertAudits(1, 3);

    $settings = app(GeneralSettings::class);
    $settings->audit_retention_months = 6;
    $settings->save();

    Artisan::call('audits:purge');

    expect(DB::table('audits')->count())->toBe(1);
});

it('con retención 0 no elimina nada', function () {
    insertAudits(2, 60);

    $settings = app(GeneralSettings::class);
    $settings->audit_retention_months = 0;
    $settings->save();

    $exit = Artisan::call('audits:purge');

    expect($exit)->toBe(0)
        ->and(DB::table('audits')->count())->toBe(2);
});

it('--months reemplaza la retención configurada', function () {
    insertAudits(2, 7);
    insertAudits(1, 3);

    Artisan::call('audits:purge', ['--months' => 6]);

    expect(DB::table('audits')->count())->toBe(1);
});

it('--dry-run informa pero no elimina', function () {
    insertAudits(4, 30);

    Artisan::call('audits:purge', ['--dry-run' => true]);

    expect(Artisan::output())->toContain('4 registros')
        ->and(DB::table('audits')->count())->toBe(4);
});

it('rechaza una retención negativa sin eliminar nada', function () {
    insertAudits(2, 30);

    $exit = Artisan::call('audits:purge', ['--months' => -1]);

    expect($exit)->toBe(1)
        ->and(DB::table('audits')->count())->toBe(2);
});

it('purga por lotes cuando hay más registros que el tamaño de lote', function () {
    insertAudits(5100, 30);
    insertAudits(1, 1);

    Artisan::call('audits:purge');

    expect(DB::table('audits')->count())->toBe(1);
});

it('no desborda el mes al calcular la fecha de corte (31 de marzo, 1 mes)', function () {
    Carbon\Carbon::setTestNow('2026-03-31 03:00:00');

    DB::table('audits')->insert([
        ['event' => 'updated', 'auditable_type' => 'X', 'auditable_id' => 1, 'created_at' => '2026-02-27 10:00:00', 'updated_at' => '2026-02-27 10:00:00'],
        ['event' => 'updated', 'auditable_type' => 'X', 'auditable_id' => 1, 'created_at' => '2026-03-01 10:00:00', 'updated_at' => '2026-03-01 10:00:00'],
    ]);

    Artisan::call('audits:purge', ['--months' => 1]);

    expect(DB::table('audits')->count())->toBe(1);
});

it('está programado a diario', function () {
    Artisan::call('schedule:list');

    expect(Artisan::output())->toContain('audits:purge');
    expect(class_exists(PurgeAudits::class))->toBeTrue();
});

it('Configuración General guarda la retención de auditoría', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    Livewire::test(ManageGeneralSettings::class)
        ->fillForm(['audit_retention_months' => 12])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(GeneralSettings::class)->audit_retention_months)->toBe(12);
});

it('Configuración General rechaza una retención fuera de rango', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    Livewire::test(ManageGeneralSettings::class)
        ->fillForm(['audit_retention_months' => 121])
        ->call('save')
        ->assertHasFormErrors(['audit_retention_months']);
});
