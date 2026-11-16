<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un dispositivo que estuvo vinculado a un terminal (ver `Terminal::claimSanctumToken()`). Cada vinculación crea un
 * registro nuevo y cierra el anterior (`unlinked_at` y `unlink_reason`): `unlinked_at` en null es el dispositivo que hoy
 * tiene acceso (a lo sumo uno por terminal). `device_id` es el identificador aleatorio que genera y guarda el propio
 * dispositivo (`terminal-offline/device-identity.js`): permite distinguir dos equipos aunque compartan IP y navegador.
 * Es un identificador del equipo, no de una persona.
 */
class TerminalDevice extends Model
{
    /** Motivos por los que un dispositivo deja de estar vinculado. */
    public const REASON_REPLACED = 'replaced';

    public const REASON_REVOKED = 'revoked';

    public const REASON_DEACTIVATED = 'deactivated';

    protected $fillable = [
        'terminal_id', 'device_id', 'ip_address', 'user_agent', 'device_brand', 'device_model', 'os', 'browser',
        'linked_via', 'linked_at', 'last_seen_at', 'unlinked_at', 'unlink_reason', 'unlinked_by_id', 'replaced_by_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'linked_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'unlinked_at' => 'datetime',
        ];
    }

    /** Terminal al que estuvo vinculado. */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    /** Usuario del panel que lo desvinculó, si fue una desvinculación manual. */
    public function unlinkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unlinked_by_id');
    }

    /** Indica si es el dispositivo que hoy tiene acceso al terminal. */
    public function isActive(): bool
    {
        return $this->unlinked_at === null;
    }

    /**
     * Etiquetas de cada motivo de salida.
     *
     * @return array<string, string>
     */
    public static function getReasonLabels(): array
    {
        return [
            self::REASON_REPLACED => 'Reemplazado por otro dispositivo',
            self::REASON_REVOKED => 'Desvinculado desde el panel',
            self::REASON_DEACTIVATED => 'Terminal desactivado',
        ];
    }

    /** Identificador acortado para mostrar (los primeros 8 caracteres), o null si el dispositivo no lo informó. */
    public function shortDeviceId(): ?string
    {
        return $this->device_id ? substr($this->device_id, 0, 8) : null;
    }

    /** Marca y modelo juntos, o null si no se detectaron. */
    public function deviceLabel(): ?string
    {
        $label = trim(($this->device_brand ?? '').' '.($this->device_model ?? ''));

        return $label !== '' ? $label : null;
    }
}
