<?php

use App\Filament\Resources\BranchResource\Pages\EditBranch;
use App\Filament\Resources\BranchResource\Pages\ViewBranch;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Terminal;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

function watchAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

    return $user;
}

/** Sucursal con terminal vinculado; `$branch` configura el horario de atención y `$terminal` el propio. */
function watchTerminal(array $branch = [], array $terminal = []): Terminal
{
    $branchModel = Branch::create(array_merge([
        'company_id' => Company::factory()->create()->id,
        'name' => 'Sucursal '.fake()->unique()->numerify('######'),
    ], $branch));

    $model = Terminal::create(array_merge(['name' => 'Terminal '.fake()->unique()->numerify('######'), 'branch_id' => $branchModel->id], $terminal));
    $model->claimSanctumToken();

    return $model->fresh();
}

function at(string $dateTime): Carbon
{
    return Carbon::parse($dateTime, config('app.timezone'));
}

// 2026-10-12 es lunes.
const BRANCH_HOURS = ['watch_days' => [1, 2, 3, 4, 5, 6], 'watch_from' => '06:00:00', 'watch_to' => '23:00:00'];

// ─── Herencia del horario ───────────────────────────────────────────────────

it('el terminal sin horario propio usa el horario de atención de su sucursal', function () {
    $terminal = watchTerminal(BRANCH_HOURS);

    expect($terminal->watchConfig()['source'])->toBe('branch')
        ->and($terminal->isWithinWatchWindow(at('2026-10-12 10:00')))->toBeTrue()
        ->and($terminal->isWithinWatchWindow(at('2026-10-12 05:59')))->toBeFalse()
        ->and($terminal->isWithinWatchWindow(at('2026-10-12 23:00')))->toBeFalse()
        ->and($terminal->isWithinWatchWindow(at('2026-10-18 10:00')))->toBeFalse() // domingo
        ->and($terminal->watchScheduleLabel())->toBe('Lun, Mar, Mié, Jue, Vie, Sáb · 06:00–23:00 (de la sucursal)');
});

it('el horario propio del terminal tiene prioridad sobre el de la sucursal', function () {
    $terminal = watchTerminal(BRANCH_HOURS, ['watch_from' => '08:00:00', 'watch_to' => '12:00:00']);

    expect($terminal->watchConfig()['source'])->toBe('terminal')
        ->and($terminal->isWithinWatchWindow(at('2026-10-12 07:00')))->toBeFalse() // la sucursal sí atiende, el terminal no
        ->and($terminal->isWithinWatchWindow(at('2026-10-12 09:00')))->toBeTrue()
        ->and($terminal->isWithinWatchWindow(at('2026-10-18 09:00')))->toBeTrue()  // el propio no limita días
        ->and($terminal->watchScheduleLabel())->toBe('Todos los días · 08:00–12:00');
});

it('sin horario en el terminal ni en la sucursal se vigila siempre', function () {
    $terminal = watchTerminal();

    expect($terminal->watchConfig()['source'])->toBe('none')
        ->and($terminal->isWithinWatchWindow(at('2026-10-18 03:00')))->toBeTrue()
        ->and($terminal->watchScheduleLabel())->toBe('Siempre (24/7)');
});

it('un terminal vencido fuera del horario de la sucursal figura como fuera de horario y dentro como desconectado', function () {
    $terminal = watchTerminal(BRANCH_HOURS);
    $terminal->forceFill(['last_heartbeat_at' => at('2026-10-11 20:00')])->save();

    Carbon::setTestNow(at('2026-10-12 03:00')); // lunes de madrugada, antes de abrir
    expect($terminal->fresh()->connectivity_status)->toBe('off_hours');

    Carbon::setTestNow(at('2026-10-12 12:00'));
    expect($terminal->fresh()->connectivity_status)->toBe('stale');
});

it('idsOutsideWatchWindow incluye a los terminales que heredan el horario de la sucursal', function () {
    $inherits = watchTerminal(BRANCH_HOURS);
    $own = watchTerminal(BRANCH_HOURS, ['watch_from' => '00:00:00', 'watch_to' => '23:59:00']);
    $always = watchTerminal();

    Carbon::setTestNow(at('2026-10-12 03:00'));

    expect(Terminal::idsOutsideWatchWindow())->toContain($inherits->id)
        ->not->toContain($always->id)
        ->not->toContain($own->id);

    Carbon::setTestNow(at('2026-10-12 12:00'));

    expect(Terminal::idsOutsideWatchWindow())->not->toContain($inherits->id);
});

// ─── Sucursal ───────────────────────────────────────────────────────────────

