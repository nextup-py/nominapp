<?php

use App\Filament\Pages\MyNotifications;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\NotificationDigestItem;
use App\Models\NotificationPreference;
use App\Models\Position;
use App\Models\Terminal;
use App\Models\TerminalEvent;
use App\Models\User;
use App\Notifications\ContractAlertNotification;
use App\Notifications\NotificationDigestNotification;
use App\Notifications\TerminalHealthNotification;
use App\Services\NotificationPreferenceService;
use App\Services\TerminalHealthService;
use App\Settings\GeneralSettings;
use App\Support\NotificationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

// ─── Helpers ────────────────────────────────────────────────────────────────

/** Usuario del panel (con un rol, para entrar a /admin) y los permisos indicados. */
function prefsUser(string ...$permissions): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'Soporte', 'guard_name' => 'web']));

    foreach ($permissions as $permission) {
        $user->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
    }

    return $user;
}

function prefsTerminal(): Terminal
{
    static $n = 8800000;
    $n++;

    $company = Company::create(['name' => "Empresa Prefs {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "Sucursal Prefs {$n}", 'company_id' => $company->id]);

    return Terminal::create(['name' => "Terminal Prefs {$n}", 'branch_id' => $branch->id]);
}

// ─── Catálogo ───────────────────────────────────────────────────────────────

it('cada notificación declara una clave que existe en el catálogo y no sobra ninguna entrada', function () {
    $used = [];

    foreach (glob(app_path('Notifications/*Notification.php')) as $file) {
        $source = file_get_contents($file);

        if (! str_contains($source, 'HonorsPreferences')) {
            continue;
        }

        preg_match("/function preferenceKey\(\): string\s*\{\s*return ([^;]+);/", $source, $match);
        $expression = $match[1] ?? null;

        if ($expression === '"terminal.{$this->alert}"') {
            array_push($used, ...array_map(fn ($alert) => "terminal.{$alert}", ['offline', 'recovered', 'unlinked', 'queue_stuck', 'battery_low', 'no_enrolled', 'cache_empty', 'clock_skew', 'app_outdated', 'not_installed', 'tab_hidden']));

            continue;
        }

        expect($expression)->not->toBeNull(basename($file).' no declara preferenceKey()');
        $used[] = trim($expression, "'");
    }

    foreach (array_unique($used) as $key) {
        expect(NotificationCatalog::get($key))->not->toBeNull("La clave {$key} no está en el catálogo");
    }

    expect(array_values(array_unique($used)))->toEqualCanonicalizing(array_keys(NotificationCatalog::all()));
});

it('cada entrada del catálogo pertenece a una categoría conocida y tiene valores por defecto válidos', function () {
    foreach (NotificationCatalog::all() as $key => $entry) {
        expect(NotificationCatalog::categories())->toHaveKey($entry['category']);
        expect($entry['channels'])->not->toBeEmpty()
            ->and(array_diff($entry['channels'], array_keys(NotificationCatalog::channelOptions())))->toBe([])
            ->and(NotificationCatalog::frequencyOptions())->toHaveKey($entry['frequency']);
    }
});

// ─── Resolución de preferencias ─────────────────────────────────────────────

it('sin elecciones guardadas usa los valores por defecto del catálogo', function () {
    $user = prefsUser('update_terminal');
    $service = app(NotificationPreferenceService::class);

    expect($service->resolve($user, 'terminal.offline'))->toBe(['enabled' => true, 'channels' => ['database'], 'frequency' => 'instant'])
        ->and($service->resolve($user, 'terminal.recovered')['enabled'])->toBeFalse()
        ->and($service->resolve($user, 'terminal.provisioned')['channels'])->toBe(['database', 'mail']);
});

it('guardar la elección por defecto borra la fila y una distinta la crea', function () {
    $user = prefsUser('update_terminal');
    $service = app(NotificationPreferenceService::class);

    $service->store($user, 'terminal.offline', false, ['database'], 'instant');
    expect(NotificationPreference::where('user_id', $user->id)->count())->toBe(1)
        ->and($service->resolve($user, 'terminal.offline')['enabled'])->toBeFalse();

    $service->store($user, 'terminal.offline', true, ['database'], 'instant');
    expect(NotificationPreference::where('user_id', $user->id)->count())->toBe(0);
});

it('un aviso obligatorio no se puede apagar ni diferir, pero sí cambiar de canal', function () {
    $user = prefsUser('update_terminal');
    $service = app(NotificationPreferenceService::class);

    $service->store($user, 'terminal.unlinked', false, ['mail'], 'digest');

    expect($service->resolve($user, 'terminal.unlinked'))->toBe(['enabled' => true, 'channels' => ['mail'], 'frequency' => 'instant'])
        ->and($service->channelsFor($user, 'terminal.unlinked'))->toBe(['mail']);
});

it('solo ofrece los avisos de los módulos a los que el usuario tiene acceso', function () {
    $terminals = prefsUser('update_terminal');
    $contracts = prefsUser('view_any_contract');
    $nobody = prefsUser();

    expect(array_keys(NotificationCatalog::forUser($terminals)))->toContain('terminal.offline')->not->toContain('contract.alert')
        ->and(array_keys(NotificationCatalog::forUser($contracts)))->toBe(['contract.alert'])
        ->and(NotificationCatalog::forUser($nobody))->toBe([]);

    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

    expect(NotificationCatalog::forUser($admin))->toHaveCount(count(NotificationCatalog::all()));
});

// ─── Despacho ───────────────────────────────────────────────────────────────

it('el aviso llega a quien tiene el permiso y no a quien no lo tiene', function () {
    $allowed = prefsUser('update_terminal');
    $denied = prefsUser('view_any_contract');
    $notification = new TerminalHealthNotification(prefsTerminal(), 'offline');

    $allowed->notify($notification);
    $denied->notify($notification);

    expect($allowed->notifications()->count())->toBe(1)
        ->and($denied->notifications()->count())->toBe(0);
});

it('si el usuario apaga un aviso no le llega', function () {
    $user = prefsUser('update_terminal');
    app(NotificationPreferenceService::class)->store($user, 'terminal.battery_low', false, ['database'], 'instant');

    $user->notify(new TerminalHealthNotification(prefsTerminal(), 'battery_low'));
    $user->notify(new TerminalHealthNotification(prefsTerminal(), 'offline'));

    expect($user->notifications()->count())->toBe(1);
});

it('«Terminal reconectado» no llega por defecto pero sí si el usuario lo activa', function () {
    $user = prefsUser('update_terminal');
    $notification = new TerminalHealthNotification(prefsTerminal(), 'recovered');

    $user->notify($notification);
    expect($user->notifications()->count())->toBe(0);

    app(NotificationPreferenceService::class)->store($user, 'terminal.recovered', true, ['database'], 'instant');
    $user->notify($notification);
    expect($user->notifications()->count())->toBe(1);
});

it('respeta el canal elegido: correo, campanita o ambos', function () {
    Notification::fake();
    $user = prefsUser('update_terminal');
    $service = app(NotificationPreferenceService::class);
    $notification = new TerminalHealthNotification(prefsTerminal(), 'offline');

    $service->store($user, 'terminal.offline', true, ['mail'], 'instant');
    expect($notification->via($user))->toBe(['mail']);

    $service->store($user, 'terminal.offline', true, ['database', 'mail'], 'instant');
    expect($notification->via($user))->toBe(['database', 'mail']);
});

it('el correo genérico usa el título, el texto y el primer enlace de la campanita', function () {
    $user = prefsUser('update_terminal');
    $mail = (new TerminalHealthNotification(prefsTerminal(), 'offline'))->toMail($user);

    expect($mail->subject)->toBe('Terminal sin conexión')
        ->and($mail->actionText)->toBe('Ver terminal')
        ->and($mail->actionUrl)->toContain('/terminales/');
});

it('con frecuencia «resumen» el aviso se retiene y no llega a la campanita', function () {
    $user = prefsUser('update_terminal');
    app(NotificationPreferenceService::class)->store($user, 'terminal.offline', true, ['database'], 'digest');

    $user->notify(new TerminalHealthNotification(prefsTerminal(), 'offline'));

    expect($user->notifications()->count())->toBe(0);

    $item = NotificationDigestItem::where('user_id', $user->id)->sole();
    expect($item->key)->toBe('terminal.offline')
        ->and($item->title)->toBe('Terminal sin conexión')
        ->and($item->channels)->toBe(['database'])
        ->and($item->url)->toContain('/terminales/');
});

// ─── Resumen diario ─────────────────────────────────────────────────────────

function digestItem(User $user, string $key = 'terminal.offline', array $channels = ['database']): NotificationDigestItem
{
    return NotificationDigestItem::create([
        'user_id' => $user->id, 'key' => $key, 'channels' => $channels, 'title' => 'Terminal sin conexión', 'body' => 'Detalle', 'url' => null,
    ]);
}

it('envía un solo resumen por usuario a la hora que eligió y vacía su cola', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-18 09:10:00', config('app.timezone')));
    $morning = prefsUser('update_terminal');
    $morning->forceFill(['notification_digest_hour' => 9])->save();
    $evening = prefsUser('update_terminal');
    $evening->forceFill(['notification_digest_hour' => 18])->save();

    digestItem($morning);
    digestItem($morning);
    digestItem($morning, 'terminal.battery_low');
    digestItem($evening);

    $this->artisan('notifications:send-digests')->assertSuccessful();

    $notification = $morning->notifications()->sole();
    expect($notification->data['title'])->toBe('Resumen de avisos (3)')
        ->and($notification->data['body'])->toContain('2 × Terminal sin conexión')->toContain('1 × Batería baja')
        ->and(NotificationDigestItem::where('user_id', $morning->id)->count())->toBe(0)
        ->and($evening->notifications()->count())->toBe(0)
        ->and(NotificationDigestItem::where('user_id', $evening->id)->count())->toBe(1);
});

it('el resumen respeta el canal: lo que eligió correo sale por correo y lo demás por la campanita', function () {
    Notification::fake();
    $user = prefsUser('update_terminal');
    $user->forceFill(['notification_digest_hour' => 8])->save();
    digestItem($user, 'terminal.offline', ['database']);
    digestItem($user, 'terminal.battery_low', ['mail']);

    $this->artisan('notifications:send-digests', ['--force' => true])->assertSuccessful();

    Notification::assertSentTo($user, NotificationDigestNotification::class, function ($notification, array $channels) {
        return $channels === ['database', 'mail']
            && $notification->databaseItems->count() === 1
            && $notification->mailItems->count() === 1;
    });
});

it('descarta los avisos retenidos que llevan más de 14 días sin entregarse', function () {
    $user = prefsUser('update_terminal');
    $user->forceFill(['notification_digest_hour' => 3])->save();
    $old = digestItem($user);
    $old->forceFill(['created_at' => now()->subDays(15)])->save();

    $this->artisan('notifications:send-digests', ['--force' => true])->assertSuccessful();

    expect(NotificationDigestItem::count())->toBe(0)
        ->and($user->notifications()->count())->toBe(0);
});

// ─── Página «Mis notificaciones» ────────────────────────────────────────────

it('la página abre para cualquier usuario del panel y muestra solo sus categorías', function () {
    $user = prefsUser('update_terminal');
    app(GeneralSettings::class)->setup_completed = true;
    app(GeneralSettings::class)->save();
    $this->actingAs($user);

    $this->get(MyNotifications::getUrl())->assertOk()->assertSee('Mis notificaciones');

    Livewire::actingAs($user)->test(MyNotifications::class)
        ->assertSee('Terminal sin conexión')
        ->assertSee('Salud de terminales')
        ->assertDontSee('Contratos por vencer o vencidos');
});

it('guarda las elecciones del usuario y la hora del resumen', function () {
    $user = prefsUser('update_terminal');

    Livewire::actingAs($user)->test(MyNotifications::class)
        ->set('data.digest_hour', 17)
        ->set('data.rows.terminal__battery_low.enabled', false)
        ->set('data.rows.terminal__offline.frequency', 'digest')
        ->call('save')
        ->assertHasNoFormErrors();

    $service = app(NotificationPreferenceService::class);
    expect($user->fresh()->notification_digest_hour)->toBe(17)
        ->and($service->resolve($user, 'terminal.battery_low')['enabled'])->toBeFalse()
        ->and($service->resolve($user, 'terminal.offline')['frequency'])->toBe('digest');
});

it('no deja guardar un aviso activo sin ningún canal', function () {
    $user = prefsUser('update_terminal');

    Livewire::actingAs($user)->test(MyNotifications::class)
        ->set('data.rows.terminal__offline.channels', [])
        ->call('save')
        ->assertHasFormErrors(['rows.terminal__offline.channels' => 'required']);
});

it('el aviso obligatorio se muestra bloqueado y no se puede apagar desde la página', function () {
    $user = prefsUser('update_terminal');

    Livewire::actingAs($user)->test(MyNotifications::class)
        ->set('data.rows.terminal__unlinked.enabled', false)
        ->set('data.rows.terminal__unlinked.frequency', 'digest')
        ->call('save');

    expect(app(NotificationPreferenceService::class)->resolve($user, 'terminal.unlinked'))
        ->toMatchArray(['enabled' => true, 'frequency' => 'instant']);
});

it('restablecer vuelve a los valores por defecto y a las 08:00', function () {
    $user = prefsUser('update_terminal');
    $user->forceFill(['notification_digest_hour' => 20])->save();
    app(NotificationPreferenceService::class)->store($user, 'terminal.offline', false, ['database'], 'instant');

    Livewire::actingAs($user)->test(MyNotifications::class)
        ->callAction('reset');

    expect(NotificationPreference::where('user_id', $user->id)->count())->toBe(0)
        ->and($user->fresh()->notification_digest_hour)->toBe(NotificationCatalog::DEFAULT_DIGEST_HOUR);
});

it('el usuario sin avisos configurables ve un mensaje en lugar de categorías', function () {
    Livewire::actingAs(prefsUser())->test(MyNotifications::class)
        ->assertSee('Tu rol no recibe avisos que se puedan configurar.');
});

it('la entrada «Mis notificaciones» está en el menú de usuario del panel', function () {
    $items = filament()->getPanel('admin')->getUserMenuItems();

    $this->actingAs(prefsUser());

    expect($items)->not->toBeEmpty();
    expect(collect($items)->map(fn ($item) => $item->getLabel())->all())->toContain('Mis notificaciones');
});

// ─── Comportamiento de los avisos de terminales ─────────────────────────────

it('al reconectarse el terminal se marca como leído su aviso de desconexión', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-12 10:00:00', config('app.timezone')));
    $manager = prefsUser('update_terminal');
    $terminal = prefsTerminal();
    $terminal->claimSanctumToken();
    $terminal->update(['stale_after_minutes' => 10, 'last_heartbeat_at' => now()->subMinute()]);
    $service = app(TerminalHealthService::class);

    $service->evaluate($terminal->fresh()); // línea de base: en línea

    $terminal->update(['last_heartbeat_at' => now()->subMinutes(20)]);
    expect($service->evaluate($terminal->fresh()))->toBe(['offline'])
        ->and($manager->unreadNotifications()->where('type', TerminalHealthNotification::class)->count())->toBe(1);

    $terminal->update(['last_heartbeat_at' => now()]);
    expect($service->evaluate($terminal->fresh()))->toBe(['recovered'])
        ->and($manager->unreadNotifications()->where('type', TerminalHealthNotification::class)->count())->toBe(0)
        ->and($manager->notifications()->where('type', TerminalHealthNotification::class)->count())->toBe(1);
});

