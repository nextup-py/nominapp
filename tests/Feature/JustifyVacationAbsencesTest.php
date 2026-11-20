<?php

use App\Models\Absence;
use App\Models\AttendanceDay;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Models\Vacation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** Vacación con ausencias pendientes dentro (2) y una fuera de su período. */
function vacationWithAbsences(string $status = 'approved'): array
{
    static $n = 6700000;
    $n++;

    $company = Company::create(['name' => "EmpJV {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucJV {$n}", 'company_id' => $company->id]);
    $employee = Employee::create(['first_name' => 'Ana', 'last_name' => "Vac{$n}", 'ci' => (string) $n, 'branch_id' => $branch->id, 'status' => 'active']);

    $vacation = Vacation::create([
        'employee_id' => $employee->id, 'start_date' => '2026-03-02', 'end_date' => '2026-03-03', 'return_date' => '2026-03-04',
        'payment_method' => 'immediate', 'status' => $status, 'business_days' => 2,
    ]);

    $absences = collect(['2026-03-02', '2026-03-03', '2026-03-05'])->map(function (string $date) use ($employee) {
        $day = AttendanceDay::create(['employee_id' => $employee->id, 'date' => $date, 'status' => 'absent', 'is_calculated' => false]);

        return Absence::firstOrCreate(['attendance_day_id' => $day->id], ['employee_id' => $employee->id, 'status' => 'pending']);
    });

    return [$vacation, $absences];
}

beforeEach(function () {
    $this->admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web'])));
});

it('justifica solo las ausencias pendientes dentro de una vacación aprobada y es idempotente', function () {
    [$vacation, $absences] = vacationWithAbsences();

    Artisan::call('vacations:justify-absences', ['--dry-run' => true]);
    expect($absences->every(fn ($a) => $a->fresh()->status === 'pending'))->toBeTrue();

    Artisan::call('vacations:justify-absences');

    expect($absences[0]->fresh()->status)->toBe('justified')
        ->and($absences[0]->fresh()->review_notes)->toContain('vacaciones aprobadas')
        ->and($absences[0]->fresh()->attendanceDay->justified_absence)->toBeTrue()
        ->and($absences[1]->fresh()->status)->toBe('justified')
        ->and($absences[2]->fresh()->status)->toBe('pending');

    Artisan::call('vacations:justify-absences');

    expect(Absence::where('employee_id', $vacation->employee_id)->where('status', 'justified')->count())->toBe(2);
});

it('no toca vacaciones que no están aprobadas', function () {
    [, $absences] = vacationWithAbsences('pending');

    Artisan::call('vacations:justify-absences');

    expect($absences->every(fn ($a) => $a->fresh()->status === 'pending'))->toBeTrue();
});
