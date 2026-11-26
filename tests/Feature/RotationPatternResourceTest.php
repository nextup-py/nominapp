<?php

use App\Exports\RotationAssignmentsExport;
use App\Filament\Resources\RotationPatternResource;
use App\Filament\Resources\RotationPatternResource\Pages\CreateRotationPattern;
use App\Filament\Resources\RotationPatternResource\Pages\EditRotationPattern;
use App\Filament\Resources\RotationPatternResource\Pages\ListRotationPatterns;
use App\Filament\Resources\RotationPatternResource\Pages\ViewRotationPattern;
use App\Filament\Resources\RotationPatternResource\RelationManagers\AssignmentsRelationManager;
use App\Filament\Resources\RotationPatternResource\RelationManagers\AuditsRelationManager;
use App\Filament\Resources\RotationPatternResource\RelationManagers\EmployeesRelationManager;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\RotationAssignment;
use App\Models\RotationPattern;
use App\Models\ShiftTemplate;
use App\Models\User;
use App\Services\RotationService;
use App\Support\PanelGlobalSearchProvider;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
});

/** Empresa de prueba con RUC único por llamada. */
function patternCompany(): Company
{
    static $n = 7840000;
    $n++;

    return Company::create(['name' => "EmpPatron {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
}

/** Patrón de prueba con un turno de mañana y un franco. */
function makePattern(Company $company, string $name = 'Seis por uno', array $extra = []): RotationPattern
{
    $morning = ShiftTemplate::firstOrCreate(
        ['company_id' => $company->id, 'name' => 'Mañana'],
        ['shift_type' => 'diurno', 'start_time' => '07:00', 'end_time' => '15:00', 'break_minutes' => 60],
    );
    $off = ShiftTemplate::firstOrCreate(
        ['company_id' => $company->id, 'name' => 'Franco'],
        ['shift_type' => 'diurno', 'is_day_off' => true],
    );

    return RotationPattern::create(array_merge([
        'company_id' => $company->id, 'name' => $name,
        'sequence' => [$morning->id, $morning->id, $morning->id, $off->id], 'is_active' => true,
    ], $extra));
}

/** Empleado de prueba. */
function patternEmployee(Company $company): Employee
{
    static $n = 7850000;
    $n++;
    $branch = Branch::create(['name' => "SucPatron {$n}", 'company_id' => $company->id]);

    return Employee::create(['first_name' => 'Rosa', 'last_name' => "Patron {$n}", 'ci' => (string) $n, 'email' => "patron{$n}@test.com", 'branch_id' => $branch->id, 'status' => 'active']);
}

it('resume el ciclo contando los días por turno', function () {
    $pattern = makePattern(patternCompany());

    expect($pattern->cycleSummary())->toBe('3 Mañana, 1 Franco · 4 días')
        ->and(collect($pattern->cycleDays())->pluck('name')->all())->toBe(['Mañana', 'Mañana', 'Mañana', 'Franco']);
});

it('el ciclo completo: asignar, remover hoy y desactivar el mismo día', function () {
    $company = patternCompany();
    $pattern = makePattern($company);
    $employee = patternEmployee($company);

    RotationService::assign($employee, $pattern, Carbon::today()->subDays(2));

    expect($pattern->currentAssignmentsCount())->toBe(1)
        ->and(fn () => $pattern->update(['is_active' => false]))->toThrow(DomainException::class)
        ->and($pattern->deletionBlockers())->not->toBe([]);

    Livewire::test(EmployeesRelationManager::class, ['ownerRecord' => $pattern, 'pageClass' => ViewRotationPattern::class])
        ->assertCanSeeTableRecords([$employee])
        ->callTableAction('remove', $employee, ['valid_until' => today()->toDateString()]);

    $pattern->refresh();

    expect($pattern->currentAssignmentsCount())->toBe(0)
        ->and($pattern->currentEmployees()->count())->toBe(0)
        ->and($pattern->update(['is_active' => false]))->toBeTrue()
        ->and(fn () => $pattern->delete())->toThrow(DomainException::class);

    Livewire::test(ViewRotationPattern::class, ['record' => $pattern->id])->callAction('toggle_status');
    expect($pattern->fresh()->is_active)->toBeTrue();
});

it('remover con una fecha futura programa el cambio y con una anterior al inicio no hace nada', function () {
    $company = patternCompany();
    $pattern = makePattern($company);
    $employee = patternEmployee($company);
    RotationService::assign($employee, $pattern, Carbon::today()->subDays(5));

    expect(RotationService::closeActive($employee, Carbon::today()->addDays(3)))->toBe(1)
        ->and($pattern->currentAssignmentsCount())->toBe(1)
        ->and(RotationService::closeActive($employee, Carbon::today()->subDays(10)))->toBe(0)
        ->and(RotationService::closeActive($employee, Carbon::today()))->toBe(1)
        ->and($pattern->currentAssignmentsCount())->toBe(0);
});

it('eliminar solo aparece si el patrón nunca se asignó', function () {
    $company = patternCompany();
    $pattern = makePattern($company);

    Livewire::test(EditRotationPattern::class, ['record' => $pattern->id])->assertActionVisible('delete');

    $used = makePattern($company, 'Usado');
    RotationService::assign(patternEmployee($company), $used, Carbon::today()->subDay());
    $used->assignments()->update(['valid_until' => today()->subDay()]);

    Livewire::test(EditRotationPattern::class, ['record' => $used->id])->assertActionHidden('delete');
});

it('el nombre es único por empresa y con una sola empresa activa no pide la empresa', function () {
    $company = patternCompany();
    $shift = ShiftTemplate::create(['company_id' => $company->id, 'name' => 'Mañana', 'shift_type' => 'diurno', 'start_time' => '07:00', 'end_time' => '15:00']);
    makePattern($company, 'Existente');

    Livewire::test(CreateRotationPattern::class)
        ->assertFormFieldIsHidden('company_id')
        ->fillForm(['name' => 'Existente', 'sequence' => [['shift_template_id' => $shift->id]]])
        ->call('create')
        ->assertHasFormErrors(['name' => 'unique']);

    Livewire::test(CreateRotationPattern::class)
        ->fillForm(['name' => 'patrón nuevo', 'sequence' => [['shift_template_id' => $shift->id]]])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(RotationPattern::where('name', 'Patrón nuevo')->value('company_id'))->toBe($company->id);
});

it('las pestañas del listado filtran por estado y las acciones masivas omiten los que tienen asignaciones', function () {
    $company = patternCompany();
    $active = makePattern($company, 'Activo');
    $inactive = makePattern($company, 'Inactivo', ['is_active' => false]);

    Livewire::test(ListRotationPatterns::class)
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$inactive])
        ->set('activeTab', 'inactive')
        ->assertCanSeeTableRecords([$inactive])
        ->set('activeTab', 'all')
        ->assertCountTableRecords(2);

    RotationService::assign(patternEmployee($company), $active, Carbon::today());

    RotationPatternResource::changeStatusBulk(RotationPattern::whereKey($active->id)->get(), false);
    expect($active->fresh()->is_active)->toBeTrue();
});