it('la reconexión de un terminal no marca como leído el aviso de otro terminal', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-12 10:00:00', config('app.timezone')));
    $manager = prefsUser('update_terminal');
    $manager->notify(new TerminalHealthNotification(prefsTerminal(), 'offline'));

    $terminal = prefsTerminal();
    $terminal->claimSanctumToken();
    $terminal->update(['stale_after_minutes' => 10, 'last_heartbeat_at' => now()->subMinute()]);
    $service = app(TerminalHealthService::class);
    $service->evaluate($terminal->fresh());
    $terminal->update(['last_heartbeat_at' => now()->subMinutes(20)]);
    $service->evaluate($terminal->fresh());
    $terminal->update(['last_heartbeat_at' => now()]);
    $service->evaluate($terminal->fresh());

    expect($manager->unreadNotifications()->where('type', TerminalHealthNotification::class)->count())->toBe(1);
});

it('quien abrió la ventana de vinculación no recibe el aviso de que el terminal se provisionó', function () {
    $opener = prefsUser('update_terminal');
    $colleague = prefsUser('update_terminal');
    $terminal = prefsTerminal();

    $terminal->openLinkWindow($opener);
    $terminal->claimSanctumToken(via: 'pairing');

    expect($opener->notifications()->count())->toBe(0)
        ->and($colleague->notifications()->count())->toBe(1);
});

