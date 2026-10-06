<?php

use App\Filament\Resources\TerminalResource\Pages\ListTerminals;
use App\Filament\Resources\TerminalResource\Pages\ViewTerminal;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Terminal;
use App\Models\TerminalCommand;
use App\Models\TerminalEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

// ─── Helpers ────────────────────────────────────────────────────────────────

function makeCommandTerminal(array $attributes = [], bool $linked = true): Terminal
{
    static $n = 8400000;
    $n++;

    $company = Company::create(['name' => "Empresa Cmd {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "Sucursal Cmd {$n}", 'company_id' => $company->id]);
    $terminal = Terminal::create(['name' => "Terminal Cmd {$n}", 'branch_id' => $branch->id, ...$attributes]);

    if ($linked) {
        $terminal->claimSanctumToken();
    }

    return $terminal->fresh();
}

function commandManager(): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

    return $user;
}

function heartbeatAs(Terminal $terminal, array $body = [])
{
    Sanctum::actingAs($terminal, ['terminal:sync']);

    return test()->postJson('/api/v1/terminal/heartbeat', $body);
}

beforeEach(fn () => Notification::fake());

// ─── Modelo: envío ──────────────────────────────────────────────────────────

it('envía un comando pendiente con vencimiento a 15 minutos y registra quién lo envió', function () {
    $terminal = makeCommandTerminal();
    $admin = commandManager();

    $command = $terminal->sendCommand(TerminalCommand::FORCE_SYNC, $admin);

    expect($command->status)->toBe('pending')
        ->and($command->requested_by_id)->toBe($admin->id)
        ->and($command->expires_at->between(now()->addMinutes(14), now()->addMinutes(15)->addSecond()))->toBeTrue()
        ->and($terminal->events()->where('type', 'command_sent')->first()->actor_id)->toBe($admin->id);
});

it('no acumula duplicados: reenviar el mismo comando sin resolver devuelve el existente', function () {
    $terminal = makeCommandTerminal();

    $first = $terminal->sendCommand(TerminalCommand::RELOAD);
    $second = $terminal->sendCommand(TerminalCommand::RELOAD);
    $other = $terminal->sendCommand(TerminalCommand::REPORT);

    expect($second->id)->toBe($first->id)
        ->and($other->id)->not->toBe($first->id)
        ->and($terminal->commands()->count())->toBe(2);
});

it('rechaza comandos desconocidos y terminales inactivos o sin vincular', function () {
    expect(fn () => makeCommandTerminal()->sendCommand('rm_rf'))->toThrow(DomainException::class)
        ->and(fn () => makeCommandTerminal(linked: false)->sendCommand(TerminalCommand::REPORT))->toThrow(DomainException::class)
        ->and(fn () => makeCommandTerminal(['status' => 'inactive'])->sendCommand(TerminalCommand::REPORT))->toThrow(DomainException::class);
});

// ─── Heartbeat: entrega y confirmación ──────────────────────────────────────

it('el heartbeat entrega los comandos pendientes una sola vez y los marca entregados', function () {
    $terminal = makeCommandTerminal();
    $command = $terminal->sendCommand(TerminalCommand::CLEAR_CACHE);

    $first = heartbeatAs($terminal, ['command_acks' => []]);
    $first->assertOk()->assertJsonPath('commands.0.id', $command->id)->assertJsonPath('commands.0.command', 'clear_cache');

    heartbeatAs($terminal, ['command_acks' => []])->assertOk()->assertJsonCount(0, 'commands');

    expect($command->fresh()->status)->toBe('delivered')->and($command->fresh()->delivered_at)->not->toBeNull();
});

it('un cliente viejo (sin command_acks) nunca recibe comandos y el comando queda pendiente', function () {
    $terminal = makeCommandTerminal();
    $command = $terminal->sendCommand(TerminalCommand::RELOAD);

    heartbeatAs($terminal)->assertOk()->assertJsonCount(0, 'commands');

    expect($command->fresh()->status)->toBe('pending');
});

it('la confirmación done/failed cierra el comando y deja evento en la bitácora', function () {
    $terminal = makeCommandTerminal();
    $ok = $terminal->sendCommand(TerminalCommand::FORCE_SYNC);
    $bad = $terminal->sendCommand(TerminalCommand::CLEAR_CACHE);
    heartbeatAs($terminal, ['command_acks' => []]);

    heartbeatAs($terminal, ['command_acks' => [
        ['id' => $ok->id, 'status' => 'done'],
        ['id' => $bad->id, 'status' => 'failed', 'message' => 'Sin red'],
    ]])->assertOk();

    expect($ok->fresh()->status)->toBe('done')
        ->and($bad->fresh()->status)->toBe('failed')
        ->and($bad->fresh()->result_message)->toBe('Sin red')
        ->and($terminal->events()->where('type', 'command_done')->exists())->toBeTrue()
        ->and($terminal->events()->where('type', 'command_failed')->exists())->toBeTrue();
});

it('un terminal no puede confirmar comandos de otro terminal ni comandos que no se le entregaron', function () {
    $mine = makeCommandTerminal();
    $other = makeCommandTerminal();
    $foreign = $other->sendCommand(TerminalCommand::RELOAD);
    $notDelivered = $mine->sendCommand(TerminalCommand::REPORT);

    heartbeatAs($mine, ['command_acks' => [
        ['id' => $foreign->id, 'status' => 'done'],
        ['id' => $notDelivered->id, 'status' => 'done'],
    ]])->assertOk();

    expect($foreign->fresh()->status)->toBe('pending')
        ->and($notDelivered->fresh()->status)->not->toBe('done');
});

it('valida el formato de las confirmaciones', function () {
    $terminal = makeCommandTerminal();

    heartbeatAs($terminal, ['command_acks' => [['id' => 1, 'status' => 'hacked']]])->assertUnprocessable();
});

// ─── Vencimiento ────────────────────────────────────────────────────────────

it('vence los pendientes no entregados y falla los entregados sin confirmar', function () {
    $terminal = makeCommandTerminal();
    $pending = $terminal->sendCommand(TerminalCommand::RELOAD);
    $delivered = $terminal->sendCommand(TerminalCommand::REPORT);
    $delivered->update(['status' => 'delivered', 'delivered_at' => now()]);
    $fresh = $terminal->sendCommand(TerminalCommand::CLEAR_CACHE);
    TerminalCommand::whereIn('id', [$pending->id, $delivered->id])->update(['expires_at' => now()->subMinute()]);

    $this->artisan('terminals:expire-commands')->assertSuccessful();

    expect($pending->fresh()->status)->toBe('expired')
        ->and($delivered->fresh()->status)->toBe('failed')
        ->and($fresh->fresh()->status)->toBe('pending')
        ->and($terminal->events()->where('type', 'command_expired')->exists())->toBeTrue();
});

it('un comando vencido no se entrega en el heartbeat', function () {
    $terminal = makeCommandTerminal();
    $command = $terminal->sendCommand(TerminalCommand::RELOAD);
    $command->update(['expires_at' => now()->subMinute()]);

    heartbeatAs($terminal, ['command_acks' => []])->assertOk()->assertJsonCount(0, 'commands');

    expect($command->fresh()->status)->toBe('expired');
});

it('la purga de la bitácora también elimina comandos viejos', function () {
    $terminal = makeCommandTerminal();
    $old = $terminal->sendCommand(TerminalCommand::REPORT);
    TerminalCommand::whereKey($old->id)->update(['created_at' => now()->subDays(400)]);

    $this->artisan('terminals:purge-events', ['--days' => 90])->assertSuccessful();

    expect(TerminalCommand::count())->toBe(0);
});

it('los tipos de evento de comandos tienen etiqueta, color y detalle', function () {
    foreach (['command_sent', 'command_done', 'command_failed', 'command_expired'] as $type) {
        expect(TerminalEvent::getTypeLabels())->toHaveKey($type)->and(TerminalEvent::getTypeColors())->toHaveKey($type);
    }

    $event = TerminalEvent::record(makeCommandTerminal(), 'command_done', ['command' => 'clear_cache']);
    expect($event->detail)->toBe('Comando: Limpiar caché');
});

// ─── Panel ──────────────────────────────────────────────────────────────────

it('el detalle envía un comando desde el panel', function () {
    $terminal = makeCommandTerminal();
    $this->actingAs(commandManager());

    Livewire::test(ViewTerminal::class, ['record' => $terminal->getRouteKey()])
        ->callAction('send_command_force_sync')
        ->assertNotified('Comando enviado');

    expect($terminal->commands()->where('command', 'force_sync')->exists())->toBeTrue();
});

it('las acciones de comando no se ofrecen si el terminal está sin vincular', function () {
    $terminal = makeCommandTerminal(linked: false);
    $this->actingAs(commandManager());

    Livewire::test(ViewTerminal::class, ['record' => $terminal->getRouteKey()])
        ->assertActionHidden('send_command_force_sync');
});

it('un usuario sin update_terminal no ve las acciones de comando', function () {
    $terminal = makeCommandTerminal();
    $viewer = User::factory()->create();
    foreach (['view_any_terminal', 'view_terminal'] as $name) {
        $viewer->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
    }
    $this->actingAs($viewer);

    Livewire::test(ViewTerminal::class, ['record' => $terminal->getRouteKey()])
        ->assertActionHidden('send_command_reload');
});

it('la acción masiva envía el comando a los terminales vinculados y omite los demás', function () {
    $linked = makeCommandTerminal();
    $unlinked = makeCommandTerminal(linked: false);
    $this->actingAs(commandManager());

    Livewire::test(ListTerminals::class)
        ->callTableBulkAction('send_command_bulk', [$linked, $unlinked], ['command' => 'report'])
        ->assertNotified();

    expect($linked->commands()->count())->toBe(1)->and($unlinked->commands()->count())->toBe(0);
});
