<?php

use App\Filament\Resources\TerminalResource\Pages\EditTerminal;
use App\Filament\Resources\TerminalResource\Pages\ListTerminals;
use App\Filament\Resources\TerminalResource\Pages\ViewTerminal;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Terminal;
use App\Models\User;
use App\Notifications\TerminalHealthNotification;
use App\Notifications\TerminalOfflineGroupNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Datos de gestión del equipo que ningún navegador entrega: responsable (nombre y teléfono), fecha de compra y garantía.
 */
function managedTerminal(array $attributes = []): Terminal
{
    static $n = 9900000;
    $n++;

    $company = Company::create(['name' => "EmpRW {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucRW {$n}", 'company_id' => $company->id]);

    return Terminal::create(array_merge(['name' => "Terminal RW {$n}", 'branch_id' => $branch->id], $attributes))->fresh();
}

function managedTerminalAdmin(): void
{
    test()->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
}

it('el estado de la garantía sale de la fecha de vencimiento', function (?string $until, string $expected) {
    $terminal = managedTerminal(['warranty_until' => $until]);

    expect($terminal->warrantyStatus())->toBe($expected);
})->with([
    'sin dato' => [null, 'unknown'],
    'vencida ayer' => [fn () => today()->subDay()->toDateString(), 'expired'],
    'vence hoy' => [fn () => today()->toDateString(), 'expiring'],
    'vence en el límite' => [fn () => today()->addDays(Terminal::WARRANTY_EXPIRING_DAYS)->toDateString(), 'expiring'],
    'vence pasado el límite' => [fn () => today()->addDays(Terminal::WARRANTY_EXPIRING_DAYS + 1)->toDateString(), 'active'],
]);

it('el filtro de garantía en SQL coincide con la definición del modelo', function () {
    $terminals = collect([
        'unknown' => managedTerminal(),
        'expired' => managedTerminal(['warranty_until' => today()->subDays(10)]),
        'expiring' => managedTerminal(['warranty_until' => today()->addDays(10)]),
        'active' => managedTerminal(['warranty_until' => today()->addDays(200)]),
    ]);

    foreach ($terminals as $status => $terminal) {
        expect($terminal->warrantyStatus())->toBe($status);
        expect(Terminal::withWarrantyStatus($status)->pluck('id')->all())->toBe([$terminal->id]);
    }
});

it('arma el contacto del responsable con lo que haya cargado', function (?string $name, ?string $phone, ?string $expected) {
    expect(managedTerminal(['responsible_name' => $name, 'responsible_phone' => $phone])->responsibleContact())->toBe($expected);
})->with([
    'ambos' => ['Richard Sánchez', '0981123456', 'Richard Sánchez (0981123456)'],
    'solo nombre' => ['Richard Sánchez', null, 'Richard Sánchez'],
    'solo teléfono' => [null, '0981123456', '0981123456'],
    'ninguno' => [null, null, null],
]);

it('se cargan responsable y garantía desde el formulario', function () {
    managedTerminalAdmin();
    $terminal = managedTerminal();

    Livewire::test(EditTerminal::class, ['record' => $terminal->getKey()])
        ->fillForm([
            'responsible_name' => 'Richard Sánchez',
            'responsible_phone' => '0981123456',
            'purchased_at' => '2026-01-10',
            'warranty_until' => '2027-01-10',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $terminal->refresh();

    expect($terminal->responsible_name)->toBe('Richard Sánchez')
        ->and($terminal->responsible_phone)->toBe('0981123456')
        ->and($terminal->purchased_at->toDateString())->toBe('2026-01-10')
        ->and($terminal->warranty_until->toDateString())->toBe('2027-01-10');
});

it('rechaza un teléfono con formato inválido', function (string $phone) {
    managedTerminalAdmin();
    $terminal = managedTerminal();

    Livewire::test(EditTerminal::class, ['record' => $terminal->getKey()])
        ->fillForm(['responsible_phone' => $phone])
        ->call('save')
        ->assertHasFormErrors(['responsible_phone']);
})->with(['incompleto' => '98112345', 'demasiado largo' => '098112345678']);

it('normaliza el teléfono del responsable al salir del campo', function (string $phone) {
    managedTerminalAdmin();
    $terminal = managedTerminal();

    Livewire::test(EditTerminal::class, ['record' => $terminal->getKey()])
        ->fillForm(['responsible_phone' => $phone])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($terminal->fresh()->responsible_phone)->toBe('0981123456');
})->with(['con prefijo' => '+595981123456', 'con guiones' => '0981-123-456', 'sin cero inicial' => '981123456']);

it('la garantía no puede vencer antes de la compra', function () {
    managedTerminalAdmin();
    $terminal = managedTerminal();

    Livewire::test(EditTerminal::class, ['record' => $terminal->getKey()])
        ->fillForm(['purchased_at' => '2026-05-01', 'warranty_until' => '2026-04-01'])
        ->call('save')
        ->assertHasFormErrors(['warranty_until']);
});

it('el detalle muestra la sección solo si hay datos cargados', function () {
    managedTerminalAdmin();
    $vacio = managedTerminal();
    $conDatos = managedTerminal(['responsible_name' => 'Richard Sánchez', 'warranty_until' => today()->addDays(10)]);

    Livewire::test(ViewTerminal::class, ['record' => $vacio->getKey()])->assertDontSee('Responsable del equipo');
    Livewire::test(ViewTerminal::class, ['record' => $conDatos->getKey()])
        ->assertSee('Responsable del equipo')
        ->assertSee('Richard Sánchez')
        ->assertSee('Por vencer');
});

it('el listado filtra por estado de garantía', function () {
    managedTerminalAdmin();
    $vencida = managedTerminal(['warranty_until' => today()->subDays(5)]);
    $vigente = managedTerminal(['warranty_until' => today()->addDays(300)]);

    Livewire::test(ListTerminals::class)
        ->filterTable('warranty_status', 'expired')
        ->assertCanSeeTableRecords([$vencida])
        ->assertCanNotSeeTableRecords([$vigente]);
});

it('el aviso de desconexión dice a quién llamar cuando hay responsable', function () {
    $terminal = managedTerminal(['responsible_name' => 'Richard Sánchez', 'responsible_phone' => '0981123456']);
    $sin = managedTerminal();

    $body = fn (Terminal $t) => (new TerminalHealthNotification($t, 'offline'))->toDatabase(User::factory()->create())['body'];

    expect($body($terminal))->toContain('Responsable del equipo: Richard Sánchez (0981123456).')
        ->and($body($sin))->not->toContain('Responsable del equipo');
});

it('el aviso agrupado lista el responsable de cada terminal que lo tiene', function () {
    $a = managedTerminal(['responsible_name' => 'Richard Sánchez']);
    $b = managedTerminal();

    $body = (new TerminalOfflineGroupNotification(collect([$a->load('branch'), $b->load('branch')])))->toDatabase(User::factory()->create())['body'];

    expect($body)->toContain('responsable: Richard Sánchez')
        ->and(substr_count($body, 'responsable:'))->toBe(1);
});

it('el listado ofrece las columnas de responsable y garantía', function () {
    managedTerminalAdmin();

    Livewire::test(ListTerminals::class)
        ->assertTableColumnExists('responsible_name')
        ->assertTableColumnExists('warranty_until');
});
