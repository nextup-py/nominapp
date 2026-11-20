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
        'device_id',
        'same_device',
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
            'same_device' => 'boolean',
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

    /**
     * Otro terminal al que este mismo equipo ya está vinculado (mismo `device_id`, vínculo vigente), o null. Un equipo
     * solo puede ser un terminal: aprobarlo acá lo deja sin ser el otro, que pasa a verse desconectado.
     */
    public function linkedElsewhere(): ?Terminal
    {
        if (blank($this->device_id)) {
            return null;
        }

        return TerminalDevice::query()
            ->where('device_id', $this->device_id)
            ->whereNull('unlinked_at')
            ->where('terminal_id', '!=', $this->terminal_id)
            ->with('terminal.branch')
            ->latest('linked_at')
            ->first()?->terminal;
    }

    /**
     * Indica si aprobar esta solicitud cortaría a un equipo que hoy está funcionando: la pide otro dispositivo
     * mientras el vinculado sigue en línea, o el equipo que la pide ya es otro terminal. En esos casos el panel exige
     * escribir el nombre del terminal para confirmar (un toque por costumbre dejó terminales sin equipo).
     */
    public function requiresReplacementConfirmation(): bool
    {
        if ($this->linkedElsewhere() !== null) {
            return true;
        }

        return $this->replaces_active_device
            && $this->same_device !== true
            && (bool) $this->terminal?->isReportingNow();
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
