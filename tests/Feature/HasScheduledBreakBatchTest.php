<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\RotationPattern;
use App\Models\Schedule;
use App\Models\ScheduleDay;
use App\Models\ShiftOverride;
use App\Models\ShiftTemplate;
use App\Services\AttendanceCalculator;
use App\Services\RotationService;
use App\Services\ScheduleAssignmentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Regresión: EmployeeDescriptorSyncService::breakFlagsForBranch() hacía 2-4
 * queries por empleado (N+1 real en sucursales grandes, recalculado en cada
 * sync del terminal). AttendanceCalculator::hasScheduledBreakBatch() debe
 * producir exactamente el mismo resultado que hasScheduledBreak() llamado
 * empleado por empleado, para cada nivel de la jerarquía de prioridad.
 */
function makeBreakBatchCompany(): Company
{
    static $n = 8700000;
    $n++;

    return Company::create(['name' => "Empresa BreakBatch {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
}

function makeBreakBatchEmployee(Company $company): Employee
{
    static $ci = 8700000;
    $n = $ci++;

    $branch = Branch::create(['name' => "Sucursal BreakBatch {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "Depto BreakBatch {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "Cargo BreakBatch {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'BreakBatch',
        'last_name' => 'Test',
        'ci' => (string) $n,
        'birth_date' => '1990-01-01',
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);

    Contract::create([
        'employee_id' => $employee->id,
        'type' => 'indefinido',
        'start_date' => now()->subYear(),
        'salary_type' => 'mensual',
        'salary' => 2_550_000,
        'position_id' => $position->id,
        'department_id' => $department->id,
        'status' => 'active',
    ]);

    return $employee->fresh();
}

it('produce el mismo resultado que hasScheduledBreak() para override, rotación, horario fijo y sin horario', function () {
    $company = makeBreakBatchCompany();
    $today = Carbon::parse('2026-08-24'); // lunes

    // 1) Override puntual con descanso
    $employeeOverride = makeBreakBatchEmployee($company);
    $overrideShift = ShiftTemplate::create([
        'company_id' => $company->id, 'name' => 'Turno Override', 'shift_type' => 'diurno',
        'is_day_off' => false, 'start_time' => '08:00', 'end_time' => '17:00', 'break_minutes' => 60, 'is_active' => true,
    ]);
    ShiftOverride::create([
        'employee_id' => $employeeOverride->id, 'override_date' => $today->toDateString(), 'shift_id' => $overrideShift->id,
    ]);

    // 2) Rotación vigente con descanso
    $employeeRotation = makeBreakBatchEmployee($company);
    $rotationShift = ShiftTemplate::create([
        'company_id' => $company->id, 'name' => 'Turno Rotación', 'shift_type' => 'diurno',
        'is_day_off' => false, 'start_time' => '07:00', 'end_time' => '15:00', 'break_minutes' => 30, 'is_active' => true,
    ]);
    $pattern = RotationPattern::create([
        'company_id' => $company->id, 'name' => 'Patrón BreakBatch', 'sequence' => [$rotationShift->id], 'is_active' => true,
    ]);
    RotationService::assign($employeeRotation, $pattern, $today->copy()->subMonth());

    // 3) Rotación en franco (sin descanso — is_day_off)
    $employeeDayOff = makeBreakBatchEmployee($company);
    $dayOffShift = ShiftTemplate::create([
        'company_id' => $company->id, 'name' => 'Franco', 'shift_type' => 'diurno',
        'is_day_off' => true, 'start_time' => null, 'end_time' => null, 'break_minutes' => 0, 'is_active' => true,
    ]);
    $dayOffPattern = RotationPattern::create([
        'company_id' => $company->id, 'name' => 'Patrón Franco', 'sequence' => [$dayOffShift->id], 'is_active' => true,
    ]);
    RotationService::assign($employeeDayOff, $dayOffPattern, $today->copy()->subMonth());

    // 4) Horario fijo (ScheduleAssignment) con descanso
    $employeeFixed = makeBreakBatchEmployee($company);
    $schedule = Schedule::create(['name' => 'Horario Fijo BreakBatch', 'shift_type' => 'diurno', 'description' => null]);
    ScheduleDay::create([
        'schedule_id' => $schedule->id, 'day_of_week' => $today->dayOfWeekIso, 'is_active' => true,
        'start_time' => '08:00', 'end_time' => '17:00', 'total_break_minutes' => 45,
    ]);
    ScheduleAssignmentService::assign($employeeFixed, $schedule, $today->copy()->subYear());

    // 5) Horario legacy directo (employees.schedule_id, sin ScheduleAssignment)
    $employeeLegacy = makeBreakBatchEmployee($company);
    $legacySchedule = Schedule::create(['name' => 'Horario Legacy BreakBatch', 'shift_type' => 'diurno', 'description' => null]);
    ScheduleDay::create([
        'schedule_id' => $legacySchedule->id, 'day_of_week' => $today->dayOfWeekIso, 'is_active' => true,
        'start_time' => '09:00', 'end_time' => '18:00', 'total_break_minutes' => 0,
    ]);
    $employeeLegacy->update(['schedule_id' => $legacySchedule->id]);
    $employeeLegacy = $employeeLegacy->fresh();

    // 6) Sin ningún horario asignado
    $employeeNone = makeBreakBatchEmployee($company);

    $employees = collect([$employeeOverride, $employeeRotation, $employeeDayOff, $employeeFixed, $employeeLegacy, $employeeNone]);

    $expected = $employees->mapWithKeys(fn (Employee $e) => [$e->id => AttendanceCalculator::hasScheduledBreak($e, $today)]);

    $batchInput = Employee::query()->whereIn('id', $employees->pluck('id'))->get(['id', 'schedule_id']);
    $actual = AttendanceCalculator::hasScheduledBreakBatch($batchInput, $today);

    ksort($actual);
    $expectedSorted = $expected->all();
    ksort($expectedSorted);

    expect($actual)->toBe($expectedSorted);

    // Sanity: los valores esperados no son todos iguales (si lo fueran, el test
    // no distinguiría una implementación batch rota que siempre devuelve el mismo booleano).
    expect(collect($expected)->unique()->count())->toBeGreaterThan(1);
});

it('devuelve array vacío para una colección vacía sin hacer queries de más', function () {
    expect(AttendanceCalculator::hasScheduledBreakBatch(collect(), Carbon::parse('2026-08-24')))->toBe([]);
});
