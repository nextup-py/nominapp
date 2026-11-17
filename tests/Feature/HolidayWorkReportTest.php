<?php

use App\Filament\Pages\HolidayWorkReport;
use App\Models\AttendanceDay;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Services\HolidayWorkReportService;
use App\Settings\PayrollSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** Empleado mensual de 3.000.000 con una jornada de `$worked` horas (`$extra` extra) en la fecha dada. */
function holidayWorkDay(string $date, float $worked = 9.0, float $extra = 0.0, bool $holiday = true, int $salary = 3_000_000): array
{
    static $n = 3100000;
    $n++;

    $company = Company::create(['name' => "EmpHT {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucHT {$n}", 'company_id' => $company->id]);
    $employee = Employee::create(['first_name' => 'Ana', 'last_name' => "Fer{$n}", 'ci' => (string) $n, 'branch_id' => $branch->id, 'status' => 'active']);
    $department = Department::create(['name' => "DepHT {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "PosHT {$n}", 'department_id' => $department->id]);
    Contract::create([
        'employee_id' => $employee->id, 'type' => 'indefinido', 'start_date' => '2025-01-01', 'salary_type' => 'mensual', 'salary' => $salary,
        'payroll_type' => 'monthly', 'payment_method' => 'cash', 'work_modality' => 'presencial', 'position_id' => $position->id,
        'department_id' => $department->id, 'status' => 'active',
    ]);
    $day = AttendanceDay::create([
        'employee_id' => $employee->id, 'date' => $date, 'status' => 'present', 'check_in_time' => '08:00:00', 'total_hours' => $worked,
        'expected_hours' => $worked - $extra, 'extra_hours' => $extra, 'is_holiday' => $holiday, 'is_weekend' => ! $holiday,
    ]);

    return [$employee, $day];
}

it('estima el recargo sobre el turno normal con el valor hora y el multiplicador menos uno', function () {
    $settings = app(PayrollSettings::class);
    $settings->monthly_hours = 240;
    $settings->overtime_multiplier_holiday = 2.0;
    $settings->save();

    holidayWorkDay('2026-09-29', worked: 10.0, extra: 2.0);

    $rows = HolidayWorkReportService::rows(Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31'));

    // 8 h de turno × (3.000.000 / 240 = 12.500) × (2 − 1) = 100.000
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['kind'])->toBe('Feriado')
        ->and($rows[0]['normal'])->toBe(8.0)
        ->and($rows[0]['hourly'])->toBe(12500.0)
        ->and($rows[0]['premium'])->toBe(100000.0);
});

it('filtra por feriados o domingos y ordena los totales', function () {
    holidayWorkDay('2026-09-29');
    holidayWorkDay('2026-09-27', holiday: false);

    $from = Carbon::parse('2026-01-01');
    $to = Carbon::parse('2026-12-31');

    expect(HolidayWorkReportService::rows($from, $to, 'holiday'))->toHaveCount(1)
        ->and(HolidayWorkReportService::rows($from, $to, 'sunday'))->toHaveCount(1)
        ->and(HolidayWorkReportService::rows($from, $to, 'all'))->toHaveCount(2)
        ->and(HolidayWorkReportService::totals(HolidayWorkReportService::rows($from, $to))['days'])->toBe(2);
});

it('sin contrato mensual vigente ese día no hay estimación', function () {
    [$employee] = holidayWorkDay('2026-09-29');
    Contract::where('employee_id', $employee->id)->update(['start_date' => '2026-10-01']);

    $rows = HolidayWorkReportService::rows(Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31'));

    expect($rows[0]['hourly'])->toBeNull()
        ->and($rows[0]['premium'])->toBeNull()
        ->and(HolidayWorkReportService::totals($rows)['without_rate'])->toBe(1);
});

it('la página es solo para quien ve asistencias y contratos, y exporta a Excel', function () {
    holidayWorkDay('2026-09-29');
    $rrhh = User::factory()->create();
    $rrhh->assignRole(Role::firstOrCreate(['name' => 'Otro', 'guard_name' => 'web']));
    $admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web'])));

    Livewire::actingAs($rrhh)->test(HolidayWorkReport::class)->assertForbidden();

    Livewire::actingAs($admin)->test(HolidayWorkReport::class)
        ->set('from', '2026-01-01')
        ->assertSee('Trabajo en feriado')
        ->callAction('export_excel')
        ->assertHasNoActionErrors();
});
