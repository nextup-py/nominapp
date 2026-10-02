<?php

use App\Filament\Resources\WarningResource\Pages\CreateWarning;
use App\Filament\Resources\WarningResource\Pages\EditWarning;
use App\Models\EmployeeDeduction;
use App\Models\User;
use App\Models\Warning;
use App\Models\WarningSuspensionDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Support/SuspensionHelpers.php';

beforeEach(function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
});

/** Datos base válidos del formulario de creación. */
function suspFormData(int $employeeId, array $extra = []): array
{
    return array_merge([
        'employee_id' => $employeeId,
        'type' => 'severe',
        'reason' => 'conducta',
        'description' => 'Hecho de prueba',
    ], $extra);
}

it('crea una amonestación con suspensión y genera los descuentos', function () {
    $employee = makeSuspEmployee();

    Livewire::test(CreateWarning::class)
        ->fillForm(suspFormData($employee->id, [
            'suspension_days' => 2,
            'suspension_start_date' => '2026-10-05',
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $warning = Warning::first();
    expect($warning->suspension_days)->toBe(2)
        ->and($warning->suspensionDays)->toHaveCount(2)
        ->and(EmployeeDeduction::count())->toBe(2);
});

it('exige la fecha de inicio cuando hay días de suspensión', function () {
    $employee = makeSuspEmployee();

    Livewire::test(CreateWarning::class)
        ->fillForm(suspFormData($employee->id, ['suspension_days' => 2]))
        ->call('create')
        ->assertHasFormErrors(['suspension_start_date' => 'required']);

    expect(Warning::count())->toBe(0);
});

it('exige confirmar el sumario desde 4 días', function () {
    $employee = makeSuspEmployee();

    Livewire::test(CreateWarning::class)
        ->fillForm(suspFormData($employee->id, [
            'suspension_days' => 5,
            'suspension_start_date' => '2026-10-05',
            'suspension_summary_done' => false,
        ]))
        ->call('create')
        ->assertHasFormErrors(['suspension_summary_done']);

    expect(Warning::count())->toBe(0);
});

it('rechaza más de 8 días desde el formulario', function () {
    $employee = makeSuspEmployee();

    Livewire::test(CreateWarning::class)
        ->fillForm(suspFormData($employee->id, [
            'suspension_days' => 9,
            'suspension_start_date' => '2026-10-05',
            'suspension_summary_done' => true,
        ]))
        ->call('create')
        ->assertHasFormErrors(['suspension_days']);
});

it('notifica y no guarda cuando el empleado ya tiene nómina del período', function () {
    $employee = makeSuspEmployee();
    makeSuspPayroll($employee);

    Livewire::test(CreateWarning::class)
        ->fillForm(suspFormData($employee->id, [
            'suspension_days' => 2,
            'suspension_start_date' => '2026-10-05',
        ]))
        ->call('create')
        ->assertNotified('No se puede aplicar la suspensión');

    expect(Warning::count())->toBe(0);
});

it('al editar a 0 días revierte la suspensión', function () {
    $employee = makeSuspEmployee();
    $warning = makeSuspWarning($employee, 2);

    Livewire::test(EditWarning::class, ['record' => $warning->getKey()])
        ->fillForm(['suspension_days' => 0])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(WarningSuspensionDay::count())->toBe(0)
        ->and(EmployeeDeduction::count())->toBe(0)
        ->and($warning->fresh()->suspension_start_date)->toBeNull();
});

it('no permite eliminar desde la página de edición si ya hay nómina de esas fechas', function () {
    $employee = makeSuspEmployee();
    $warning = makeSuspWarning($employee, 2);
    makeSuspPayroll($employee);

    Livewire::test(EditWarning::class, ['record' => $warning->getKey()])
        ->callAction('delete')
        ->assertNotified('No se puede eliminar');

    expect(Warning::count())->toBe(1)
        ->and(WarningSuspensionDay::count())->toBe(2);
});
