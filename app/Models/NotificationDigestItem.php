<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aviso retenido para el resumen diario de un usuario (frecuencia «resumen»). El comando
 * `notifications:send-digests` los agrupa en una sola notificación y los elimina.
 *
 * @property int $user_id
 * @property string $key
 * @property array<int, string> $channels
 * @property string $title
 * @property string|null $body
 * @property string|null $url
 */
class NotificationDigestItem extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'key', 'channels', 'title', 'body', 'url'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['channels' => 'array'];
    }

    /** Usuario al que pertenece el aviso retenido. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
