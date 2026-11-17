<?php

use App\Models\Absence;
use App\Models\AttendanceDay;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollPeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** Empleado con una jornada del día dada en `absent` y su ausencia pendiente. */
function absentEmployee(string $date = '2026-11-17'): array
{
    static $n = 6900000;
    $n++;

    $company = Company::create(['name' => "EmpRE {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucRE {$n}", 'company_id' => $company->id]);
    $employee = Employee::create(['first_name' => 'Ana', 'last_name' => "Ent{$n}", 'ci' => (string) $n, 'branch_id' => $branch->id, 'status' => 'active']);
    $day = AttendanceDay::create(['employee_id' => $employee->id, 'date' => $date, 'status' => 'absent', 'is_calculated' => false]);
    $absence = Absence::firstOrCreate(['attendance_day_id' => $day->id], ['employee_id' => $employee->id, 'status' => 'pending']);

    return [$employee, $day, $absence];
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-17 12:00:00', config('app.timezone')));
    $this->admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web'])));
});

afterEach(fn () => Carbon::setTestNow());

it('carga la entrada, recalcula la jornada y justifica la ausencia con el motivo', function () {
    [$employee, $day, $absence] = absentEmployee();

    $exit = Artisan::call('attendance:register-entries', ['--entry' => ["{$employee->id}=06:35"], '--notes' => 'terminal caído']);

    expect($exit)->toBe(0)
        ->and($day->events()->where('event_type', 'check_in')->first()->recorded_at->format('H:i'))->toBe('06:35')
        ->and($day->events()->where('event_type', 'check_in')->first()->source)->toBe('manual')
        ->and($day->fresh()->status)->not->toBe('absent')
        ->and($day->fresh()->check_in_time)->not->toBeNull()
        ->and($day->fresh()->justified_absence)->toBeTrue()
        ->and(Absence::where('status', 'pending')->count())->toBe(0); // la ausencia deja de existir como pendiente
});

it('--dry-run no guarda nada', function () {
    [$employee, $day, $absence] = absentEmployee();

    Artisan::call('attendance:register-entries', ['--entry' => ["{$employee->id}=06:35"], '--dry-run' => true]);

    expect($day->events()->count())->toBe(0)->and($absence->fresh()->status)->toBe('pending');
});

it('carga varias personas, con salida opcional, y es idempotente', function () {
    [$a] = absentEmployee();
    [$b, $dayB] = absentEmployee();

    Artisan::call('attendance:register-entries', ['--entry' => ["{$a->id}=06:30", "{$b->id}=07:30"], '--exit' => ["{$b->id}=11:00"]]);

    expect($dayB->events()->pluck('event_type')->sort()->values()->all())->toBe(['check_in', 'check_out']);

    Artisan::call('attendance:register-entries', ['--entry' => ["{$a->id}=06:30", "{$b->id}=07:30"]]);

    expect(Absence::where('status', 'pending')->count())->toBe(0)
        ->and(AttendanceDay::where('employee_id', $a->id)->first()->events()->count())->toBe(1);
});

it('omite lo que no corresponde y lo informa', function () {
    [$withoutAbsence] = absentEmployee();
    Absence::query()->delete();
    [$locked, $lockedDay] = absentEmployee();
    [$future] = absentEmployee();
    [$otherDate] = absentEmployee('2026-11-16');

    $period = PayrollPeriod::create(['company_id' => $locked->branch->company_id, 'frequency' => 'monthly', 'start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'status' => 'draft']);
    Payroll::create(['employee_id' => $locked->id, 'payroll_period_id' => $period->id, 'status' => 'draft', 'base_salary' => 1, 'gross_salary' => 1, 'net_salary' => 1, 'total_deductions' => 0, 'total_perceptions' => 0]);

    Artisan::call('attendance:register-entries', ['--entry' => ["{$locked->id}=06:30", "{$future->id}=23:30", "{$otherDate->id}=06:30", '99999=06:30']]);
    $output = Artisan::output();

    expect($output)->toContain('tiene nómina generada')
        ->toContain('la hora es futura')
        ->toContain('no tiene ausencia en esa fecha')
        ->and($lockedDay->events()->count())->toBe(0);
});

it('rechaza formatos y motivos inválidos sin tocar nada', function () {
    [$employee, $day] = absentEmployee();

    expect(Artisan::call('attendance:register-entries', ['--entry' => ["{$employee->id}=6:30"]]))->toBe(1)
        ->and(Artisan::call('attendance:register-entries', ['--entry' => ["{$employee->id}=25:00"]]))->toBe(1)
        ->and(Artisan::call('attendance:register-entries', ['--entry' => ["{$employee->id}=06:30"], '--reason' => 'inventado']))->toBe(1)
        ->and(Artisan::call('attendance:register-entries'))->toBe(1)
        ->and($day->events()->count())->toBe(0);
});
