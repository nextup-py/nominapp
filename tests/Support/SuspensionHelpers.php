<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollPeriod;
use App\Models\Position;
use App\Models\Schedule;
use App\Models\ScheduleDay;
use App\Models\User;
use App\Models\Warning;
use App\Services\ScheduleAssignmentService;
use Carbon\Carbon;

/*
 * Helpers compartidos por los tests de suspensión disciplinaria.
 * Calendario de referencia: 2026-10-05 es lunes (jornada lun-vie).
 */

/**
 * Crea un empleado con contrato activo y horario lun-vie 08:00-17:00.
 *
 * @param  string  $salaryType  'mensual' | 'jornal'
 */
function makeSuspEmployee(string $salaryType = 'mensual', int $salary = 2_550_000, string $status = 'active'): Employee
{
    static $n = 9100000;
    $n++;

    $company = Company::create(['name' => "EmpSusp {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucSusp {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "DepSusp {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "PosSusp {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Susp',
        'last_name' => 'Test',
        'ci' => (string) $n,
        'birth_date' => '1990-01-01',
        'branch_id' => $branch->id,
        'status' => $status,
    ]);

    Contract::create([
        'employee_id' => $employee->id,
        'type' => 'indefinido',
        'start_date' => '2025-01-01',
        'salary_type' => $salaryType,
        'salary' => $salary,
        'payroll_type' => 'monthly',
        'position_id' => $position->id,
        'department_id' => $department->id,
        'status' => 'active',
    ]);

    $schedule = Schedule::create(['name' => "Horario Susp {$n}", 'shift_type' => 'diurno', 'description' => null]);
    foreach ([1, 2, 3, 4, 5] as $dow) {
        ScheduleDay::create([
            'schedule_id' => $schedule->id,
            'day_of_week' => $dow,
            'is_active' => true,
            'start_time' => '08:00',
            'end_time' => '17:00',
        ]);
    }
    ScheduleAssignmentService::assign($employee, $schedule, Carbon::parse('2025-01-01'));

    return $employee->fresh();
}

/** Crea una amonestación con suspensión (la aplica el observer). */
function makeSuspWarning(Employee $employee, int $days, string $start = '2026-10-05', bool $summary = false): Warning
{
    return Warning::create([
        'employee_id' => $employee->id,
        'type' => 'severe',
        'reason' => 'conducta',
        'description' => 'Hecho de prueba',
        'issued_at' => '2026-10-01',
        'issued_by_id' => User::factory()->create()->id,
        'suspension_start_date' => $days > 0 ? $start : null,
        'suspension_days' => $days,
        'suspension_summary_done' => $summary,
    ]);
}

function makeSuspPayroll(Employee $employee, string $from = '2026-10-01', string $to = '2026-10-31'): Payroll
{
    $period = PayrollPeriod::create([
        'name' => 'Octubre 2026',
        'start_date' => $from,
        'end_date' => $to,
        'frequency' => 'monthly',
        'status' => 'draft',
    ]);

    return Payroll::create([
        'employee_id' => $employee->id,
        'payroll_period_id' => $period->id,
        'status' => 'draft',
        'base_salary' => 2_550_000,
        'gross_salary' => 2_550_000,
        'net_salary' => 2_550_000,
        'total_deductions' => 0,
        'total_perceptions' => 0,
    ]);
}
