<?php

use App\Models\Absence;
use App\Models\AttendanceDay;
use App\Models\Deduction;
use App\Models\EmployeeDeduction;
use App\Models\User;
use Database\Seeders\DeductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Support/SuspensionHelpers.php';

/**
 * Las deducciones de sistema (AUS-INJ, SUS-DIS, PRE001, ADE001, MER001) nunca son obligatorias:
 * AUS-INJ se creaba con is_mandatory = true y se asignaba a cada empleado nuevo como deducción
 * abierta, sin monto, generando una línea en cero en cada nómina.
 */

/** Crea una deducción cualquiera (el hook del modelo fuerza is_mandatory = false para códigos de sistema). */
function makeSysDeduction(string $code, bool $mandatory = true): Deduction
{
    return Deduction::create([
        'name' => "Deducción {$code}",
        'code' => $code,
        'type' => 'other',
        'calculation' => 'fixed',
        'amount' => 10_000,
        'is_mandatory' => $mandatory,
        'is_active' => true,
    ]);
}

it('markAsUnjustified crea AUS-INJ como no obligatoria', function () {
    $employee = makeSuspEmployee();
    $day = AttendanceDay::create(['employee_id' => $employee->id, 'date' => '2026-10-05', 'status' => 'absent', 'is_calculated' => false]);
    $absence = Absence::where('attendance_day_id', $day->id)->firstOrFail();

    $absence->markAsUnjustified(User::factory()->create()->id, 'Falta sin aviso');

    $deduction = Deduction::where('code', 'AUS-INJ')->first();
    expect($deduction)->not->toBeNull()
        ->and($deduction->is_mandatory)->toBeFalse()
        ->and($deduction->type)->toBe('other');
});

it('un empleado nuevo no recibe AUS-INJ como deducción permanente', function () {
    // Deducción real obligatoria (control) y una de sistema existente
    $ips = makeSysDeduction('IPS001');
    makeSysDeduction('AUS-INJ');

    $employee = makeSuspEmployee();

    $assigned = EmployeeDeduction::where('employee_id', $employee->id)->pluck('deduction_id')->all();
    expect($assigned)->toBe([$ips->id]);
});

it('el modelo fuerza is_mandatory = false en cualquier código de sistema', function (string $code) {
    $deduction = makeSysDeduction($code, mandatory: true);

    expect($deduction->fresh()->is_mandatory)->toBeFalse();

    $deduction->update(['is_mandatory' => true]);
    expect($deduction->fresh()->is_mandatory)->toBeFalse();
})->with(['AUS-INJ', 'SUS-DIS', 'PRE001', 'ADE001', 'MER001']);

it('una deducción normal sí puede ser obligatoria', function () {
    $deduction = makeSysDeduction('SEG-01');

    expect($deduction->fresh()->is_mandatory)->toBeTrue()
        ->and($deduction->isSystem())->toBeFalse();
});

it('assignMandatoryDeductions y el scope ignoran códigos de sistema aunque estén marcados en la base', function () {
    $normal = makeSysDeduction('SEG-01');
    $legacy = makeSysDeduction('AUS-INJ');
    // Simula una base heredada donde el flag quedó en true (saltando el hook del modelo)
    DB::table('deductions')->where('id', $legacy->id)->update(['is_mandatory' => true]);

    expect(Deduction::mandatoryAssignable()->pluck('id')->all())->toBe([$normal->id]);

    $employee = makeSuspEmployee();
    EmployeeDeduction::where('employee_id', $employee->id)->delete();

    expect($employee->assignMandatoryDeductions())->toBe(1)
        ->and(EmployeeDeduction::where('employee_id', $employee->id)->pluck('deduction_id')->all())->toBe([$normal->id]);
});

it('los seeders siembran AUS-INJ como no obligatoria', function () {
    $this->seed(DeductionSeeder::class);

    $deduction = Deduction::where('code', 'AUS-INJ')->first();
    expect($deduction)->not->toBeNull()
        ->and($deduction->is_mandatory)->toBeFalse();
});

// ─── Migración de datos ─────────────────────────────────────────────────────

/** Ejecuta la migración de corrección de datos. */
function runSystemDeductionsFix(): void
{
    (require database_path('migrations/2026_10_03_100000_fix_system_deductions_mandatory_flag.php'))->up();
}

it('la migración quita el flag y borra solo las asignaciones vacías de AUS-INJ', function () {
    $deduction = makeSysDeduction('AUS-INJ');
    DB::table('deductions')->where('id', $deduction->id)->update(['is_mandatory' => true]);
    $a = makeSuspEmployee();
    $b = makeSuspEmployee();
    EmployeeDeduction::query()->delete();

    // Asignación errónea: abierta y sin monto (la que creaba assignMandatoryDeductions)
    $empty = EmployeeDeduction::create(['employee_id' => $a->id, 'deduction_id' => $deduction->id, 'start_date' => '2026-01-01']);
    // Deducción real por ausencia: un día, con monto
    $real = EmployeeDeduction::create(['employee_id' => $b->id, 'deduction_id' => $deduction->id, 'start_date' => '2026-03-05', 'end_date' => '2026-03-05', 'custom_amount' => 85_000]);

    runSystemDeductionsFix();

    expect($deduction->fresh()->is_mandatory)->toBeFalse()
        ->and(EmployeeDeduction::find($empty->id))->toBeNull()
        ->and(EmployeeDeduction::find($real->id))->not->toBeNull();
});

it('la migración es idempotente y no toca deducciones normales obligatorias', function () {
    $normal = makeSysDeduction('IPS001');
    $employee = makeSuspEmployee();
    $assignment = EmployeeDeduction::where('employee_id', $employee->id)->where('deduction_id', $normal->id)->first();

    runSystemDeductionsFix();
    runSystemDeductionsFix();

    expect($normal->fresh()->is_mandatory)->toBeTrue()
        ->and(EmployeeDeduction::find($assignment->id))->not->toBeNull();
});