it('el historial de asignaciones y el de cambios se ven en el detalle', function () {
    $company = patternCompany();
    $pattern = makePattern($company);
    $employee = patternEmployee($company);
    RotationService::assign($employee, $pattern, Carbon::today()->subDays(3));
    $assignment = RotationAssignment::first();

    Livewire::test(AssignmentsRelationManager::class, ['ownerRecord' => $pattern, 'pageClass' => ViewRotationPattern::class])
        ->assertCanSeeTableRecords([$assignment]);

    config(['audit.console' => true]);
    $pattern->update(['name' => 'Renombrado']);

    Livewire::test(AuditsRelationManager::class, ['ownerRecord' => $pattern->fresh(), 'pageClass' => ViewRotationPattern::class])
        ->assertCountTableRecords(1);
});

it('con un rol de solo lectura no se ven las acciones que modifican', function () {
    $pattern = makePattern(patternCompany());
    Permission::findOrCreate('view_any_rotation_pattern', 'web');
    Permission::findOrCreate('view_rotation_pattern', 'web');
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'Lector patrones', 'guard_name' => 'web']));
    $user->givePermissionTo(['view_any_rotation_pattern', 'view_rotation_pattern']);
    $this->actingAs($user);

    expect(RotationPatternResource::canManage())->toBeFalse();

    Livewire::test(ViewRotationPattern::class, ['record' => $pattern->id])
        ->assertActionHidden('toggle_status')
        ->assertActionHidden('edit');
});

it('la búsqueda global ofrece patrones con su ciclo', function () {
    makePattern(patternCompany(), 'Rotativo nocturno');

    $results = (new PanelGlobalSearchProvider)->getResults('Rotativo nocturno');

    expect(collect($results->getCategories())->has('Patrones de rotación'))->toBeTrue();
});

it('asignar con «Rige desde» futuro cierra la rotación actual el día anterior y respeta «Hasta»', function () {
    $company = patternCompany();
    $current = makePattern($company, 'Actual');
    $next = makePattern($company, 'Siguiente');
    $employee = patternEmployee($company);
    RotationService::assign($employee, $current, Carbon::today()->subDays(3));

    Livewire::test(EmployeesRelationManager::class, ['ownerRecord' => $next, 'pageClass' => ViewRotationPattern::class])
        ->callTableAction('assign', data: [
            'employee_ids' => [$employee->id],
            'valid_from' => today()->addDays(5)->toDateString(),
            'valid_until' => today()->addDays(20)->toDateString(),
            'start_index' => 0,
        ])
        ->assertHasNoTableActionErrors();

    $new = RotationAssignment::where('pattern_id', $next->id)->first();

    expect($current->assignments()->first()->valid_until->toDateString())->toBe(today()->addDays(4)->toDateString())
        ->and($new->valid_from->toDateString())->toBe(today()->addDays(5)->toDateString())
        ->and($new->valid_until->toDateString())->toBe(today()->addDays(20)->toDateString())
        ->and($next->currentAssignmentsCount())->toBe(1);
});

it('duplicar copia el ciclo sin las asignaciones', function () {
    $company = patternCompany();
    $pattern = makePattern($company);
    RotationService::assign(patternEmployee($company), $pattern, Carbon::today());

    $copy = $pattern->duplicate('Seis por uno (copia)');

    expect($copy->id)->not->toBe($pattern->id)
        ->and($copy->sequence)->toBe($pattern->sequence)
        ->and($copy->assignments()->count())->toBe(0);

    Livewire::test(ViewRotationPattern::class, ['record' => $pattern->id])
        ->callAction('duplicate', data: ['name' => 'otra copia']);

    expect(RotationPattern::where('name', 'Otra copia')->exists())->toBeTrue();
});

it('exporta el historial de asignaciones del patrón', function () {
    $company = patternCompany();
    $pattern = makePattern($company);
    $employee = patternEmployee($company);
    RotationService::assign($employee, $pattern, Carbon::today()->subDays(2), 1);
    $export = new RotationAssignmentsExport($pattern->id);
    $rows = $export->query()->get()->map(fn ($a) => $export->map($a));

    expect($rows)->toHaveCount(1)
        ->and($rows->first()[3])->toBe('Vigente')
        ->and($rows->first()[6])->toBe('Día 2');
});
