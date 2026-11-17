<?php

namespace App\Models;

use App\Services\EmployeeLeaveDeductionService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use OwenIt\Auditing\Contracts\Auditable;

/** Representa una licencia o permiso registrado para un empleado. */
class EmployeeLeave extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    /** @var array<int, string> Campos auditados en el historial de cambios. */
    protected array $auditInclude = [
        'status',
        'type',
        'generates_deduction',
        'reason',
    ];

    protected $fillable = [
        'employee_id',
        'type',
        'start_date',
        'end_date',
        'start_time',
        'end_time',
        'generates_deduction',
        'employee_deduction_id',
        'reason',
        'document_path',
        'status',
        'approved_by_id',
        'approved_at',
        'cancelled_by_id',
        'cancelled_at',
        'cancel_reason',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'generates_deduction' => 'boolean',
        'approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    // ─── Relaciones ──────────────────────────────────────────────────────────

    /** Empleado al que pertenece la licencia. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Usuario que aprobó la licencia. */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    /** Usuario que anuló la licencia. */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_id');
    }

    /** Días descontados de una licencia de día completo con descuento. */
    public function deductionDays(): HasMany
    {
        return $this->hasMany(EmployeeLeaveDeductionDay::class);
    }

    /** Ausencias justificadas por esta licencia. */
    public function absences(): HasMany
    {
        return $this->hasMany(Absence::class);
    }

    /** Deducción generada al aprobar un permiso parcial con descuento. */
    public function employeeDeduction(): BelongsTo
    {
        return $this->belongsTo(EmployeeDeduction::class);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /** Indica si es un permiso parcial (por horas, no por días completos). */
    public function isPartialDay(): bool
    {
        return filled($this->start_time) && filled($this->end_time);
    }

    /**
     * Duración en horas del permiso parcial (fracción decimal).
     * Retorna 0 si no es un permiso parcial o si los tiempos son inválidos.
     */
    public function durationInHours(): float
    {
        if (! $this->isPartialDay()) {
            return 0.0;
        }

        $start = Carbon::parse($this->start_date->format('Y-m-d').' '.$this->start_time);
        $end = Carbon::parse($this->start_date->format('Y-m-d').' '.$this->end_time);

        if ($end->lte($start)) {
            return 0.0;
        }

        return round($start->floatDiffInHours($end), 2);
    }

    /**
     * Monto de la deducción calculado según el salario del contrato activo.
     * Mensual: salario / (30 × 8) × horas
     * Jornalero: salario_diario / 8 × horas
     */
    public function calculatedDeductionAmount(): float
    {
        $hours = $this->durationInHours();
        if ($hours <= 0) {
            return 0.0;
        }

        $contract = $this->employee?->activeContract;
        if (! $contract) {
            return 0.0;
        }

        $salary = (float) $contract->salary;
        if ($salary <= 0) {
            return 0.0;
        }

        $hourlyRate = $contract->salary_type === 'mensual'
            ? $salary / (30 * 8)
            : $salary / 8;

        return round($hourlyRate * $hours, 2);
    }

    // ─── Ciclo de vida ────────────────────────────────────────────────────────

    /**
     * Aprueba la licencia y registra quién y cuándo.
     * - Con «genera descuento»: crea las deducciones LIC001 (una por las horas del permiso parcial, o una por cada día
     *   laborable de una licencia de día completo); ver `EmployeeLeaveDeductionService`.
     * - Licencias de día completo: justifica automáticamente las ausencias del período.
     *
     * @return array{justified_count: int, justified_dates: list<Carbon>, deduction_count: int, deduction_amount: float, deduction_warning: ?string}
     */
    public function approve(int $approvedById): array
    {
        $this->update(['status' => 'approved', 'approved_by_id' => $approvedById, 'approved_at' => now()]);

        $deduction = app(EmployeeLeaveDeductionService::class)->apply($this);

        // Solo justifica ausencias en licencias de día completo
        $justified = $this->justifyAbsences($approvedById);

        return [
            'justified_count' => count($justified),
            'justified_dates' => $justified,
            'deduction_count' => $deduction['count'],
            'deduction_amount' => $deduction['amount'],
            'deduction_warning' => $deduction['warning'],
        ];
    }

    /**
     * Justifica las ausencias del empleado dentro del período de esta licencia (solo de día completo).
     * Lo usa `approve()` y el comando `leaves:justify-absences`, que repara licencias aprobadas por un camino que
     * no justificó sus ausencias. Es idempotente: una ausencia ya justificada no se vuelve a tocar.
     *
     * @param  list<string>  $statuses  Estados de ausencia a justificar (por defecto pendientes e injustificadas).
     * @return list<Carbon> Fechas de las ausencias justificadas.
     */
    public function justifyAbsences(int $reviewedById, array $statuses = ['pending', 'unjustified']): array
    {
        if ($this->isPartialDay()) {
            return [];
        }

        $absences = Absence::where('employee_id', $this->employee_id)
            ->whereHas('attendanceDay', fn ($q) => $q->whereBetween('date', [$this->start_date, $this->end_date]))
            ->whereIn('status', $statuses)
            ->with('attendanceDay')
            ->get();

        $typeLabel = self::getTypeOptions()[$this->type] ?? $this->type;
        $period = $this->start_date->format('d/m/Y').' al '.$this->end_date->format('d/m/Y');
        $justifiedDates = [];

        foreach ($absences as $absence) {
            $absence->justify(
                $reviewedById,
                "Justificada por licencia: {$typeLabel} ({$period})",
                $this->id
            );
            $justifiedDates[] = $absence->attendanceDay->date->copy();
        }

        return $justifiedDates;
    }

    /**
     * Motivo por el que una licencia aprobada no se puede anular, o null si se puede: solo se anulan las aprobadas, y
     * con descuentos no si el empleado ya tiene nómina generada para esas fechas (esos descuentos no se pueden deshacer).
     */
    public function cancellationBlocker(): ?string
    {
        if ($this->status !== 'approved') {
            return 'Solo se pueden anular licencias aprobadas.';
        }

        $deductions = app(EmployeeLeaveDeductionService::class);

        return $deductions->deductionCount($this) > 0 ? $deductions->payrollBlocker($this) : null;
    }

    /**
     * Anula una licencia aprobada y deshace sus efectos: elimina sus descuentos y devuelve a «pendiente» las ausencias
     * que había justificado (RR.HH. decide de nuevo; no se genera ningún descuento solo).
     *
     * @return array{success: bool, message: string, absences: int, deductions: int}
     */
    public function cancel(int $cancelledById, string $reason): array
    {
        $blocker = $this->cancellationBlocker();

        if ($blocker !== null) {
            return ['success' => false, 'message' => $blocker, 'absences' => 0, 'deductions' => 0];
        }

        return DB::transaction(function () use ($cancelledById, $reason): array {
            $deductions = app(EmployeeLeaveDeductionService::class)->revert($this);

            $this->update([
                'status' => 'cancelled',
                'cancelled_by_id' => $cancelledById,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            $absences = $this->absences()->where('status', 'justified')->with('attendanceDay')->get();

            foreach ($absences as $absence) {
                $absence->update([
                    'status' => 'pending',
                    'employee_leave_id' => null,
                    'reviewed_at' => null,
                    'reviewed_by_id' => null,
                    'review_notes' => null,
                ]);

                $day = $absence->attendanceDay;
                $coveredByAnother = EmployeeLeave::where('employee_id', $this->employee_id)
                    ->where('status', 'approved')
                    ->where('id', '!=', $this->id)
                    ->whereDate('start_date', '<=', $day->date)
                    ->whereDate('end_date', '>=', $day->date)
                    ->exists();

                $day->update(['justified_absence' => $coveredByAnother]);
            }

            return [
                'success' => true,
                'message' => 'La licencia fue anulada.',
                'absences' => $absences->count(),
                'deductions' => $deductions,
            ];
        });
    }

    /** Rechaza la licencia. */
    public function reject(): void
    {
        $this->update(['status' => 'rejected']);
    }

    // ─── Opciones estáticas ───────────────────────────────────────────────────

    /**
     * Opciones de tipo de licencia para selects y filtros.
     *
     * @return array<string, string>
     */
    public static function getTypeOptions(): array
    {
        return [
            'medical_leave' => 'Reposo Médico',
            'vacation' => 'Vacaciones',
            'day_off' => 'Día Libre',
            'maternity_leave' => 'Licencia de Maternidad',
            'paternity_leave' => 'Licencia de Paternidad',
            'unpaid_leave' => 'Sin Goce de Sueldo',
            'other' => 'Otro',
        ];
    }

    /**
     * Colores de badge por tipo de licencia.
     *
     * @return array<string, string>
     */
    public static function getTypeColors(): array
    {
        return [
            'medical_leave' => 'danger',
            'vacation' => 'success',
            'day_off' => 'info',
            'maternity_leave' => 'primary',
            'paternity_leave' => 'gray',
            'unpaid_leave' => 'warning',
            'other' => 'gray',
        ];
    }

    /**
     * Íconos por tipo de licencia.
     *
     * @return array<string, string>
     */
    public static function getTypeIcons(): array
    {
        return [
            'medical_leave' => 'heroicon-o-heart',
            'vacation' => 'heroicon-o-sun',
            'day_off' => 'heroicon-o-calendar',
            'maternity_leave' => 'heroicon-o-home',
            'paternity_leave' => 'heroicon-o-home',
            'unpaid_leave' => 'heroicon-o-pause-circle',
            'other' => 'heroicon-o-document-text',
        ];
    }

    /**
     * Opciones de estado para selects y filtros.
     *
     * @return array<string, string>
     */
    public static function getStatusOptions(): array
    {
        return [
            'pending' => 'Pendiente',
            'approved' => 'Aprobado',
            'rejected' => 'Rechazado',
            'cancelled' => 'Anulada',
        ];
    }

    /**
     * Colores de badge por estado.
     *
     * @return array<string, string>
     */
    public static function getStatusColors(): array
    {
        return [
            'pending' => 'warning',
            'approved' => 'success',
            'rejected' => 'danger',
            'cancelled' => 'gray',
        ];
    }

    /**
     * Íconos por estado.
     *
     * @return array<string, string>
     */
    public static function getStatusIcons(): array
    {
        return [
            'pending' => 'heroicon-o-clock',
            'approved' => 'heroicon-o-check-circle',
            'rejected' => 'heroicon-o-x-circle',
            'cancelled' => 'heroicon-o-arrow-uturn-left',
        ];
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
            'type' => 'Tipo',
            'generates_deduction' => 'Genera descuento',
            'reason' => 'Motivo',
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
            'status' => static::getStatusOptions()[$value] ?? $value,
            'type' => static::getTypeOptions()[$value] ?? $value,
            'generates_deduction' => $value ? 'Sí' : 'No',
            'reason' => Str::limit((string) $value, 120),
            default => (string) $value,
        };
    }
}
