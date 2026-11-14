<?php

use App\Console\Commands\NotifyPendingApprovals;
use App\Models\Advance;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\MailFailedNotification;
use App\Notifications\PendingApprovalsNotification;
use App\Services\NotificationPreferenceService;
use App\Settings\GeneralSettings;
use App\Support\NotificationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * El correo configurado en el panel suma el correo a los avisos críticos por defecto, un correo que no sale se avisa al
 * Super Admin por la campanita, y quienes aprueban reciben un resumen diario de lo pendiente.
 */
function mailNotificationsSetMail(bool $enabled): void
{
    $settings = app(GeneralSettings::class);
    $settings->mail_enabled = $enabled;
    $settings->mail_host = 'smtp.ejemplo.com.py';
    $settings->mail_from_address = 'avisos@ejemplo.com.py';
    $settings->save();
}

function mailNotificationsAdmin(): User
{
    return tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web'])));
}

/** Notificación que solo viaja por correo (para provocar un fallo del canal). */
function mailOnlyNotification(): Notification
{
    return new class extends Notification
    {
        public function via(object $notifiable): array
        {
            return ['mail'];
        }

        public function toMail(object $notifiable): MailMessage
        {
            return (new MailMessage)->line('Prueba');
        }
    };
}

it('sin el correo del panel, los avisos críticos siguen yendo solo por la campanita', function () {
    mailNotificationsSetMail(false);
    $user = User::factory()->create();

    expect(app(NotificationPreferenceService::class)->resolve($user, 'terminal.offline')['channels'])->toBe(['database']);
});

it('con el correo del panel activo, los avisos críticos suman el correo por defecto', function (string $key) {
    mailNotificationsSetMail(true);
    $user = User::factory()->create();

    expect(app(NotificationPreferenceService::class)->resolve($user, $key)['channels'])->toBe(['database', 'mail']);
})->with(['terminal.offline', 'terminal.unlinked', 'contract.alert']);

it('los demás avisos no suman el correo aunque esté activo', function () {
    mailNotificationsSetMail(true);
    $user = User::factory()->create();

    expect(app(NotificationPreferenceService::class)->resolve($user, 'terminal.battery_low')['channels'])->toBe(['database']);
});

it('quien eligió solo la campanita la conserva cuando se activa el correo', function () {
    $service = app(NotificationPreferenceService::class);
    $user = User::factory()->create();

    mailNotificationsSetMail(true);
    $service->store($user, 'terminal.offline', true, ['database'], 'instant');

    expect($service->resolve($user, 'terminal.offline')['channels'])->toBe(['database']);
});