it('quien generó el enlace de configuración tampoco recibe el aviso', function () {
    $author = prefsUser('update_terminal');
    $colleague = prefsUser('update_terminal');
    $terminal = prefsTerminal();

    TerminalEvent::record($terminal, 'setup_link_generated', ['expires_in_minutes' => 30], $author->id);
    $terminal->claimSanctumToken();

    expect($author->notifications()->count())->toBe(0)
        ->and($colleague->notifications()->count())->toBe(1);
});

// ─── Avisos que ya llegan una vez al día ────────────────────────────────────

it('los avisos que ya son un resumen diario no se pueden diferir al resumen', function () {
    $user = prefsUser('view_any_attendance_day');
    $service = app(NotificationPreferenceService::class);

    expect(NotificationCatalog::get('attendance.open_shifts')['digestable'])->toBeFalse();

    $service->store($user, 'attendance.open_shifts', true, ['database'], 'digest');

    expect($service->resolve($user, 'attendance.open_shifts')['frequency'])->toBe('instant')
        ->and(NotificationPreference::where('user_id', $user->id)->count())->toBe(0);
});

it('la pantalla bloquea la frecuencia de los avisos que ya llegan una vez al día', function () {
    $user = prefsUser('view_any_contract');

    Livewire::actingAs($user)->test(MyNotifications::class)
        ->assertSee('Ya llega una vez al día.');
});

