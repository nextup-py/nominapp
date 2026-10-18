<?php

use App\Http\Controllers\MobileLinkController;
use App\Models\AttendanceEvent;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Schedule;
use App\Models\ScheduleBreak;
use App\Models\ScheduleDay;
use App\Models\Terminal;
use App\Models\User;
use App\Notifications\MobileDeviceLinkedNotification;
use App\Notifications\MobileDeviceRelinkedNotification;
use App\Notifications\MobileLinkThrottledNotification;
use App\Services\ScheduleAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/** Admin con acceso a dispositivos de empleados: los avisos de vinculación solo llegan a quien puede verlos. */
function deviceAdmin(string $email, string $name = 'Admin'): User
{
    $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('secret')]);
    $user->givePermissionTo(Permission::firstOrCreate(['name' => 'view_any_employee_device', 'guard_name' => 'web']));

    return $user;
}

// ─── Helpers ────────────────────────────────────────────────────────────────

function makeLinkableEmployee(array $overrides = []): Employee
{
    static $ci = 9500000;
    $n = $ci++;

    $company = Company::create(['name' => "Empresa Link {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "Sucursal Link {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "Depto Link {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "Cargo Link {$n}", 'department_id' => $department->id]);

    $employee = Employee::create(array_merge([
        'first_name' => 'Link',
        'last_name' => 'Test',
        'ci' => (string) $n,
        'birth_date' => '1990-05-15',
        'email' => "link{$n}@test.com",
        'branch_id' => $branch->id,
        'status' => 'active',
        'face_descriptor' => array_fill(0, 128, 0.1),
    ], $overrides));

    Contract::create([
        'employee_id' => $employee->id,
        'type' => 'indefinido',
        'start_date' => now()->subYear(),
        'salary_type' => 'mensual',
        'salary' => 2_550_000,
        'position_id' => $position->id,
        'department_id' => $department->id,
        'status' => 'active',
    ]);

    return $employee->fresh();
}

/**
 * Asigna un horario fijo con descanso configurado los 7 días de la semana —
 * necesario para que AttendanceDay::resolveForEvent() ofrezca 'break_start'
 * (ver AttendanceCalculator::hasScheduledBreak()).
 */
function giveEmployeeBreakSchedule(Employee $employee): void
{
    $schedule = Schedule::create(['name' => 'Horario Con Descanso', 'shift_type' => 'diurno', 'description' => null]);

    foreach (range(1, 7) as $dayOfWeek) {
        $day = ScheduleDay::create([
            'schedule_id' => $schedule->id,
            'day_of_week' => $dayOfWeek,
            'is_active' => true,
            'start_time' => '07:00',
            'end_time' => '22:00',
        ]);

        ScheduleBreak::create([
            'schedule_day_id' => $day->id,
            'name' => 'Almuerzo',
            'start_time' => '12:00',
            'end_time' => '13:00',
        ]);
    }

    ScheduleAssignmentService::assign($employee, $schedule, now()->subYear());
}

// ─── Vinculación (/vincular-dispositivo) ───────────────────────────────────────

it('vincula el dispositivo con CI y fecha de nacimiento correctos y emite un token', function () {
    $employee = makeLinkableEmployee();

    $response = $this->postJson('/vincular-dispositivo', [
        'ci' => $employee->ci,
        'birth_date' => '1990-05-15',
    ]);

    $response->assertOk()->assertJson(['ok' => true]);
    expect($response->json('token'))->not->toBeNull()
        ->and($response->json('employee.id'))->toBe($employee->id)
        ->and($response->json('employee.face_descriptor'))->toHaveCount(128);

    expect($employee->fresh()->hasMobileLinked())->toBeTrue();
});

it('el device_model_hint enviado por el cliente llega hasta el EmployeeDevice creado', function () {
    $employee = makeLinkableEmployee();

    $this->postJson('/vincular-dispositivo', [
        'ci' => $employee->ci,
        'birth_date' => '1990-05-15',
        'device_model_hint' => 'Pixel 8 Pro',
    ])->assertOk();

    expect($employee->fresh()->activeDevice->device_model)->toBe('Pixel 8 Pro')
        ->and($employee->fresh()->activeDevice->device_brand)->toBe('Google');
});

it('rechaza CI o fecha de nacimiento incorrectos con un mensaje genérico', function () {
    $employee = makeLinkableEmployee();

    $response = $this->postJson('/vincular-dispositivo', [
        'ci' => $employee->ci,
        'birth_date' => '2000-01-01',
    ]);

    $response->assertStatus(422)->assertJson(['ok' => false]);
    expect($employee->fresh()->hasMobileLinked())->toBeFalse();
});

it('rechaza la vinculación de un empleado inactivo', function () {
    $employee = makeLinkableEmployee(['status' => 'inactive']);

    $response = $this->postJson('/vincular-dispositivo', [
        'ci' => $employee->ci,
        'birth_date' => '1990-05-15',
    ]);

    $response->assertStatus(422);
});

it('rechaza la vinculación de un empleado sin descriptor facial', function () {
    $employee = makeLinkableEmployee(['face_descriptor' => null]);

    $response = $this->postJson('/vincular-dispositivo', [
        'ci' => $employee->ci,
        'birth_date' => '1990-05-15',
    ]);

    $response->assertStatus(422);
});

it('vincular un dispositivo nuevo revoca el token anterior — un solo dispositivo a la vez', function () {
    $employee = makeLinkableEmployee();

    $first = $this->postJson('/vincular-dispositivo', [
        'ci' => $employee->ci,
        'birth_date' => '1990-05-15',
    ])->json('token');

    $second = $this->postJson('/vincular-dispositivo', [
        'ci' => $employee->ci,
        'birth_date' => '1990-05-15',
    ])->json('token');

    expect($first)->not->toBe($second);

    // El token viejo ya no autentica.
    $this->withHeader('Authorization', "Bearer {$first}")
        ->getJson('/api/v1/mobile/status')
        ->assertStatus(401);

    // El nuevo sí.
    $this->withHeader('Authorization', "Bearer {$second}")
        ->getJson('/api/v1/mobile/status')
        ->assertOk();
});

// ─── API /api/v1/mobile/* ──────────────────────────────────────────────────

it('el heartbeat devuelve la config vigente y el descriptor facial del empleado', function () {
    $employee = makeLinkableEmployee();
    Sanctum::actingAs($employee, [Employee::MOBILE_SYNC_ABILITY]);

    $response = $this->postJson('/api/v1/mobile/heartbeat');

    $response->assertOk()->assertJson(['ok' => true]);
    expect($response->json('employee.id'))->toBe($employee->id)
        ->and($response->json('config.face_threshold'))->not->toBeNull();
});

it('el heartbeat también devuelve photo_thumbnail para que el celular muestre la foto real', function () {
    $employee = makeLinkableEmployee(['photo_thumbnail' => 'data:image/jpeg;base64,FAKE_THUMBNAIL']);
    Sanctum::actingAs($employee, [Employee::MOBILE_SYNC_ABILITY]);

    $response = $this->postJson('/api/v1/mobile/heartbeat');

    $response->assertOk();
    expect($response->json('employee.photo_thumbnail'))->toBe('data:image/jpeg;base64,FAKE_THUMBNAIL');
});

it('el heartbeat también devuelve sucursal y empresa para el header de /marcar', function () {
    $employee = makeLinkableEmployee();
    Sanctum::actingAs($employee, [Employee::MOBILE_SYNC_ABILITY]);

    $response = $this->postJson('/api/v1/mobile/heartbeat');

    $response->assertOk();
    expect($response->json('employee.branch_name'))->toBe($employee->branch->name)
        ->and($response->json('employee.company_name'))->toBe($employee->branch->company->name);
});

it('el heartbeat también devuelve el logo de la empresa para el header de /marcar', function () {
    $employee = makeLinkableEmployee();
    $employee->branch->company->update(['logo_thumbnail' => 'data:image/png;base64,FAKE_LOGO']);
    Sanctum::actingAs($employee, [Employee::MOBILE_SYNC_ABILITY]);

    $response = $this->postJson('/api/v1/mobile/heartbeat');

    $response->assertOk();
    expect($response->json('employee.company_logo'))->toBe('data:image/png;base64,FAKE_LOGO');
});

it('el heartbeat devuelve company_logo null si la empresa no tiene logo', function () {
    $employee = makeLinkableEmployee();
    Sanctum::actingAs($employee, [Employee::MOBILE_SYNC_ABILITY]);

    $response = $this->postJson('/api/v1/mobile/heartbeat');

    $response->assertOk();
    expect($response->json('employee.company_logo'))->toBeNull();
});

it('el heartbeat revoca el token y responde 403 si el empleado ya no está activo', function () {
    $employee = makeLinkableEmployee();
    Sanctum::actingAs($employee, [Employee::MOBILE_SYNC_ABILITY]);
    $employee->update(['status' => 'inactive']);

    $response = $this->postJson('/api/v1/mobile/heartbeat');

    $response->assertStatus(403);
    expect($employee->tokens()->count())->toBe(0);
});

it('status devuelve el último evento y los eventos permitidos para el propio empleado', function () {
    $employee = makeLinkableEmployee();
    Sanctum::actingAs($employee, [Employee::MOBILE_SYNC_ABILITY]);

    $response = $this->getJson('/api/v1/mobile/status');

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'last_event' => null,
            'allowed_events' => ['check_in'],
            'today_events' => [],
        ]);
});

/**
 * Regresión: alimenta la pantalla "Mis marcaciones" en /marcar — a diferencia
 * del resto de la respuesta, esta lista completa del día no tiene resolución
 * offline local equivalente (ver resolveOwnStatus() en mobile-offline/queue.js).
 */
it('status devuelve la lista completa de eventos de hoy, no solo el último', function () {
    $employee = makeLinkableEmployee();
    giveEmployeeBreakSchedule($employee);
    Sanctum::actingAs($employee, [Employee::MOBILE_SYNC_ABILITY]);

    $this->postJson('/api/v1/mobile/events/sync', [
        'events' => [
            [
                'client_event_id' => (string) Str::uuid(),
                'event_type' => 'check_in',
                'recorded_at' => now()->setTime(8, 3)->toDateTimeString(),
                'location' => ['lat' => -25.28, 'lng' => -57.64],
            ],
            [
                'client_event_id' => (string) Str::uuid(),
                'event_type' => 'break_start',
                'recorded_at' => now()->setTime(12, 1)->toDateTimeString(),
                'location' => ['lat' => -25.28, 'lng' => -57.64],
            ],
        ],
    ])->assertOk();

    $response = $this->getJson('/api/v1/mobile/status');

    $response->assertOk();
    expect($response->json('today_events'))->toHaveCount(2)
        ->and($response->json('today_events.0'))->toBe([
            'event_type' => 'check_in',
            'event_type_label' => 'Entrada jornada',
            'time' => '08:03',
        ])
        ->and($response->json('today_events.1.event_type'))->toBe('break_start')
        ->and($response->json('today_events.1.time'))->toBe('12:01');
});

it('events/sync crea el evento con origen mobile para el empleado autenticado', function () {
    $employee = makeLinkableEmployee();
    Sanctum::actingAs($employee, [Employee::MOBILE_SYNC_ABILITY]);
    $clientEventId = (string) Str::uuid();

    $response = $this->postJson('/api/v1/mobile/events/sync', [
        'events' => [[
            'client_event_id' => $clientEventId,
            'event_type' => 'check_in',
            'recorded_at' => now()->toDateTimeString(),
            'location' => ['lat' => -25.28, 'lng' => -57.64],
        ]],
    ]);

    $response->assertOk()->assertJson(['ok' => true]);
    expect($response->json('results.0.status'))->toBe('synced');

    $event = AttendanceEvent::where('client_event_id', $clientEventId)->first();
    expect($event->source)->toBe('mobile');
});

it('events/sync revoca el token y responde 403 si el empleado ya no está activo', function () {
    $employee = makeLinkableEmployee();
    Sanctum::actingAs($employee, [Employee::MOBILE_SYNC_ABILITY]);
    $employee->update(['status' => 'inactive']);

    $response = $this->postJson('/api/v1/mobile/events/sync', [
        'events' => [[
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'check_in',
            'recorded_at' => now()->toDateTimeString(),
        ]],
    ]);

    $response->assertStatus(403);
    expect($employee->tokens()->count())->toBe(0);
});

it('un token de terminal no puede usar las rutas móviles (ability distinta)', function () {
    $employee = makeLinkableEmployee();
    $terminal = Terminal::create([
        'name' => 'Terminal Test', 'branch_id' => $employee->branch_id,
    ]);
    Sanctum::actingAs($terminal, [Terminal::SYNC_ABILITY]);

    $this->postJson('/api/v1/mobile/heartbeat')->assertStatus(403);
});

// ─── Auto-desvinculación (/api/v1/mobile/unlink) ───────────────────────────

it('unlink revoca el token propio del empleado — auto-servicio, sin admin', function () {
    $employee = makeLinkableEmployee();
    Sanctum::actingAs($employee, [Employee::MOBILE_SYNC_ABILITY]);

    $response = $this->postJson('/api/v1/mobile/unlink');

    $response->assertOk()->assertJson(['ok' => true]);
    expect($employee->fresh()->hasMobileLinked())->toBeFalse()
        ->and($employee->tokens()->count())->toBe(0);
});

it('unlink deja al empleado listo para vincular un dispositivo nuevo', function () {
    $employee = makeLinkableEmployee();
    Sanctum::actingAs($employee, [Employee::MOBILE_SYNC_ABILITY]);
    $this->postJson('/api/v1/mobile/unlink')->assertOk();

    $response = $this->postJson('/vincular-dispositivo', [
        'ci' => $employee->ci,
        'birth_date' => '1990-05-15',
    ]);

    $response->assertOk()->assertJson(['ok' => true]);
});

it('un token de terminal no puede usar el endpoint de unlink móvil (ability distinta)', function () {
    $employee = makeLinkableEmployee();
    $terminal = Terminal::create([
        'name' => 'Terminal Test', 'branch_id' => $employee->branch_id,
    ]);
    Sanctum::actingAs($terminal, [Terminal::SYNC_ABILITY]);

    $this->postJson('/api/v1/mobile/unlink')->assertStatus(403);
});

// ─── Hardening (Fase 4) ─────────────────────────────────────────────────────

it('notifica a los admins (sin advertencia) en la primera vinculación', function () {
    Notification::fake();
    $admin = deviceAdmin('admin-first@test.com', 'Admin');

    $employee = makeLinkableEmployee();

    $this->postJson('/vincular-dispositivo', [
        'ci' => $employee->ci,
        'birth_date' => '1990-05-15',
    ])->assertOk();

    Notification::assertSentTo(
        $admin,
        MobileDeviceLinkedNotification::class,
        fn ($notification) => $notification->employee->is($employee)
    );
    Notification::assertNotSentTo($admin, MobileDeviceRelinkedNotification::class);
});

it('notifica a todos los admins cuando un dispositivo ya vinculado se re-vincula', function () {
    $employee = makeLinkableEmployee();
    $admin1 = deviceAdmin('admin-relink1@test.com', 'Admin 1');
    $admin2 = deviceAdmin('admin-relink2@test.com', 'Admin 2');

    // Primera vinculación — sin notificación (no hay nada previo que "reemplazar").
    $this->postJson('/vincular-dispositivo', ['ci' => $employee->ci, 'birth_date' => '1990-05-15'])->assertOk();

    Notification::fake();

    // Segunda vinculación del mismo empleado — el dispositivo anterior existía.
    $this->postJson('/vincular-dispositivo', ['ci' => $employee->ci, 'birth_date' => '1990-05-15'])->assertOk();

    Notification::assertSentTo(
        [$admin1, $admin2],
        MobileDeviceRelinkedNotification::class,
        fn ($notification) => $notification->employee->is($employee)
    );
});

/**
 * Regresión: mismo gotcha que claimSanctumToken() en Terminal — el
 * mobile_linked_at (single-use del CI+fecha) ya había quedado persistido
 * antes de notificar a los admins, así que un mailer roto (MAIL_MAILER=resend
 * sin RESEND_KEY) tumbaba el request con 500 DESPUÉS de vincular el
 * dispositivo, dejando al empleado sin el token pero con /vincular-dispositivo
 * ya consumido.
 */
it('vincula el dispositivo aunque falle el envío de la notificación por email', function () {
    config(['mail.default' => 'resend', 'services.resend.key' => null]);
    deviceAdmin('admin-mailfail@test.com', 'Admin');

    $employee = makeLinkableEmployee();

    $response = $this->postJson('/vincular-dispositivo', [
        'ci' => $employee->ci,
        'birth_date' => '1990-05-15',
    ]);

    $response->assertOk()->assertJson(['ok' => true]);
    expect($response->json('token'))->not->toBeNull()
        ->and($employee->fresh()->hasMobileLinked())->toBeTrue();
});

it('el heartbeat actualiza mobile_last_heartbeat_at', function () {
    $employee = makeLinkableEmployee();
    Sanctum::actingAs($employee, [Employee::MOBILE_SYNC_ABILITY]);

    expect($employee->mobile_last_heartbeat_at)->toBeNull();

    $this->postJson('/api/v1/mobile/heartbeat')->assertOk();

    expect($employee->fresh()->mobile_last_heartbeat_at)->not->toBeNull();
});

it('revocar el dispositivo limpia mobile_last_heartbeat_at además de mobile_linked_at', function () {
    $employee = makeLinkableEmployee();
    Sanctum::actingAs($employee, [Employee::MOBILE_SYNC_ABILITY]);
    $this->postJson('/api/v1/mobile/heartbeat')->assertOk();
    expect($employee->fresh()->mobile_last_heartbeat_at)->not->toBeNull();

    $employee->fresh()->revokeMobileToken();

    $fresh = $employee->fresh();
    expect($fresh->mobile_linked_at)->toBeNull()
        ->and($fresh->mobile_last_heartbeat_at)->toBeNull();
});

it('el POST de vincular-dispositivo tiene throttling más estricto que el GET', function () {
    $employee = makeLinkableEmployee();

    // 5 intentos permitidos por minuto (credenciales incorrectas a propósito, no importa el resultado).
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/vincular-dispositivo', ['ci' => $employee->ci, 'birth_date' => '2000-01-01'])
            ->assertStatus(422);
    }

    // El 6º intento en la misma ventana debe ser rechazado por el rate limiter.
    $this->postJson('/vincular-dispositivo', ['ci' => $employee->ci, 'birth_date' => '2000-01-01'])
        ->assertStatus(429);
});

