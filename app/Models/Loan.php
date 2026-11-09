<?php

namespace App\Models;

use App\Settings\PayrollSettings;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Préstamo otorgado a un empleado, pagadero en cuotas mensuales.
 *
 * Ciclo de vida: pending → approve() → approved → disburse() → disbursed → [todas las cuotas pagadas] → paid
 *               pending → reject()  → rejected
 *               pending/approved    → cancel()  → cancelled
 *
 * Las cuotas se generan con amortización francesa: cuota fija con desglose
 * de capital e interés por período. Con tasa 0%, todas las cuotas son iguales
 * y el desglose es capital = cuota, interés = 0.
 */
class Loan extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    /** Campos auditados: solo cambios de estado y metadatos operacionales. */
    protected array $auditInclude = [
        'status',
        'payment_method',
        'granted_at',
        'granted_by_id',
        'disbursement_batch_id',
        'bank_rejection_reason',
        'notes',
    ];

    protected $fillable = [
        'employee_id',
        'amount',
        'interest_rate',
        'installments_count',
        'installment_amount',
        'first_installment_days',
        'outstanding_balance',
        'status',
        'payment_method',
        'disbursement_batch_id',
        'reason',
        'granted_at',
        'granted_by_id',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'interest_rate' => 'decimal:2',
        'installment_amount' => 'decimal:2',
        'outstanding_balance' => 'decimal:2',
        'first_installment_days' => 'integer',
        'granted_at' => 'date',
    ];

    // =========================================================================
    // RELACIONES
    // =========================================================================

    /** Empleado al que se otorgó el préstamo. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Usuario que otorgó/activó el préstamo. */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_id');
    }

    /** Lote bancario al que pertenece este préstamo. */
    public function disbursementBatch(): BelongsTo
    {
        return $this->belongsTo(DisbursementBatch::class);
    }

    /** Cuotas del préstamo ordenadas por número. */
    public function installments(): HasMany
    {
        return $this->hasMany(LoanInstallment::class)->orderBy('installment_number');
    }

    // =========================================================================
    // HELPERS ESTÁTICOS — ESTADOS
    // =========================================================================

    /**
     * Retorna el label legible de un estado.
     */
    public static function getStatusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'Pendiente',
            'approved' => 'Aprobado',
            'disbursed' => 'Desembolsado',
            'paid' => 'Pagado',
            'rejected' => 'Rechazado',
            'cancelled' => 'Cancelado',
            default => 'Desconocido',
        };
    }

    /**
     * Retorna el color semántico Filament para un estado.
     */
    public static function getStatusColor(string $status): string
    {
        return match ($status) {
            'pending' => 'warning',
            'approved' => 'info',
            'disbursed' => 'primary',
            'paid' => 'success',
            'rejected' => 'danger',
            'cancelled' => 'gray',
            default => 'gray',
        };
    }

    /**
     * Retorna el icono heroicon para un estado.
     */
    public static function getStatusIcon(string $status): string
    {
        return match ($status) {
            'pending' => 'heroicon-o-clock',
            'approved' => 'heroicon-o-check-badge',
            'disbursed' => 'heroicon-o-banknotes',
            'paid' => 'heroicon-o-check-circle',
            'rejected' => 'heroicon-o-x-circle',
            'cancelled' => 'heroicon-o-minus-circle',
            default => 'heroicon-o-question-mark-circle',
        };
    }

    /**
     * Retorna las opciones de estado para selects Filament.
     *
     * @return array<string, string>
     */
    public static function getStatusOptions(): array
    {
        return [
            'pending' => 'Pendiente',
            'approved' => 'Aprobado',
            'disbursed' => 'Desembolsado',
            'paid' => 'Pagado',
            'rejected' => 'Rechazado',
            'cancelled' => 'Cancelado',
        ];
    }

    // =========================================================================
    // HELPERS ESTÁTICOS PARA MÉTODO DE PAGO
    // =========================================================================

    public static function getPaymentMethodOptions(): array
    {
        return [
            'transfer' => 'Acreditación bancaria',
            'cash' => 'Efectivo',
        ];
    }

    public static function getPaymentMethodLabel(string $method): string
    {
        return self::getPaymentMethodOptions()[$method] ?? $method;
    }

    public static function getPaymentMethodColor(string $method): string
    {
        return match ($method) {
            'transfer' => 'info',
            'cash' => 'success',
            default => 'gray',
        };
    }

    public static function getPaymentMethodIcon(string $method): string
    {
        return match ($method) {
            'transfer' => 'heroicon-o-building-library',
            'cash' => 'heroicon-o-banknotes',
            default => 'heroicon-o-question-mark-circle',
        };
    }

    // =========================================================================
    // VERIFICADORES DE ESTADO
    // =========================================================================

    /** Verifica si el préstamo está pendiente de aprobación. */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /** Verifica si el préstamo está aprobado y en curso de cobro. */
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /** Verifica si el préstamo fue completamente pagado. */
    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    /** Verifica si el préstamo fue rechazado. */
    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    /** Verifica si el préstamo fue desembolsado (dinero entregado al empleado, pendiente de descuento en cuotas). */
    public function isDisbursed(): bool
    {
        return $this->status === 'disbursed';
    }

    /** Verifica si el préstamo fue cancelado. */
    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    // =========================================================================
    // ATRIBUTOS COMPUTADOS
    // =========================================================================

    /**
     * Suma de capital pagado (cuotas con status=paid).
     */
    public function getPaidAmountAttribute(): float
    {
        return (float) $this->installments()->where('status', 'paid')->sum('capital_amount');
    }

    /**
     * Cantidad de cuotas pagadas.
     */
    public function getPaidInstallmentsCountAttribute(): int
    {
        return $this->installments()->where('status', 'paid')->count();
    }

    /**
     * Cantidad de cuotas pendientes.
     */
    public function getPendingInstallmentsCountAttribute(): int
    {
        return $this->installments()->where('status', 'pending')->count();
    }

    /**
     * Descripción del progreso de pago: "X/Y cuotas".
     */
    public function getProgressDescriptionAttribute(): string
    {
        return "{$this->paid_installments_count}/{$this->installments_count} cuotas";
    }

    /**
     * Porcentaje de avance del pago (0–100).
     */
    public function getProgressPercentageAttribute(): int
    {
        if ($this->installments_count === 0) {
            return 0;
        }

        return (int) round(($this->paid_installments_count / $this->installments_count) * 100);
    }

    /**
     * Indica si el préstamo tiene interés.
     */
    public function hasInterest(): bool
    {
        return (float) $this->interest_rate > 0;
    }

    /**
     * Retorna el label del estado actual.
     */
    public function getStatusLabelAttribute(): string
    {
        return self::getStatusLabel($this->status);
    }

    // =========================================================================
    // CÁLCULO DE CUOTA (PMT — AMORTIZACIÓN FRANCESA)
    // =========================================================================

    /**
     * Calcula la cuota mensual fija usando la fórmula PMT de amortización francesa.
     *
     * Con tasa 0% retorna simplemente amount / installments_count.
     * La cuota calculada se almacena en installment_amount al activar.
     *
     * @return float Monto de cuota redondeado a 2 decimales.
     */
    public function calculatePmt(): float
    {
        return static::computePmt((float) $this->amount, (int) $this->installments_count, (float) $this->interest_rate);
    }

    /**
     * Fórmula PMT estática reutilizable desde formularios Filament sin instanciar el modelo.
     *
     * @param  float  $principal  Monto del préstamo.
     * @param  int  $n  Cantidad de cuotas.
     * @param  float  $annualRate  Tasa de interés anual en porcentaje (ej: 3.0 para 3%).
     * @return float Cuota mensual redondeada a 2 decimales.
     */
    public static function computePmt(float $principal, int $n, float $annualRate): float
    {
        if ($n === 0) {
            return 0.0;
        }

        if ($annualRate <= 0) {
            return round($principal / $n, 0);
        }

        $r = ($annualRate / 100) / 12;
        $pmt = $principal * $r * pow(1 + $r, $n) / (pow(1 + $r, $n) - 1);

        return round($pmt, 0);
    }

    // =========================================================================
    // ACCIONES PRINCIPALES
    // =========================================================================

    /**
     * Activa el préstamo, calcula el PMT y genera el plan de cuotas.
     *
     * Valida:
     *  - Estado pending
     *  - Contrato activo del empleado
     *  - Límite legal del 25% del salario (Art. 245 CLT) por cuota
     *
     * @param  int  $grantedById  ID del usuario que otorga el préstamo.
     * @return array{success: bool, message: string}
     */
    public function activate(int $grantedById): array
    {
        if (! $this->isPending()) {
            return [
                'success' => false,
                'message' => 'Solo se pueden activar préstamos en estado Pendiente.',
            ];
        }

        if (! $this->employee->activeContract) {
            return [
                'success' => false,
                'message' => 'El empleado no tiene un contrato activo.',
            ];
        }

        // Calcular PMT con la tasa de interés actual
        $pmt = $this->calculatePmt();

        // Validar límite de cuota como % del salario (configurable en PayrollSettings)
        $salaryBase = $this->getLoanDeductionBase();
        if ($salaryBase > 0) {
            $capPercent = app(PayrollSettings::class)->loan_installment_cap_percent;
            $cap = round($salaryBase * $capPercent / 100, 2);
            if ($pmt > $cap) {
                $capFormatted = number_format($cap, 0, ',', '.');
                $pmtFormatted = number_format($pmt, 0, ',', '.');

                return [
                    'success' => false,
                    'message' => "La cuota de Gs. {$pmtFormatted} supera el límite del {$capPercent}% del salario (máximo Gs. {$capFormatted}). Reducí el monto, aumentá la cantidad de cuotas o ajustá la tasa.",
                ];
            }
        }

        $startDate = $this->calculateNextPayrollDate();

        DB::transaction(function () use ($grantedById, $pmt, $startDate) {
            $this->update([
                'status' => 'approved',
                'granted_at' => now(),
                'granted_by_id' => $grantedById,
                'installment_amount' => $pmt,
                'outstanding_balance' => $this->amount,
            ]);

            $this->generateInstallments($startDate, $pmt);
        });

        $formattedDate = $startDate->format('d/m/Y');

        return [
            'success' => true,
            'message' => 'Préstamo activado. Primera cuota de Gs. '.number_format($pmt, 0, ',', '.')." programada para {$formattedDate}.",
        ];
    }

    /**
     * Rechaza la solicitud de préstamo.
     *
     * Solo aplica a préstamos en estado pendiente.
     *
     * @param  string|null  $reason  Motivo del rechazo.
     * @return array{success: bool, message: string}
     */
    public function reject(?string $reason = null): array
    {
        if (! $this->isPending()) {
            return [
                'success' => false,
                'message' => 'Solo se pueden rechazar préstamos en estado Pendiente.',
            ];
        }

        $notes = $this->notes;
        if ($reason) {
            $notes = $notes ? "{$notes}\n\nRechazo: {$reason}" : "Rechazo: {$reason}";
        }

        $this->update([
            'status' => 'rejected',
            'notes' => $notes,
        ]);

        return [
            'success' => true,
            'message' => 'El préstamo ha sido rechazado.',
        ];
    }

    /**
     * Cancela el préstamo.
     *
     * Las cuotas ya pagadas se conservan; solo se cancelan las pendientes.
     *
     * @param  string|null  $reason  Motivo de cancelación.
     * @return array{success: bool, message: string}
     */
    public function cancel(?string $reason = null): array
    {
        if (! $this->isPending() && ! $this->isApproved()) {
            return [
                'success' => false,
                'message' => 'Solo se pueden cancelar préstamos en estado Pendiente o Aprobado.',
            ];
        }

        DB::transaction(function () use ($reason) {
            $this->installments()->where('status', 'pending')->update(['status' => 'cancelled']);

            $notes = $this->notes;
            if ($reason) {
                $notes = $notes ? "{$notes}\n\nCancelación: {$reason}" : "Cancelación: {$reason}";
            }

            $this->update([
                'status' => 'cancelled',
                'outstanding_balance' => 0,
                'notes' => $notes,
            ]);
        });

        return [
            'success' => true,
            'message' => 'El préstamo ha sido cancelado.',
        ];
    }

    /**
     * Marca el préstamo como desembolsado (dinero entregado al empleado).
     *
     * Solo aplica a préstamos en estado aprobado. El descuento en cuotas
     * se procesará en la siguiente nómina por LoanInstallmentCalculator.
     *
     * @param  int  $disbursedById  ID del usuario que registra el desembolso.
     * @return array{success: bool, message: string}
     */
    public function disburse(int $disbursedById): array
    {
        if (! $this->isApproved()) {
            return [
                'success' => false,
                'message' => 'Solo se pueden desembolsar préstamos aprobados.',
            ];
        }

        $this->update([
            'status' => 'disbursed',
        ]);

        return [
            'success' => true,
            'message' => 'El préstamo ha sido marcado como desembolsado.',
        ];
    }

    /**
     * Verifica si todas las cuotas están pagadas y cierra el préstamo.
     *
     * Llamado por LoanInstallmentCalculator::markInstallmentsAsPaid().
     */
    public function checkIfPaid(): void
    {
        if ($this->isDisbursed() && $this->pending_installments_count === 0) {
            $this->update([
                'status' => 'paid',
                'outstanding_balance' => 0,
            ]);
        }
    }

    // =========================================================================
    // MÉTODOS DE PERÍODO Y FECHAS
    // =========================================================================

    /**
     * Base salarial mensual para calcular el límite del 25% (Art. 245 CLT).
     *
     * Para mensualeros usa el salario base del contrato.
     * Para jornaleros usa daily_rate × 30 como equivalente mensual aproximado.
     */
    protected function getLoanDeductionBase(): float
    {
        return $this->employee->getLoanDeductionBase();
    }

    /**
     * Calcula la fecha de inicio de la primera cuota según el tipo de nómina del empleado.
     */
    protected function calculateNextPayrollDate(): Carbon
    {
        return Carbon::now()->addDays($this->first_installment_days);
    }

    /**
     * Genera el plan de cuotas con amortización francesa.
     *
     * Cada cuota almacena su monto total, la porción de capital y la de interés.
     * La última cuota absorbe cualquier diferencia de redondeo acumulada.
     *
     * @param  Carbon  $startDate  Fecha de vencimiento de la primera cuota.
     * @param  float  $pmt  Cuota fija calculada (PMT).
     */
    protected function generateInstallments(Carbon $startDate, float $pmt): void
    {
        $this->installments()->delete();

        $principal = (float) $this->amount;
        $n = (int) $this->installments_count;
        $annualRate = (float) $this->interest_rate;
        $monthlyRate = $annualRate > 0 ? ($annualRate / 100) / 12 : 0;
        $balance = $principal;
        $dueDate = $startDate->copy();

        for ($i = 1; $i <= $n; $i++) {
            $interestAmount = round($balance * $monthlyRate, 2);

            if ($i === $n) {
                // Última cuota: absorbe el saldo restante exacto
                $capitalAmount = round($balance, 2);
                $installmentAmount = round($capitalAmount + $interestAmount, 2);
            } else {
                $capitalAmount = round($pmt - $interestAmount, 2);
                $installmentAmount = $pmt;
            }

            $balance -= $capitalAmount;

            LoanInstallment::create([
                'loan_id' => $this->id,
                'installment_number' => $i,
                'amount' => $installmentAmount,
                'capital_amount' => $capitalAmount,
                'interest_amount' => $interestAmount,
                'due_date' => $dueDate->copy(),
                'status' => 'pending',
            ]);

            $dueDate->addMonth();
        }
    }

    /**
     * Verifica si existe nómina para el empleado en el período dado.
     */
    protected function payrollExistsForPeriod(PayrollPeriod $period): bool
    {
        return Payroll::where('employee_id', $this->employee_id)
            ->where('payroll_period_id', $period->id)
            ->exists();
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    /**
     * Filtra por estado.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Filtra préstamos aprobados (en curso de cobro).
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    /**
     * Filtra préstamos pendientes.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Filtra préstamos de un empleado específico.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeForEmployee($query, int $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    // =========================================================================
    // MÉTODOS ESTÁTICOS DE CONSULTA
    // =========================================================================

    /**
     * Retorna el préstamo activo o pendiente de un empleado, si existe.
     */
    public static function getActiveForEmployee(int $employeeId): ?self
    {
        return static::where('employee_id', $employeeId)
            ->whereIn('status', ['pending', 'approved', 'disbursed'])
            ->first();
    }

    /**
     * Obtiene la deuda activa total de un empleado usando outstanding_balance.
     */
    public static function getTotalActiveDebtForEmployee(int $employeeId): float
    {
        return (float) static::where('employee_id', $employeeId)
            ->whereIn('status', ['approved', 'disbursed'])
            ->sum('outstanding_balance');
    }

    /**
     * Cantidad de préstamos pendientes de activación (para badge de navegación).
     */
    public static function getPendingCount(): int
    {
        return static::where('status', 'pending')->count();
    }

    // =========================================================================
    // AUDITORÍA
    // =========================================================================

    /**
     * Formatea los valores auditados para presentación en el RelationManager de historial.
     */
    public function formatAuditFieldsForPresentation(string $column, mixed $auditRecord): HtmlString
    {
        $values = $auditRecord->{$column} ?? [];

        if (empty($values)) {
            return new HtmlString('<span class="text-gray-400 text-xs">—</span>');
        }

        $fieldLabels = [
            'status' => 'Estado',
            'payment_method' => 'Método de pago',
            'granted_at' => 'Fecha de aprobación',
            'granted_by_id' => 'Aprobado por',
            'disbursement_batch_id' => 'Lote bancario',
            'bank_rejection_reason' => 'Motivo de rechazo',
            'notes' => 'Notas',
        ];

        $html = '<ul class="space-y-0.5 text-sm">';
        foreach ($values as $key => $value) {
            $label = $fieldLabels[$key] ?? Str::headline($key);
            $formatted = $this->formatAuditValue($key, $value);
            $html .= "<li><span class=\"text-gray-500\">{$label}:</span> <span class=\"font-medium\">{$formatted}</span></li>";
        }
        $html .= '</ul>';

        return new HtmlString($html);
    }

    /**
     * Convierte el valor crudo de un campo auditado a su representación legible.
     */
    private function formatAuditValue(string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($key) {
            'status' => static::getStatusLabel($value),
            'payment_method' => static::getPaymentMethodLabel($value),
            'granted_by_id' => User::find($value)?->name ?? "ID {$value}",
            'granted_at' => Carbon::parse($value)->format('d/m/Y H:i'),
            'disbursement_batch_id' => "Lote #{$value}",
            'notes' => Str::limit((string) $value, 120),
            default => (string) $value,
        };
    }
}
