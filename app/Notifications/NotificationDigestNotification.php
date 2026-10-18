<?php

namespace App\Notifications;

use App\Models\NotificationDigestItem;
use App\Support\NotificationCatalog;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Resumen diario con los avisos que el usuario eligió recibir «en el resumen» (ver
 * `notifications:send-digests`). Llega por la campanita y/o por correo según el canal de cada aviso.
 */
class NotificationDigestNotification extends Notification
{
    use Queueable;

    /** Máximo de avisos detallados en el correo; el resto se resume en una línea. */
    public const MAIL_MAX_LINES = 50;

    /**
     * @param  Collection<int, NotificationDigestItem>  $databaseItems  Avisos que van a la campanita.
     * @param  Collection<int, NotificationDigestItem>  $mailItems  Avisos que van por correo.
     */
    public function __construct(
        public readonly Collection $databaseItems,
        public readonly Collection $mailItems,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return array_values(array_filter([
            $this->databaseItems->isNotEmpty() ? NotificationCatalog::CHANNEL_DATABASE : null,
            $this->mailItems->isNotEmpty() ? NotificationCatalog::CHANNEL_MAIL : null,
        ]));
    }

    /**
     * Se arma con el builder de Filament para que la campanita lo muestre (ver
     * `TerminalProvisionedNotification::toDatabase()`).
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $total = $this->databaseItems->count();

        return FilamentNotification::make()
            ->title("Resumen de avisos ({$total})")
            ->body($this->summaryLine($this->databaseItems))
            ->icon('heroicon-o-inbox-stack')
            ->info()
            ->getDatabaseMessage();
    }

    /** Correo con el detalle de cada aviso retenido. */
    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Resumen de avisos de Nominapp ({$this->mailItems->count()})")
            ->line($this->summaryLine($this->mailItems));

        foreach ($this->mailItems->take(self::MAIL_MAX_LINES) as $item) {
            $mail->line('• '.$item->title.($item->body ? ' — '.$item->body : ''));
        }

        $remaining = $this->mailItems->count() - self::MAIL_MAX_LINES;

        if ($remaining > 0) {
            $mail->line("… y {$remaining} más.");
        }

        return $mail;
    }

    /**
     * «3 × Terminal sin conexión · 1 × Jornadas sin salida».
     *
     * @param  Collection<int, NotificationDigestItem>  $items
     */
    private function summaryLine(Collection $items): string
    {
        return $items
            ->groupBy('key')
            ->map(fn (Collection $group, string $key) => $group->count().' × '.(NotificationCatalog::get($key)['label'] ?? $key))
            ->implode(' · ');
    }
}