/**
 * Regresión: el 429 del throttling por minuto mostraba el mensaje genérico
 * de Laravel ("Too Many Attempts.", en inglés, sin indicar cuánto esperar).
 */
it('el 429 por límite de minuto tiene un mensaje en español con el tiempo de espera', function () {
    $employee = makeLinkableEmployee();

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/vincular-dispositivo', ['ci' => $employee->ci, 'birth_date' => '2000-01-01'])->assertStatus(422);
    }

    $response = $this->postJson('/vincular-dispositivo', ['ci' => $employee->ci, 'birth_date' => '2000-01-01']);

    $response->assertStatus(429)->assertJson(['ok' => false]);
    expect($response->json('message'))->toContain('Demasiados intentos')
        ->and($response->json('message'))->toContain('minuto');
});

/**
 * Regresión: otras rutas públicas con throttling (terminal/setup,
 * registro-facial) no deben verse afectadas por el render() scopeado a
 * device-link.claim en bootstrap/app.php — deben mantener el 429 default.
 */
it('el 429 de otras rutas throttled no se ve afectado por el mensaje de vincular-dispositivo', function () {
    // terminal/{code}/setup/{setupToken} usa throttle:10,1 (sin scopear en bootstrap/app.php).
    for ($i = 0; $i < 10; $i++) {
        $this->getJson('/terminal/BOGUS/setup/faketoken');
    }

    $response = $this->getJson('/terminal/BOGUS/setup/faketoken');

    $response->assertStatus(429);
    // No debe tener la forma de MobileLinkController::throttledResponse() ('ok' + 'message' en español).
    expect($response->json('ok'))->toBeNull();
});

