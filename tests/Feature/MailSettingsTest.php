<?php

use App\Filament\Pages\ManageGeneralSettings;
use App\Models\User;
use App\Services\MailConfigurator;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Configuración del envío de correo desde el panel (Configuración General → Correo): el SMTP del panel reemplaza al del
 * `.env` solo cuando está activo, la clave se guarda cifrada y nunca vuelve al navegador, y se puede probar antes de guardar.
 */
function configureMail(array $overrides = []): GeneralSettings
{
    $settings = app(GeneralSettings::class);

    foreach ([
        'mail_enabled' => true,
        'mail_host' => 'smtp.ejemplo.com.py',
        'mail_port' => 587,
        'mail_encryption' => 'tls',
        'mail_username' => 'avisos@ejemplo.com.py',
        'mail_password' => 'secreto-123',
        'mail_from_address' => 'avisos@ejemplo.com.py',
        'mail_from_name' => 'Avisos',
        ...$overrides,
    ] as $property => $value) {
        $settings->{$property} = $value;
    }
    $settings->save();

    return $settings;
}

function mailSuperAdmin(): void
{
    test()->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
}

/** Valores de ejemplo para `MailConfigurator::apply()`. */
function mailValues(array $overrides = []): array
{
    return [
        'host' => 'smtp.ejemplo.com.py', 'port' => 587, 'encryption' => 'tls', 'username' => 'u', 'password' => 'p',
        'from_address' => 'avisos@ejemplo.com.py', 'from_name' => 'Avisos', ...$overrides,
    ];
}

it('de fábrica el correo del panel está apagado y rige el .env', function () {
    expect(MailConfigurator::isEnabled())->toBeFalse();
});

it('el correo del panel solo cuenta como activo con servidor y remitente cargados', function (array $overrides, bool $expected) {
    configureMail($overrides);

    expect(MailConfigurator::isEnabled())->toBe($expected);
})->with([
    'completo' => [[], true],
    'apagado' => [['mail_enabled' => false], false],
    'sin servidor' => [['mail_host' => ''], false],
    'sin remitente' => [['mail_from_address' => ''], false],
]);

