<?php

namespace App\Console\Commands;

use App\Models\NotificationDigestItem;
use App\Models\User;
use App\Notifications\NotificationDigestNotification;
use App\Support\NotificationCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Envía el resumen diario de avisos: junta en una sola notificación por usuario lo que quedó
 * retenido en `notification_digest_items` y lo entrega a la hora que cada usuario eligió
 * (`users.notification_digest_hour`). Corre cada hora; solo procesa a quienes les toca esa hora.
 */
class SendNotificationDigests extends Command
{
    /** Días tras los cuales un aviso retenido que nunca se entregó se descarta. */
    public const RETENTION_DAYS = 14;

    protected $signature = 'notifications:send-digests {--hour= : Hora a procesar (0-23); por defecto la actual} {--force : Enviar a todos sin importar su hora}';

    protected $description = 'Envía el resumen diario de avisos a los usuarios que lo eligieron';

    public function handle(): int
    {
        NotificationDigestItem::query()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS))->delete();

        $hour = $this->option('hour') !== null ? (int) $this->option('hour') : now()->hour;

        $users = User::query()
            ->whereIn('id', NotificationDigestItem::query()->select('user_id'))
            ->when(! $this->option('force'), fn ($query) => $query->where('notification_digest_hour', $hour))
            ->get();

        $sent = 0;

        foreach ($users as $user) {
            $items = NotificationDigestItem::query()->where('user_id', $user->id)->orderBy('id')->get();

            $notification = new NotificationDigestNotification(
                $items->filter(fn ($item) => in_array(NotificationCatalog::CHANNEL_DATABASE, $item->channels, true))->values(),
                $items->filter(fn ($item) => in_array(NotificationCatalog::CHANNEL_MAIL, $item->channels, true))->values(),
            );

            try {
                $user->notify($notification);
            } catch (\Throwable $e) {
                Log::warning("No se pudo enviar el resumen de avisos al usuario #{$user->id}: {$e->getMessage()}");

                continue;
            }

            NotificationDigestItem::query()->whereIn('id', $items->pluck('id'))->delete();
            $sent++;
        }

        $this->info("Resumen enviado a {$sent} usuario(s).");

        return self::SUCCESS;
    }
}
