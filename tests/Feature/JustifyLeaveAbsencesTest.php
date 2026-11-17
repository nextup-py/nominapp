<?php

use App\Filament\Resources\EmployeeLeaveResource\Pages\EditEmployeeLeaves;
use App\Models\Absence;
use App\Models\AttendanceDay;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** Licencia de día completo aprobada con ausencias pendientes dentro de su período. */
function leaveWithPendingAbsences(int $days = 3, array $leave = []): array
{
    static $n = 6500000;
    $n++;

    $company = Company::create(['name' => "EmpJL {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucJL {$n}", 'company_id' => $company->id]);
    $employee = Employee::create(['first_name' => 'Ana', 'last_name' => "Lic{$n}", 'ci' => (string) $n, 'branch_id' => $branch->id, 'status' => 'active']);

    $record = EmployeeLeave::create($leave + [
        'employee_id' => $employee->id, 'type' => 'maternity_leave', 'start_date' => '2026-03-02', 'end_date' => '2026-03-'.str_pad((string) (1 + $days), 2, '0', STR_PAD_LEFT),
        'status' => 'approved', 'generates_deduction' => false,
    ]);

    $absences = collect(range(0, $days - 1))->map(function (int $i) use ($employee) {
        $day = AttendanceDay::create(['employee_id' => $employee->id, 'date' => now()->setDate(2026, 3, 2)->addDays($i)->toDateString(), 'status' => 'absent', 'is_calculated' => false]);

        return Absence::firstOrCreate(['attendance_day_id' => $day->id], ['employee_id' => $employee->id, 'status' => 'pending']);
    });

    return [$record, $absences];
}

beforeEach(function () {
    $this->admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web'])));
});

it('el comando justifica las ausencias pendientes de una licencia aprobada y es idempotente', function () {
    [$leave, $absences] = leaveWithPendingAbsences(3);

    Artisan::call('leaves:justify-absences', ['--dry-run' => true]);

    expect($absences->every(fn ($a) => $a->fresh()->status === 'pending'))->toBeTrue();

    Artisan::call('leaves:justify-absences');

    expect($absences->every(fn ($a) => $a->fresh()->status === 'justified' && $a->fresh()->employee_leave_id === $leave->id))->toBeTrue()
        ->and($absences->first()->fresh()->attendanceDay->justified_absence)->toBeTrue();

    Artisan::call('leaves:justify-absences');

    expect(Absence::where('status', 'justified')->count())->toBe(3);
});

it('no toca licencias parciales, pendientes ni ausencias fuera del período', function () {
    [$partial, $partialAbsences] = leaveWithPendingAbsences(2, ['start_time' => '08:00', 'end_time' => '10:00']);
    [$pending, $pendingAbsences] = leaveWithPendingAbsences(2, ['status' => 'pending']);
    [$leave, $absences] = leaveWithPendingAbsences(2);

    $outside = AttendanceDay::create(['employee_id' => $leave->employee_id, 'date' => '2026-04-20', 'status' => 'absent', 'is_calculated' => false]);
    $outsideAbsence = Absence::firstOrCreate(['attendance_day_id' => $outside->id], ['employee_id' => $leave->employee_id, 'status' => 'pending']);

    Artisan::call('leaves:justify-absences');

    expect($partialAbsences->every(fn ($a) => $a->fresh()->status === 'pending'))->toBeTrue()
        ->and($pendingAbsences->every(fn ($a) => $a->fresh()->status === 'pending'))->toBeTrue()
        ->and($outsideAbsence->fresh()->status)->toBe('pending')
        ->and($absences->every(fn ($a) => $a->fresh()->status === 'justified'))->toBeTrue();
});

it('el estado no se puede cambiar editando el formulario de la licencia', function () {
    [$leave] = leaveWithPendingAbsences(2, ['status' => 'pending']);

    Livewire::actingAs($this->admin)
        ->test(EditEmployeeLeaves::class, ['record' => $leave->getKey()])
        ->fillForm(['status' => 'approved'])
        ->call('save');

    expect($leave->fresh()->status)->toBe('pending');
});
