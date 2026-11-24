<?php

use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\AttendanceMarkFailure;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Terminal;
use App\Models\TerminalPairingRequest;
use App\Models\User;
use App\Services\LiveAttendanceMonitor;
use Carbon\Carbon;
use Database\Seeders\BranchSeeder;
use Database\Seeders\BusinessActionPermissionSeeder;
use Database\Seeders\CompanySeeder;
use Database\Seeders\DemoLiveAttendanceSeeder;
use Database\Seeders\DemoStaffSeeder;
use Database\Seeders\DemoTerminalSeeder;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\EmployeeSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ScheduleAssignmentSeeder;
use Database\Seeders\ScheduleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

// `DemoSeeder` completo trunca tablas (en MySQL eso confirma la transacción y deja la base de pruebas sembrada):
// aquí se prueban sus piezas nuevas sobre una base mínima, dentro de la transacción de cada prueba.

beforeEach(function () {
    // Miércoles 25/11/2026, 11:30 (hora de Asunción): hay turnos en curso, descansos y salidas por delante.
    Carbon::setTestNow(Carbon::parse('2026-11-25 11:30:00', config('app.timezone')));

    $this->seed([CompanySeeder::class, ScheduleSeeder::class, DepartmentSeeder::class, BranchSeeder::class, EmployeeSeeder::class, DemoStaffSeeder::class, ScheduleAssignmentSeeder::class]);
});

afterEach(fn () => Carbon::setTestNow());

it('completa la plantilla hasta 40 empleados activos con rostro y superior por sucursal', function () {
    $active = Employee::query()->where('status', 'active')->get();

    expect($active)->toHaveCount(DemoStaffSeeder::TARGET_ACTIVE)
        ->and($active->whereNotNull('face_descriptor')->count())->toBeGreaterThanOrEqual(35)
        ->and($active->whereNull('face_descriptor')->count())->toBeGreaterThan(0) // queda alguno sin enrolar a propósito
        ->and($active->whereNotNull('reports_to_id')->count())->toBeGreaterThan(20)
        ->and(Employee::query()->where('status', 'active')->pluck('ci')->duplicates())->toBeEmpty();

    $branchesWithStaff = $active->pluck('branch_id')->unique();
    expect($branchesWithStaff->count())->toBeGreaterThanOrEqual(3);
});

it('deja contratos a plazo fijo por vencer y empleados recién ingresados para mostrar los avisos de contratos', function () {
    $expiring = Contract::query()->where('status', 'active')->where('type', 'plazo_fijo')->whereBetween('end_date', [today(), today()->addDays(30)])->count();
    $recent = Contract::query()->where('status', 'active')->where('start_date', '>=', today()->subDays(30))->count();

    expect($expiring)->toBeGreaterThanOrEqual(2)->and($recent)->toBeGreaterThanOrEqual(2);
});

it('crea un usuario por rol que puede entrar al panel, con la contraseña de la demo', function () {
    $this->seed([PermissionSeeder::class, BusinessActionPermissionSeeder::class, RoleSeeder::class, DemoUsersSeeder::class]);

    expect(User::query()->whereIn('email', array_keys(DemoUsersSeeder::USERS))->count())->toBe(count(DemoUsersSeeder::USERS));

    foreach (DemoUsersSeeder::USERS as $email => [$name, $role]) {
        $user = User::query()->where('email', $email)->firstOrFail();

        expect($user->hasRole($role))->toBeTrue()
            ->and($user->canAccessPanel(Filament\Facades\Filament::getPanel('admin')))->toBeTrue()
            ->and(Hash::check(DemoUsersSeeder::DEFAULT_PASSWORD, $user->password))->toBeTrue();
    }

    $boss = User::query()->where('email', 'jefe@demo.nextup.com.py')->firstOrFail();
    expect($boss->employee_id)->not->toBeNull()
        ->and(Employee::query()->where('reports_to_id', $boss->employee_id)->count())->toBeGreaterThan(0);
});

it('volver a sembrar los usuarios no los duplica ni cambia sus roles', function () {
    $this->seed([PermissionSeeder::class, BusinessActionPermissionSeeder::class, RoleSeeder::class]);
    $this->seed(DemoUsersSeeder::class);
    $this->seed(DemoUsersSeeder::class);

    expect(User::query()->where('email', 'like', '%@demo.nextup.com.py')->count())->toBe(count(DemoUsersSeeder::USERS));
});

