<?php

namespace App\Notifications\Channels;

use App\Models\NotificationDigestItem;
use App\Models\User;
use App\Notifications\Concerns\HonorsPreferences;
use App\Services\NotificationPreferenceService;
use Illuminate\Notifications\Notification;

/**
 * Canal de «resumen»: en vez de entregar el aviso, lo retiene en `notification_digest_items`
 * para que `notifications:send-digests` lo agrupe con el resto a la hora elegida por el usuario.
 */
class DigestChannel
{
    public function __construct(private readonly NotificationPreferenceService $preferences) {}

    /**
     * @param  Notification&HonorsPreferences  $notification
     */
    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! method_exists($notification, 'preferenceKey') || ! method_exists($notification, 'toDatabase')) {
            return;
        }

        $key = $notification->preferenceKey();
        $data = $notification->toDatabase($notifiable);
        $action = $data['actions'][0] ?? null;

        NotificationDigestItem::query()->create([
            'user_id' => $notifiable->id,
            'key' => $key,
            'channels' => $this->preferences->resolve($notifiable, $key)['channels'],
            'title' => (string) ($data['title'] ?? $key),
            'body' => $data['body'] ?? null,
            'url' => is_array($action) ? ($action['url'] ?? null) : null,
        ]);
    }
}
