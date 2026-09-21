<?php

use App\Filament\Resources\TerminalResource;
use App\Filament\Resources\TerminalResource\Pages\CreateTerminal;
use App\Filament\Resources\TerminalResource\Pages\EditTerminal;
use App\Filament\Resources\TerminalResource\Pages\ListTerminals;
use App\Filament\Resources\TerminalResource\Pages\ViewTerminal;
use App\Filament\Resources\TerminalResource\RelationManagers\AttendanceEventsRelationManager;
use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Terminal;
use App\Models\User;
use Filament\Actions\ActionGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

// ─── Helpers ────────────────────────────────────────────────────────────────

function makeProvisioningTerminal(): Terminal
{
    static $n = 7500000;
    $n++;

    $company = Company::create(['name' => "Empresa Prov {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "Sucursal Prov {$n}", 'company_id' => $company->id]);

    return Terminal::create(['name' => 'Terminal Prov', 'branch_id' => $branch->id]);
}

function makeAttendanceEventForTerminal(Terminal $terminal, array $overrides = []): AttendanceEvent
{
    static $ci = 9800000;
    $n = $ci++;

    $department = Department::create(['name' => "Depto Term {$n}", 'company_id' => $terminal->branch->company_id]);
    $position = Position::create(['name' => "Cargo Term {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Terminal', 'last_name' => "Empleado {$n}", 'ci' => (string) $n,
        'birth_date' => '1990-01-01', 'branch_id' => $terminal->branch_id, 'status' => 'active',
    ]);
    Contract::create([
        'employee_id' => $employee->id, 'type' => 'indefinido', 'start_date' => now()->subYear(),
        'salary_type' => 'mensual', 'salary' => 2_550_000, 'position_id' => $position->id,
        'department_id' => $department->id, 'status' => 'active',
    ]);

    $recordedAt = now();
    $day = AttendanceDay::create(['employee_id' => $employee->id, 'date' => $recordedAt->toDateString(), 'status' => 'present']);

    return AttendanceEvent::create(array_merge([
        'attendance_day_id' => $day->id,
        'employee_id' => $employee->id,
        'employee_name' => $employee->full_name,
        'employee_ci' => $employee->ci,
        'event_type' => 'check_in',
        'recorded_at' => $recordedAt,
        'source' => 'terminal',
        'terminal_id' => $terminal->id,
        'branch_id' => $terminal->branch_id,
        'branch_name' => $terminal->branch->name,
    ], $overrides));
}

// ─── Tests ──────────────────────────────────────────────────────────────────

/**
 * Regresión: el QR se generaba como SVG y se inyectaba con
 * TextEntry->html(), lo que activa el sanitizador HTML de Filament (Symfony
 * HtmlSanitizer) — que elimina el <svg> completo porque no está en su lista
 * de elementos "seguros", dejando el QR invisible. Un data URI en
 * ImageEntry evita el sanitizador por completo.
 */
it('el QR de acceso es un data URI SVG válido, no HTML crudo', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $terminal = makeProvisioningTerminal();

    $component = collect(
        Livewire::test(ViewTerminal::class, ['record' => $terminal->getKey()])
            ->instance()
            ->getInfolist('infolist')
            ->getFlatComponents()
    )->first(fn ($c) => method_exists($c, 'getName') && $c->getName() === 'qr_code');

    expect($component)->not->toBeNull();

    $state = $component->getState();
    expect($state)->toStartWith('data:image/svg+xml;base64,');

    $decoded = base64_decode(substr($state, strpos($state, ',') + 1));
    expect($decoded)->toContain('<svg');
});

it('sin ?provision=1 no abre el modal de generar enlace automáticamente', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $terminal = makeProvisioningTerminal();

    $test = Livewire::test(ViewTerminal::class, ['record' => $terminal->getKey()]);

    expect($test->get('mountedActions'))->toBe([]);
});

/**
 * Regresión de implementación: mountAction() no puede llamarse dentro de
 * mount() porque cachedActions recién se puebla en el hook de Livewire
 * bootedInteractsWithHeaderActions(), que corre después — hacerlo antes
 * desmonta la acción de inmediato sin abrir el modal.
 */
it('con ?provision=1 abre el modal de generar enlace automáticamente tras crear', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $terminal = makeProvisioningTerminal();

    $test = Livewire::withQueryParams(['provision' => '1'])
        ->test(ViewTerminal::class, ['record' => $terminal->getKey()]);

    expect($test->get('mountedActions'))->toBe(['generate_setup_link']);
});

