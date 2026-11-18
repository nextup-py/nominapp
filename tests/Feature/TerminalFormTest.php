<?php

use App\Filament\Resources\TerminalResource\Pages\CreateTerminal;
use App\Filament\Resources\TerminalResource\Pages\EditTerminal;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
});

/** Terminal de una sucursal nueva, vinculado o no. */
function formTerminal(array $attributes = [], bool $linked = false): Terminal
{
    $branch = Branch::create(['name' => 'Central '.uniqid(), 'company_id' => Company::factory()->create()->id]);
    $terminal = Terminal::create(['name' => 'Entrada', 'branch_id' => $branch->id, ...$attributes]);

    if ($linked) {
        $terminal->claimSanctumToken();
    }

    return $terminal->fresh();
}

/** Encabezados de las secciones del formulario con su estado colapsado. */
function terminalSections(Terminal $terminal): array
{
    return collect(Livewire::test(EditTerminal::class, ['record' => $terminal->getKey()])->instance()->form->getComponents())
        ->mapWithKeys(fn ($section) => [(string) $section->getHeading() => $section->isCollapsed()])
        ->all();
}

it('el estado solo se muestra al editar', function () {
    $terminal = formTerminal();

    Livewire::test(CreateTerminal::class)->assertFormFieldDoesNotExist('status');
    Livewire::test(EditTerminal::class, ['record' => $terminal->getKey()])->assertFormFieldExists('status');
});

it('al crear precarga la fecha de instalación de hoy y quién instala', function () {
    $user = auth()->user();

    Livewire::test(CreateTerminal::class)->assertFormSet([
        'installed_at' => fn ($state) => str_starts_with((string) $state, today()->toDateString()),
        'installed_by_id' => $user->id,
    ]);
});

it('el título de la edición lleva el nombre del terminal', function () {
    Livewire::test(EditTerminal::class, ['record' => formTerminal(['name' => 'Recepción'])->getKey()])
        ->assertSee('Editar: Recepción');
});

it('avisa al cambiar la sucursal solo si el terminal está vinculado', function () {
    $other = Branch::create(['name' => 'Otra', 'company_id' => Company::factory()->create()->id]);

    $linked = formTerminal(linked: true);
    $unlinked = formTerminal();

    $hint = fn (Terminal $terminal) => Livewire::test(EditTerminal::class, ['record' => $terminal->getKey()])
        ->set('data.branch_id', $other->id)
        ->instance()->form->getFlatFields()['branch_id']->getHint();

    expect((string) $hint($linked))->toContain('Al cambiar de sucursal')
        ->and($hint($unlinked))->toBeNull();
});

it('las secciones opcionales arrancan cerradas y se abren solas con datos', function () {
    $empty = terminalSections(formTerminal());

    expect($empty['Identificación'])->toBeFalse()
        ->and($empty['Marcación y monitoreo'])->toBeTrue()
        ->and($empty['Dispositivo'])->toBeTrue()
        ->and($empty['Responsable y garantía'])->toBeTrue()
        ->and($empty['Instalación'])->toBeTrue();

    $filled = terminalSections(formTerminal([
        'stale_after_minutes' => 30,
        'device_brand' => 'Lenovo',
        'responsible_name' => 'Ana',
        'installed_at' => today(),
    ]));

    expect($filled['Marcación y monitoreo'])->toBeFalse()
        ->and($filled['Dispositivo'])->toBeFalse()
        ->and($filled['Responsable y garantía'])->toBeFalse()
        ->and($filled['Instalación'])->toBeFalse();
});
