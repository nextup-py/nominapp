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

    /**
     * Etiquetas en español de cada tipo de evento de la bitácora.
     *
     * @return array<string, string>
     */
    public static function getTypeLabels(): array
    {
        return [
            'pairing_requested' => 'Solicitud de vinculación',
            'pairing_approved' => 'Vinculación aprobada',
            'pairing_auto_approved' => 'Vinculación aprobada por ventana',
            'pairing_denied' => 'Vinculación rechazada',
            'pairing_claimed' => 'Código canjeado por el dispositivo',
            'link_window_opened' => 'Ventana de vinculación abierta',
            'link_window_closed' => 'Ventana de vinculación cerrada',
            'setup_link_generated' => 'Enlace de configuración generado',
            'linked' => 'Dispositivo vinculado',
            'revoked' => 'Dispositivo desvinculado',
            'health_offline' => 'Sin conexión',
            'health_recovered' => 'Reconectado',
            'health_unlinked' => 'Quedó sin vincular',
            'health_queue_stuck' => 'Cola de marcaciones atascada',
            'health_queue_recovered' => 'Cola de marcaciones normalizada',
            'health_battery_low' => 'Batería baja',
            'health_battery_ok' => 'Batería recuperada',
        ];
    }

    /**
     * Colores de badge por tipo de evento.
     *
     * @return array<string, string>
     */
    public static function getTypeColors(): array
    {
        return [
            'pairing_requested' => 'warning',
            'pairing_approved' => 'success',
            'pairing_auto_approved' => 'success',
            'pairing_denied' => 'danger',
            'pairing_claimed' => 'info',
            'link_window_opened' => 'warning',
            'link_window_closed' => 'gray',
            'setup_link_generated' => 'info',
            'linked' => 'success',
            'revoked' => 'danger',
            'health_offline' => 'danger',
            'health_recovered' => 'success',
            'health_unlinked' => 'danger',
            'health_queue_stuck' => 'warning',
            'health_queue_recovered' => 'success',
            'health_battery_low' => 'warning',
            'health_battery_ok' => 'success',
        ];
    }

    /** Detalle legible del evento (IP, vigencia, motivo...) a partir de su payload. */
    public function getDetailAttribute(): ?string
    {
        $payload = $this->payload ?? [];
        $parts = [];

        if (isset($payload['via'])) {
            $parts[] = 'Vía '.($payload['via'] === 'setup' ? 'enlace de configuración' : 'código de emparejamiento');
        }
        if (isset($payload['expires_in_minutes'])) {
            $parts[] = 'Vigencia: '.(Terminal::SETUP_LINK_EXPIRY_OPTIONS[$payload['expires_in_minutes']] ?? $payload['expires_in_minutes'].' minutos');
        }
        if (isset($payload['reason'])) {
            $parts[] = 'Motivo: '.(['manual' => 'cerrada a mano', 'auto_approved' => 'la aprovechó un dispositivo', 'claimed' => 'se entregó el acceso', 'expired' => 'venció sin usarse'][$payload['reason']] ?? $payload['reason']);
        }
        if (! empty($payload['replaces_active_device'])) {
            $parts[] = 'Reemplaza al dispositivo actual';
        }
        if (! empty($payload['tokens'])) {
            $parts[] = 'Accesos revocados: '.$payload['tokens'];
        }
        if (isset($payload['stale_after_minutes'])) {
            $parts[] = 'Umbral: '.$payload['stale_after_minutes'].' min';
        }
        if (isset($payload['pending']) || isset($payload['conflicts'])) {
            $parts[] = ($payload['pending'] ?? 0).' pendientes, '.($payload['conflicts'] ?? 0).' en conflicto';
        }
        if (isset($payload['level'])) {
            $parts[] = 'Nivel: '.$payload['level'].'%';
        }
        if (! empty($payload['ip'])) {
            $parts[] = 'IP '.$payload['ip'];
        }

        return $parts ? implode(' · ', $parts) : null;
    }
}
