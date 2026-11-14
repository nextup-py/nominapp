<?php

namespace App\Listeners;

use App\Models\User;
use App\Notifications\MailFailedNotification;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Avisa al Super Admin cuando el canal de correo falla al enviar una notificación. Laravel dispara `NotificationFailed`
 * apenas el canal lanza una excepción, así que un solo oyente cubre todos los avisos (antes cada punto de envío solo lo
 * registraba en el log y nadie se enteraba de que los correos no salían). El aviso va por la campanita y nunca se
 * reporta a sí mismo, para no entrar en un ciclo si el propio servidor de correo es el que cayó.
 */
class ReportMailFailure
{
    public function handle(NotificationFailed $event): void
    {
        if ($event->channel !== 'mail' || $event->notification instanceof MailFailedNotification) {
            return;
        }

        $exception = $event->data['exception'] ?? null;
        $message = $exception instanceof Throwable ? $exception->getMessage() : ($event->data['message'] ?? '');
        $reason = str($message !== '' ? (string) $message : 'error desconocido')->squish()->limit(200)->toString();

        Log::warning("Falló el envío de correo ({$reason}).");

        try {
            foreach (User::role('Super Admin')->get() as $admin) {
                $alreadyPending = $admin->unreadNotifications()->where('type', MailFailedNotification::class)->exists();

                if (! $alreadyPending) {
                    $admin->notify(new MailFailedNotification($reason));
                }
            }
        } catch (Throwable $e) {
            Log::warning('No se pudo avisar del fallo del correo: '.$e->getMessage());
        }
    }
}
