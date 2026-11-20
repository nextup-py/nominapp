<?php

use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use App\Filament\Resources\EmployeeResource\Pages\ViewEmployee;
use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow(Carbon::create(2026, 11, 20, 12, 0)));

afterEach(fn () => Carbon::setTestNow());

/**
 * Canal con el que marca cada empleado en los últimos 30 días: Terminal, Celular, Ambos, Ninguno o Nuevo. La
 * definición en PHP (`markChannel()`) y en SQL (`scopeMarkChannel()`) debe coincidir.
 */
function channelEmployee(string $contractStart = '2026-01-01'): Employee
{
    static $n = 8700000;
    $n++;

    $company = Company::create(['name' => "EmpMC {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucMC {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "DeptoMC {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "CargoMC {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Canal', 'last_name' => "Test{$n}", 'ci' => (string) $n, 'email' => "mc{$n}@test.com",
        'branch_id' => $branch->id, 'status' => 'active',
    ]);

    Contract::create([
        'employee_id' => $employee->id, 'type' => 'indefinido', 'start_date' => $contractStart,
        'salary_type' => 'mensual', 'salary' => 3_000_000, 'position_id' => $position->id,
        'department_id' => $department->id, 'status' => 'active',
    ]);

    return $employee->fresh();
}

function channelMark(Employee $employee, string $source, int $daysAgo = 2): void
{
    $at = now()->subDays($daysAgo);
    $day = AttendanceDay::firstOrCreate(['employee_id' => $employee->id, 'date' => $at->toDateString()], ['status' => 'present']);
    AttendanceEvent::create(['attendance_day_id' => $day->id, 'employee_id' => $employee->id, 'event_type' => 'check_in', 'recorded_at' => $at, 'source' => $source]);
}

/** Un empleado por cada canal posible. @return array<string, Employee> */
function channelSet(): array
{
    $set = [
        'terminal' => channelEmployee(),
        'mobile' => channelEmployee(),
        'both' => channelEmployee(),
        'none' => channelEmployee(),
        'new' => channelEmployee('2026-11-10'),
    ];

    channelMark($set['terminal'], 'terminal');
    channelMark($set['mobile'], 'mobile');
    channelMark($set['both'], 'terminal');
    channelMark($set['both'], 'mobile', 3);

    return $set;
}

it('calcula el canal con el que marca cada empleado', function () {
    foreach (channelSet() as $expected => $employee) {
        expect($employee->fresh()->markChannel())->toBe($expected);
    }
});

it('las marcaciones manuales y las de hace más de 30 días no cuentan', function () {
    $manual = channelEmployee();
    channelMark($manual, 'manual');

    $old = channelEmployee();
    channelMark($old, 'terminal', 45);

    expect($manual->fresh()->markChannel())->toBe('none')
        ->and($old->fresh()->markChannel())->toBe('none');
});

it('la definición en SQL coincide con la de PHP', function () {
    $set = channelSet();

    foreach ($set as $channel => $employee) {
        $ids = Employee::query()->markChannel($channel)->pluck('employees.id')->all();

        expect($ids)->toContain($employee->id);

        foreach ($set as $other => $otherEmployee) {
            if ($other !== $channel) {
                expect($ids)->not->toContain($otherEmployee->id);
            }
        }
    }
});

it('el listado trae los conteos sin consultar por fila y muestra el canal con el estado del celular', function () {
    $set = channelSet();
    test()->actingAs(tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    $row = Employee::query()->withMarkChannelCounts()->findOrFail($set['both']->id);

    expect($row->getAttributes())->toHaveKeys(['terminal_marks', 'mobile_marks'])
        ->and((int) $row->terminal_marks)->toBe(1)
        ->and($row->markChannel())->toBe('both');

    Livewire::test(ListEmployees::class)
        ->set('activeTab', 'active')
        ->assertTableColumnExists('mark_channel')
        ->assertSee('Ambos')
        ->assertSee('Celular: No habilitado')
        ->filterTable('mark_channel', 'mobile')
        ->assertCanSeeTableRecords([$set['mobile']])
        ->assertCanNotSeeTableRecords([$set['terminal'], $set['both'], $set['none'], $set['new']]);
});

it('la ficha avisa si marcó con celular pero hoy no lo tiene habilitado', function () {
    $employee = channelEmployee();
    channelMark($employee, 'mobile');
    test()->actingAs(tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    expect($employee->fresh()->hasMobileMarkMismatch())->toBeTrue();

    Livewire::test(ViewEmployee::class, ['record' => $employee->getRouteKey()])
        ->assertSee('Marca con')
        ->assertSee('Marcó con el celular en los últimos 30 días, pero hoy no lo tiene habilitado')
        ->assertSee('Terminal 0 · Celular 1 · Manual 0');

    $employee->update(['mobile_marking_allowed' => true]);

    Livewire::test(ViewEmployee::class, ['record' => $employee->getRouteKey()])
        ->assertDontSee('hoy no lo tiene habilitado');
});

it('la ficha de quien nunca marcó con celular no muestra el aviso', function () {
    $employee = channelEmployee();
    channelMark($employee, 'terminal');
    test()->actingAs(tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    Livewire::test(ViewEmployee::class, ['record' => $employee->getRouteKey()])
        ->assertSee('Sin marcaciones')
        ->assertDontSee('hoy no lo tiene habilitado');
});