it('aplica el SMTP del panel a la configuración de correo', function () {
    MailConfigurator::apply(mailValues());

    expect(config('mail.default'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp.ejemplo.com.py')
        ->and(config('mail.mailers.smtp.port'))->toBe(587)
        ->and(config('mail.mailers.smtp.scheme'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.username'))->toBe('u')
        ->and(config('mail.mailers.smtp.password'))->toBe('p')
        ->and(config('mail.from.address'))->toBe('avisos@ejemplo.com.py')
        ->and(config('mail.from.name'))->toBe('Avisos');
});

it('el cifrado SSL usa conexión cifrada desde el inicio', function () {
    MailConfigurator::apply(mailValues(['encryption' => 'ssl', 'port' => 465]));

    expect(config('mail.mailers.smtp.scheme'))->toBe('smtps')->and(config('mail.mailers.smtp.port'))->toBe(465);
});

it('sin usuario ni clave no manda credenciales vacías y el remitente sin nombre usa el de la app', function () {
    MailConfigurator::apply(mailValues(['username' => '', 'password' => '', 'from_name' => '']));

    expect(config('mail.mailers.smtp.username'))->toBeNull()
        ->and(config('mail.mailers.smtp.password'))->toBeNull()
        ->and(config('mail.from.name'))->toBe(config('app.name'));
});

it('al usar el envío por primera vez aplica los ajustes guardados si el correo está activo', function () {
    configureMail(['mail_host' => 'smtp.activo.com.py']);
    config(['mail.default' => 'log']);

    app()->forgetInstance('mail.manager');
    Mail::clearResolvedInstance('mail.manager');
    app('mail.manager');

    expect(config('mail.default'))->toBe('smtp')->and(config('mail.mailers.smtp.host'))->toBe('smtp.activo.com.py');
});

it('con el correo apagado no toca la configuración del .env', function () {
    configureMail(['mail_enabled' => false]);
    config(['mail.default' => 'log']);

    app()->forgetInstance('mail.manager');
    Mail::clearResolvedInstance('mail.manager');
    app('mail.manager');

    expect(config('mail.default'))->toBe('log');
});

it('la clave se guarda cifrada en la base y se lee en claro desde los ajustes', function () {
    configureMail(['mail_password' => 'secreto-123']);

    $stored = (string) DB::table('settings')->where('group', 'general')->where('name', 'mail_password')->value('payload');

    expect($stored)->not->toContain('secreto-123')->and($stored)->not->toBe('')
        ->and(app(GeneralSettings::class)->mail_password)->toBe('secreto-123');
});

it('el formulario nunca muestra la clave guardada', function () {
    mailSuperAdmin();
    configureMail();

    Livewire::test(ManageGeneralSettings::class)
        ->assertFormSet(['mail_password' => null, 'mail_host' => 'smtp.ejemplo.com.py'])
        ->assertDontSee('secreto-123');
});

it('al guardar con la clave vacía se conserva la guardada', function () {
    mailSuperAdmin();
    configureMail(['mail_password' => 'secreto-123']);

    Livewire::test(ManageGeneralSettings::class)
        ->fillForm(['mail_host' => 'smtp.nuevo.com.py', 'mail_password' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(GeneralSettings::class)->mail_host)->toBe('smtp.nuevo.com.py')
        ->and(app(GeneralSettings::class)->mail_password)->toBe('secreto-123');
});

it('al guardar con una clave nueva la reemplaza', function () {
    mailSuperAdmin();
    configureMail(['mail_password' => 'secreto-123']);

    Livewire::test(ManageGeneralSettings::class)
        ->fillForm(['mail_password' => 'otra-clave'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(GeneralSettings::class)->mail_password)->toBe('otra-clave');
});

it('activar el correo exige servidor y remitente', function () {
    mailSuperAdmin();

    Livewire::test(ManageGeneralSettings::class)
        ->fillForm(['mail_enabled' => true, 'mail_host' => '', 'mail_from_address' => ''])
        ->call('save')
        ->assertHasFormErrors(['mail_host', 'mail_from_address']);
});

it('el correo de prueba usa los datos del formulario y la clave guardada si el campo está vacío', function () {
    mailSuperAdmin();
    configureMail(['mail_password' => 'secreto-123']);

    Mail::shouldReceive('forgetMailers')->once();
    Mail::shouldReceive('raw')->once();

    Livewire::test(ManageGeneralSettings::class)
        ->fillForm(['mail_host' => 'smtp.probando.com.py', 'mail_password' => ''])
        ->callAction('sendTestMail', ['to' => 'prueba@ejemplo.com.py'])
        ->assertNotified('Correo de prueba enviado');

    expect(config('mail.mailers.smtp.host'))->toBe('smtp.probando.com.py')
        ->and(config('mail.mailers.smtp.password'))->toBe('secreto-123');
});

it('el correo de prueba muestra el motivo si el servidor falla y no guarda nada', function () {
    mailSuperAdmin();
    configureMail(['mail_host' => 'smtp.original.com.py']);

    Mail::shouldReceive('forgetMailers')->once();
    Mail::shouldReceive('raw')->once()->andThrow(new RuntimeException('Connection refused'));

    Livewire::test(ManageGeneralSettings::class)
        ->fillForm(['mail_host' => 'smtp.roto.com.py'])
        ->callAction('sendTestMail', ['to' => 'prueba@ejemplo.com.py'])
        ->assertNotified('No se pudo enviar el correo de prueba');

    expect(app(GeneralSettings::class)->mail_host)->toBe('smtp.original.com.py');
});

it('el correo de prueba avisa si faltan servidor o remitente', function () {
    mailSuperAdmin();

    Livewire::test(ManageGeneralSettings::class)
        ->fillForm(['mail_host' => '', 'mail_from_address' => ''])
        ->callAction('sendTestMail', ['to' => 'prueba@ejemplo.com.py'])
        ->assertNotified('Faltan datos');
});
