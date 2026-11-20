<?php

use App\Models\Absence;
use App\Models\AttendanceDay;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** Jornada con extras y tardanza pendientes en la fecha indicada. */
function pendingDay(string $date): AttendanceDay
{
    static $n = 6800000;
    $n++;

    $company = Company::create(['name' => "EmpDP {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucDP {$n}", 'company_id' => $company->id]);
    $employee = Employee::create(['first_name' => 'Ana', 'last_name' => "Dp{$n}", 'ci' => (string) $n, 'branch_id' => $branch->id, 'status' => 'active']);

    return AttendanceDay::create([
        'employee_id' => $employee->id, 'date' => $date, 'status' => 'present', 'is_calculated' => true,
        'extra_hours' => 1.0, 'late_minutes' => 20,
    ]);
}

beforeEach(function () {
    $this->admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web'])));
});

it('descarta extras y tardanzas anteriores a una fecha sin aprobarlas y las saca de los pendientes', function () {
    $old = pendingDay('2026-10-25');
    $recent = pendingDay('2026-11-05');

    Artisan::call('attendance:dismiss-pending', ['--overtime' => true, '--before' => '2026-11-01', '--dry-run' => true]);
    expect($old->fresh()->overtime_dismissed_at)->toBeNull();

    Artisan::call('attendance:dismiss-pending', ['--overtime' => true, '--before' => '2026-11-01']);

    expect($old->fresh()->overtime_dismissed_at)->not->toBeNull()
        ->and($old->fresh()->overtime_approved)->toBeFalsy()
        ->and((float) $old->fresh()->extra_hours)->toBe(1.0)
        ->and($old->fresh()->tardiness_dismissed_at)->toBeNull()
        ->and($recent->fresh()->overtime_dismissed_at)->toBeNull()
        ->and(AttendanceDay::whereRaw(AttendanceDay::PENDING_OVERTIME_SQL)->pluck('id')->all())->toBe([$recent->id])
        ->and($old->fresh()->attentionReasons())->toContain('Tardanza por aprobar')->not->toContain('Extras por aprobar');

    Artisan::call('attendance:dismiss-pending', ['--tardiness' => true, '--before' => '2026-11-20']);

    expect($old->fresh()->tardiness_dismissed_at)->not->toBeNull()
        ->and($recent->fresh()->tardiness_dismissed_at)->not->toBeNull()
        ->and(AttendanceDay::whereRaw(AttendanceDay::PENDING_TARDINESS_SQL)->count())->toBe(0);
});

it('exige indicar qué descartar y hasta qué fecha', function () {
    expect(Artisan::call('attendance:dismiss-pending', ['--before' => '2026-11-01']))->toBe(1)
        ->and(Artisan::call('attendance:dismiss-pending', ['--overtime' => true]))->toBe(1);
});

it('justifica las ausencias pendientes hasta una fecha con el motivo y sin descuento', function () {
    $old = pendingDay('2026-10-25');
    $recent = pendingDay('2026-11-10');
    $oldAbsence = Absence::firstOrCreate(['attendance_day_id' => $old->id], ['employee_id' => $old->employee_id, 'status' => 'pending']);
    $recentAbsence = Absence::firstOrCreate(['attendance_day_id' => $recent->id], ['employee_id' => $recent->employee_id, 'status' => 'pending']);

    expect(Artisan::call('absences:justify-pending', ['--until' => '2026-10-31']))->toBe(1);

    Artisan::call('absences:justify-pending', ['--until' => '2026-10-31', '--reason' => 'Anteriores a la implementacion', '--dry-run' => true]);
    expect($oldAbsence->fresh()->status)->toBe('pending');

    Artisan::call('absences:justify-pending', ['--until' => '2026-10-31', '--reason' => 'Anteriores a la implementacion']);

    expect($oldAbsence->fresh()->status)->toBe('justified')
        ->and($oldAbsence->fresh()->review_notes)->toBe('Anteriores a la implementacion')
        ->and($oldAbsence->fresh()->employee_deduction_id)->toBeNull()
        ->and($recentAbsence->fresh()->status)->toBe('pending');
});
