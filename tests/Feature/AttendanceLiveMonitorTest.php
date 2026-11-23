<?php

use App\Filament\Pages\AttendanceLiveMonitor;
use App\Models\Absence;
use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Terminal;
use App\Models\User;
use App\Services\LiveAttendanceMonitor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-16 10:00:00'));
    Cache::flush();
});

afterEach(fn () => Carbon::setTestNow());

function liveBranch(string $name = 'Central'): Branch
{
    $company = Company::first() ?? Company::create(['name' => 'Empresa Vivo', 'ruc' => '4700000-1', 'employer_number' => 4700000]);

    return Branch::firstOrCreate(['name' => $name, 'company_id' => $company->id]);
}

function liveEmployee(string $first, ?Branch $branch = null, ?array $shift = ['07:00:00', '15:00:00']): Employee
{
    static $ci = 4700100;
    $employee = Employee::create([
        'first_name' => $first, 'last_name' => 'Vivo', 'ci' => (string) $ci++, 'email' => strtolower($first).'@test.com',
        'branch_id' => ($branch ?? liveBranch())->id, 'status' => 'active',
    ]);

    Cache::put('live-monitor:shift:'.$employee->id.':2026-11-16', [
        'check_in' => $shift[0] ?? null, 'check_out' => $shift[1] ?? null, 'break_minutes' => 30,
    ], 300);

    return $employee;
}

/** @param  array<int, array{0: string, 1: string}>  $events */
function liveDay(Employee $employee, array $events, int $late = 0, string $date = '2026-11-16', string $status = 'present'): AttendanceDay
{
    $day = AttendanceDay::create([
        'employee_id' => $employee->id, 'date' => $date, 'status' => $status,
        'expected_check_in' => '07:00:00', 'expected_check_out' => '15:00:00', 'expected_hours' => 8.0,
        'is_calculated' => true, 'is_weekend' => false, 'is_holiday' => false, 'on_vacation' => false, 'justified_absence' => false,
    ]);

    foreach ($events as [$type, $time]) {
        AttendanceEvent::create([
            'attendance_day_id' => $day->id, 'employee_id' => $employee->id, 'employee_name' => $employee->full_name,
            'event_type' => $type, 'recorded_at' => Carbon::parse($date.' '.$time),
        ]);
    }

    $day->forceFill(['late_minutes' => $late])->saveQuietly();

    return $day;
}

function liveStates(array $filters = []): array
{
    return collect(LiveAttendanceMonitor::snapshot($filters)['rows'])->pluck('state', 'name')->all();
}

it('clasifica a cada empleado según sus marcaciones y su horario', function () {
    liveDay(liveEmployee('Trabaja'), [['check_in', '07:02:00']], late: 2);
    liveDay(liveEmployee('Descansa'), [['check_in', '07:00:00'], ['break_start', '09:00:00']]);
    liveDay(liveEmployee('Termino'), [['check_in', '07:00:00'], ['check_out', '09:30:00']]);
    liveEmployee('Falta');
    liveEmployee('Tarde', shift: ['13:00:00', '21:00:00']);
    liveEmployee('Libre', shift: [null, null]);

    expect(liveStates())->toBe([
        'Falta Vivo' => 'missing',
        'Descansa Vivo' => 'on_break',
        'Trabaja Vivo' => 'working',
        'Tarde Vivo' => 'upcoming',
        'Termino Vivo' => 'finished',
        'Libre Vivo' => 'no_shift',
    ]);

    $counts = LiveAttendanceMonitor::snapshot()['counts'];
    expect($counts)->toMatchArray(['present' => 2, 'late' => 1, 'missing' => 1, 'on_break' => 1, 'upcoming' => 1, 'finished' => 1, 'no_shift' => 1]);
});

it('marca ausentes y de permiso a partir de las ausencias detectadas', function () {
    $absent = liveEmployee('Ausente');
    $absentDay = liveDay($absent, [], status: 'absent');
    Absence::create(['employee_id' => $absent->id, 'attendance_day_id' => $absentDay->id, 'status' => 'pending']);

    $leave = liveEmployee('Permiso');
    $leaveDay = liveDay($leave, []);
    Absence::create(['employee_id' => $leave->id, 'attendance_day_id' => $leaveDay->id, 'status' => 'justified']);

    expect(liveStates())->toMatchArray(['Ausente Vivo' => 'absent', 'Permiso Vivo' => 'on_leave']);
});

it('cuenta como presente a quien sigue en un turno nocturno abierto desde ayer', function () {
    $night = liveEmployee('Nocturno', shift: [null, null]);
    liveDay($night, [['check_in', '22:00:00']], date: '2026-11-15');

    expect(liveStates())->toMatchArray(['Nocturno Vivo' => 'working']);
});