it('el redirect tras crear un terminal apunta a su vista con ?provision=1', function () {
    $terminal = makeProvisioningTerminal();

    $page = new CreateTerminal;
    $page->record = $terminal;

    $reflection = new ReflectionMethod(CreateTerminal::class, 'getRedirectUrl');
    $reflection->setAccessible(true);
    $redirectUrl = $reflection->invoke($page);

    expect($redirectUrl)->toContain("/terminales/{$terminal->id}")
        ->and($redirectUrl)->toEndWith('?provision=1');
});

/**
 * revoke_token vive dentro del ActionGroup "Más acciones" — hay que
 * aplanar los grupos con getFlatActions() para encontrarla, en vez de
 * buscar directo en getCachedHeaderActions() (que solo devuelve el nivel
 * superior: la acción individual quedaría anidada dentro del grupo).
 */
it('la acción "Revocar token" solo es visible en el detalle si el terminal tiene un token activo', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $terminal = makeProvisioningTerminal();

    $withoutToken = Livewire::test(ViewTerminal::class, ['record' => $terminal->getKey()]);
    $action = collect($withoutToken->instance()->getCachedHeaderActions())
        ->flatMap(fn ($a) => $a instanceof ActionGroup ? $a->getFlatActions() : [$a])
        ->first(fn ($a) => $a->getName() === 'revoke_token');

    expect($action->isVisible())->toBeFalse();

    $terminal->claimSanctumToken();

    $withToken = Livewire::test(ViewTerminal::class, ['record' => $terminal->fresh()->getKey()]);
    $action = collect($withToken->instance()->getCachedHeaderActions())
        ->flatMap(fn ($a) => $a instanceof ActionGroup ? $a->getFlatActions() : [$a])
        ->first(fn ($a) => $a->getName() === 'revoke_token');

    expect($action->isVisible())->toBeTrue();
});

it('el detalle del terminal expone la acción "Generar enlace de configuración"', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $terminal = makeProvisioningTerminal();

    $test = Livewire::test(ViewTerminal::class, ['record' => $terminal->getKey()]);
    $action = collect($test->instance()->getCachedHeaderActions())
        ->first(fn ($a) => $a->getName() === 'generate_setup_link');

    expect($action)->not->toBeNull();
});

// ─── DeviceHintsParser: marca/modelo sugeridos al provisionar ──────────────

it('claimSanctumToken() guarda el User-Agent y sugiere marca/modelo cuando el terminal no tiene datos cargados', function () {
    $terminal = makeProvisioningTerminal();

    $terminal->claimSanctumToken('Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15');
    $terminal->refresh();

    expect($terminal->user_agent)->toContain('iPhone')
        ->and($terminal->device_brand)->toBe('Apple')
        ->and($terminal->device_model)->toBe('iPhone');
});

it('claimSanctumToken() NO pisa marca/modelo cargados manualmente al reprovisionar el mismo terminal', function () {
    $terminal = makeProvisioningTerminal();
    $terminal->update(['device_brand' => 'Apple (corregido a mano)', 'device_model' => 'iPad Pro 12.9 2022']);

    $terminal->claimSanctumToken('Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X)');
    $terminal->refresh();

    expect($terminal->device_brand)->toBe('Apple (corregido a mano)')
        ->and($terminal->device_model)->toBe('iPad Pro 12.9 2022');
});

/**
 * Regresión: producción tenía MAIL_MAILER=resend sin RESEND_KEY configurada.
 * claimSanctumToken() ya había consumido el setup_token (single-use) antes de
 * notificar a los admins, así que el TypeError de Resend::client() tumbaba el
 * request con 500 DESPUÉS de invalidar el enlace — el terminal quedaba con
 * "Server Error" y, al recargar, con "enlace inválido" sin haber recibido
 * nunca su token Sanctum. Un fallo de notificación nunca debe poder romper
 * la provisión ya persistida.
 */
it('claimSanctumToken() sigue provisionando el terminal aunque falle el envío de la notificación por email', function () {
    config(['mail.default' => 'resend', 'services.resend.key' => null]);

    $terminal = makeProvisioningTerminal();
    User::factory()->create();

    $token = $terminal->claimSanctumToken();

    expect($token)->not->toBeEmpty();

    $terminal->refresh();
    expect($terminal->setup_token)->toBeNull()
        ->and($terminal->tokens()->where('name', 'like', 'kiosk:%')->exists())->toBeTrue();
});

it('POST .../claim responde ok=true aunque falle el envío de la notificación por email', function () {
    config(['mail.default' => 'resend', 'services.resend.key' => null]);

    $terminal = makeProvisioningTerminal();
    User::factory()->create();
    $setupToken = $terminal->generateSetupToken();

    $this->postJson("/terminal/{$terminal->code}/setup/{$setupToken}/claim")
        ->assertOk()
        ->assertJson(['ok' => true]);
});

