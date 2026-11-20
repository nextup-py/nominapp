<?php

use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\Position;
use App\Models\User;
use App\Models\Vacation;
use App\Notifications\SilentEmployeesNotification;
use App\Services\SilentEmployeesService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 11, 20, 12, 0));
});

afterEach(fn () => Carbon::setTestNow());

function silentEmployee(string $startDate = '2026-01-01', string $contractStatus = 'active'): Employee
{
    static $n = 9300000;
    $n++;

    $company = Company::create(['name' => "EmpSil {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucSil {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "DeptoSil {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "CargoSil {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Silencio', 'last_name' => "Test{$n}", 'ci' => (string) $n, 'email' => "sil{$n}@test.com",
        'branch_id' => $branch->id, 'status' => 'active',
    ]);

    Contract::create([
        'employee_id' => $employee->id, 'type' => 'indefinido', 'start_date' => $startDate,
        'salary_type' => 'mensual', 'salary' => 3_000_000, 'position_id' => $position->id,
        'department_id' => $department->id, 'status' => $contractStatus,
    ]);

    return $employee->fresh();
}

function silentMark(Employee $employee, string $daysAgo = '2'): void
{
    $at = now()->subDays((int) $daysAgo);
    $day = AttendanceDay::create(['employee_id' => $employee->id, 'date' => $at->toDateString(), 'status' => 'present']);
    AttendanceEvent::create(['attendance_day_id' => $day->id, 'employee_id' => $employee->id, 'event_type' => 'check_in', 'recorded_at' => $at, 'source' => 'terminal']);
}

it('detecta a quien no marcó nada en los últimos días y no a quien sí marcó', function () {
    $silent = silentEmployee();
    $marked = silentEmployee();
    silentMark($marked, '2');

    $ids = app(SilentEmployeesService::class)->find(7)->pluck('id');

    expect($ids)->toContain($silent->id)->not->toContain($marked->id);
});

it('una marcación vieja no cuenta como actividad reciente', function () {
    $oldMark = silentEmployee();
    silentMark($oldMark, '15');

    expect(app(SilentEmployeesService::class)->find(7)->pluck('id'))->toContain($oldMark->id);
});

it('excluye a quien está de vacaciones o con licencia aprobada, recién ingresado, suspendido o inactivo', function () {
    $vacation = silentEmployee();
    Vacation::create(['employee_id' => $vacation->id, 'start_date' => '2026-11-10', 'end_date' => '2026-11-25', 'status' => 'approved', 'business_days' => 10]);

    $leave = silentEmployee();
    EmployeeLeave::create(['employee_id' => $leave->id, 'type' => 'medical_leave', 'start_date' => '2026-11-05', 'end_date' => '2026-12-05', 'status' => 'approved', 'reason' => 'x']);

    $pendingLeave = silentEmployee();
    EmployeeLeave::create(['employee_id' => $pendingLeave->id, 'type' => 'medical_leave', 'start_date' => '2026-11-05', 'end_date' => '2026-12-05', 'status' => 'pending', 'reason' => 'x']);

    $newHire = silentEmployee('2026-11-18');
    $suspended = silentEmployee('2026-01-01', 'suspended');
    $inactive = silentEmployee();
    $inactive->update(['status' => 'inactive']);

    $ids = app(SilentEmployeesService::class)->find(7)->pluck('id');

    expect($ids)->toContain($pendingLeave->id)
        ->not->toContain($vacation->id)
        ->not->toContain($leave->id)
        ->not->toContain($newHire->id)
        ->not->toContain($suspended->id)
        ->not->toContain($inactive->id);
});

it('avisa a quien puede ver asistencias, no repite mientras haya uno sin leer y respeta --dry-run', function () {
    $employee = silentEmployee();
    $viewer = User::create(['name' => 'RRHH', 'email' => 'rrhh-sil@test.com', 'password' => bcrypt('secret')]);
    $viewer->givePermissionTo(Permission::firstOrCreate(['name' => 'view_any_attendance_day', 'guard_name' => 'web']));
    $other = User::create(['name' => 'Otro', 'email' => 'otro-sil@test.com', 'password' => bcrypt('secret')]);

    $this->artisan('attendance:notify-silent-employees', ['--dry-run' => true])->assertSuccessful();
    expect($viewer->notifications()->count())->toBe(0);

    $this->artisan('attendance:notify-silent-employees')->assertSuccessful();

    $notification = $viewer->notifications()->where('type', SilentEmployeesNotification::class)->first();

    expect($notification)->not->toBeNull()
        ->and($notification->data['silent_employees_count'])->toBe(1)
        ->and($notification->data['body'])->toContain($employee->first_name)
        ->and($other->notifications()->count())->toBe(0);

    $this->artisan('attendance:notify-silent-employees')->assertSuccessful();
    expect($viewer->notifications()->count())->toBe(1);
});
