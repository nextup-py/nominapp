<?php

use App\Filament\Pages\AdvanceReport;
use App\Filament\Pages\AttendanceReport;
use App\Filament\Pages\EmployeeReport;
use App\Filament\Pages\LogViewer;
use App\Filament\Pages\ManageGeneralSettings;
use App\Filament\Pages\ManagePayrollSettings;
use App\Filament\Pages\SalaryReport;
use App\Filament\Pages\VacationReport;
use App\Filament\Widgets\PayrollStatusWidget;
use App\Filament\Widgets\StatsOverview;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Settings\GeneralSettings;
use Database\Seeders\BusinessActionPermissionSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    (new PermissionSeeder)->run();
    (new BusinessActionPermissionSeeder)->run();
    (new RoleSeeder)->run();

    app(GeneralSettings::class)->setup_completed = true;
    app(GeneralSettings::class)->save();
});

/** Crea un usuario con el rol indicado (los roles base los siembra el RoleSeeder). */
function userWithRole(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));

    return $user;
}

it('solo el Super Admin abre la configuración de nómina, la general y el visor de logs', function (string $role, bool $allowed) {
    $this->actingAs(userWithRole($role));

    expect(ManagePayrollSettings::canAccess())->toBe($allowed)
        ->and(ManageGeneralSettings::canAccess())->toBe($allowed)
        ->and(LogViewer::canAccess())->toBe($allowed);
})->with([
    'Super Admin' => ['Super Admin', true],
    'RRHH' => ['RRHH', false],
    'Contador/Nómina' => ['Contador/Nómina', false],
    'Solo Lectura' => ['Solo Lectura', false],
]);

it('cada reporte pide el permiso de su módulo', function () {
    $rrhh = userWithRole('RRHH');
    $this->actingAs($rrhh);

    expect(EmployeeReport::canAccess())->toBeTrue()
        ->and(AttendanceReport::canAccess())->toBeTrue()
        ->and(VacationReport::canAccess())->toBeTrue()
        ->and(SalaryReport::canAccess())->toBeFalse()
        ->and(AdvanceReport::canAccess())->toBeFalse();

    $this->actingAs(userWithRole('Contador/Nómina'));

    expect(SalaryReport::canAccess())->toBeTrue()
        ->and(AdvanceReport::canAccess())->toBeTrue()
        ->and(AttendanceReport::canAccess())->toBeFalse()
        ->and(VacationReport::canAccess())->toBeFalse();
});

it('los widgets del escritorio respetan el permiso del módulo', function () {
    $this->actingAs(userWithRole('RRHH'));
    expect(PayrollStatusWidget::canView())->toBeFalse()
        ->and(StatsOverview::canView())->toBeTrue();

    $this->actingAs(userWithRole('Contador/Nómina'));
    expect(PayrollStatusWidget::canView())->toBeTrue();
});

it('las descargas de reportes y logs devuelven 403 sin el permiso del módulo', function (string $route) {
    $this->actingAs(userWithRole('RRHH'))->get(route($route))->assertForbidden();
})->with([
    'salarios' => 'salary-report.pdf',
    'adelantos' => 'advances.report.pdf',
    'préstamos masivo de adelantos' => 'advances.pdf.bulk',
    'mercadería' => 'merchandise.report.pdf',
    'logs' => 'logs.download',
]);

it('un usuario sin permisos recibe 403 en reportes y legajos pero entra con el permiso', function () {
    $company = Company::create(['name' => 'EmpPP', 'ruc' => '4900001-1', 'employer_number' => 4900001]);
    $branch = Branch::create(['name' => 'SucPP', 'company_id' => $company->id]);
    $employee = Employee::create([
        'first_name' => 'Per', 'last_name' => 'Miso', 'ci' => '4900001', 'email' => 'pp@test.com',
        'branch_id' => $branch->id, 'status' => 'active',
    ]);

    $sinPermisos = User::factory()->create();
    $sinPermisos->assignRole(Role::create(['name' => 'Sin permisos', 'guard_name' => 'web']));

    $this->actingAs($sinPermisos);
    $this->get(route('employees.legajo', $employee))->assertForbidden();
    $this->get(route('employees.report.pdf'))->assertForbidden();

    $this->actingAs(userWithRole('RRHH'));
    expect($this->get(route('employees.legajo', $employee))->status())->not->toBe(403)
        ->and($this->get(route('employees.report.pdf'))->status())->not->toBe(403);
});