it('POST .../claim pasa el device_model_hint del cliente hasta el terminal provisionado', function () {
    $terminal = makeProvisioningTerminal();
    $setupToken = $terminal->generateSetupToken();

    $this->postJson("/terminal/{$terminal->code}/setup/{$setupToken}/claim", [
        'device_model_hint' => 'Pixel 8 Pro',
    ])->assertOk()->assertJson(['ok' => true]);

    $terminal->refresh();
    expect($terminal->device_brand)->toBe('Google')
        ->and($terminal->device_model)->toBe('Pixel 8 Pro');
});

// ─── RelationManager de marcaciones ────────────────────────────────────────

it('el RelationManager de marcaciones renderiza en la ficha del terminal', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $terminal = makeProvisioningTerminal();
    makeAttendanceEventForTerminal($terminal);

    Livewire::test(AttendanceEventsRelationManager::class, [
        'ownerRecord' => $terminal,
        'pageClass' => ViewTerminal::class,
    ])->assertOk();
});

it('el RelationManager de marcaciones solo muestra eventos de ese terminal, no de otros', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $terminal = makeProvisioningTerminal();
    $otherTerminal = makeProvisioningTerminal();

    $ownEvent = makeAttendanceEventForTerminal($terminal);
    makeAttendanceEventForTerminal($otherTerminal);

    $component = Livewire::test(AttendanceEventsRelationManager::class, [
        'ownerRecord' => $terminal,
        'pageClass' => ViewTerminal::class,
    ]);

    $component->assertCanSeeTableRecords([$ownEvent])
        ->assertCountTableRecords(1);
});

it('el RelationManager de marcaciones es de solo lectura, sin acciones de fila', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $terminal = makeProvisioningTerminal();
    makeAttendanceEventForTerminal($terminal);

    $manager = new AttendanceEventsRelationManager;

    expect($manager->isReadOnly())->toBeTrue();
});

// ─── Endurecimiento del flujo de vinculación (Ver/Generar enlace, carrera) ──

/**
 * Regresión: claim() ahora corre el check-y-consumo del setup_token bajo un
 * lockForUpdate() para cerrar la ventana de carrera entre dos reclamos casi
 * simultáneos — este test confirma que el comportamiento de un solo uso
 * sigue intacto para el caso simple (secuencial) tras ese cambio.
 */
it('reclamar un enlace de configuración ya usado falla con 422 — un solo uso', function () {
    $terminal = makeProvisioningTerminal();
    $setupToken = $terminal->generateSetupToken();

    $this->postJson("/terminal/{$terminal->code}/setup/{$setupToken}/claim")
        ->assertOk()
        ->assertJson(['ok' => true]);

    $this->postJson("/terminal/{$terminal->code}/setup/{$setupToken}/claim")
        ->assertStatus(422)
        ->assertJson(['ok' => false]);
});

it('"Generar enlace" está visible sin enlace vigente; "Ver enlace" y "Generar nuevo" aparecen tras generar uno', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $terminal = makeProvisioningTerminal();

    $withoutLink = collect(
        Livewire::test(ViewTerminal::class, ['record' => $terminal->getKey()])->instance()->getCachedHeaderActions()
    );
    expect($withoutLink->first(fn ($a) => $a->getName() === 'generate_setup_link')?->isVisible())->toBeTrue();
    expect($withoutLink->first(fn ($a) => $a->getName() === 'view_setup_link')?->isVisible())->toBeFalse();
    expect($withoutLink->first(fn ($a) => $a->getName() === 'regenerate_setup_link')?->isVisible())->toBeFalse();

    $terminal->generateSetupToken();

    $withLink = collect(
        Livewire::test(ViewTerminal::class, ['record' => $terminal->fresh()->getKey()])->instance()->getCachedHeaderActions()
    );
    expect($withLink->first(fn ($a) => $a->getName() === 'generate_setup_link')?->isVisible())->toBeFalse();
    expect($withLink->first(fn ($a) => $a->getName() === 'view_setup_link')?->isVisible())->toBeTrue();
    expect($withLink->first(fn ($a) => $a->getName() === 'regenerate_setup_link')?->isVisible())->toBeTrue();
});

it('TerminalResource::renderCurrentSetupLinkModal() no genera un token nuevo, a diferencia de renderSetupLinkModal()', function () {
    $terminal = makeProvisioningTerminal();
    $setupToken = $terminal->generateSetupToken();

    TerminalResource::renderCurrentSetupLinkModal($terminal);
    expect($terminal->fresh()->setup_token)->toBe($setupToken);

    TerminalResource::renderSetupLinkModal($terminal);
    expect($terminal->fresh()->setup_token)->not->toBe($setupToken);
});