it('no cuenta un turno de ayer abierto fuera del límite nocturno', function () {
    $old = liveEmployee('Olvido', shift: [null, null]);
    liveDay($old, [['check_in', '07:00:00']], date: '2026-11-15');

    expect(liveStates())->toMatchArray(['Olvido Vivo' => 'no_shift']);
});

it('aplica los filtros de sucursal, turno, búsqueda y estado', function () {
    liveEmployee('Uno', liveBranch('Central'));
    liveEmployee('Dos', liveBranch('Norte'));
    liveEmployee('Libre', liveBranch('Central'), shift: [null, null]);

    expect(array_keys(liveStates(['branch_id' => liveBranch('Norte')->id])))->toBe(['Dos Vivo'])
        ->and(array_keys(liveStates(['only_scheduled' => true])))->toEqualCanonicalizing(['Uno Vivo', 'Dos Vivo'])
        ->and(array_keys(liveStates(['search' => 'libre'])))->toBe(['Libre Vivo'])
        ->and(array_keys(liveStates(['state' => 'missing'])))->toEqualCanonicalizing(['Uno Vivo', 'Dos Vivo']);
});

it('genera alertas por falta de marcación y descansos largos y un feed ordenado', function () {
    liveEmployee('Falta');
    liveDay(liveEmployee('Descanso'), [['check_in', '07:00:00'], ['break_start', '08:30:00']]);

    $snapshot = LiveAttendanceMonitor::snapshot();

    expect(collect($snapshot['alerts'])->pluck('type')->all())->toEqualCanonicalizing(['missing', 'long_break'])
        ->and($snapshot['feed'][0]['event_type'])->toBe('break_start')
        ->and($snapshot['feed'])->toHaveCount(2);
});

it('muestra el monitor a quien puede ver asistencias y lo niega a quien no', function () {
    liveEmployee('Falta');

    $viewer = User::factory()->create();
    Permission::firstOrCreate(['name' => 'view_any_attendance_day', 'guard_name' => 'web']);
    $viewer->givePermissionTo('view_any_attendance_day');
    $viewer->assignRole(Role::firstOrCreate(['name' => 'Consulta', 'guard_name' => 'web']));

    $this->actingAs($viewer);
    Livewire::test(AttendanceLiveMonitor::class)->assertSuccessful()->assertSee('Falta Vivo')->assertSee('Sin marcar');

    $this->actingAs(tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::firstOrCreate(['name' => 'Otro', 'guard_name' => 'web']))));
    expect(AttendanceLiveMonitor::canAccess())->toBeFalse();
});

it('alterna el filtro de estado al tocar una tarjeta', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    Livewire::test(AttendanceLiveMonitor::class)
        ->call('toggleState', 'missing')->assertSet('state', 'missing')
        ->call('toggleState', 'missing')->assertSet('state', null);
});

it('avisa de las marcaciones de hoy fuera de radio que siguen sin revisar', function () {
    $employee = liveEmployee('Lejos');
    $day = liveDay($employee, [['check_in', '07:00:00']]);
    AttendanceEvent::where('attendance_day_id', $day->id)->update(['location_flag' => 'outside_radius', 'location_distance_m' => 850, 'location_review' => 'pending']);

    $alerts = collect(LiveAttendanceMonitor::snapshot()['alerts'])->where('type', 'outside_radius');

    expect($alerts)->toHaveCount(1)
        ->and($alerts->first()['text'])->toContain('850 m');

    AttendanceEvent::where('attendance_day_id', $day->id)->update(['location_review' => 'approved']);

    expect(collect(LiveAttendanceMonitor::snapshot()['alerts'])->where('type', 'outside_radius'))->toHaveCount(0);
});

it('pasa a «Sin salida» a quien sigue abierto una hora después de que terminó su turno', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-16 16:10:00'));

    liveDay(liveEmployee('Olvido'), [['check_in', '07:00:00']]);
    liveDay(liveEmployee('Descanso'), [['check_in', '07:00:00'], ['break_start', '14:55:00']]);
    liveDay(liveEmployee('Salio'), [['check_in', '07:00:00'], ['check_out', '15:02:00']]);
    liveDay(liveEmployee('Reciente'), [['check_in', '07:00:00']])->forceFill(['expected_check_out' => '16:00:00'])->saveQuietly();

    expect(liveStates())->toMatchArray([
        'Olvido Vivo' => 'no_exit',
        'Descanso Vivo' => 'no_exit',
        'Salio Vivo' => 'finished',
        'Reciente Vivo' => 'working',
    ]);

    $snapshot = LiveAttendanceMonitor::snapshot();
    $alerts = collect($snapshot['alerts'])->where('type', 'no_exit');

    expect($snapshot['counts']['no_exit'])->toBe(2)
        ->and($snapshot['counts']['present'])->toBe(1)
        ->and($alerts)->toHaveCount(2)
        ->and($alerts->first()['text'])->toContain('no marcó la salida');
});