it('quien no tocó nada recibe el correo por defecto apenas se activa, sin guardar filas', function () {
    $service = app(NotificationPreferenceService::class);
    $user = User::factory()->create();

    mailNotificationsSetMail(true);
    $service->store($user, 'terminal.offline', true, ['database', 'mail'], 'instant');

    expect(NotificationPreference::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('el aviso de pendientes se ofrece solo a quien puede aprobar algo', function () {
    $aprueba = mailNotificationsApprover(['approve_loan']);
    $sinPermiso = mailNotificationsApprover(['view_any_employee']);

    expect(array_keys(NotificationCatalog::forUser($aprueba)))->toContain('approvals.pending')
        ->and(array_keys(NotificationCatalog::forUser($sinPermiso)))->not->toContain('approvals.pending')
        ->and(app(NotificationPreferenceService::class)->channelsFor($sinPermiso, 'approvals.pending'))->toBe([])
        ->and(app(NotificationPreferenceService::class)->channelsFor($aprueba, 'approvals.pending'))->toBe(['database']);
});

it('el catálogo incluye los dos avisos nuevos con su permiso', function () {
    $approvals = NotificationCatalog::get('approvals.pending');
    $failed = NotificationCatalog::get('system.mail_failed');

    expect($approvals['permission'])->toContain('approve_advance', 'approve_loan', 'approve_merchandise_withdrawal', 'approve_employee_leave')
        ->and($approvals['digestable'])->toBeFalse()
        ->and($failed['permission'])->toBe('super-admin')->and($failed['mandatory'])->toBeTrue()
        ->and(NotificationCatalog::categories())->toHaveKeys(['approvals', 'system']);
});

it('un correo que falla se avisa al Super Admin por la campanita', function () {
    $admin = mailNotificationsAdmin();
    $recipient = User::factory()->create();

    event(new NotificationFailed($recipient, mailOnlyNotification(), 'mail', ['exception' => new RuntimeException('Connection refused')]));

    $notification = $admin->unreadNotifications()->where('type', MailFailedNotification::class)->first();

    expect($notification)->not->toBeNull()
        ->and($notification->data['reason'])->toBe('Connection refused')
        ->and($notification->data['body'])->toContain('Connection refused');
});

it('no repite el aviso de fallo mientras el anterior siga sin leer', function () {
    $admin = mailNotificationsAdmin();
    $recipient = User::factory()->create();

    foreach (range(1, 3) as $i) {
        event(new NotificationFailed($recipient, mailOnlyNotification(), 'mail', ['exception' => new RuntimeException("Fallo {$i}")]));
    }

    expect($admin->unreadNotifications()->where('type', MailFailedNotification::class)->count())->toBe(1);

    $admin->unreadNotifications->markAsRead();
    event(new NotificationFailed($recipient, mailOnlyNotification(), 'mail', ['exception' => new RuntimeException('Otro')]));

    expect($admin->fresh()->unreadNotifications()->where('type', MailFailedNotification::class)->count())->toBe(1);
});

it('solo reporta fallos del canal de correo y nunca los de su propio aviso', function () {
    $admin = mailNotificationsAdmin();
    $recipient = User::factory()->create();

    event(new NotificationFailed($recipient, mailOnlyNotification(), 'database', ['exception' => new RuntimeException('x')]));
    event(new NotificationFailed($recipient, new MailFailedNotification('x'), 'mail', ['exception' => new RuntimeException('y')]));

    expect($admin->unreadNotifications()->count())->toBe(0);
});

it('un envío real que falla llega hasta el aviso al Super Admin', function () {
    $admin = mailNotificationsAdmin();
    $recipient = User::factory()->create();

    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => '127.0.0.1',
        'mail.mailers.smtp.port' => 1,
        'mail.mailers.smtp.scheme' => 'smtp',
        'mail.mailers.smtp.url' => null,
    ]);

    expect(fn () => $recipient->notify(mailOnlyNotification()))->toThrow(Exception::class);

    expect($admin->unreadNotifications()->where('type', MailFailedNotification::class)->count())->toBe(1);
});

/** Empleado con un permiso pendiente (el tipo de solicitud más sencillo de crear). */
function mailNotificationsLeave(): EmployeeLeave
{
    static $n = 9950000;
    $n++;

    $company = Company::create(['name' => "EmpMN {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucMN {$n}", 'company_id' => $company->id]);
    $employee = Employee::create(['first_name' => 'Ana', 'last_name' => "Pend{$n}", 'ci' => (string) $n, 'email' => "mn{$n}@test.com", 'branch_id' => $branch->id, 'status' => 'active']);

    return EmployeeLeave::create(['employee_id' => $employee->id, 'type' => 'vacation', 'start_date' => now()->addDays(5), 'end_date' => now()->addDays(7), 'status' => 'pending']);
}

/** Usuario con rol propio y solo los permisos indicados. */
function mailNotificationsApprover(array $permissions): User
{
    $role = Role::create(['name' => 'Aprobador '.uniqid(), 'guard_name' => 'web']);
    $role->syncPermissions(collect($permissions)->map(fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'])));

    return tap(User::factory()->create(), fn (User $user) => $user->assignRole($role));
}

it('el resumen de pendientes llega solo a quien puede aprobar ese tipo', function () {
    mailNotificationsLeave();
    $aprueba = mailNotificationsApprover(['approve_employee_leave']);
    $apruebaOtro = mailNotificationsApprover(['approve_loan']);
    $sinPermiso = mailNotificationsApprover(['view_any_employee']);

    $this->artisan(NotifyPendingApprovals::class)->assertSuccessful();

    $notification = $aprueba->unreadNotifications()->where('type', PendingApprovalsNotification::class)->first();

    expect($notification)->not->toBeNull()
        ->and($notification->data['pending_approvals_count'])->toBe(1)
        ->and($notification->data['title'])->toBe('1 solicitud espera tu aprobación')
        ->and($notification->data['body'])->toBe('1 permiso')
        ->and($apruebaOtro->unreadNotifications()->count())->toBe(0)
        ->and($sinPermiso->unreadNotifications()->count())->toBe(0);
});

it('el resumen cuenta cada tipo y lo muestra solo a quien puede aprobarlo', function () {
    mailNotificationsLeave();
    mailNotificationsLeave();
    $empleado = Employee::first();
    Advance::create(['employee_id' => $empleado->id, 'amount' => 500_000, 'status' => 'pending', 'payment_method' => 'transfer']);
    $todo = mailNotificationsApprover(['approve_employee_leave', 'approve_advance']);
    $soloPermisos = mailNotificationsApprover(['approve_employee_leave']);

    $this->artisan(NotifyPendingApprovals::class)->assertSuccessful();

    expect($todo->unreadNotifications()->first()->data['body'])->toBe('1 adelanto · 2 permisos')
        ->and($todo->unreadNotifications()->first()->data['title'])->toBe('3 solicitudes esperan tu aprobación')
        ->and($soloPermisos->unreadNotifications()->first()->data['body'])->toBe('2 permisos');
});

it('el resumen no se repite mientras el anterior siga sin leer', function () {
    mailNotificationsLeave();
    $aprueba = mailNotificationsApprover(['approve_employee_leave']);

    $this->artisan(NotifyPendingApprovals::class)->assertSuccessful();
    $this->artisan(NotifyPendingApprovals::class)->assertSuccessful();

    expect($aprueba->unreadNotifications()->count())->toBe(1);
});

it('sin solicitudes pendientes no avisa a nadie', function () {
    $aprueba = mailNotificationsApprover(['approve_employee_leave']);

    $this->artisan(NotifyPendingApprovals::class)->expectsOutput('No hay solicitudes pendientes de aprobar.')->assertSuccessful();

    expect($aprueba->unreadNotifications()->count())->toBe(0);
});

it('una solicitud ya resuelta no cuenta como pendiente', function () {
    mailNotificationsLeave()->update(['status' => 'approved']);
    $aprueba = mailNotificationsApprover(['approve_employee_leave']);

    $this->artisan(NotifyPendingApprovals::class)->assertSuccessful();

    expect($aprueba->unreadNotifications()->count())->toBe(0);
});
