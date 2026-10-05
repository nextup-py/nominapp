<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Terminal;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ─── Helpers ────────────────────────────────────────────────────────────────

function makeConnectivityTerminal(): Terminal
{
    static $n = 7000000;
    $n++;

    $company = Company::create(['name' => "Empresa Term {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "Sucursal Term {$n}", 'company_id' => $company->id]);

    return Terminal::create(['name' => 'Terminal Test', 'branch_id' => $branch->id]);
}

/** Emite un token de sincronización vigente (equivale a haber reclamado el enlace de setup). */
function linkConnectivityTerminal(Terminal $terminal, array $abilities = [Terminal::SYNC_ABILITY]): Terminal
{
    $terminal->createToken('kiosk:'.$terminal->code, $abilities);

    return $terminal;
}

// ─── Tests ──────────────────────────────────────────────────────────────────

it('sin vincular cuando no tiene ningún token de sincronización', function () {
    $terminal = makeConnectivityTerminal();

    expect($terminal->connectivity_status)->toBe('unlinked')
        ->and($terminal->hasActiveSyncToken())->toBeFalse();
});

it('sin vincular tiene prioridad sobre un heartbeat reciente si se revocó el token', function () {
    $terminal = linkConnectivityTerminal(makeConnectivityTerminal());
    $terminal->update(['last_heartbeat_at' => now()]);
    expect($terminal->connectivity_status)->toBe('online');

    $terminal->revokeSyncTokens();

    expect($terminal->fresh()->connectivity_status)->toBe('unlinked');
});

it('un token sin la ability terminal:sync no cuenta como vinculado', function () {
    $terminal = linkConnectivityTerminal(makeConnectivityTerminal(), ['otra:ability']);

    expect($terminal->connectivity_status)->toBe('unlinked');
});

it('un token expirado no cuenta como vinculado', function () {
    $terminal = makeConnectivityTerminal();
    $terminal->createToken('kiosk:'.$terminal->code, [Terminal::SYNC_ABILITY], now()->subMinute());

    expect($terminal->connectivity_status)->toBe('unlinked');
});

it('los scopes syncLinked / syncUnlinked separan los terminales según su token', function () {
    $linked = linkConnectivityTerminal(makeConnectivityTerminal());
    $unlinked = makeConnectivityTerminal();

    expect(Terminal::syncLinked()->pluck('id')->all())->toBe([$linked->id])
        ->and(Terminal::syncUnlinked()->pluck('id')->all())->toBe([$unlinked->id]);
});

it('withSyncTokenFlag resuelve connectivity_status de varios terminales sin consultar tokens por fila', function () {
    foreach (range(1, 3) as $i) {
        linkConnectivityTerminal(makeConnectivityTerminal())->update(['last_heartbeat_at' => now()]);
    }
    makeConnectivityTerminal();
    // Resuelve y cachea los settings antes de contar, para medir solo las consultas de tokens.
    app(GeneralSettings::class)->terminal_stale_threshold_hours;

    $tokenQueries = 0;
    DB::listen(function ($query) use (&$tokenQueries) {
        if (str_contains($query->sql, 'personal_access_tokens')) {
            $tokenQueries++;
        }
    });

    $statuses = Terminal::withSyncTokenFlag()->get()->map->connectivity_status->all();

    expect($statuses)->toHaveCount(4)
        ->and(collect($statuses)->countBy()->all())->toBe(['online' => 3, 'unlinked' => 1])
        ->and($tokenQueries)->toBe(1);
});

it('nunca conectado cuando está vinculado pero last_heartbeat_at es null', function () {
    $terminal = linkConnectivityTerminal(makeConnectivityTerminal());

    expect($terminal->connectivity_status)->toBe('never_connected');
});

it('en línea cuando el último heartbeat está dentro del umbral configurado', function () {
    $settings = app(GeneralSettings::class);
    $settings->terminal_stale_threshold_hours = 2;
    $settings->save();

    $terminal = linkConnectivityTerminal(makeConnectivityTerminal());
    $terminal->update(['last_heartbeat_at' => now()->subHour()]);

    expect($terminal->connectivity_status)->toBe('online');
});

it('desconectado cuando el último heartbeat superó el umbral configurado', function () {
    $settings = app(GeneralSettings::class);
    $settings->terminal_stale_threshold_hours = 2;
    $settings->save();

    $terminal = linkConnectivityTerminal(makeConnectivityTerminal());
    $terminal->update(['last_heartbeat_at' => now()->subHours(3)]);

    expect($terminal->connectivity_status)->toBe('stale');
});

it('el heartbeat de la API actualiza last_seen_at y last_heartbeat_at', function () {
    $terminal = makeConnectivityTerminal();
    Sanctum::actingAs($terminal, [Terminal::SYNC_ABILITY]);

    $response = $this->postJson('/api/v1/terminal/heartbeat');

    $response->assertOk()->assertJson(['ok' => true]);
    $terminal->refresh();
    expect($terminal->last_heartbeat_at)->not->toBeNull()
        ->and($terminal->last_seen_at)->not->toBeNull();
});

it('el sync de empleados actualiza last_employee_sync_at', function () {
    $terminal = makeConnectivityTerminal();
    Sanctum::actingAs($terminal, [Terminal::SYNC_ABILITY]);

    $response = $this->getJson('/api/v1/terminal/employees/sync');

    $response->assertOk()->assertJson(['ok' => true]);
    $terminal->refresh();
    expect($terminal->last_employee_sync_at)->not->toBeNull();
});

it('el sync de eventos actualiza last_event_sync_at', function () {
    $terminal = makeConnectivityTerminal();
    Sanctum::actingAs($terminal, [Terminal::SYNC_ABILITY]);

    $response = $this->postJson('/api/v1/terminal/events/sync', [
        'events' => [[
            'client_event_id' => (string) Str::uuid(),
            'employee_id' => 999999,
            'event_type' => 'check_in',
            'recorded_at' => now()->toDateTimeString(),
        ]],
    ]);

    $response->assertOk()->assertJson(['ok' => true]);
    $terminal->refresh();
    expect($terminal->last_event_sync_at)->not->toBeNull();
});

it('sin pendientes ni conflictos reportados, la cola de sync es ok', function () {
    $terminal = makeConnectivityTerminal();

    expect($terminal->sync_queue_status)->toBe('ok');
});

it('con pendientes y sin conflictos, la cola de sync es pending', function () {
    $terminal = makeConnectivityTerminal();
    $terminal->update(['last_pending_events_count' => 3, 'last_conflict_events_count' => 0]);

    expect($terminal->fresh()->sync_queue_status)->toBe('pending');
});

it('con al menos un conflicto, la cola de sync es conflict aunque también haya pendientes', function () {
    $terminal = makeConnectivityTerminal();
    $terminal->update(['last_pending_events_count' => 3, 'last_conflict_events_count' => 1]);

    expect($terminal->fresh()->sync_queue_status)->toBe('conflict');
});

it('el heartbeat guarda los contadores de cola que reporta el terminal', function () {
    $terminal = makeConnectivityTerminal();
    Sanctum::actingAs($terminal, [Terminal::SYNC_ABILITY]);

    $response = $this->postJson('/api/v1/terminal/heartbeat', [
        'pending_events' => 5,
        'conflict_events' => 2,
    ]);

    $response->assertOk()->assertJson(['ok' => true]);
    $terminal->refresh();
    expect($terminal->last_pending_events_count)->toBe(5)
        ->and($terminal->last_conflict_events_count)->toBe(2)
        ->and($terminal->sync_queue_status)->toBe('conflict');
});

it('el heartbeat sin contadores no rompe y deja los contadores en null', function () {
    $terminal = makeConnectivityTerminal();
    Sanctum::actingAs($terminal, [Terminal::SYNC_ABILITY]);

    $response = $this->postJson('/api/v1/terminal/heartbeat');

    $response->assertOk()->assertJson(['ok' => true]);
    $terminal->refresh();
    expect($terminal->last_pending_events_count)->toBeNull()
        ->and($terminal->last_conflict_events_count)->toBeNull();
});

/**
 * Regresión: el título de pestaña ayuda a diferenciar terminales de distintas
 * sucursales cuando un admin monitorea varios a la vez — antes solo mostraba
 * el nombre del propio terminal, sin sucursal ni empresa.
 */
it('el título de la página del terminal muestra sucursal — empresa', function () {
    $terminal = makeConnectivityTerminal();

    $response = $this->get("/terminal/{$terminal->code}");

    $response->assertOk();
    $expectedTitle = "{$terminal->branch->name} — {$terminal->branch->company->name}";
    expect($response->getContent())->toContain("<title>{$expectedTitle}</title>");
});
