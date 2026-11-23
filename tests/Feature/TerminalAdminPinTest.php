<?php

use App\Filament\Resources\TerminalResource\Pages\EditTerminal;
use App\Filament\Resources\TerminalResource\Pages\ViewTerminal;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** Terminal vinculado de una sucursal nueva. */
function pinTerminal(array $attributes = []): Terminal
{
    static $n = 8900000;
    $n++;

    $company = Company::create(['name' => "Empresa PIN {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "Sucursal PIN {$n}", 'company_id' => $company->id]);
    $terminal = Terminal::create(['name' => "Terminal PIN {$n}", 'branch_id' => $branch->id, ...$attributes]);
    $terminal->claimSanctumToken();

    return $terminal->fresh();
}

function pinSuperAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

    return $user;
}

it('deriva el PIN igual que el cliente (PBKDF2-SHA256, sal como texto, 64 caracteres hex)', function () {
    expect(Terminal::deriveAdminPin('1234', 'abcdef0123456789abcdef0123456789'))
        ->toHaveLength(64)
        ->and(hash_pbkdf2('sha256', '1234', 'abcdef0123456789abcdef0123456789', Terminal::ADMIN_PIN_ITERATIONS, 64))
        ->toBe(Terminal::deriveAdminPin('1234', 'abcdef0123456789abcdef0123456789'));
});

it('guarda solo el PIN derivado con su sal, nunca el PIN, y no lo expone al serializar', function () {
    $terminal = pinTerminal();
    expect($terminal->hasAdminPin())->toBeFalse()->and($terminal->adminPinConfig())->toBeNull();

    $terminal->update(['admin_pin' => '4321']);
    $terminal->refresh();

    expect($terminal->hasAdminPin())->toBeTrue()
        ->and($terminal->admin_pin_hash)->not->toContain('4321')
        ->and($terminal->admin_pin_hash)->toBe(Terminal::deriveAdminPin('4321', $terminal->admin_pin_salt))
        ->and($terminal->toArray())->not->toHaveKeys(['admin_pin_hash', 'admin_pin_salt', 'admin_pin']);
});

it('un PIN vacío no cambia el definido y «quitar» lo borra', function () {
    $terminal = pinTerminal();
    $terminal->update(['admin_pin' => '1111']);
    $hash = $terminal->fresh()->admin_pin_hash;

    $terminal->update(['admin_pin' => null, 'clear_admin_pin' => false]);
    expect($terminal->fresh()->admin_pin_hash)->toBe($hash);

    $terminal->update(['clear_admin_pin' => true]);
    expect($terminal->fresh()->hasAdminPin())->toBeFalse();
});

it('cada cambio queda en la bitácora sin guardar el PIN', function () {
    $terminal = pinTerminal();
    $terminal->update(['admin_pin' => '2222']);
    $terminal->update(['admin_pin' => '3333']);
    $terminal->update(['clear_admin_pin' => true]);

    expect($terminal->events()->whereIn('type', ['admin_pin_changed', 'admin_pin_removed'])->orderBy('id')->pluck('type')->all())
        ->toBe(['admin_pin_changed', 'admin_pin_changed', 'admin_pin_removed'])
        ->and($terminal->events()->where('type', 'like', 'admin_pin%')->get()->every(fn ($e) => $e->payload === null))->toBeTrue();
});

it('el latido le manda al terminal la sal y el derivado (o null sin PIN) para verificarlo sin conexión', function () {
    $terminal = pinTerminal();
    Sanctum::actingAs($terminal, [Terminal::SYNC_ABILITY]);

    $this->postJson('/api/v1/terminal/heartbeat', [])->assertOk()->assertJsonPath('config.admin_pin', null);

    $terminal->update(['admin_pin' => '5678']);
    $terminal->refresh();

    $response = $this->postJson('/api/v1/terminal/heartbeat', [])->assertOk();

    expect($response->json('config.admin_pin'))->toBe([
        'salt' => $terminal->admin_pin_salt,
        'hash' => $terminal->admin_pin_hash,
        'iterations' => Terminal::ADMIN_PIN_ITERATIONS,
    ])->and($response->getContent())->not->toContain('5678');
});

it('el formulario define, valida y quita el PIN', function () {
    $this->actingAs(pinSuperAdmin());
    $terminal = pinTerminal();

    foreach (['12', '123456789', 'abcd'] as $invalid) {
        Livewire::test(EditTerminal::class, ['record' => $terminal->getKey()])
            ->fillForm(['admin_pin' => $invalid])
            ->call('save')
            ->assertHasFormErrors(['admin_pin']);
    }

    Livewire::test(EditTerminal::class, ['record' => $terminal->getKey()])
        ->fillForm(['admin_pin' => '246810'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($terminal->fresh()->admin_pin_hash)->toBe(Terminal::deriveAdminPin('246810', $terminal->fresh()->admin_pin_salt));

    Livewire::test(EditTerminal::class, ['record' => $terminal->getKey()])
        ->fillForm(['admin_pin' => null, 'clear_admin_pin' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($terminal->fresh()->hasAdminPin())->toBeFalse();
});

it('el detalle indica si hay PIN sin mostrarlo', function () {
    $this->actingAs(pinSuperAdmin());
    $terminal = pinTerminal();

    Livewire::test(ViewTerminal::class, ['record' => $terminal->getKey()])->assertSee('Sin PIN');

    $terminal->update(['admin_pin' => '9999']);

    Livewire::test(ViewTerminal::class, ['record' => $terminal->getKey()])->assertSee('Definido')->assertDontSee('9999');
});