/**
 * Regresión: 'terminal.setup' y 'face-enrollment' usaban ambos 'throttle:10,1'
 * sin prefijo — ThrottleRequests::resolveRequestSignature() genera la clave
 * del rate limit solo con dominio+IP (sin distinguir ruta), así que ambos
 * grupos compartían el mismo bucket por IP. Agotar el límite de una ruta
 * bloqueaba también a la otra, aunque cada una tenga su propio límite
 * nominal de 10/min. Ahora cada grupo tiene su propio prefijo
 * ('terminal-setup' / 'face-enrollment').
 */
it('el throttle de terminal.setup y el de face-enrollment ya no comparten bucket', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->getJson('/terminal/BOGUS/setup/faketoken');
    }
    $this->getJson('/terminal/BOGUS/setup/faketoken')->assertStatus(429);

    // Antes del fix, esta request ya venía bloqueada por el bucket compartido
    // aunque 'registro-facial' nunca haya sido golpeada.
    $this->getJson('/registro-facial/faketoken')->assertStatus(404);
});

/**
 * MobileLinkController::throttledResponse() es la lógica que arma el mensaje
 * y decide si notificar a los admins — se prueba directamente (sin esperar
 * 15 requests reales limitadas a 5/minuto) construyendo la excepción con los
 * headers exactos que produce ThrottleRequests::buildException().
 */
