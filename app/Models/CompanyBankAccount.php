<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cuenta bancaria de una empresa.
 *
 * Una empresa puede tener múltiples cuentas; solo una activa puede ser principal.
 * Comparte el catálogo de bancos y tipos de cuenta con EmployeeBankAccount.
 */
class CompanyBankAccount extends Model
{
    use HasFactory;

    /** Red de seguridad: la cuenta principal no se elimina mientras haya otras activas que la reemplacen. */
    protected static function booted(): void
    {
        static::deleting(function (CompanyBankAccount $account): void {
            if (($message = $account->deletionBlocker()) !== null) {
                throw new \DomainException($message);
            }
        });
    }

    protected $fillable = [
        'company_id',
        'bank',
        'bank_company_id',
        'account_number',
        'account_type',
        'holder_name',
        'holder_ci',
        'is_primary',
        'status',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    // =========================================================================
    // RELACIONES
    // =========================================================================

    /** Empresa a la que pertenece la cuenta. */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    // =========================================================================
    // HELPERS ESTÁTICOS — CATÁLOGOS (delegados a EmployeeBankAccount)
    // =========================================================================

    /**
     * Opciones de bancos para selects Filament.
     *
     * @return array<string, string>
     */
    public static function getBankOptions(): array
    {
        return EmployeeBankAccount::BANKS;
    }

    /**
     * Opciones de tipos de cuenta para selects Filament.
     *
     * @return array<string, string>
     */
    public static function getAccountTypeOptions(): array
    {
        return EmployeeBankAccount::ACCOUNT_TYPES;
    }

    /**
     * Label legible de un banco.
     */
    public static function getBankLabel(string $bank): string
    {
        return EmployeeBankAccount::BANKS[$bank] ?? $bank;
    }

    /**
     * Label legible de un tipo de cuenta.
     */
    public static function getAccountTypeLabel(string $type): string
    {
        return EmployeeBankAccount::ACCOUNT_TYPES[$type] ?? $type;
    }

    /**
     * Color semántico Filament para el estado.
     */
    public static function getStatusColor(string $status): string
    {
        return match ($status) {
            'active' => 'success',
            'inactive' => 'gray',
            default => 'gray',
        };
    }

    /**
     * Label legible del estado.
     */
    public static function getStatusLabel(string $status): string
    {
        return match ($status) {
            'active' => 'Activa',
            'inactive' => 'Inactiva',
            default => 'Desconocido',
        };
    }

    /**
     * Opciones de estado para selects.
     *
     * @return array<string, string>
     */
    public static function getStatusOptions(): array
    {
        return [
            'active' => 'Activa',
            'inactive' => 'Inactiva',
        ];
    }

    // =========================================================================
    // VERIFICADORES DE ESTADO
    // =========================================================================

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isInactive(): bool
    {
        return $this->status === 'inactive';
    }

    // =========================================================================
    // ATRIBUTOS COMPUTADOS
    // =========================================================================

    /** Label del banco actual. */
    public function getBankLabelAttribute(): string
    {
        return self::getBankLabel($this->bank);
    }

    /** Label del tipo de cuenta actual. */
    public function getAccountTypeLabelAttribute(): string
    {
        return self::getAccountTypeLabel($this->account_type);
    }

    /** Label del estado actual. */
    public function getStatusLabelAttribute(): string
    {
        return self::getStatusLabel($this->status);
    }

    // =========================================================================
    // ACCIONES
    // =========================================================================

    /**
     * Motivo por el que no se puede eliminar, o null si se puede.
     *
     * La cuenta principal no se elimina si hay otras activas: hay que marcar otra como principal antes
     * (misma regla que {@see self::deactivate()}). Si es la única cuenta, se permite eliminarla.
     */
    public function deletionBlocker(): ?string
    {
        if (! $this->is_primary) {
            return null;
        }

        $otherActive = static::where('company_id', $this->company_id)
            ->where('id', '!=', $this->id)
            ->where('status', 'active')
            ->exists();

        return $otherActive
            ? 'No se puede eliminar la cuenta principal. Marque otra cuenta como principal primero.'
            : null;
    }

    /**
     * Marca esta cuenta como principal y desmarca las demás de la misma empresa.
     * Solo se puede marcar como principal si la cuenta está activa.
     *
     * @return array{success: bool, message: string}
     */
    public function markAsPrimary(): array
    {
        if (! $this->isActive()) {
            return [
                'success' => false,
                'message' => 'Solo se puede marcar como principal una cuenta activa.',
            ];
        }

        static::where('company_id', $this->company_id)
            ->where('id', '!=', $this->id)
            ->update(['is_primary' => false]);

        $this->update(['is_primary' => true]);

        return [
            'success' => true,
            'message' => 'Cuenta marcada como principal.',
        ];
    }

    /**
     * Desactiva la cuenta bancaria.
     * No se puede desactivar si es la cuenta principal y hay otras activas.
     *
     * @return array{success: bool, message: string}
     */
    public function deactivate(): array
    {
        if ($this->isInactive()) {
            return ['success' => false, 'message' => 'La cuenta ya está inactiva.'];
        }

        if ($this->is_primary) {
            $otherActive = static::where('company_id', $this->company_id)
                ->where('id', '!=', $this->id)
                ->where('status', 'active')
                ->exists();

            if ($otherActive) {
                return [
                    'success' => false,
                    'message' => 'No se puede desactivar la cuenta principal. Asigná otra cuenta como principal primero.',
                ];
            }

            $this->update(['status' => 'inactive', 'is_primary' => false]);
        } else {
            $this->update(['status' => 'inactive']);
        }

        return ['success' => true, 'message' => 'Cuenta desactivada.'];
    }

    /**
     * Reactiva la cuenta bancaria.
     *
     * @return array{success: bool, message: string}
     */
    public function reactivate(): array
    {
        if ($this->isActive()) {
            return ['success' => false, 'message' => 'La cuenta ya está activa.'];
        }

        $this->update(['status' => 'active']);

        return ['success' => true, 'message' => 'Cuenta reactivada.'];
    }
}