it('un turno que cruza la medianoche no pasa a «Sin salida» antes de que termine', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-16 23:30:00'));

    $night = liveEmployee('Noche', shift: ['22:00:00', '06:00:00']);
    liveDay($night, [['check_in', '22:00:00']])->forceFill(['expected_check_in' => '22:00:00', 'expected_check_out' => '06:00:00'])->saveQuietly();

    expect(liveStates())->toMatchArray(['Noche Vivo' => 'working']);
});

/** Terminal activo y vinculado de la sucursal, con su último latido. */
function liveTerminal(Branch $branch, string $name, ?Carbon $heartbeat): Terminal
{
    $terminal = Terminal::create(['name' => $name, 'branch_id' => $branch->id]);
    $terminal->claimSanctumToken();
    $terminal->forceFill(['last_heartbeat_at' => $heartbeat])->save();

    return $terminal->fresh();
}

function liveSuperAdmin(): void
{
    test()->actingAs(tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
}

it('resume cada sucursal con presentes, faltantes y ausentes y pone primero la que pide acción', function () {
    $alfa = liveBranch('Alfa');
    $beta = liveBranch('Beta');

    liveDay(liveEmployee('Entro', $alfa), [['check_in', '07:00:00']]);
    liveEmployee('Falta', $alfa);
    liveDay(liveEmployee('Llego', $beta), [['check_in', '07:05:00']]);

    $branches = collect(LiveAttendanceMonitor::snapshot()['branches'])->keyBy('branch');

    expect($branches->keys()->all())->toBe(['Alfa', 'Beta'])
        ->and($branches['Alfa'])->toMatchArray(['total' => 2, 'present' => 1, 'missing' => 1, 'absent' => 0, 'last_event_at' => '07:00'])
        ->and($branches['Beta'])->toMatchArray(['total' => 1, 'present' => 1, 'missing' => 0, 'last_event_at' => '07:05']);
});

it('incluye los terminales de cada sucursal y cuenta los desconectados, respetando el filtro de sucursal', function () {
    $alfa = liveBranch('Alfa');
    $beta = liveBranch('Beta');
    liveEmployee('Uno', $alfa);
    liveEmployee('Dos', $beta);
    liveTerminal($alfa, 'Terminal Alfa', now());
    liveTerminal($beta, 'Terminal Beta', now()->subHours(5));

    $snapshot = LiveAttendanceMonitor::snapshot();
    $byBranch = collect($snapshot['branches'])->keyBy('branch');

    expect($snapshot['terminals'])->toHaveCount(2)
        ->and($snapshot['offline_terminals'])->toBe(1)
        ->and($byBranch['Alfa']['terminals'][0]['status'])->toBe('online')
        ->and($byBranch['Beta']['terminals'][0]['status'])->toBe('stale')
        ->and($byBranch['Beta']['terminals'][0]['label'])->toBe('Desconectado');

    $filtered = LiveAttendanceMonitor::snapshot(['branch_id' => $alfa->id]);

    expect($filtered['terminals'])->toHaveCount(1)
        ->and($filtered['offline_terminals'])->toBe(0);
});

it('avisa en la página de los terminales caídos y muestra el resumen por sucursal', function () {
    liveSuperAdmin();
    $alfa = liveBranch('Alfa');
    $beta = liveBranch('Beta');
    liveEmployee('Uno', $alfa);
    liveEmployee('Dos', $beta);
    liveTerminal($alfa, 'Terminal Alfa', now());

    Livewire::test(AttendanceLiveMonitor::class)->assertSuccessful()
        ->assertDontSee('sin conexión o sin vincular')
        ->assertSee('Por sucursal');

    liveTerminal($beta, 'Terminal Beta', now()->subHours(5));

    Livewire::test(AttendanceLiveMonitor::class)
        ->assertSee('terminal sin conexión o sin vincular')
        ->assertSee('Terminal Beta (Beta)')
        ->assertSee('Las faltas de esas sucursales pueden no ser reales');
});

it('muestra la lista plana por defecto y la agrupa por sucursal con el selector', function () {
    liveSuperAdmin();
    liveEmployee('Uno', liveBranch('Alfa'));

    Livewire::test(AttendanceLiveMonitor::class)
        ->assertSet('grouped', false)
        ->assertDontSee('· 1 empleado')
        ->set('grouped', true)
        ->assertSee('· 1 empleado');
});

it('en pantalla grande oculta los filtros y deja la hora y el botón para salir', function () {
    liveSuperAdmin();
    liveEmployee('Uno');

    Livewire::test(AttendanceLiveMonitor::class)
        ->assertSee('Todas las sucursales')
        ->set('kiosk', true)
        ->assertDontSee('Todas las sucursales')
        ->assertSee('Salir de pantalla grande');
});
