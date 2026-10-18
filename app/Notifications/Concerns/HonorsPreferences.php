<?php

namespace App\Notifications\Concerns;

use App\Services\NotificationPreferenceService;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Hace que una notificación respete las preferencias del destinatario (ver
 * `NotificationCatalog` y `NotificationPreferenceService`): `via()` devuelve los canales que
 * eligió, ninguno si no la quiere o no tiene el permiso del módulo, o el canal de resumen.
 *
 * La clase solo declara su clave de catálogo. El correo se arma a partir del mismo mensaje de
 * la campanita (título, texto y primer enlace); una clase puede definir su propio `toMail()`.
 */
trait HonorsPreferences
{
    /** Clave de esta notificación en `NotificationCatalog`. */
    abstract public function preferenceKey(): string;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return app(NotificationPreferenceService::class)->channelsFor($notifiable, $this->preferenceKey());
    }

    /** Correo genérico con el mismo contenido que la campanita. */
    public function toMail(object $notifiable): MailMessage
    {
        $data = $this->toDatabase($notifiable);

        $mail = (new MailMessage)->subject((string) ($data['title'] ?? 'Aviso de Nominapp'));

        if (filled($data['body'] ?? null)) {
            $mail->line((string) $data['body']);
        }

        $action = $data['actions'][0] ?? null;

        if (is_array($action) && filled($action['url'] ?? null)) {
            $mail->action((string) ($action['label'] ?: 'Ver detalle'), (string) $action['url']);
        }

        return $mail;
    }
}
