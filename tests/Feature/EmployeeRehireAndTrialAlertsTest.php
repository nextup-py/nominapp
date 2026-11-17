<?php

use App\Filament\Resources\EmployeeResource\Pages\ViewEmployee;
use App\Filament\Widgets\ExpiringContracts;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Notifications\TrialEndingNotification;
use App\Services\EmployeeRehireService;
use App\Services\EmployeeTerminationService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** Empleado activo con un contrato vigente de indefinido que empezó hace `$monthsAgo` meses. */
function rehireEmployee(int $monthsAgo = 8): Employee
{
    static $n = 7300000;
    $n++;

    $company = Company::create(['name' => "EmpRH {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucRH {$n}", 'company_id' => $company->id]);
    $employee = Employee::create(['first_name' => 'Ana', 'last_name' => "Rein{$n}", 'ci' => (string) $n, 'branch_id' => $branch->id, 'status' => 'active']);
    $department = Department::create(['name' => "DepRH {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "PosRH {$n}", 'department_id' => $department->id]);

    Contract::create([
        'employee_id' => $employee->id, 'type' => 'indefinido', 'start_date' => now()->subMonths($monthsAgo)->toDateString(),
        'salary_type' => 'mensual', 'salary' => 3_044_000, 'payroll_type' => 'monthly', 'payment_method' => 'cash',
        'work_modality' => 'presencial', 'position_id' => $position->id, 'department_id' => $department->id, 'status' => 'active',
    ]);

    return $employee->fresh();
}

function rehireAdmin(): User
{
    return tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web'])));
}

/** Empleado dado de baja (contrato terminado) que empezó hace 14 meses. */
function rehireInactiveEmployee(): Employee
{
    $employee = rehireEmployee(14);
    app(EmployeeTerminationService::class)->terminate($employee, today()->subMonths(2), 'resignation', 'Renuncia');

    return $employee->fresh();
}

it('el reingreso reactiva al empleado y abre un contrato prellenado con los datos del último', function () {
    $employee = rehireInactiveEmployee();
    $last = Contract::where('employee_id', $employee->id)->first();

    $contract = app(EmployeeRehireService::class)->rehire($employee, [
        'type' => 'indefinido', 'start_date' => today()->toDateString(), 'trial_days' => 30,
        'salary' => 3_500_000, 'accumulate_seniority' => false,
    ]);

    expect($employee->fresh()->status)->toBe('active')
        ->and($contract->status)->toBe('active')
        ->and($contract->position_id)->toBe($last->position_id)
        ->and($contract->salary)->toBe(3_500_000)
        ->and($contract->trial_days)->toBe(30)
        ->and($contract->seniority_from)->toBeNull()
        ->and($employee->fresh()->hire_date->toDateString())->toBe(today()->toDateString());
});

it('acumular antigüedad fija seniority_from al primer ingreso y hire_date la respeta', function () {
    $employee = rehireInactiveEmployee();
    $first = $employee->contracts()->oldest('start_date')->first()->start_date->toDateString();

    app(EmployeeRehireService::class)->rehire($employee, [
        'type' => 'indefinido', 'start_date' => today()->toDateString(), 'accumulate_seniority' => true,
    ]);

    expect($employee->fresh()->hire_date->toDateString())->toBe($first);
});

it('no reingresa a un activo, ni a uno con contrato vigente, ni excede el Art. 53', function () {
    $active = rehireEmployee();
    expect(fn () => app(EmployeeRehireService::class)->rehire($active, ['start_date' => today()->toDateString()]))
        ->toThrow(DomainException::class, 'ya está activo');

    $employee = rehireInactiveEmployee();
    expect(fn () => app(EmployeeRehireService::class)->rehire($employee, [
        'type' => 'plazo_fijo', 'start_date' => today()->toDateString(), 'end_date' => today()->addMonths(18)->toDateString(),
    ]))->toThrow(DomainException::class, 'Art. 53');

    expect(fn () => app(EmployeeRehireService::class)->rehire($employee, [
        'type' => 'plazo_fijo', 'start_date' => today()->toDateString(),
    ]))->toThrow(DomainException::class, 'fecha de finalización');
});

it('la acción Reingresar solo aparece en inactivos y funciona desde la ficha', function () {
    $employee = rehireInactiveEmployee();
    $this->actingAs(rehireAdmin());

    Livewire::test(ViewEmployee::class, ['record' => $employee->id])
        ->assertActionVisible('rehire_employee')
        ->callAction('rehire_employee', ['type' => 'indefinido', 'start_date' => today()->toDateString(), 'salary' => 3_044_000, 'trial_days' => 0, 'accumulate_seniority' => false])
        ->assertHasNoActionErrors();

    expect($employee->fresh()->status)->toBe('active');

    Livewire::test(ViewEmployee::class, ['record' => $employee->id])->assertActionHidden('rehire_employee');
});

it('trialEndingWithin lista solo contratos vigentes cuya prueba termina en la ventana', function () {
    $in = rehireEmployee(1);
    Contract::where('employee_id', $in->id)->update(['start_date' => today()->subDays(27), 'trial_days' => 30]); // termina en 3 días
    $far = rehireEmployee(1);
    Contract::where('employee_id', $far->id)->update(['start_date' => today()->subDays(5), 'trial_days' => 30]); // en 25 días
    $past = rehireEmployee(1);
    Contract::where('employee_id', $past->id)->update(['start_date' => today()->subDays(50), 'trial_days' => 30]); // ya terminó

    $ids = Contract::trialEndingWithin(7)->pluck('employee_id')->all();

    expect($ids)->toBe([$in->id]);
});

it('contracts:notify-trial-ending avisa una vez, respeta el permiso y el 0 lo apaga', function () {
    $employee = rehireEmployee(1);
    Contract::where('employee_id', $employee->id)->update(['start_date' => today()->subDays(27), 'trial_days' => 30]);

    $hr = rehireAdmin();
    $other = User::factory()->create();

    $this->artisan('contracts:notify-trial-ending')->assertSuccessful();
    $this->artisan('contracts:notify-trial-ending')->assertSuccessful();

    expect($hr->unreadNotifications()->where('type', TrialEndingNotification::class)->count())->toBe(1)
        ->and($other->unreadNotifications()->where('type', TrialEndingNotification::class)->count())->toBe(0);

    $hr->unreadNotifications()->delete();
    $settings = app(GeneralSettings::class);
    $settings->contract_trial_alert_days = 0;
    $settings->save();

    $this->artisan('contracts:notify-trial-ending')->assertSuccessful();

    expect($hr->unreadNotifications()->count())->toBe(0);
});

it('el widget Por vencer lista contratos por fin de prueba y por vencimiento, con motivo', function () {
    $trial = rehireEmployee(1);
    Contract::where('employee_id', $trial->id)->update(['start_date' => today()->subDays(27), 'trial_days' => 30]);
    $expiring = rehireEmployee(6);
    Contract::where('employee_id', $expiring->id)->update(['type' => 'plazo_fijo', 'end_date' => today()->addDays(10)]);
    $quiet = rehireEmployee(6);

    $this->actingAs(rehireAdmin());

    Livewire::test(ExpiringContracts::class)
        ->assertCanSeeTableRecords(Contract::whereIn('employee_id', [$trial->id, $expiring->id])->get())
        ->assertCanNotSeeTableRecords(Contract::where('employee_id', $quiet->id)->get())
        ->assertSee('Fin de prueba')
        ->assertSee('Por vencer');
});
