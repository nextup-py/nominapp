<?php

use App\Filament\Resources\TerminalResource;
use App\Filament\Resources\TerminalResource\Pages\EditTerminal;
use App\Filament\Resources\TerminalResource\Pages\ListTerminals;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Terminal;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * «Confirmar salida y descansos» por terminal: `terminals.confirm_exit` (null = hereda Configuración General), para
 * poder probar sin confirmación en una sucursal sin cambiar el comportamiento de todas.
 */
function confirmExitTerminal(?bool $confirmExit = null): Terminal
{
    static $n = 9800000;
    $n++;

    $company = Company::create(['name' => "EmpCE {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucCE {$n}", 'company_id' => $company->id]);
    $terminal = Terminal::create(['name' => "Terminal CE {$n}", 'branch_id' => $branch->id, 'confirm_exit' => $confirmExit]);
    $terminal->claimSanctumToken();

    return $terminal->fresh();
}

function setGlobalConfirmExit(bool $value): void
{
    $settings = app(GeneralSettings::class);
    $settings->terminal_confirm_exit = $value;
    $settings->save();
}

function confirmExitAdmin(): void
{
    test()->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
}

it('sin valor propio, el terminal usa el de Configuración General', function (bool $global) {
    setGlobalConfirmExit($global);

    expect(confirmExitTerminal(null)->confirmExitEnabled())->toBe($global);
})->with([true, false]);

it('el valor propio del terminal gana sobre Configuración General', function (bool $global, bool $own) {
    setGlobalConfirmExit($global);

    expect(confirmExitTerminal($own)->confirmExitEnabled())->toBe($own);
})->with([
    'general sí, terminal no' => [true, false],
    'general no, terminal sí' => [false, true],
]);

it('el heartbeat entrega a cada terminal su propio valor de confirmación', function () {
    setGlobalConfirmExit(true);

    $hereda = confirmExitTerminal(null);
    $sinConfirmar = confirmExitTerminal(false);

    Sanctum::actingAs($hereda, [Terminal::SYNC_ABILITY]);
    $this->postJson('/api/v1/terminal/heartbeat', [])->assertOk()->assertJsonPath('config.confirm_exit', true);

    Sanctum::actingAs($sinConfirmar, [Terminal::SYNC_ABILITY]);
    $this->postJson('/api/v1/terminal/heartbeat', [])->assertOk()->assertJsonPath('config.confirm_exit', false);
});

it('el detalle dice de dónde viene el valor', function () {
    setGlobalConfirmExit(true);

    expect(TerminalResource::describeConfirmExit(confirmExitTerminal(null)))->toBe('Sí (de Configuración General)')
        ->and(TerminalResource::describeConfirmExit(confirmExitTerminal(false)))->toBe('No')
        ->and(TerminalResource::describeConfirmExit(confirmExitTerminal(true)))->toBe('Sí');
});

it('el formulario del terminal guarda Heredar como null, Sí y No', function (string $option, ?bool $expected) {
    confirmExitAdmin();
    $terminal = confirmExitTerminal(true);

    Livewire::test(EditTerminal::class, ['record' => $terminal->getKey()])
        ->fillForm(['confirm_exit' => $option])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($terminal->fresh()->confirm_exit)->toBe($expected);
})->with([
    'heredar' => ['inherit', null],
    'sí' => ['yes', true],
    'no' => ['no', false],
]);

it('el formulario muestra el valor actual del terminal', function (?bool $stored, string $option) {
    confirmExitAdmin();
    $terminal = confirmExitTerminal($stored);

    Livewire::test(EditTerminal::class, ['record' => $terminal->getKey()])
        ->assertFormSet(['confirm_exit' => $option]);
})->with([
    'sin valor' => [null, 'inherit'],
    'sí' => [true, 'yes'],
    'no' => [false, 'no'],
]);

it('la acción masiva fija el valor en todos los terminales seleccionados', function () {
    confirmExitAdmin();
    $a = confirmExitTerminal(true);
    $b = confirmExitTerminal(null);
    $sinTocar = confirmExitTerminal(true);

    Livewire::test(ListTerminals::class)
        ->callTableBulkAction('set_confirm_exit', [$a, $b], data: ['value' => 'no'])
        ->assertHasNoTableBulkActionErrors();

    expect($a->fresh()->confirm_exit)->toBeFalse()
        ->and($b->fresh()->confirm_exit)->toBeFalse()
        ->and($sinTocar->fresh()->confirm_exit)->toBeTrue();
});

it('la acción masiva con Heredar devuelve los terminales al valor general', function () {
    confirmExitAdmin();
    $terminal = confirmExitTerminal(false);

    Livewire::test(ListTerminals::class)
        ->callTableBulkAction('set_confirm_exit', [$terminal], data: ['value' => 'inherit']);

    expect($terminal->fresh()->confirm_exit)->toBeNull();
});