it('siembra terminales en estados distintos con responsable, bitácora y confiabilidad', function () {
    $this->seed(DemoTerminalSeeder::class);

    $terminals = Terminal::query()->withSyncTokenFlag()->with('branch')->get();
    $statuses = $terminals->map->connectivity_status->countBy()->all();

    expect($terminals)->toHaveCount(5)
        ->and($statuses)->toBe(['online' => 3, 'stale' => 1, 'unlinked' => 1])
        ->and($terminals->every(fn (Terminal $t) => filled($t->responsible_name) && filled($t->responsible_phone)))->toBeTrue()
        ->and($terminals->every(fn (Terminal $t) => $t->branch->watch_day_hours !== null))->toBeTrue()
        ->and(DB::table('terminal_daily_stats')->count())->toBeGreaterThan(40)
        ->and(TerminalPairingRequest::query()->pending()->count())->toBe(1);

    $sleeping = $terminals->firstWhere('name', 'Terminal Este 1');
    expect($sleeping->events()->where('type', 'health_offline')->exists())->toBeTrue()
        ->and($sleeping->reportedBatteryLevel())->toBe(14);
});

it('demo:refresh pone al día los latidos de los terminales respecto de la hora actual', function () {
    $this->seed(DemoTerminalSeeder::class);

    Carbon::setTestNow(now()->addHours(5));
    expect(Terminal::query()->withSyncTokenFlag()->where('name', 'Terminal Entrada Central')->first()->connectivity_status)->toBe('stale');

    app(DemoTerminalSeeder::class)->refresh();

    expect(Terminal::query()->withSyncTokenFlag()->where('name', 'Terminal Entrada Central')->first()->connectivity_status)->toBe('online');
});

it('arma el día de hoy respecto de la hora: gente trabajando, en descanso, sin marcar y fallos de marcación', function () {
    $this->seed([DemoTerminalSeeder::class, DemoLiveAttendanceSeeder::class]);

    $days = AttendanceDay::query()->where('date', today()->toDateString())->get();

    expect($days->count())->toBeGreaterThan(10)
        ->and(AttendanceEvent::query()->whereIn('attendance_day_id', $days->pluck('id'))->whereNotNull('terminal_id')->exists())->toBeTrue()
        ->and(AttendanceEvent::query()->whereIn('attendance_day_id', $days->pluck('id'))->where('recorded_at', '>', now())->exists())->toBeFalse() // nada del futuro
        ->and(AttendanceMarkFailure::query()->where('resolution_status', 'pending')->count())->toBeGreaterThanOrEqual(3);

    $snapshot = app(LiveAttendanceMonitor::class)->snapshot([]);
    $states = collect($snapshot['rows'] ?? [])->pluck('state')->countBy();

    expect($states->get('working', 0) + $states->get('on_break', 0))->toBeGreaterThan(5)
        ->and($states->get('missing', 0))->toBeGreaterThan(0);
});

it('refrescar el día de hoy es idempotente: no duplica jornadas ni fallos de marcación', function () {
    $this->seed(DemoTerminalSeeder::class);
    $seeder = app(DemoLiveAttendanceSeeder::class);

    $first = $seeder->generate();
    $days = AttendanceDay::query()->where('date', today()->toDateString())->count();
    $failures = AttendanceMarkFailure::query()->count();

    $second = $seeder->generate();

    expect($second['marked'])->toBe($first['marked'])
        ->and(AttendanceDay::query()->where('date', today()->toDateString())->count())->toBe($days)
        ->and(AttendanceMarkFailure::query()->count())->toBe($failures);
});

it('un lunes deja sin salida jornadas del viernes: el fin de semana no tiene jornadas presentes', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-23 11:30:00', config('app.timezone'))); // lunes
    $friday = Carbon::parse('2026-11-20', config('app.timezone'));

    foreach (Employee::query()->where('status', 'active')->orderBy('id')->take(6)->get() as $employee) {
        $day = AttendanceDay::query()->create(['employee_id' => $employee->id, 'date' => $friday->toDateString(), 'status' => 'present']);

        foreach (['check_in' => '07:00', 'check_out' => '16:00'] as $type => $time) {
            $day->events()->create(['event_type' => $type, 'recorded_at' => $friday->copy()->setTimeFromTimeString($time), 'source' => 'terminal', 'employee_id' => $employee->id]);
        }
    }

    $this->seed(DemoTerminalSeeder::class);
    $result = app(DemoLiveAttendanceSeeder::class)->generate();

    $open = AttendanceDay::query()
        ->where('date', $friday->toDateString())
        ->whereDoesntHave('events', fn ($q) => $q->where('event_type', 'check_out'))
        ->count();

    expect($result['open_yesterday'])->toBe(3)
        ->and($open)->toBe(3);

    // Idempotente: otra pasada no abre más jornadas.
    expect(app(DemoLiveAttendanceSeeder::class)->generate()['open_yesterday'])->toBe(3);
});

it('demo:refresh no hace nada fuera de una instancia de demo', function () {
    config(['app.demo' => false]);

    $this->artisan('demo:refresh')->assertFailed();

    expect(AttendanceDay::query()->count())->toBe(0);
});

it('demo:refresh con APP_DEMO activo refresca terminales y asistencia', function () {
    config(['app.demo' => true]);
    $this->seed(DemoTerminalSeeder::class);

    $this->artisan('demo:refresh')->assertSuccessful();

    expect(AttendanceDay::query()->where('date', today()->toDateString())->count())->toBeGreaterThan(10);
});
