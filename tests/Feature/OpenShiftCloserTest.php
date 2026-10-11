<?php

use App\Filament\Resources\AttendanceDayResource;
use App\Filament\Resources\AttendanceDayResource\Pages\ListAttendanceDays;
use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\OpenShiftCloser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 10, 12, 12, 0));
});

afterEach(fn () => Carbon::setTestNow());

function closerEmployee(): Employee
{
    static $n = 9100000;
    $n++;

    $company = Company::create(['name' => "EmpOC {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucOC {$n}", 'company_id' => $company->id]);

    return Employee::create([
        'first_name' => 'Cierre', 'last_name' => "Test{$n}", 'ci' => (string) $n, 'email' => "oc{$n}@test.com",
        'branch_id' => $branch->id, 'status' => 'active',
    ]);
}

/**
 * Jornada pasada con las marcaciones dadas (hora local `H:i`, o `d+1 H:i` para el día siguiente) y el horario esperado.
 *
 * @param  array<int, array{0: string, 1: string}>  $events
 * @param  array<string, mixed>  $attributes
 */
function closerDay(Employee $employee, int $daysAgo, array $events, ?string $expectedIn = '09:00:00', ?string $expectedOut = '16:00:00', array $attributes = []): AttendanceDay
{
    $date = Carbon::today()->subDays($daysAgo);
    $day = AttendanceDay::create(['employee_id' => $employee->id, 'date' => $date->toDateString(), 'status' => 'present', 'is_calculated' => true]);

    foreach ($events as [$type, $time]) {
        $at = str_starts_with($time, 'd+1 ')
            ? $date->copy()->addDay()->setTimeFromTimeString(substr($time, 4))
            : $date->copy()->setTimeFromTimeString($time);

        AttendanceEvent::create(['attendance_day_id' => $day->id, 'employee_id' => $employee->id, 'event_type' => $type, 'recorded_at' => $at, 'source' => 'terminal']);
    }

    $day->forceFill(array_merge(['expected_check_in' => $expectedIn, 'expected_check_out' => $expectedOut], $attributes))->saveQuietly();

    return $day->fresh();
}

it('cierra una jornada abierta con la hora de fin del horario esperado', function () {
    $day = closerDay(closerEmployee(), 2, [['check_in', '08:55']]);

    $skip = app(OpenShiftCloser::class)->close($day);

    $out = $day->events()->where('event_type', 'check_out')->first();

    expect($skip)->toBeNull()
        ->and($out->recorded_at->format('H:i'))->toBe('16:00')
        ->and($out->source)->toBe('manual')
        ->and($day->fresh()->check_out_time)->toStartWith('16:00');
});

it('en un turno que cruza medianoche pone la salida al día siguiente', function () {
    $day = closerDay(closerEmployee(), 2, [['check_in', '21:58']], '22:00:00', '06:00:00');

    app(OpenShiftCloser::class)->close($day);

    $out = $day->events()->where('event_type', 'check_out')->first();

    expect($out->recorded_at->toDateString())->toBe($day->date->copy()->addDay()->toDateString())
        ->and($out->recorded_at->format('H:i'))->toBe('06:00');
});

it('cierra una jornada que quedó con el fin de un descanso', function () {
    $day = closerDay(closerEmployee(), 2, [['check_in', '08:55'], ['break_start', '12:00'], ['break_end', '12:30']]);

    expect(app(OpenShiftCloser::class)->close($day))->toBeNull()
        ->and($day->events()->orderByDesc('recorded_at')->first()->event_type)->toBe('check_out');
});

it('omite las jornadas donde inventar la salida sería arriesgado', function (string $expectedSkip, Closure $make) {
    $day = $make();

    expect(app(OpenShiftCloser::class)->close($day))->toBe($expectedSkip)
        ->and($day->events()->where('event_type', 'check_out')->exists())->toBeFalse();
})->with([
    'quedó en un descanso' => [OpenShiftCloser::SKIP_ON_BREAK, fn () => closerDay(closerEmployee(), 2, [['check_in', '08:55'], ['break_start', '12:00']])],
    'sin hora de salida esperada' => [OpenShiftCloser::SKIP_NO_EXPECTED_EXIT, fn () => closerDay(closerEmployee(), 2, [['check_in', '08:55']], null, null)],
    'salida esperada anterior a la última marcación' => [OpenShiftCloser::SKIP_EXIT_BEFORE_LAST_EVENT, fn () => closerDay(closerEmployee(), 2, [['check_in', '17:30']])],
    'con extras aprobadas' => [OpenShiftCloser::SKIP_PROTECTED, fn () => closerDay(closerEmployee(), 2, [['check_in', '08:55']], '09:00:00', '16:00:00', ['overtime_approved' => true])],
    'con ajuste manual' => [OpenShiftCloser::SKIP_PROTECTED, fn () => closerDay(closerEmployee(), 2, [['check_in', '08:55']], '09:00:00', '16:00:00', ['manual_adjustment' => true])],
]);

it('omite las jornadas ya cerradas y la de hoy', function () {
    $employee = closerEmployee();
    $closed = closerDay($employee, 3, [['check_in', '08:55'], ['check_out', '16:00']]);
    $today = closerDay($employee, 0, [['check_in', '08:55']]);

    expect(app(OpenShiftCloser::class)->close($closed))->toBe(OpenShiftCloser::SKIP_NOT_OPEN)
        ->and(app(OpenShiftCloser::class)->close($today))->toBe(OpenShiftCloser::SKIP_NOT_OPEN);
});

it('omite las jornadas dentro de un período con nómina generada', function () {
    $employee = closerEmployee();
    $day = closerDay($employee, 2, [['check_in', '08:55']]);

    $period = PayrollPeriod::create([
        'name' => 'Octubre 2026', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'frequency' => 'monthly', 'status' => 'processing',
    ]);
    Payroll::create([
        'employee_id' => $employee->id, 'payroll_period_id' => $period->id, 'status' => 'draft',
        'base_salary' => 2_550_000, 'gross_salary' => 2_550_000, 'net_salary' => 2_550_000,
        'total_deductions' => 0, 'total_perceptions' => 0, 'payment_method' => 'transfer',
    ]);

    expect(app(OpenShiftCloser::class)->close($day))->toBe(OpenShiftCloser::SKIP_PAYROLL_LOCKED);
});

it('la acción masiva cierra las elegibles y omite el resto', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $employee = closerEmployee();

    $ok = closerDay($employee, 2, [['check_in', '08:55']]);
    $onBreak = closerDay($employee, 3, [['check_in', '08:55'], ['break_start', '12:00']]);
    $noShift = closerDay($employee, 4, [['check_in', '08:55']], null, null);

    Livewire::test(ListAttendanceDays::class)
        ->callTableBulkAction('close_open_shifts', [$ok, $onBreak, $noShift]);

    expect($ok->events()->where('event_type', 'check_out')->exists())->toBeTrue()
        ->and($onBreak->events()->where('event_type', 'check_out')->exists())->toBeFalse()
        ->and($noShift->events()->where('event_type', 'check_out')->exists())->toBeFalse();
});

it('la acción masiva solo se ofrece a quien puede editar marcaciones', function () {
    $this->actingAs(User::factory()->create());
    expect(AttendanceDayResource::getCloseOpenShiftsBulkAction()->isVisible())->toBeFalse();

    $this->actingAs(tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    expect(AttendanceDayResource::getCloseOpenShiftsBulkAction()->isVisible())->toBeTrue();
});
