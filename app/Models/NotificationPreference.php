<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Elección de un usuario para un tipo de aviso (ver `NotificationCatalog`): si lo recibe, por
 * qué canales y si llega al instante o en el resumen diario. Solo se guardan las que difieren
 * del valor por defecto del catálogo.
 *
 * @property int $user_id
 * @property string $key
 * @property bool $enabled
 * @property array<int, string> $channels
 * @property string $frequency
 */
class NotificationPreference extends Model
{
    protected $fillable = ['user_id', 'key', 'enabled', 'channels', 'frequency'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'channels' => 'array',
        ];
    }

    /** Usuario dueño de la preferencia. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
