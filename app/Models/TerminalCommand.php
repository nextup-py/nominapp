<?php

namespace App\Models;

use App\Notifications\TerminalCommandExpiredNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Comando remoto enviado a un terminal. Ciclo: `pending` (en espera del próximo heartbeat)
 * → `delivered` (el terminal lo recibió) → `done` / `failed` (confirmación del terminal).
 * Un comando que no se entrega a tiempo pasa a `expired`; uno entregado que nunca se
 * confirma pasa a `failed` ("sin confirmación").
 */
class TerminalCommand extends Model
{
    public const FORCE_SYNC = 'force_sync';

    public const RELOAD = 'reload';

    public const CLEAR_CACHE = 'clear_cache';

    public const REPORT = 'report';

    /** Minutos que un comando espera ser entregado (y luego confirmado) antes de vencer. */
    public const TTL_MINUTES = 15;

    /** Horas que un comando diferible espera a que el terminal vuelva a conectarse antes de vencer. */
    public const DEFERRED_TTL_HOURS = 24;

    /** Comandos inofensivos si llegan tarde: esperan al terminal hasta `DEFERRED_TTL_HOURS` en vez de `TTL_MINUTES`. */
    public const DEFERRABLE = [self::RELOAD];

    protected $fillable = [
        'terminal_id', 'command', 'status', 'requested_by_id', 'expires_at',
        'delivered_at', 'completed_at', 'result_message',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'delivered_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** Indica si el comando puede esperar horas a que el terminal vuelva a conectarse. */
    public static function isDeferrable(string $command): bool
    {
        return in_array($command, self::DEFERRABLE, true);
    }

    /** Minutos que el comando espera ser entregado: largos para los diferibles, `TTL_MINUTES` para el resto. */
    public static function deliveryWindowMinutes(string $command): int
    {
        return self::isDeferrable($command) ? self::DEFERRED_TTL_HOURS * 60 : self::TTL_MINUTES;
    }

    /** Terminal destinatario. */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    /** Usuario del panel que envió el comando. */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    /**
     * Comandos disponibles con su etiqueta.
     *
     * @return array<string, string>
     */
    public static function getCommandLabels(): array
    {
        return [
            self::FORCE_SYNC => 'Forzar sincronización',
            self::RELOAD => 'Recargar app',
            self::CLEAR_CACHE => 'Limpiar caché',
            self::REPORT => 'Reenviar reporte',
        ];
    }

    /**
     * Qué hace cada comando (para los modales de confirmación).
     *
     * @return array<string, string>
     */
    public static function getCommandDescriptions(): array
    {
        return [
            self::FORCE_SYNC => 'El terminal sincroniza empleados y vacía su cola de marcaciones.',
            self::RELOAD => 'El terminal recarga la aplicación (cuando no hay una marcación en curso).',
            self::CLEAR_CACHE => 'El terminal borra la caché de la app y de empleados y la reconstruye, y recarga. Las marcaciones pendientes de sincronizar y el acceso del dispositivo NO se tocan.',
            self::REPORT => 'El terminal envía de inmediato un reporte completo de su estado.',
        ];
    }

    /** @return array<string, string> */
    public static function getStatusLabels(): array
    {
        return [
            'pending' => 'Pendiente',
            'delivered' => 'Entregado',
            'done' => 'Ejecutado',
            'failed' => 'Falló',
            'expired' => 'Vencido',
        ];
    }

    /** @return array<string, string> */
    public static function getStatusColors(): array
    {
        return [
            'pending' => 'warning',
            'delivered' => 'info',
            'done' => 'success',
            'failed' => 'danger',
            'expired' => 'gray',
        ];
    }

    /** Comandos aún sin resolver (esperando entrega o confirmación). */
    public function scopeOpen($query)
    {
        return $query->whereIn('status', ['pending', 'delivered']);
    }

    /**
     * Vence los comandos que no se entregaron a tiempo y marca como fallidos los entregados
     * que nunca se confirmaron. Devuelve los comandos afectados para registrar la bitácora.
     *
     * @return Collection<int, self>
     */
    public static function expireStale(?Terminal $terminal = null): Collection
    {
        $stale = static::query()
            ->open()
            ->where('expires_at', '<', now())
            ->when($terminal, fn ($q) => $q->where('terminal_id', $terminal->id))
            ->with('terminal')
            ->get();

        foreach ($stale as $command) {
            $wasDelivered = $command->status === 'delivered';
            $command->update([
                'status' => $wasDelivered ? 'failed' : 'expired',
                'completed_at' => now(),
                'result_message' => $wasDelivered ? 'El terminal no confirmó la ejecución.' : 'El terminal no se conectó a tiempo.',
            ]);

            TerminalEvent::record($command->terminal, $wasDelivered ? 'command_failed' : 'command_expired', [
                'command' => $command->command,
                'message' => $command->result_message,
            ]);

            if (! $wasDelivered && static::isDeferrable($command->command)) {
                $command->notifyDeferredExpiry();
            }
        }

        return $stale;
    }

    /**
     * Avisa por la campanita a quien envió un comando diferible que venció sin que el terminal volviera a
     * conectarse. Es best-effort: un fallo del aviso no debe deshacer el vencimiento ya persistido.
     */
    private function notifyDeferredExpiry(): void
    {
        $sender = $this->requested_by_id ? User::find($this->requested_by_id) : null;

        if (! $sender || ! $this->terminal) {
            return;
        }

        try {
            $sender->notify(new TerminalCommandExpiredNotification($this->terminal, $this));
        } catch (\Throwable $e) {
            Log::warning('No se pudo avisar el vencimiento de un comando remoto', ['command_id' => $this->id, 'error' => $e->getMessage()]);
        }
    }
}
