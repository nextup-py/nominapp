<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evento de bitácora de un terminal: solo cambios de estado (vinculación
 * solicitada/aprobada/rechazada/reclamada, revocación...), nunca un registro
 * por heartbeat. `actor_id` es el usuario del panel que provocó el cambio
 * (null cuando lo origina el propio dispositivo o un comando programado).
 */
class TerminalEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['terminal_id', 'type', 'payload', 'actor_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    /** Terminal al que pertenece el evento. */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    /** Usuario del panel que originó el evento, si lo hubo. */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Registra un evento del terminal.
     *
     * @param  array<string, mixed>|null  $payload
     */
    public static function record(Terminal $terminal, string $type, ?array $payload = null, ?int $actorId = null): self
    {
        return static::create([
            'terminal_id' => $terminal->id,
            'type' => $type,
            'payload' => $payload,
            'actor_id' => $actorId,
        ]);
    }
}
