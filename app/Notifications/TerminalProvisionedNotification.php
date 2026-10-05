<?php

namespace App\Notifications;

use App\Filament\Resources\TerminalResource;
use App\Models\Terminal;
use Filament\Notifications\Actions\Action as FilamentAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notificación a los admins cuando un terminal reclama el enlace de
 * configuración de un solo uso y recibe su token Sanctum.
 *
 * No confirma que la instalación física haya salido bien — solo que el
 * servidor emitió el token. Si la respuesta HTTP nunca llega al dispositivo
 * (ej. se corta la red justo después de que el servidor la generó), esta
 * notificación se dispara igual aunque el terminal físico se haya quedado
 * sin token — por eso el wording pide verificar Conectividad en vez de dar
 * la instalación por confirmada. Ver columna "Conectividad" en
 * `TerminalResource` (`never_connected` hasta el primer heartbeat exitoso; `unlinked` si no tiene token vigente).
 */
class TerminalProvisionedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Terminal $terminal,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * Envía por email a los admins además de la campanita — confirma la
     * instalación física del terminal (o su reprovisión) sin depender de que
     * alguien revise Filament a tiempo.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Terminal provisionado — '.$this->terminal->name)
            ->line("El terminal \"{$this->terminal->name}\" ({$this->terminal->branch?->name}) reclamó su token de sincronización.")
            ->line('Verificá el estado de Conectividad en unos minutos para confirmar que el dispositivo físico quedó sincronizando con normalidad.')
            ->action('Ver terminal', TerminalResource::getUrl('view', ['record' => $this->terminal]));
    }

    /**
     * Se arma con el builder de `Filament\Notifications\Notification` (no un
     * array plano) porque la campanita del panel filtra explícitamente por
     * `data->format = 'filament'` (ver
     * `Filament\Notifications\Livewire\DatabaseNotifications::getNotificationsQuery()`)
     * — un array a mano sin esa clave se guarda en la tabla `notifications`
     * sin error, pero la campanita nunca lo muestra.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            ...FilamentNotification::make()
                ->title('Terminal provisionado')
                ->body("El terminal \"{$this->terminal->name}\" ({$this->terminal->branch?->name}) reclamó su token de sincronización. Verificá Conectividad en unos minutos para confirmar que quedó sincronizando.")
                ->icon('heroicon-o-computer-desktop')
                ->success()
                ->actions([
                    FilamentAction::make('view')->label('Ver terminal')->url(TerminalResource::getUrl('view', ['record' => $this->terminal])),
                ])
                ->getDatabaseMessage(),
            'terminal_id' => $this->terminal->id,
        ];
    }
}