it('el límite diario notifica a los admins con la IP y el CI intentado, una sola vez por día', function () {
    Notification::fake();
    deviceAdmin('admin-throttle@test.com');

    $request = Request::create('/vincular-dispositivo', 'POST', ['ci' => '4445556']);
    $request->server->set('REMOTE_ADDR', '203.0.113.20');

    $exception = new ThrottleRequestsException('Too Many Attempts.', null, [
        'Retry-After' => 43200,
        'X-RateLimit-Limit' => 15,
        'X-RateLimit-Remaining' => 0,
    ]);

    $response = MobileLinkController::throttledResponse($exception, $request);
    $data = $response->getData(true);

    expect($response->getStatusCode())->toBe(429)
        ->and($data['ok'])->toBeFalse()
        ->and($data['message'])->toContain('RRHH');

    Notification::assertSentTimes(MobileLinkThrottledNotification::class, 1);
    Notification::assertSentTo(User::first(), MobileLinkThrottledNotification::class, function ($notification) {
        return $notification->ip === '203.0.113.20' && $notification->lastCiAttempted === '4445556';
    });

    // Un segundo 429 diario de la MISMA IP el mismo día no debe volver a notificar.
    MobileLinkController::throttledResponse($exception, $request);
    Notification::assertSentTimes(MobileLinkThrottledNotification::class, 1);
});

it('el límite por minuto (distinto de 15) no notifica a los admins', function () {
    Notification::fake();
    deviceAdmin('admin-minute@test.com');

    $request = Request::create('/vincular-dispositivo', 'POST', ['ci' => '1112223']);
    $request->server->set('REMOTE_ADDR', '203.0.113.10');

    $exception = new ThrottleRequestsException('Too Many Attempts.', null, [
        'Retry-After' => 45,
        'X-RateLimit-Limit' => 5,
        'X-RateLimit-Remaining' => 0,
    ]);

    MobileLinkController::throttledResponse($exception, $request);

    Notification::assertNothingSent();
});
