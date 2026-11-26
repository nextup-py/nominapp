<?php

use App\Filament\Resources\ShiftTemplateResource;
use App\Filament\Resources\ShiftTemplateResource\Pages\CreateShiftTemplate;
use App\Filament\Resources\ShiftTemplateResource\Pages\EditShiftTemplate;
use App\Filament\Resources\ShiftTemplateResource\Pages\ListShiftTemplates;
use App\Filament\Resources\ShiftTemplateResource\Pages\ViewShiftTemplate;
use App\Filament\Resources\ShiftTemplateResource\RelationManagers\AuditsRelationManager;
use App\Filament\Resources\ShiftTemplateResource\RelationManagers\OverridesRelationManager;
use App\Filament\Resources\ShiftTemplateResource\RelationManagers\RotationPatternsRelationManager;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\RotationPattern;
use App\Models\ShiftOverride;
use App\Models\ShiftTemplate;
use App\Models\User;
use App\Support\PanelGlobalSearchProvider;
use Filament\Actions\ActionGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
});

/** Empresa de prueba con RUC único por llamada. */
function shiftCompany(): Company
{
    static $n = 7820000;
    $n++;

    return Company::create(['name' => "EmpTurno {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
}

/** Turno de prueba de la empresa dada. */
function makeShift(Company $company, string $name = 'Mañana', array $extra = []): ShiftTemplate
{
    return ShiftTemplate::create(array_merge([
        'company_id' => $company->id, 'name' => $name, 'shift_type' => 'diurno',
        'start_time' => '07:00', 'end_time' => '15:00', 'break_minutes' => 60,
    ], $extra));
}

/** Empleado de prueba. */
function shiftEmployee(Company $company): Employee
{
    static $n = 7830000;
    $n++;
    $branch = Branch::create(['name' => "SucTurno {$n}", 'company_id' => $company->id]);

    return Employee::create(['first_name' => 'Luis', 'last_name' => "Turno {$n}", 'ci' => (string) $n, 'email' => "turno{$n}@test.com", 'branch_id' => $branch->id, 'status' => 'active']);
}

it('resume el horario con horas netas y marca los que cruzan la medianoche', function () {
    $company = shiftCompany();

    expect(makeShift($company)->scheduleSummary())->toBe('07:00–15:00 · 7 h netas')
        ->and(makeShift($company, 'Noche', ['start_time' => '22:00', 'end_time' => '06:00', 'break_minutes' => 0])->crossesMidnight())->toBeTrue()
        ->and(makeShift($company, 'Franco', ['is_day_off' => true, 'start_time' => null, 'end_time' => null])->scheduleSummary())->toBe('Franco');
});

it('desactivar se bloquea con un patrón activo o un turno puntual futuro y se permite con solo historial', function () {
    $company = shiftCompany();
    $shift = makeShift($company);
    $pattern = RotationPattern::create(['company_id' => $company->id, 'name' => 'P1', 'sequence' => [(string) $shift->id], 'is_active' => true]);

    expect($shift->deactivationBlockers())->not->toBe([])
        ->and(fn () => $shift->update(['is_active' => false]))->toThrow(DomainException::class);

    $pattern->update(['is_active' => false]);
    $employee = shiftEmployee($company);
    $override = ShiftOverride::create(['employee_id' => $employee->id, 'override_date' => today()->addDay(), 'shift_id' => $shift->id, 'reason_type' => 'otro']);

    expect($shift->deactivationBlockers())->not->toBe([]);

    $override->update(['override_date' => today()->subDay()]);

    expect($shift->deactivationBlockers())->toBe([])
        ->and($shift->update(['is_active' => false]))->toBeTrue()
        ->and($shift->deletionBlockers())->not->toBe([])
        ->and(fn () => $shift->delete())->toThrow(DomainException::class);
});

it('solo se elimina un turno que nunca se usó', function () {
    $shift = makeShift(shiftCompany());

    Livewire::test(EditShiftTemplate::class, ['record' => $shift->id])->assertActionVisible('delete');
    $shift->delete();

    expect(ShiftTemplate::find($shift->id))->toBeNull();

    $used = makeShift(shiftCompany(), 'Usado');
    RotationPattern::create(['company_id' => $used->company_id, 'name' => 'P', 'sequence' => [$used->id], 'is_active' => false]);

    Livewire::test(EditShiftTemplate::class, ['record' => $used->id])->assertActionHidden('delete');
});

it('en el detalle Desactivar y Activar funcionan desde Más acciones', function () {
    $company = shiftCompany();
    $shift = makeShift($company);
    $pattern = RotationPattern::create(['company_id' => $company->id, 'name' => 'P', 'sequence' => [$shift->id], 'is_active' => true]);

    $actions = collect(Livewire::test(ViewShiftTemplate::class, ['record' => $shift->id])->instance()->getCachedHeaderActions());
    expect($actions->contains(fn ($a) => $a instanceof ActionGroup))->toBeTrue();

    Livewire::test(ViewShiftTemplate::class, ['record' => $shift->id])->callAction('toggle_status');
    expect($shift->fresh()->is_active)->toBeTrue();

    $pattern->update(['is_active' => false]);
    Livewire::test(ViewShiftTemplate::class, ['record' => $shift->id])->callAction('toggle_status');
    expect($shift->fresh()->is_active)->toBeFalse();

    Livewire::test(ViewShiftTemplate::class, ['record' => $shift->id])->callAction('toggle_status');
    expect($shift->fresh()->is_active)->toBeTrue();
});

it('el nombre es único por empresa, con mensaje propio, y se capitaliza solo la primera letra', function () {
    $company = shiftCompany();
    makeShift($company, 'Mañana');

    Livewire::test(CreateShiftTemplate::class)
        ->fillForm(['company_id' => $company->id, 'name' => 'Mañana', 'shift_type' => 'diurno', 'color' => '#112233', 'start_time' => '07:00', 'end_time' => '15:00', 'break_minutes' => 0])
        ->call('create')
        ->assertHasFormErrors(['name' => 'unique']);

    Livewire::test(CreateShiftTemplate::class)
        ->fillForm(['company_id' => $company->id, 'name' => 'turno de la tarde', 'shift_type' => 'diurno', 'color' => '#112233', 'start_time' => '15:00', 'end_time' => '23:00', 'break_minutes' => 0])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(ShiftTemplate::where('name', 'Turno de la tarde')->exists())->toBeTrue();
});

it('con una sola empresa activa no pide la empresa y la asigna sola', function () {
    $company = shiftCompany();

    Livewire::test(CreateShiftTemplate::class)
        ->assertFormFieldIsHidden('company_id')
        ->fillForm(['name' => 'Solo uno', 'shift_type' => 'diurno', 'color' => '#112233', 'start_time' => '07:00', 'end_time' => '15:00', 'break_minutes' => 0])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(ShiftTemplate::where('name', 'Solo uno')->value('company_id'))->toBe($company->id);
});

it('las pestañas del listado cuentan y filtran por estado', function () {
    $company = shiftCompany();
    makeShift($company, 'Activo');
    makeShift($company, 'Inactivo', ['is_active' => false]);

    Livewire::test(ListShiftTemplates::class)
        ->assertCanSeeTableRecords(ShiftTemplate::where('name', 'Activo')->get())
        ->assertCanNotSeeTableRecords(ShiftTemplate::where('name', 'Inactivo')->get())
        ->set('activeTab', 'inactive')
        ->assertCanSeeTableRecords(ShiftTemplate::where('name', 'Inactivo')->get())
        ->set('activeTab', 'all')
        ->assertCountTableRecords(2);
});

it('las pestañas de uso listan patrones y turnos puntuales, y el historial registra los cambios', function () {
    $company = shiftCompany();
    $shift = makeShift($company);
    $pattern = RotationPattern::create(['company_id' => $company->id, 'name' => 'Ciclo 6x1', 'sequence' => [$shift->id, $shift->id, 999], 'is_active' => true]);
    $employee = shiftEmployee($company);
    $override = ShiftOverride::create(['employee_id' => $employee->id, 'override_date' => today(), 'shift_id' => $shift->id, 'reason_type' => 'otro']);

    Livewire::test(RotationPatternsRelationManager::class, ['ownerRecord' => $shift, 'pageClass' => ViewShiftTemplate::class])
        ->assertCanSeeTableRecords([$pattern]);

    Livewire::test(OverridesRelationManager::class, ['ownerRecord' => $shift, 'pageClass' => ViewShiftTemplate::class])
        ->assertCanSeeTableRecords([$override]);

    config(['audit.console' => true]);
    $shift->update(['name' => 'Mañana B', 'break_minutes' => 30]);

    Livewire::test(AuditsRelationManager::class, ['ownerRecord' => $shift->fresh(), 'pageClass' => ViewShiftTemplate::class])
        ->assertCountTableRecords(1);
});

it('duplicar copia el turno sin su uso', function () {
    $company = shiftCompany();
    $shift = makeShift($company);
    RotationPattern::create(['company_id' => $company->id, 'name' => 'P', 'sequence' => [$shift->id], 'is_active' => true]);

    $copy = $shift->duplicate('Mañana (copia)');

    expect($copy->id)->not->toBe($shift->id)
        ->and($copy->start_time)->toBe($shift->start_time)
        ->and($copy->deletionBlockers())->toBe([]);
});

it('con un rol de solo lectura no se ven las acciones que modifican', function () {
    $company = shiftCompany();
    $shift = makeShift($company);
    Permission::findOrCreate('view_any_shift_template', 'web');
    Permission::findOrCreate('view_shift_template', 'web');
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'Lector turnos', 'guard_name' => 'web']));
    $user->givePermissionTo(['view_any_shift_template', 'view_shift_template']);
    $this->actingAs($user);

    expect(ShiftTemplateResource::canManage())->toBeFalse();

    Livewire::test(ViewShiftTemplate::class, ['record' => $shift->id])
        ->assertActionHidden('duplicate')
        ->assertActionHidden('toggle_status')
        ->assertActionHidden('edit');
});

it('la búsqueda global ofrece turnos con su horario', function () {
    $shift = makeShift(shiftCompany(), 'Guardia nocturna', ['start_time' => '22:00', 'end_time' => '06:00', 'break_minutes' => 0]);

    $results = (new PanelGlobalSearchProvider)->getResults('Guardia nocturna');

    expect(collect($results->getCategories())->has('Turnos'))->toBeTrue();
});
