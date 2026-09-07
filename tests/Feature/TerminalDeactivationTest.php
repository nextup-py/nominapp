<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Terminal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ─── Helpers ────────────────────────────────────────────────────────────────

function makeDeactivationTerminal(): Terminal
{
    static $n = 7500000;
    $n++;

    $company = Company::create(['name' => "Empresa Deact {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "Sucursal Deact {$n}", 'company_id' => $company->id]);

    return Terminal::create(['name' => 'Terminal Test', 'branch_id' => $branch->id]);
}

// ─── Tests ──────────────────────────────────────────────────────────────────

it('desactivar un terminal revoca todos sus tokens Sanctum', function () {
    $terminal = makeDeactivationTerminal();
    $terminal->createToken('kiosk:test', [Terminal::SYNC_ABILITY]);

    expect($terminal->tokens()->count())->toBe(1);

    $terminal->update(['status' => 'inactive']);

    expect($terminal->fresh()->tokens()->count())->toBe(0);
});

it('reactivar un terminal no revoca tokens (solo desactivar lo hace)', function () {
    $terminal = makeDeactivationTerminal();
    $terminal->update(['status' => 'inactive']);
    $terminal->createToken('kiosk:test', [Terminal::SYNC_ABILITY]);

    $terminal->update(['status' => 'active']);

    expect($terminal->fresh()->tokens()->count())->toBe(1);
});

it('actualizar un campo que no sea status no revoca tokens', function () {
    $terminal = makeDeactivationTerminal();
    $terminal->createToken('kiosk:test', [Terminal::SYNC_ABILITY]);

    $terminal->update(['last_seen_at' => now()]);

    expect($terminal->fresh()->tokens()->count())->toBe(1);
});

it('un terminal desactivado no puede sincronizar vía la API aunque conserve un token', function () {
    $terminal = makeDeactivationTerminal();
    $terminal->update(['status' => 'inactive']);
    Sanctum::actingAs($terminal, [Terminal::SYNC_ABILITY]);

    $response = $this->postJson('/api/v1/terminal/heartbeat');

    $response->assertForbidden();
});

it('un terminal desactivado no puede sincronizar empleados vía la API', function () {
    $terminal = makeDeactivationTerminal();
    $terminal->update(['status' => 'inactive']);
    Sanctum::actingAs($terminal, [Terminal::SYNC_ABILITY]);

    $response = $this->getJson('/api/v1/terminal/employees/sync');

    $response->assertForbidden();
});

it('un terminal activo sigue pudiendo sincronizar normalmente', function () {
    $terminal = makeDeactivationTerminal();
    Sanctum::actingAs($terminal, [Terminal::SYNC_ABILITY]);

    $response = $this->postJson('/api/v1/terminal/heartbeat');

    $response->assertOk()->assertJson(['ok' => true]);
});
