<?php

use App\Filament\Resources\TerminalResource;
use App\Filament\Resources\TerminalResource\Pages\ViewTerminal;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * El tiempo de identificación (mediana y p90 de las últimas marcaciones) lo mide el navegador del equipo y viaja en
 * el heartbeat: es lo único que el servidor no puede ver y lo que explica un terminal «lento».
 */
function timingTerminal(array $deviceReport = []): Terminal
{
    static $n = 9700000;
    $n++;

    $company = Company::create(['name' => "EmpTM {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucTM {$n}", 'company_id' => $company->id]);
    $terminal = Terminal::create(['name' => "Terminal TM {$n}", 'branch_id' => $branch->id]);
    $terminal->claimSanctumToken();
    $terminal->update(['last_heartbeat_at' => now(), 'device_report_at' => now(), 'device_report' => $deviceReport === [] ? null : $deviceReport]);

    return $terminal->fresh();
}

it('el heartbeat guarda el tiempo de identificación informado por el equipo', function () {
    $terminal = timingTerminal();
    Sanctum::actingAs($terminal, [Terminal::SYNC_ABILITY]);

    $this->postJson('/api/v1/terminal/heartbeat', ['device' => [
        'identification_median_ms' => 2100, 'identification_p90_ms' => 4800, 'identification_samples' => 12,
    ]])->assertOk();

    expect($terminal->fresh()->identificationTiming())->toBe(['median_ms' => 2100, 'p90_ms' => 4800, 'samples' => 12]);
});

it('el heartbeat rechaza tiempos de identificación imposibles', function (array $device) {
    $terminal = timingTerminal();
    Sanctum::actingAs($terminal, [Terminal::SYNC_ABILITY]);

    $this->postJson('/api/v1/terminal/heartbeat', ['device' => $device])->assertUnprocessable();
})->with([
    'negativo' => [['identification_median_ms' => -1]],
    'desmedido' => [['identification_p90_ms' => 600001]],
    'texto' => [['identification_median_ms' => 'rapido']],
    'muestras de más' => [['identification_samples' => 1001]],
]);

it('un cliente viejo sin tiempo de identificación no pisa el último dato', function () {
    $terminal = timingTerminal(['identification_median_ms' => 3000, 'identification_p90_ms' => 5000, 'identification_samples' => 8]);
    Sanctum::actingAs($terminal, [Terminal::SYNC_ABILITY]);

    $this->postJson('/api/v1/terminal/heartbeat', ['pending_events' => 0])->assertOk();

    expect($terminal->fresh()->identificationTiming()['median_ms'])->toBe(3000);
});

it('sin reporte de identificación el terminal no tiene tiempo ni se considera lento', function () {
    $terminal = timingTerminal(['cached_employees' => 3]);

    expect($terminal->identificationTiming())->toBeNull()
        ->and($terminal->isIdentificationSlow())->toBeFalse()
        ->and(TerminalResource::describeIdentification($terminal))->toBeNull();
});

it('considera lento al equipo cuando la mediana llega al umbral', function (int $medianMs, bool $slow) {
    $terminal = timingTerminal(['identification_median_ms' => $medianMs, 'identification_p90_ms' => $medianMs + 1000, 'identification_samples' => 10]);

    expect($terminal->isIdentificationSlow())->toBe($slow);
})->with([
    'rápido' => [1800, false],
    'justo debajo' => [Terminal::SLOW_IDENTIFICATION_MS - 1, false],
    'en el umbral' => [Terminal::SLOW_IDENTIFICATION_MS, true],
    'muy lento' => [15000, true],
]);

it('describe el tiempo de identificación en segundos y avisa cuando es lento', function () {
    $normal = timingTerminal(['identification_median_ms' => 2100, 'identification_p90_ms' => 4800, 'identification_samples' => 12]);
    $lento = timingTerminal(['identification_median_ms' => 9500, 'identification_p90_ms' => 14200, 'identification_samples' => 5]);

    expect(TerminalResource::describeIdentification($normal))->toBe('2,1 s habitual · 4,8 s el 10 % más lento (últimas 12)')
        ->and(TerminalResource::describeIdentification($lento))->toContain('9,5 s habitual')->toContain('lento: revisar el equipo');
});

it('el detalle del terminal muestra el tiempo de identificación', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $terminal = timingTerminal(['identification_median_ms' => 2100, 'identification_p90_ms' => 4800, 'identification_samples' => 12]);

    Livewire::test(ViewTerminal::class, ['record' => $terminal->getKey()])
        ->assertSee('Tiempo de identificación')
        ->assertSee('2,1 s habitual');
});
