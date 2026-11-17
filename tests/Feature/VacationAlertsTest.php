<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Models\Vacation;
use App\Models\VacationBalance;
use App\Notifications\VacationAlertNotification;
use App\Services\VacationAlertService;
use App\Settings\PayrollSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/** Empleado activo cuyo contrato vigente empezó en la fecha dada (la antigüedad sale del contrato). */
function alertEmployee(string $hired, string $first = 'Ana'): Employee
{
    static $n = 6400000;
    $n++;

    $company = Company::create(['name' => "EmpVA {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucVA {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "DeptoVA {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "CargoVA {$n}", 'department_id' => $department->id]);

    $employee = Employee::create(['first_name' => $first, 'last_name' => "Alerta{$n}", 'ci' => (string) $n, 'branch_id' => $branch->id, 'status' => 'active']);

    Contract::create([
        'employee_id' => $employee->id, 'type' => 'indefinido', 'start_date' => $hired, 'salary_type' => 'mensual',
        'salary' => 2_550_000, 'position_id' => $position->id, 'department_id' => $department->id, 'status' => 'active',
    ]);

    return $employee->fresh();
}

/** Vacación del empleado en el estado indicado. */
function alertVacation(Employee $employee, array $attributes = []): Vacation
{
    $balance = VacationBalance::firstOrCreate(['employee_id' => $employee->id, 'year' => 2026], ['years_of_service' => 2, 'entitled_days' => 12, 'used_days' => 0, 'pending_days' => 0]);

    return Vacation::create($attributes + [
        'employee_id' => $employee->id, 'vacation_balance_id' => $balance->id, 'start_date' => '2026-05-04', 'end_date' => '2026-05-09',
        'payment_method' => 'immediate', 'status' => 'approved', 'business_days' => 6, 'payment_amount' => 1_000_000, 'payment_status' => 'unpaid',
    ]);
}

/** Usuario con permiso de editar vacaciones. */
function vacationManager(): User
{
    $permission = Permission::firstOrCreate(['name' => 'update_vacation', 'guard_name' => 'web']);

    return tap(User::factory()->create(), fn (User $u) => $u->givePermissionTo($permission));
}

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 16)->startOfDay()->addHours(9)); // lunes
});

it('avisa de las solicitudes pendientes solo después de los días configurados', function () {
    $employee = alertEmployee('2020-01-10');
    $old = alertVacation($employee, ['status' => 'pending']);
    $old->forceFill(['created_at' => now()->subDays(5)])->save();
    alertVacation($employee, ['status' => 'pending', 'start_date' => '2026-12-01', 'end_date' => '2026-12-05']);

    $result = app(VacationAlertService::class)->pending();

    expect($result['count'])->toBe(1)->and($result['days'])->toBe(3);

    $settings = app(PayrollSettings::class);
    $settings->vacation_alert_pending_days = 0;
    $settings->save();

    expect(app(VacationAlertService::class)->pending())->toBeNull();
});

it('avisa de las aprobadas sin pagar con inicio de hace más de una semana y respeta el interruptor', function () {
    $employee = alertEmployee('2020-01-10');
    alertVacation($employee);
    alertVacation($employee, ['payment_status' => 'paid', 'start_date' => '2026-06-01', 'end_date' => '2026-06-06']);
    alertVacation($employee, ['start_date' => now()->subDays(2)->toDateString(), 'end_date' => now()->addDays(3)->toDateString()]);

    expect(app(VacationAlertService::class)->unpaid()['count'])->toBe(1);

    $settings = app(PayrollSettings::class);
    $settings->vacation_alert_unpaid_enabled = false;
    $settings->save();

    expect(app(VacationAlertService::class)->unpaid())->toBeNull();
});

it('detecta al empleado con el derecho anual completo sin tomar y no al que ya usó parte', function () {
    $full = alertEmployee('2022-03-01', 'Completa');
    VacationBalance::create(['employee_id' => $full->id, 'year' => 2026, 'years_of_service' => 4, 'entitled_days' => 12, 'used_days' => 0, 'pending_days' => 0]);

    $partial = alertEmployee('2022-03-01', 'Parcial');
    VacationBalance::create(['employee_id' => $partial->id, 'year' => 2026, 'years_of_service' => 4, 'entitled_days' => 12, 'used_days' => 6, 'pending_days' => 0]);

    alertEmployee('2026-06-01', 'Nueva'); // menos de un año: sin derecho

    $result = app(VacationAlertService::class)->unused();

    expect($result['count'])->toBe(1)->and($result['names'][0])->toStartWith('Completa');
});

it('detecta a quien cumple el primer año dentro de los días configurados', function () {
    alertEmployee('2025-12-01', 'Pronto');      // cumple 01/12/2026: dentro de 30 días
    alertEmployee('2026-03-01', 'Lejos');       // cumple en 2027
    alertEmployee('2020-12-01', 'Antiguo');     // ya pasó su primer año

    $result = app(VacationAlertService::class)->anniversary();

    expect($result['count'])->toBe(1)->and($result['names'][0])->toStartWith('Pronto');
});

it('el comando avisa a quien edita vacaciones, no repite con uno sin leer y el semanal solo sale los lunes', function () {
    $manager = vacationManager();
    $other = User::factory()->create();

    alertVacation(alertEmployee('2020-01-10'));

    Artisan::call('vacations:notify');

    expect($manager->unreadNotifications()->where('type', VacationAlertNotification::class)->count())->toBe(1)
        ->and($other->unreadNotifications()->count())->toBe(0);

    Artisan::call('vacations:notify');

    expect($manager->unreadNotifications()->where('type', VacationAlertNotification::class)->count())->toBe(1);

    $this->travelTo(now()->addDay()); // martes
    $full = alertEmployee('2022-03-01');
    VacationBalance::create(['employee_id' => $full->id, 'year' => 2026, 'years_of_service' => 4, 'entitled_days' => 12, 'used_days' => 0, 'pending_days' => 0]);

    Artisan::call('vacations:notify');

    expect($manager->unreadNotifications->contains(fn ($n) => ($n->data['kind'] ?? null) === 'unused'))->toBeFalse();

    Artisan::call('vacations:notify', ['--force-weekly' => true]);

    expect($manager->fresh()->unreadNotifications->contains(fn ($n) => ($n->data['kind'] ?? null) === 'unused'))->toBeTrue();
});

it('--dry-run no envía nada', function () {
    $manager = vacationManager();
    alertVacation(alertEmployee('2020-01-10'));

    Artisan::call('vacations:notify', ['--dry-run' => true]);

    expect($manager->unreadNotifications()->count())->toBe(0);
});
