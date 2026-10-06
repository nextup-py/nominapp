<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

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
        }

        return $stale;
    }
}