it('normaliza el horario de la sucursal al guardar', function () {
    $branch = Branch::create([
        'company_id' => Company::factory()->create()->id,
        'name' => 'Normalizada',
        'watch_days' => ['3', '1', 3],
        'watch_from' => '',
        'watch_to' => '',
    ]);

    expect($branch->fresh()->watch_days)->toBe([1, 3])
        ->and($branch->fresh()->watch_from)->toBeNull()
        ->and($branch->fresh()->hasWatchSchedule())->toBeTrue();

    $branch->update(['watch_days' => []]);

    expect($branch->fresh()->watch_days)->toBeNull()
        ->and($branch->fresh()->hasWatchSchedule())->toBeFalse()
        ->and($branch->fresh()->watchScheduleLabel())->toBe('Sin horario definido');
});

it('el formulario de la sucursal guarda el horario de atención', function () {
    $this->actingAs(watchAdmin());
    config(['filament-google-maps.keys.web_key' => 'test-key']);

    $branch = Branch::create(['company_id' => Company::factory()->create()->id, 'name' => 'Con Horario']);

    Livewire::test(EditBranch::class, ['record' => $branch->getKey()])
        ->fillForm(['watch_days' => [1, 2, 3], 'watch_from' => '07:00', 'watch_to' => '19:00'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($branch->fresh()->watch_days)->toBe([1, 2, 3])
        ->and(substr($branch->fresh()->watch_from, 0, 5))->toBe('07:00')
        ->and(substr($branch->fresh()->watch_to, 0, 5))->toBe('19:00');
});

it('exige ambas horas del horario de atención', function () {
    $this->actingAs(watchAdmin());
    config(['filament-google-maps.keys.web_key' => 'test-key']);

    $branch = Branch::create(['company_id' => Company::factory()->create()->id, 'name' => 'Incompleto']);

    Livewire::test(EditBranch::class, ['record' => $branch->getKey()])
        ->fillForm(['watch_from' => '07:00', 'watch_to' => null])
        ->call('save')
        ->assertHasFormErrors(['watch_to']);
});

it('el detalle muestra el horario de atención o que no está definido', function () {
    $this->actingAs(watchAdmin());

    $with = Branch::create(['company_id' => Company::factory()->create()->id, 'name' => 'Con', ...BRANCH_HOURS]);
    $without = Branch::create(['company_id' => Company::factory()->create()->id, 'name' => 'Sin']);

    Livewire::test(ViewBranch::class, ['record' => $with->getKey()])
        ->assertSee('Horario de atención')
        ->assertSee('06:00–23:00');

    Livewire::test(ViewBranch::class, ['record' => $without->getKey()])
        ->assertSee('Sin horario definido');
});

it('el cambio del horario de atención queda en el historial', function () {
    config(['audit.console' => true]);

    $branch = Branch::create(['company_id' => Company::factory()->create()->id, 'name' => 'Auditada']);
    $branch->update(BRANCH_HOURS);

    $audit = $branch->audits()->where('event', 'updated')->first();
    $html = $branch->formatAuditFieldsForPresentation('new_values', $audit)->toHtml();

    expect($html)->toContain('Días de atención')->toContain('Lunes, Martes, Miércoles, Jueves, Viernes, Sábado')->toContain('06:00')->toContain('23:00');
});

// ─── Migración de datos ─────────────────────────────────────────────────────

function runWatchMigration(): void
{
    (require database_path('migrations/2026_10_13_110000_add_watch_schedule_to_branches_table.php'))->up();
}

it('la migración copia el horario a la sucursal solo si todos sus terminales lo comparten', function () {
    $shared = Branch::create(['company_id' => Company::factory()->create()->id, 'name' => 'Compartido']);
    $mixed = Branch::create(['company_id' => Company::factory()->create()->id, 'name' => 'Mixto']);
    $partial = Branch::create(['company_id' => Company::factory()->create()->id, 'name' => 'Parcial']);
    $none = Branch::create(['company_id' => Company::factory()->create()->id, 'name' => 'Sin terminales']);

    foreach ([[$shared, BRANCH_HOURS], [$shared, ['watch_days' => [6, 1, 2, 3, 4, 5], 'watch_from' => '06:00:00', 'watch_to' => '23:00:00']]] as $i => [$branch, $hours]) {
        Terminal::create(['name' => "S{$i}", 'branch_id' => $branch->id, ...$hours]);
    }
    Terminal::create(['name' => 'M1', 'branch_id' => $mixed->id, ...BRANCH_HOURS]);
    Terminal::create(['name' => 'M2', 'branch_id' => $mixed->id, 'watch_from' => '08:00:00', 'watch_to' => '12:00:00']);
    Terminal::create(['name' => 'P1', 'branch_id' => $partial->id, ...BRANCH_HOURS]);
    Terminal::create(['name' => 'P2', 'branch_id' => $partial->id]);

    runWatchMigration();

    expect($shared->fresh()->watch_days)->toBe([1, 2, 3, 4, 5, 6])
        ->and(substr($shared->fresh()->watch_from, 0, 5))->toBe('06:00')
        ->and($mixed->fresh()->hasWatchSchedule())->toBeFalse()
        ->and($partial->fresh()->hasWatchSchedule())->toBeFalse()
        ->and($none->fresh()->hasWatchSchedule())->toBeFalse();
});
