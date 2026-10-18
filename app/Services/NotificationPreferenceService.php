<?php

namespace App\Services;

use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\Channels\DigestChannel;
use App\Support\NotificationCatalog;

/**
 * Resuelve qué hacer con un aviso para un usuario: si lo recibe, por qué canales y si llega al
 * instante o en el resumen diario. Combina el valor por defecto del catálogo con la elección
 * guardada del usuario y aplica las reglas fijas (permiso del módulo, avisos obligatorios).
 */
class NotificationPreferenceService
{
    /**
     * Preferencia efectiva de un usuario para una clave del catálogo.
     *
     * @return array{enabled: bool, channels: array<int, string>, frequency: string}
     */
    public function resolve(User $user, string $key): array
    {
        $entry = NotificationCatalog::get($key);

        if ($entry === null) {
            return ['enabled' => true, 'channels' => [NotificationCatalog::CHANNEL_DATABASE], 'frequency' => NotificationCatalog::FREQUENCY_INSTANT];
        }

        $stored = NotificationPreference::query()->where('user_id', $user->id)->where('key', $key)->first();

        $enabled = $stored?->enabled ?? $entry['enabled'];
        $channels = $this->validChannels($stored?->channels ?? $entry['channels']) ?: $entry['channels'];
        $frequency = $stored?->frequency ?? $entry['frequency'];

        if (! array_key_exists($frequency, NotificationCatalog::frequencyOptions())) {
            $frequency = $entry['frequency'];
        }

        if ($entry['mandatory']) {
            $enabled = true;
        }

        if ($entry['mandatory'] || ! $entry['digestable']) {
            $frequency = NotificationCatalog::FREQUENCY_INSTANT;
        }

        return ['enabled' => $enabled, 'channels' => $channels, 'frequency' => $frequency];
    }

    /**
     * Canales para `via()` de una notificación: vacío si no corresponde recibirla, el canal de
     * resumen si el usuario la difiere, o los canales elegidos. Un destinatario que no es un
     * usuario del panel recibe el comportamiento clásico (campanita).
     *
     * @return array<int, string>
     */
    public function channelsFor(object $notifiable, string $key): array
    {
        if (! $notifiable instanceof User) {
            return [NotificationCatalog::CHANNEL_DATABASE];
        }

        $entry = NotificationCatalog::get($key);

        if ($entry !== null && $entry['permission'] !== null && ! $notifiable->can($entry['permission'])) {
            return [];
        }

        $preference = $this->resolve($notifiable, $key);

        if (! $preference['enabled']) {
            return [];
        }

        if ($preference['frequency'] === NotificationCatalog::FREQUENCY_DIGEST) {
            return [DigestChannel::class];
        }

        return $preference['channels'];
    }

    /**
     * Guarda la elección de un usuario para una clave; si coincide con el valor por defecto
     * borra la fila para que los cambios futuros del catálogo le lleguen.
     *
     * @param  array<int, string>  $channels
     */
    public function store(User $user, string $key, bool $enabled, array $channels, string $frequency): void
    {
        $entry = NotificationCatalog::get($key);

        if ($entry === null) {
            return;
        }

        $channels = $this->validChannels($channels) ?: $entry['channels'];

        if ($entry['mandatory']) {
            $enabled = true;
        }

        if ($entry['mandatory'] || ! $entry['digestable']) {
            $frequency = NotificationCatalog::FREQUENCY_INSTANT;
        }

        $isDefault = $enabled === $entry['enabled']
            && $frequency === $entry['frequency']
            && $this->sameChannels($channels, $entry['channels']);

        if ($isDefault) {
            NotificationPreference::query()->where('user_id', $user->id)->where('key', $key)->delete();

            return;
        }

        NotificationPreference::query()->updateOrCreate(
            ['user_id' => $user->id, 'key' => $key],
            ['enabled' => $enabled, 'channels' => array_values($channels), 'frequency' => $frequency],
        );
    }

    /**
     * Quita las elecciones guardadas del usuario (vuelve a los valores por defecto).
     */
    public function reset(User $user): void
    {
        NotificationPreference::query()->where('user_id', $user->id)->delete();
    }

    /**
     * @param  array<int, string>  $channels
     * @return array<int, string>
     */
    private function validChannels(array $channels): array
    {
        return array_values(array_intersect(array_keys(NotificationCatalog::channelOptions()), $channels));
    }

    /**
     * @param  array<int, string>  $a
     * @param  array<int, string>  $b
     */
    private function sameChannels(array $a, array $b): bool
    {
        sort($a);
        sort($b);

        return $a === $b;
    }
}
