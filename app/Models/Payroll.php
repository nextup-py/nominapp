<?php

namespace App\Models;

use Carbon\Carbon;
use Database\Factories\PayrollFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use OwenIt\Auditing\Contracts\Auditable;

class Payroll extends Model implements Auditable
{
    /** @use HasFactory<PayrollFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable, SoftDeletes;

    /** @var array<int, string> Campos auditados en el historial de cambios. */
    protected array $auditInclude = [
        'status',
        'approved_by_id',
        'approved_at',
        'disbursed_at',
        'disbursed_by_id',
        'disbursement_batch_id',
        'bank_rejection_reason',
        'notes',
    ];

    protected $fillable = [
        'employee_id',
        'payroll_period_id',
        'base_salary',
        'gross_salary',
        'total_deductions',
        'total_perceptions',
        'ips_perceptions',
        'net_salary',
        'pdf_path',
        'generated_at',
        'status',
        'approved_by_id',
        'approved_at',
        'bank_account_id',
        'payment_method',
        'disbursed_at',
        'disbursed_by_id',
        'disbursement_batch_id',
        'bank_rejection_reason',
    ];

    protected $casts = [
        'base_salary' => 'decimal:2',
        'total_perceptions' => 'decimal:2',
        'ips_perceptions' => 'decimal:2',
        'gross_salary' => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'net_salary' => 'decimal:2',
        'generated_at' => 'datetime',
        'approved_at' => 'datetime',
        'disbursed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::deleting(function (Payroll $payroll) {
            if (in_array($payroll->status, ['approved', 'disbursed', 'paid'])) {
                throw new \Exception("No se puede eliminar una nómina con estado '{$payroll->status}'.");
            }

            $period = $payroll->period;
            if ($period) {
                // --- PRÉSTAMOS: revertir cuotas descontadas en esta nómina ---
                // Se identifican por payroll_id (incluye atrasadas de períodos sin nómina); las
                // anteriores a que existiera la columna se reconocen por vencimiento dentro del período.

                $loanInstallments = fn () => LoanInstallment::query()
                    ->whereHas('loan', fn ($q) => $q->where('employee_id', $payroll->employee_id))
                    ->where(fn ($q) => $q
                        ->where('payroll_id', $payroll->id)
                        ->orWhere(fn ($q) => $q->whereNull('payroll_id')
                            ->whereBetween('due_date', [$period->start_date, $period->end_date])));

                $deductionIds = $loanInstallments()
                    ->whereNotNull('employee_deduction_id')
                    ->pluck('employee_deduction_id')
                    ->filter();

                $affectedLoanIds = $loanInstallments()
                    ->whereIn('status', ['paid', 'pending'])
                    ->pluck('loan_id')
                    ->unique();

                $loanInstallments()
                    ->where('status', 'paid')
                    ->update(['status' => 'pending', 'paid_at' => null, 'payroll_id' => null]);

                // Restaurar saldo y estado de los préstamos afectados (uno pagado por completo vuelve a desembolsado)
                Loan::whereIn('id', $affectedLoanIds)->get()->each(function (Loan $loan) {
                    if (in_array($loan->status, ['approved', 'disbursed', 'paid'], true)) {
                        $loan->update([
                            'status' => $loan->status === 'paid' ? 'disbursed' : $loan->status,
                            'outstanding_balance' => $loan->installments()->where('status', 'pending')->sum('capital_amount'),
                        ]);
                    }
                });

                // Eliminar EmployeeDeduction puntuales de cuotas.
                // La FK nullOnDelete limpia automáticamente loan_installments.employee_deduction_id.
                if ($deductionIds->isNotEmpty()) {
                    EmployeeDeduction::whereIn('id', $deductionIds)->delete();
                }

                // --- ADELANTOS: revertir adelanto descontado en esta nómina ---

                $advanceDeductionIds = Advance::where('employee_id', $payroll->employee_id)
                    ->where('payroll_id', $payroll->id)
                    ->whereNotNull('employee_deduction_id')
                    ->pluck('employee_deduction_id')
                    ->filter();

                Advance::where('employee_id', $payroll->employee_id)
                    ->where('payroll_id', $payroll->id)
                    ->get()
                    ->each(fn (Advance $advance) => $advance->update([
                        'status' => 'disbursed',
                        'payroll_id' => null,
                        'employee_deduction_id' => null,
                    ]));

                if ($advanceDeductionIds->isNotEmpty()) {
                    EmployeeDeduction::whereIn('id', $advanceDeductionIds)->delete();
                }

                // --- MERCADERÍA: revertir cuotas descontadas en esta nómina ---

                $merchandiseInstallments = fn () => MerchandiseWithdrawalInstallment::query()
                    ->whereHas('withdrawal', fn ($q) => $q->where('employee_id', $payroll->employee_id))
                    ->where(fn ($q) => $q
                        ->where('payroll_id', $payroll->id)
                        ->orWhere(fn ($q) => $q->whereNull('payroll_id')
                            ->whereBetween('due_date', [$period->start_date, $period->end_date])));

                $merchandiseDeductionIds = $merchandiseInstallments()
                    ->whereNotNull('employee_deduction_id')
                    ->pluck('employee_deduction_id')
                    ->filter();

                $affectedWithdrawalIds = $merchandiseInstallments()
                    ->whereIn('status', ['paid', 'pending'])
                    ->pluck('merchandise_withdrawal_id')
                    ->unique();

                $merchandiseInstallments()
                    ->where('status', 'paid')
                    ->update(['status' => 'pending', 'paid_at' => null, 'payroll_id' => null]);

                MerchandiseWithdrawal::whereIn('id', $affectedWithdrawalIds)->get()->each(function (MerchandiseWithdrawal $withdrawal) {
                    if (in_array($withdrawal->status, ['approved', 'paid'], true)) {
                        $withdrawal->update([
                            'status' => $withdrawal->status === 'paid' ? 'approved' : $withdrawal->status,
                            'outstanding_balance' => $withdrawal->installments()->where('status', 'pending')->sum('amount'),
                        ]);
                    }
                });

                if ($merchandiseDeductionIds->isNotEmpty()) {
                    EmployeeDeduction::whereIn('id', $merchandiseDeductionIds)->delete();
                }

                // --- VACACIONES: revertir remuneración vacacional pagada en esta nómina ---

                Vacation::where('employee_id', $payroll->employee_id)
                    ->where('payment_method', 'with_payroll')
                    ->where('payment_status', 'paid')
                    ->whereBetween('start_date', [$period->start_date, $period->end_date])
                    ->update(['payment_status' => 'unpaid', 'paid_at' => null]);

                Log::warning('Cuotas de préstamo, adelantos, mercadería y vacaciones revertidos al eliminar nómina', [
                    'payroll_id' => $payroll->id,
                    'employee_id' => $payroll->employee_id,
                    'period_id' => $period->id,
                ]);
            }
        });
    }

    /**
     * Relación con el modelo Employee, una nómina pertenece a un empleado
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Relación con el modelo PayrollPeriod, una nómina pertenece a un período de nómina
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    /**
     * Relación con el modelo PayrollItem, una nómina tiene muchos items
     */
    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    /**
     * Usuario que aprobó la nómina
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    /** Cuenta bancaria utilizada para el pago de esta nómina. */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(EmployeeBankAccount::class, 'bank_account_id');
    }

    /** Usuario que registró la acreditación/entrega del pago. */
    public function disbursedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disbursed_by_id');
    }

    /** Lote bancario al que pertenece este recibo (solo para pagos por transferencia). */
    public function disbursementBatch(): BelongsTo
    {
        return $this->belongsTo(DisbursementBatch::class, 'disbursement_batch_id');
    }

    // -------------------------------------------------------------------------
    // Verificadores de estado
    // -------------------------------------------------------------------------

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isDisbursed(): bool
    {
        return $this->status === 'disbursed';
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    // -------------------------------------------------------------------------
    // Transiciones de estado
    // -------------------------------------------------------------------------

    /**
     * Marca el recibo como acreditado/entregado (approved → disbursed).
     * Para pagos en efectivo: el usuario lo marca manualmente.
     * Para transferencias: lo hace DisbursementBatch::confirm().
     *
     * @return array{success: bool, message: string}
     */
    public function markAsDisbursed(?int $disbursedById = null, ?string $disbursedAt = null): array
    {
        if (! $this->isApproved()) {
            return ['success' => false, 'message' => 'Solo se pueden acreditar recibos aprobados.'];
        }

        $this->update([
            'status' => 'disbursed',
            'disbursed_at' => $disbursedAt ? Carbon::parse($disbursedAt) : now(),
            'disbursed_by_id' => $disbursedById,
        ]);

        return ['success' => true, 'message' => 'Recibo marcado como acreditado.'];
    }

    /**
     * Revierte el recibo de disbursed a approved.
     * Solo posible si no pertenece a un lote bancario confirmado y no está descontado en nómina.
     *
     * @return array{success: bool, message: string}
     */
    public function revertToApproved(): array
    {
        if (! $this->isDisbursed()) {
            return ['success' => false, 'message' => 'Solo se pueden revertir recibos en estado acreditado.'];
        }

        if ($this->disbursement_batch_id !== null) {
            return ['success' => false, 'message' => 'El recibo pertenece a un lote bancario y no puede revertirse individualmente.'];
        }

        $this->update([
            'status' => 'approved',
            'disbursed_at' => null,
            'disbursed_by_id' => null,
        ]);

        return ['success' => true, 'message' => 'Recibo revertido a aprobado.'];
    }

    // -------------------------------------------------------------------------
    // Helpers estáticos de métodos de pago
    // -------------------------------------------------------------------------

    /** @return array<string, string> */
    public static function getPaymentMethodLabels(): array
    {
        return [
            'transfer' => 'Acreditación bancaria',
            'cash' => 'Efectivo',
        ];
    }

    /** @return array<string, string> */
    public static function getPaymentMethodColors(): array
    {
        return [
            'transfer' => 'info',
            'cash' => 'success',
        ];
    }

    /** @return array<string, string> */
    public static function getPaymentMethodIcons(): array
    {
        return [
            'transfer' => 'heroicon-o-building-library',
            'cash' => 'heroicon-o-banknotes',
        ];
    }

    /** @return array<string, string> */
    public static function getPaymentMethodOptions(): array
    {
        return [
            'transfer' => 'Acreditación bancaria',
            'cash' => 'Efectivo',
        ];
    }

    /** @return array<string, string> */
    public static function getBankRejectionReasonOptions(): array
    {
        return [
            'cuenta_inexistente' => 'Cuenta inexistente',
            'cuenta_bloqueada' => 'Cuenta bloqueada',
            'datos_incorrectos' => 'Datos incorrectos',
            'otro' => 'Otro',
        ];
    }

    public static function getBankRejectionReasonLabel(?string $reason): string
    {
        if ($reason === null) {
            return '-';
        }

        return match ($reason) {
            'cuenta_inexistente' => 'Cuenta inexistente',
            'cuenta_bloqueada' => 'Cuenta bloqueada',
            'datos_incorrectos' => 'Datos incorrectos',
            default => 'Otro',
        };
    }

    // Accesor para mostrar nombre
    public function getTitleAttribute(): string
    {
        return 'Nómina de '.($this->employee?->first_name ?? '').' '.($this->employee?->last_name ?? '').' - '.($this->period?->name ?? 'Sin período');
    }

    /** @return array<string, string> */
    public static function getStatusLabels(): array
    {
        return [
            'draft' => 'Borrador',
            'approved' => 'Aprobado',
            'disbursed' => 'Acreditado',
            'paid' => 'Pagado',
        ];
    }

    /** @return array<string, string> */
    public static function getStatusColors(): array
    {
        return [
            'draft' => 'gray',
            'approved' => 'warning',
            'disbursed' => 'info',
            'paid' => 'success',
        ];
    }

    /** @return array<string, string> */
    public static function getStatusIcons(): array
    {
        return [
            'draft' => 'heroicon-o-pencil',
            'approved' => 'heroicon-o-check-circle',
            'disbursed' => 'heroicon-o-building-library',
            'paid' => 'heroicon-o-banknotes',
        ];
    }

    /**
     * Alias de net_salary para compatibilidad con BankPaymentExportService,
     * que espera un campo `amount` genérico en los modelos que procesa.
     */
    public function getAmountAttribute(): float
    {
        return (float) $this->net_salary;
    }

    /**
     * Formatea un monto en guaraníes paraguayos
     * Ejemplo: 1500000 -> "Gs. 1.500.000"
     */
    public static function formatCurrency(float|int|null $amount): string
    {
        if ($amount === null) {
            return 'Gs. 0';
        }

        return 'Gs. '.number_format($amount, 0, ',', '.');
    }

    /**
     * Formatea el salario base en guaraníes
     */
    public function getFormattedBaseSalaryAttribute(): string
    {
        return self::formatCurrency($this->base_salary);
    }

    /**
     * Formatea el total de percepciones en guaraníes
     */
    public function getFormattedTotalPerceptionsAttribute(): string
    {
        return '+ '.self::formatCurrency($this->total_perceptions);
    }

    /**
     * Formatea el salario bruto en guaraníes
     */
    public function getFormattedGrossSalaryAttribute(): string
    {
        return self::formatCurrency($this->gross_salary);
    }

    /**
     * Formatea el total de deducciones en guaraníes
     */
    public function getFormattedTotalDeductionsAttribute(): string
    {
        return '- '.self::formatCurrency($this->total_deductions);
    }

    /**
     * Formatea el salario neto en guaraníes
     */
    public function getFormattedNetSalaryAttribute(): string
    {
        return self::formatCurrency($this->net_salary);
    }

    /**
     * Renderiza los valores de un registro de auditoría como HTML legible para el RelationManager.
     *
     * @param  string  $column  'old_values' o 'new_values'
     * @param  mixed  $auditRecord  Instancia del audit
     */
    public function formatAuditFieldsForPresentation(string $column, mixed $auditRecord): HtmlString
    {
        $values = $auditRecord->{$column} ?? [];
        if (empty($values)) {
            return new HtmlString('<span class="text-gray-400 text-xs">—</span>');
        }

        $fieldLabels = [
            'status' => 'Estado',
            'approved_by_id' => 'Aprobado por',
            'approved_at' => 'Fecha de aprobación',
            'disbursed_at' => 'Fecha de acreditación',
            'disbursed_by_id' => 'Acreditado por',
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

    /** Formatea un valor individual del audit a texto legible. */
    private function formatAuditValue(string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($key) {
            'status' => static::getStatusLabel($value),
            'approved_by_id', 'disbursed_by_id' => User::find($value)?->name ?? "ID {$value}",
            'approved_at', 'disbursed_at' => Carbon::parse($value)->format('d/m/Y H:i'),
            'disbursement_batch_id' => "Lote #{$value}",
            'notes' => Str::limit((string) $value, 120),
            default => (string) $value,
        };
    }
}
