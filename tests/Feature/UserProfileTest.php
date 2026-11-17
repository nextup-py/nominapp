<?php

use App\Filament\Pages\Auth\EditProfile;
use App\Models\LoginHistory;
use App\Models\User;
use App\Notifications\NewLoginLocationNotification;
use App\Services\UserSessionService;
use App\Support\PasswordPolicy;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function profileUser(): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

    return $user;
}

it('la política exige 10 caracteres con mayúsculas, minúsculas y números', function (string $password, bool $valid) {
    expect(Validator::make(['p' => $password], ['p' => PasswordPolicy::rule()])->passes())->toBe($valid);
})->with([
    'corta' => ['Abc12345', false],
    'sin números' => ['Abcdefghijk', false],
    'sin mayúsculas' => ['abcdefghi12', false],
    'válida' => ['Abcdefghi12', true],
]);

it('el perfil muestra nombre y correo en solo lectura y no los cambia', function () {
    $user = profileUser();
    $name = $user->name;

    Livewire::actingAs($user)->test(EditProfile::class)
        ->fillForm(['name' => 'Otro nombre', 'email' => 'otro@correo.com'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->fresh()->name)->toBe($name)->and($user->fresh()->email)->toBe($user->email);
});

it('cambia la contraseña solo si cumple la política', function () {
    $user = profileUser();

    Livewire::actingAs($user)->test(EditProfile::class)
        ->fillForm(['password' => 'corta1A', 'passwordConfirmation' => 'corta1A'])
        ->call('save')
        ->assertHasFormErrors(['password']);

    Livewire::actingAs($user)->test(EditProfile::class)
        ->fillForm(['password' => 'ClaveSegura123', 'passwordConfirmation' => 'ClaveSegura123'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Hash::check('ClaveSegura123', $user->fresh()->password))->toBeTrue();
});

it('registra los ingresos y avisa de una IP nueva solo desde el segundo', function () {
    $user = profileUser();

    event(new Login('web', $user, false));
    expect(LoginHistory::where('user_id', $user->id)->count())->toBe(1)
        ->and($user->notifications()->count())->toBe(0);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);
    $this->app['request']->server->set('REMOTE_ADDR', '203.0.113.9');
    event(new Login('web', $user, false));

    expect(LoginHistory::where('user_id', $user->id)->where('is_new_ip', true)->count())->toBe(1)
        ->and($user->notifications()->where('type', NewLoginLocationNotification::class)->count())->toBe(1);
});

it('cierra las demás sesiones y conserva la actual', function () {
    config(['session.driver' => 'database']);
    $user = profileUser();

    foreach (['actual', 'otra1', 'otra2'] as $id) {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120', 'payload' => '', 'last_activity' => now()->timestamp]);
    }

    expect(UserSessionService::forUser($user, 'actual'))->toHaveCount(3)
        ->and(UserSessionService::closeOthers($user, 'actual'))->toBe(2)
        ->and(DB::table('sessions')->where('user_id', $user->id)->pluck('id')->all())->toBe(['actual']);
});
