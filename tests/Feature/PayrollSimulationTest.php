<?php

use App\Filament\Pages\PayrollSimulation;
use App\Models\Advance;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Deduction;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDeduction;
use App\Models\Payroll;
use App\Models\PayrollPeriod;
use App\Models\Position;
use App\Models\User;
use App\Services\PayrollService;
use App\Services\PayrollSimulationService;
use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Database\Seeders\BusinessActionPermissionSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    (new PermissionSeeder)->run();
    (new BusinessActionPermissionSeeder)->run();
    (new RoleSeeder)->run();

    app(GeneralSettings::class)->setup_completed = true;
    app(GeneralSettings::class)->save();

    $settings = [
        'monthly_hours' => 240, 'monthly_hours_nocturno' => 210, 'monthly_hours_mixto' => 225,
        'daily_hours' => 8, 'daily_hours_nocturno' => 7, 'daily_hours_mixto' => 7.5, 'days_per_month' => 30,
        'overtime_multiplier_diurno' => 1.5, 'overtime_multiplier_nocturno' => 2.6,
        'overtime_multiplier_holiday' => 2.0, 'overtime_multiplier_nocturno_holiday' => 2.6,
        'overtime_max_daily_hours' => 3, 'ips_employee_rate' => 9, 'indemnizacion_days_per_year' => 15,
        'vacation_min_consecutive_days' => 6, 'vacation_min_years_service' => 1,
        'vacation_business_days' => [1, 2, 3, 4, 5, 6], 'ips_deduction_code' => 'IPS001',
        'min_salary_monthly' => 2_550_328, 'min_salary_daily_jornal' => 87_950, 'family_bonus_percentage' => 5.0,
    ];

    foreach ($settings as $name => $value) {
        DB::table('settings')->updateOrInsert(['group' => 'payroll', 'name' => $name], ['payload' => json_encode($value)]);
    }

    Deduction::firstOrCreate(['code' => 'ADE001'], [
        'name' => 'Adelanto de Salario', 'type' => 'loan', 'calculation' => 'fixed',
        'is_mandatory' => false, 'is_active' => true, 'affects_irp' => false,
    ]);
});

/** Empleado mensualizado con contrato activo y un adelanto entregado, en una empresa nueva. */
function psimEmployee(): array
{
    static $n = 4600000;
    $n++;

    $company = Company::create(['name' => "EmpPS {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucPS {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "DepPS {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "PosPS {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Simula', 'last_name' => 'Cion', 'ci' => (string) $n, 'email' => "ps{$n}@test.com",
        'branch_id' => $branch->id, 'status' => 'active',
    ]);

    Contract::create([
        'employee_id' => $employee->id, 'type' => 'indefinido', 'start_date' => Carbon::now()->subYear(),
        'salary_type' => 'mensual', 'salary' => 3_000_000, 'payroll_type' => 'monthly', 'payment_method' => 'cash',
        'position_id' => $position->id, 'department_id' => $department->id, 'status' => 'active',
    ]);

    $advance = Advance::create([
        'employee_id' => $employee->id, 'amount' => 500_000, 'status' => 'disbursed',
        'approved_at' => '2026-03-15 10:00:00',
    ]);

    return [$company, $employee->fresh(), $advance];
}

it('calcula la nómina sin dejar nada guardado', function () {
    [$company, , $advance] = psimEmployee();

    $result = app(PayrollSimulationService::class)->simulate($company->id, 'monthly', Carbon::create(2026, 3, 1), Carbon::create(2026, 3, 31));

    expect($result['rows'])->toHaveCount(1)
        ->and($result['rows'][0]['base_salary'])->toBe(3_000_000.0)
        ->and($result['rows'][0]['net'])->toBe(2_500_000.0)
        ->and($result['totals']['net'])->toBe(2_500_000.0)
        ->and($result['totals']['net_cash'])->toBe(2_500_000.0)
        ->and(Payroll::count())->toBe(0)
        ->and(PayrollPeriod::count())->toBe(0)
        ->and(EmployeeDeduction::count())->toBe(0)
        ->and($advance->fresh()->status)->toBe('disbursed')
        ->and($advance->fresh()->payroll_id)->toBeNull();
});

it('no se puede simular un período que ya tiene recibos reales', function () {
    [$company] = psimEmployee();
    $period = PayrollPeriod::create([
        'company_id' => $company->id, 'name' => 'Marzo 2026', 'start_date' => '2026-03-01', 'end_date' => '2026-03-31',
        'frequency' => 'monthly', 'status' => 'draft',
    ]);
    app(PayrollService::class)->generateForPeriod($period, withPdf: false);
    expect(Payroll::count())->toBe(1);

    app(PayrollSimulationService::class)->simulate($company->id, 'monthly', Carbon::create(2026, 3, 1), Carbon::create(2026, 3, 31));
})->throws(DomainException::class);

it('la pantalla simula y muestra los totales', function () {
    [$company] = psimEmployee();
    $admin = User::factory()->create();
    $admin->assignRole('Super Admin');
    $this->actingAs($admin);

    Livewire::test(PayrollSimulation::class)
        ->fillForm(['company_id' => $company->id, 'frequency' => 'monthly', 'from' => '2026-03-01', 'to' => '2026-03-31'])
        ->call('simulate')
        ->assertSet('error', null)
        ->assertSee('Totales de la simulación')
        ->assertSee('Descargar Excel');

    expect(Payroll::count())->toBe(0);
});

it('solo quien ve períodos de nómina abre la simulación', function (string $role, bool $allowed) {
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));
    $this->actingAs($user);

    expect(PayrollSimulation::canAccess())->toBe($allowed);
})->with([
    'Super Admin' => ['Super Admin', true],
    'Contador/Nómina' => ['Contador/Nómina', true],
    'RRHH' => ['RRHH', false],
]);

it('el Excel de la simulación pide el permiso y se descarga con él', function () {
    [$company] = psimEmployee();
    $query = ['company' => $company->id, 'frequency' => 'monthly', 'from' => '2026-03-01', 'to' => '2026-03-31'];

    $rrhh = User::factory()->create();
    $rrhh->assignRole('RRHH');
    $this->actingAs($rrhh)->get(route('payroll-simulation.excel', $query))->assertForbidden();

    Excel::fake();
    $contador = User::factory()->create();
    $contador->assignRole('Contador/Nómina');
    $this->actingAs($contador)->get(route('payroll-simulation.excel', $query))->assertSuccessful();

    Excel::assertDownloaded('simulacion_nomina_2026-03-01_2026-03-31.xlsx');
});
