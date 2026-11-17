<?php

use App\Filament\Resources\EmployeeLeaveResource\Pages\ViewEmployeeLeaves;
use App\Models\Absence;
use App\Models\AttendanceDay;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Deduction;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDeduction;
use App\Models\EmployeeLeave;
use App\Models\Holiday;
use App\Models\Payroll;
use App\Models\PayrollPeriod;
use App\Models\Position;
use App\Models\Schedule;
use App\Models\User;
use App\Services\ScheduleAssignmentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** Empleado mensual (salario 3.000.000 → 100.000 por día) con horario de lunes a viernes. */
function leaveEmployee(): Employee
{
    static $n = 6700000;
    $n++;

    $company = Company::create(['name' => "EmpLD {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucLD {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "DeptoLD {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "CargoLD {$n}", 'department_id' => $department->id]);

    $employee = Employee::create(['first_name' => 'Ana', 'last_name' => "Lic{$n}", 'ci' => (string) $n, 'branch_id' => $branch->id, 'status' => 'active']);

    Contract::create([
        'employee_id' => $employee->id, 'type' => 'indefinido', 'start_date' => '2025-01-01', 'salary_type' => 'mensual',
        'salary' => 3_000_000, 'position_id' => $position->id, 'department_id' => $department->id, 'status' => 'active',
    ]);

    $schedule = Schedule::create(['name' => "Horario LD {$n}", 'shift_type' => 'diurno']);
    foreach (range(1, 7) as $day) {
        $schedule->days()->create(['day_of_week' => $day, 'is_active' => $day <= 5, 'start_time' => '08:00', 'end_time' => '17:00']);
    }
    ScheduleAssignmentService::assign($employee, $schedule, Carbon::parse('2026-01-01'));

    return $employee->fresh();
}

/** Licencia pendiente de lunes 2 a viernes 13 de noviembre de 2026 (10 días laborables). */
function pendingLeave(Employee $employee, array $attributes = []): EmployeeLeave
{
    return EmployeeLeave::create($attributes + [
        'employee_id' => $employee->id, 'type' => 'unpaid_leave', 'start_date' => '2026-11-02', 'end_date' => '2026-11-13',
        'generates_deduction' => true, 'status' => 'pending',
    ]);
}

/** Datos mínimos de un recibo de nómina para el período dado. */
function leavePayrollAttributes(PayrollPeriod $period, int $employeeId): array
{
    return [
        'employee_id' => $employeeId, 'payroll_period_id' => $period->id, 'status' => 'draft',
        'base_salary' => 3_000_000, 'gross_salary' => 3_000_000, 'net_salary' => 3_000_000,
        'total_deductions' => 0, 'total_perceptions' => 0,
    ];
}

beforeEach(function () {
    $this->actingAs($this->admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
});

it('aprobar una licencia de día completo con descuento genera una deducción por día laborable y guarda quién aprobó', function () {
    Holiday::create(['name' => 'Feriado de prueba', 'date' => '2026-11-04', 'type' => 'fixed']);
    $leave = pendingLeave(leaveEmployee());

    $result = $leave->approve($this->admin->id);

    $leave->refresh();
    expect($result['deduction_count'])->toBe(9)                    // 10 días laborables menos el feriado
        ->and($result['deduction_amount'])->toBe(900_000.0)
        ->and($leave->deductionDays()->count())->toBe(9)
        ->and($leave->deductionDays()->where('date', '2026-11-04')->exists())->toBeFalse()
        ->and(EmployeeDeduction::where('employee_id', $leave->employee_id)->count())->toBe(9)
        ->and(Deduction::where('code', 'LIC001')->value('is_mandatory'))->toBeFalse()
        ->and($leave->approved_by_id)->toBe($this->admin->id)
        ->and($leave->approved_at)->not->toBeNull();
});

it('LIC001 se crea a demanda si no existe y aprobar dos veces no duplica los descuentos', function () {
    Deduction::where('code', 'LIC001')->delete();
    $leave = pendingLeave(leaveEmployee());

    $leave->approve($this->admin->id);
    $leave->approve($this->admin->id);

    expect(Deduction::where('code', 'LIC001')->exists())->toBeTrue()
        ->and(EmployeeDeduction::where('employee_id', $leave->employee_id)->count())->toBe(10);
});

it('sin el interruptor no genera descuento y el permiso parcial se fecha el día del permiso', function () {
    $employee = leaveEmployee();

    $none = pendingLeave($employee, ['generates_deduction' => false]);
    expect($none->approve($this->admin->id)['deduction_count'])->toBe(0);

    $partial = pendingLeave($employee, ['start_date' => '2026-11-16', 'end_date' => '2026-11-16', 'start_time' => '08:00', 'end_time' => '10:00', 'type' => 'other']);
    $result = $partial->approve($this->admin->id);

    expect($result['deduction_count'])->toBe(1)
        ->and($partial->fresh()->employeeDeduction->start_date->toDateString())->toBe('2026-11-16')
        ->and((float) $partial->fresh()->employeeDeduction->custom_amount)->toBe(round(3_000_000 / 240 * 2, 2));
});

it('con nómina ya generada para esas fechas, aprueba pero avisa que no generó el descuento', function () {
    $leave = pendingLeave(leaveEmployee());
    $period = PayrollPeriod::create(['company_id' => $leave->employee->branch->company_id, 'frequency' => 'monthly', 'start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'status' => 'draft']);
    Payroll::create(leavePayrollAttributes($period, $leave->employee_id));

    $result = $leave->approve($this->admin->id);

    expect($leave->fresh()->status)->toBe('approved')
        ->and($result['deduction_count'])->toBe(0)
        ->and($result['deduction_warning'])->toContain('nómina generada');
});

it('anular deshace los descuentos y devuelve a pendientes las ausencias que había justificado', function () {
    $employee = leaveEmployee();
    $day = AttendanceDay::create(['employee_id' => $employee->id, 'date' => '2026-11-03', 'status' => 'absent', 'is_calculated' => false]);
    $absence = Absence::firstOrCreate(['attendance_day_id' => $day->id], ['employee_id' => $employee->id, 'status' => 'pending']);

    $leave = pendingLeave($employee);
    $leave->approve($this->admin->id);

    expect($absence->fresh()->status)->toBe('justified');

    $result = $leave->fresh()->cancel($this->admin->id, 'Se cargó por error');

    expect($result['success'])->toBeTrue()
        ->and($result['absences'])->toBe(1)
        ->and($result['deductions'])->toBe(10)
        ->and($absence->fresh()->status)->toBe('pending')
        ->and($absence->fresh()->employee_leave_id)->toBeNull()
        ->and($day->fresh()->justified_absence)->toBeFalse()
        ->and(EmployeeDeduction::where('employee_id', $employee->id)->count())->toBe(0)
        ->and($leave->fresh()->status)->toBe('cancelled')
        ->and($leave->fresh()->cancel_reason)->toBe('Se cargó por error')
        ->and($leave->fresh()->cancelled_by_id)->toBe($this->admin->id);
});

it('no se puede anular una pendiente ni una licencia con descuentos si ya hay nómina generada', function () {
    $employee = leaveEmployee();

    expect(pendingLeave($employee)->cancel($this->admin->id, 'x')['success'])->toBeFalse();

    $leave = pendingLeave($employee, ['start_date' => '2026-12-01', 'end_date' => '2026-12-04']);
    $leave->approve($this->admin->id);
    $period = PayrollPeriod::create(['company_id' => $employee->branch->company_id, 'frequency' => 'monthly', 'start_date' => '2026-12-01', 'end_date' => '2026-12-31', 'status' => 'draft']);
    Payroll::create(leavePayrollAttributes($period, $employee->id));

    $result = $leave->fresh()->cancel($this->admin->id, 'x');

    expect($result['success'])->toBeFalse()
        ->and($leave->fresh()->status)->toBe('approved');
});

it('una licencia sin descuento se puede anular aunque haya nómina', function () {
    $employee = leaveEmployee();
    $leave = pendingLeave($employee, ['generates_deduction' => false]);
    $leave->approve($this->admin->id);
    $period = PayrollPeriod::create(['company_id' => $employee->branch->company_id, 'frequency' => 'monthly', 'start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'status' => 'draft']);
    Payroll::create(leavePayrollAttributes($period, $employee->id));

    expect($leave->fresh()->cancel($this->admin->id, 'x')['success'])->toBeTrue();
});

it('el detalle muestra Anular solo con el permiso y la licencia aprobada, y la anula', function () {
    $leave = pendingLeave(leaveEmployee(), ['generates_deduction' => false]);
    $leave->approve($this->admin->id);

    Livewire::test(ViewEmployeeLeaves::class, ['record' => $leave->getKey()])
        ->assertActionVisible('cancel')
        ->callAction('cancel', ['reason' => 'Error de carga'])
        ->assertNotified();

    expect($leave->fresh()->status)->toBe('cancelled');

    $noPermission = User::factory()->create();
    $noPermission->givePermissionTo(collect(['view_employee_leave', 'view_any_employee_leave'])->map(fn ($name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']))->all());
    $approved = pendingLeave(leaveEmployee(), ['generates_deduction' => false]);
    $approved->approve($this->admin->id);

    $this->actingAs($noPermission);
    Livewire::test(ViewEmployeeLeaves::class, ['record' => $approved->getKey()])->assertActionHidden('cancel');
});