it('"Generar nuevo enlace" invalida el enlace vigente y notifica en vez de mostrar el QR en el mismo paso', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $terminal = makeProvisioningTerminal();
    $oldToken = $terminal->generateSetupToken();

    Livewire::test(ViewTerminal::class, ['record' => $terminal->getKey()])
        ->callAction('regenerate_setup_link')
        ->assertHasNoActionErrors();

    expect($terminal->fresh()->setup_token)->not->toBeNull()
        ->and($terminal->fresh()->setup_token)->not->toBe($oldToken);
});

// ─── Form: sucursal filtrada por empresa activa ────────────────────────────

it('el select de sucursal excluye sucursales de empresas inactivas al crear un terminal', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    $activeCompany = Company::create(['name' => 'Empresa Activa Form', 'ruc' => '7900001-1', 'employer_number' => 7900001, 'is_active' => true]);
    $inactiveCompany = Company::create(['name' => 'Empresa Inactiva Form', 'ruc' => '7900002-1', 'employer_number' => 7900002, 'is_active' => false]);
    $activeBranch = Branch::create(['name' => 'Sucursal Activa Form', 'company_id' => $activeCompany->id]);
    $inactiveBranch = Branch::create(['name' => 'Sucursal Inactiva Form', 'company_id' => $inactiveCompany->id]);

    $field = Livewire::test(CreateTerminal::class)
        ->instance()
        ->form
        ->getFlatFields(withHidden: true)['branch_id'];

    expect($field->getOptions())->toHaveKey($activeBranch->id)
        ->not->toHaveKey($inactiveBranch->id);
});

/**
 * Regresión: Select::relationship()'s modifyQueryUsing() también filtra la
 * query que resuelve la etiqueta del valor YA seleccionado
 * (getSelectedRecordUsing()) — sin el OR por $record->branch_id en
 * TerminalResource::form(), editar un terminal cuya empresa se desactivó
 * DESPUÉS de asignarle la sucursal dejaría el campo en blanco, aunque
 * branch_id siga apuntando correctamente en la base de datos.
 */
it('editar un terminal mantiene visible su sucursal aunque la empresa se haya desactivado después', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    $company = Company::create(['name' => 'Empresa Form X', 'ruc' => '7900003-1', 'employer_number' => 7900003, 'is_active' => true]);
    $branch = Branch::create(['name' => 'Sucursal Form X', 'company_id' => $company->id]);
    $terminal = Terminal::create(['name' => 'Terminal Form X', 'branch_id' => $branch->id]);

    $company->update(['is_active' => false]);

    $field = Livewire::test(EditTerminal::class, ['record' => $terminal->getKey()])
        ->instance()
        ->form
        ->getFlatFields(withHidden: true)['branch_id'];

    expect($field->getOptions())->toHaveKey($branch->id);
});

it('el listado de terminales oculta la columna y el filtro de Empresa si solo hay una empresa activa', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    makeProvisioningTerminal();

    Livewire::test(ListTerminals::class)
        ->assertTableColumnHidden('branch.company.name')
        ->assertTableFilterHidden('company_id');
});

it('el listado de terminales muestra y filtra por Empresa cuando hay 2 o más empresas activas', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    $companyA = Company::create(['name' => 'Empresa Terminal A', 'ruc' => '7900010-1', 'employer_number' => 7900010]);
    $companyB = Company::create(['name' => 'Empresa Terminal B', 'ruc' => '7900011-1', 'employer_number' => 7900011]);
    $branchA = Branch::create(['name' => 'Sucursal Terminal A', 'company_id' => $companyA->id]);
    $branchB = Branch::create(['name' => 'Sucursal Terminal B', 'company_id' => $companyB->id]);
    $terminalA = Terminal::create(['name' => 'Terminal A', 'branch_id' => $branchA->id]);
    $terminalB = Terminal::create(['name' => 'Terminal B', 'branch_id' => $branchB->id]);

    Livewire::test(ListTerminals::class)
        ->assertTableColumnVisible('branch.company.name')
        ->assertTableFilterVisible('company_id')
        ->assertCanSeeTableRecords([$terminalA, $terminalB])
        ->filterTable('company_id', $companyA->id)
        ->assertCanSeeTableRecords([$terminalA])
        ->assertCanNotSeeTableRecords([$terminalB]);
});

it('el detalle del terminal oculta el nombre de la Empresa si solo hay una empresa activa', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $terminal = makeProvisioningTerminal();

    Livewire::test(ViewTerminal::class, ['record' => $terminal->getKey()])
        ->assertDontSee($terminal->branch->company->name);
});

it('el detalle del terminal muestra el nombre de la Empresa cuando hay 2 o más empresas activas', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $terminal = makeProvisioningTerminal();
    Company::create(['name' => 'Otra Empresa Detalle', 'ruc' => '7900012-1', 'employer_number' => 7900012]);

    Livewire::test(ViewTerminal::class, ['record' => $terminal->getKey()])
        ->assertSee($terminal->branch->company->name);
});