// ─── Aviso diario de contratos por vencer ───────────────────────────────────

it('el aviso de contratos no se duplica aunque el primer usuario no lo reciba', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:00', config('app.timezone')));

    // El primer usuario (el que antes servía de referencia) no tiene permiso sobre contratos.
    $withoutAccess = prefsUser();
    $withAccess = prefsUser('view_any_contract');
    expect($withoutAccess->id)->toBeLessThan($withAccess->id);

    $company = Company::create(['name' => 'Empresa Contratos', 'ruc' => '8800100-1', 'employer_number' => 8800100]);
    $branch = Branch::create(['name' => 'Sucursal Contratos', 'company_id' => $company->id]);
    $department = Department::create(['name' => 'Depto Contratos', 'company_id' => $company->id]);
    $position = Position::create(['name' => 'Cargo Contratos', 'department_id' => $department->id]);
    $employee = Employee::create([
        'first_name' => 'Notif', 'last_name' => 'Contrato', 'ci' => '8800100', 'birth_date' => '1990-05-15',
        'email' => 'contrato@test.com', 'branch_id' => $branch->id, 'status' => 'active',
    ]);
    Contract::create([
        'employee_id' => $employee->id, 'type' => 'plazo_fijo', 'start_date' => now()->subMonths(6),
        'end_date' => now()->addDays(10), 'salary_type' => 'mensual', 'salary' => 2_550_000,
        'position_id' => $position->id, 'department_id' => $department->id, 'status' => 'active',
    ]);

    $this->artisan('contracts:notify-expiring')->assertSuccessful();
    $this->artisan('contracts:notify-expiring')->assertSuccessful();

    expect($withAccess->notifications()->where('type', ContractAlertNotification::class)->count())->toBe(1)
        ->and($withoutAccess->notifications()->count())->toBe(0);
});
