<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Solicitud de vinculación de un dispositivo a un terminal mediante código de
 * emparejamiento. Ciclo: `pending` → `approved` (admin) → `claimed` (el
 * dispositivo recibe el token, una sola vez); o `denied` / `expired`.
 *
 * El `code` es solo para que el admin verifique contra la pantalla del
 * terminal — la credencial real del dispositivo es el `poll_secret`, que no se
 * guarda en claro (`poll_secret_hash`).
 */
class TerminalPairingRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DENIED = 'denied';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CLAIMED = 'claimed';

    /** Vigencia de una solicitud sin resolver, en minutos. */
    public const TTL_MINUTES = 10;

    /** Máximo de solicitudes pendientes simultáneas por terminal. */
    public const MAX_PENDING_PER_TERMINAL = 3;

    public const CODE_LENGTH = 6;

    /** Sin caracteres ambiguos (0/O, 1/I/L) para que se pueda dictar y tipear sin errores. */
    public const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    protected $fillable = [
        'terminal_id',
        'code',
        'poll_secret_hash',
        'ip_address',
        'user_agent',
        'device_model_hint',
        'status',
        'auto_approved',
        'replaces_active_device',
        'expires_at',
        'approved_by_id',
        'approved_at',
        'claimed_at',
    ];

    protected $hidden = ['poll_secret_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'auto_approved' => 'boolean',
            'replaces_active_device' => 'boolean',
            'expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'claimed_at' => 'datetime',
        ];
    }

    /** Terminal al que se quiere vincular el dispositivo. */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    /** Usuario del panel que aprobó la solicitud. */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    /**
     * Solicitudes pendientes y todavía vigentes.
     *
     * @param  Builder<TerminalPairingRequest>  $query
     * @return Builder<TerminalPairingRequest>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING)->where('expires_at', '>', now());
    }

    /**
     * Pendientes cuyo vencimiento ya pasó (candidatas a marcarse `expired`).
     *
     * @param  Builder<TerminalPairingRequest>  $query
     * @return Builder<TerminalPairingRequest>
     */
    public function scopeStale(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING)->where('expires_at', '<=', now());
    }

    /** Indica si sigue pendiente y vigente. */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->expires_at->isFuture();
    }

    /** Estado efectivo: una pendiente cuyo vencimiento ya pasó se considera `expired` aunque no se haya persistido. */
    public function getEffectiveStatusAttribute(): string
    {
        if ($this->status === self::STATUS_PENDING && ! $this->expires_at->isFuture()) {
            return self::STATUS_EXPIRED;
        }

        return $this->status;
    }

    /** Hash con el que se guarda y se busca el secreto de polling. */
    public static function hashSecret(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /** Normaliza un código tipeado por un humano (mayúsculas, sin espacios ni guiones). */
    public static function normalizeCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }

    /** Genera un código aleatorio con el alfabeto sin ambigüedades. */
    public static function randomCode(): string
    {
        $alphabet = self::CODE_ALPHABET;
        $code = '';

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }

    /** @return array<string, string> */
    public static function getStatusLabels(): array
    {
        return [
            self::STATUS_PENDING => 'Pendiente',
            self::STATUS_APPROVED => 'Aprobada',
            self::STATUS_DENIED => 'Rechazada',
            self::STATUS_EXPIRED => 'Vencida',
            self::STATUS_CLAIMED => 'Vinculada',
        ];
    }

    /** @return array<string, string> */
    public static function getStatusColors(): array
    {
        return [
            self::STATUS_PENDING => 'warning',
            self::STATUS_APPROVED => 'info',
            self::STATUS_DENIED => 'danger',
            self::STATUS_EXPIRED => 'gray',
            self::STATUS_CLAIMED => 'success',
        ];
    }
}
