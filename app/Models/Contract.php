<?php

namespace App\Models;

use App\Settings\GeneralSettings;
use App\Settings\PayrollSettings;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use OwenIt\Auditing\Contracts\Auditable;

class Contract extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    /** Solo auditar campos relevantes para el historial de cambios. */
    protected array $auditInclude = [
        'status',
        'salary',
        'salary_type',
        'position_id',
        'department_id',
        'start_date',
        'end_date',
        'trial_days',
        'work_modality',
        'payment_method',
        'notes',
    ];

    protected $fillable = [
        'employee_id',
        'type',
        'start_date',
        'end_date',
        'trial_days',
        'salary_type',
        'salary',
        'payroll_type',
        'position_id',
        'department_id',
        'work_modality',
        'payment_method',
        'advance_percent',
        'document_path',
        'status',
        'notes',
        'additional_clauses',
        'created_by_id',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'terminated_at' => 'datetime',
        'trial_days' => 'integer',
        'salary' => 'integer',
        'advance_percent' => 'integer',
    ];

    // ───────────────────────────────────────────
    // Relaciones
    // ───────────────────────────────────────────

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    // ───────────────────────────────────────────
    // Opciones estáticas
    // ───────────────────────────────────────────

    /**
     * Tipos de contrato según el Código Laboral Paraguayo (Ley 213/93)
     */
    public static function getTypeOptions(): array
    {
        return [
            'indefinido' => 'Por Tiempo Indefinido',
            'plazo_fijo' => 'Por Plazo Determinado',
            'obra_determinada' => 'Por Obra Determinada',
            'aprendizaje' => 'De Aprendizaje',
            'pasantia' => 'Pasantía',
        ];
    }

    public static function getTypeLabel(string $type): string
    {
        return self::getTypeOptions()[$type] ?? $type;
    }

    public static function getTypeColor(string $type): string
    {
        return match ($type) {
            'indefinido' => 'success',
            'plazo_fijo' => 'warning',
            'obra_determinada' => 'info',
            'aprendizaje' => 'primary',
            'pasantia' => 'gray',
            default => 'gray',
        };
    }

    public static function getTypeIcon(string $type): string
    {
        return match ($type) {
            'indefinido' => 'heroicon-o-check-badge',
            'plazo_fijo' => 'heroicon-o-calendar',
            'obra_determinada' => 'heroicon-o-wrench-screwdriver',
            'aprendizaje' => 'heroicon-o-academic-cap',
            'pasantia' => 'heroicon-o-book-open',
            default => 'heroicon-o-document-text',
        };
    }

    public static function getStatusOptions(): array
    {
        return [
            'draft' => 'Borrador',
            'active' => 'Vigente',
            'suspended' => 'Suspendido',
            'expired' => 'Vencido',
            'terminated' => 'Terminado',
            'renewed' => 'Renovado',
        ];
    }

    public static function getStatusLabel(string $status): string
    {
        return self::getStatusOptions()[$status] ?? $status;
    }

    public static function getStatusColor(string $status): string
    {
        return match ($status) {
            'draft' => 'gray',
            'active' => 'success',
            'suspended' => 'warning',
            'expired' => 'danger',
            'terminated' => 'gray',
            'renewed' => 'info',
            default => 'gray',
        };
    }

    public static function getStatusIcon(string $status): string
    {
        return match ($status) {
            'draft' => 'heroicon-o-pencil',
            'active' => 'heroicon-o-check-circle',
            'suspended' => 'heroicon-o-pause-circle',
            'expired' => 'heroicon-o-exclamation-triangle',
            'terminated' => 'heroicon-o-x-circle',
            'renewed' => 'heroicon-o-arrow-path',
            default => 'heroicon-o-question-mark-circle',
        };
    }

    /**
     * Tipos de remuneración según Art. 231 del Código Laboral Paraguayo
     */
    public static function getSalaryTypeOptions(): array
    {
        return [
            'mensual' => 'Mensualizado (Sueldo)',
            'jornal' => 'Jornalero (Jornal Diario)',
        ];
    }

    public static function getSalaryTypeLabel(string $salaryType): string
    {
        return self::getSalaryTypeOptions()[$salaryType] ?? $salaryType;
    }

    public static function getSalaryTypeColor(string $salaryType): string
    {
        return match ($salaryType) {
            'mensual' => 'info',
            'jornal' => 'warning',
            default => 'gray',
        };
    }

    public static function getSalaryTypeIcon(string $salaryType): string
    {
        return match ($salaryType) {
            'mensual' => 'heroicon-o-banknotes',
            'jornal' => 'heroicon-o-calendar-days',
            default => 'heroicon-o-question-mark-circle',
        };
    }

    /** Salario mínimo legal vigente para el tipo de remuneración, desde PayrollSettings. */
    public static function getMinSalaryFor(?string $salaryType): int
    {
        $settings = app(PayrollSettings::class);

        return $salaryType === 'jornal'
            ? $settings->min_salary_daily_jornal
            : $settings->min_salary_monthly;
    }

    public static function getWorkModalityOptions(): array
    {
        return [
            'presencial' => 'Presencial',
            'remoto' => 'Remoto',
            'hibrido' => 'Híbrido',
        ];
    }

    public static function getWorkModalityLabel(string $modality): string
    {
        return self::getWorkModalityOptions()[$modality] ?? $modality;
    }

    public static function getWorkModalityColor(string $modality): string
    {
        return match ($modality) {
            'presencial' => 'success',
            'remoto' => 'info',
            'hibrido' => 'warning',
            default => 'gray',
        };
    }

    public static function getWorkModalityIcon(string $modality): string
    {
        return match ($modality) {
            'presencial' => 'heroicon-o-building-office',
            'remoto' => 'heroicon-o-home',
            'hibrido' => 'heroicon-o-arrows-right-left',
            default => 'heroicon-o-question-mark-circle',
        };
    }

    // ───────────────────────────────────────────
    // Lógica de negocio
    // ───────────────────────────────────────────

    /**
     * Verifica si el contrato requiere fecha de finalización
     */
    public static function requiresEndDate(string $type): bool
    {
        return in_array($type, ['plazo_fijo', 'obra_determinada', 'aprendizaje', 'pasantia']);
    }

    /**
     * Verifica si el contrato está por vencer dentro de los días indicados
     */
    public function isExpiringSoon(int $days = 30): bool
    {
        return $this->end_date
            && $this->status === 'active'
            && $this->end_date->isFuture()
            && $this->end_date->diffInDays(now()) <= $days;
    }

    /**
     * Verifica si el contrato ya venció
     */
    public function isExpired(): bool
    {
        return $this->end_date
            && $this->status === 'active'
            && $this->end_date->isPast();
    }

    /**
     * Verifica si el empleado está en período de prueba (Art. 58 CLT)
     */
    public function isInTrialPeriod(): bool
    {
        if (! $this->trial_days || $this->status !== 'active') {
            return false;
        }

        $trialEnd = $this->start_date->copy()->addDays($this->trial_days);

        return now()->lte($trialEnd);
    }

    /**
     * Obtiene la fecha de fin del período de prueba
     */
    public function getTrialEndDateAttribute(): ?Carbon
    {
        if (! $this->trial_days) {
            return null;
        }

        return $this->start_date->copy()->addDays($this->trial_days);
    }

    /**
     * Obtiene la duración del contrato en texto legible
     */
    public function getDurationDescriptionAttribute(): string
    {
        if (! $this->end_date) {
            return 'Indefinido';
        }

        $diff = $this->start_date->diff($this->end_date->copy()->addDay());

        $parts = [];
        if ($diff->y > 0) {
            $parts[] = $diff->y.' '.($diff->y === 1 ? 'año' : 'años');
        }
        if ($diff->m > 0) {
            $parts[] = $diff->m.' '.($diff->m === 1 ? 'mes' : 'meses');
        }
        if ($diff->d > 0 && $diff->y === 0) {
            $parts[] = $diff->d.' '.($diff->d === 1 ? 'día' : 'días');
        }

        return implode(', ', $parts) ?: '0 días';
    }

    /**
     * Obtiene los días restantes hasta el vencimiento
     */
    public function getRemainingDaysAttribute(): ?int
    {
        if (! $this->end_date || $this->status !== 'active') {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->end_date->startOfDay(), false);
    }

    /**
     * Descripción del estado de vencimiento
     */
    public function getExpirationDescriptionAttribute(): ?string
    {
        if (! $this->end_date) {
            return null;
        }

        $remaining = $this->remaining_days;

        if ($remaining === null) {
            return null;
        }

        if ($remaining < 0) {
            return 'Venció hace '.abs($remaining).' días';
        }

        if ($remaining === 0) {
            return 'Vence hoy';
        }

        return 'Vence en '.$remaining.' días';
    }

    /**
     * Termina el contrato y registra la fecha de rescisión.
     */
    public function terminate(): self
    {
        $this->update([
            'status' => 'terminated',
            'terminated_at' => now(),
        ]);

        return $this;
    }

    /**
     * Renueva el contrato creando uno nuevo.
     * Art. 53 CLT: Si un contrato a plazo fijo se renueva más de una vez,
     * se convierte automáticamente en indefinido.
     */
    public function renew(array $newContractData): Contract
    {
        return DB::transaction(function () use ($newContractData) {
            // Marcar este contrato como renovado
            $this->update(['status' => 'renewed']);

            // Art. 53 CLT: Verificar si ya fue renovado antes (segunda renovación = indefinido)
            $previousRenewals = static::where('employee_id', $this->employee_id)
                ->where('status', 'renewed')
                ->where('type', 'plazo_fijo')
                ->count();

            // Si es la segunda renovación de un plazo fijo, forzar tipo indefinido
            $forceIndefinido = $this->type === 'plazo_fijo' && $previousRenewals >= 2;

            $data = array_merge([
                'employee_id' => $this->employee_id,
                'type' => $forceIndefinido ? 'indefinido' : $this->type,
                'salary_type' => $this->salary_type,
                'salary' => $this->salary,
                'payroll_type' => $this->payroll_type,
                'position_id' => $this->position_id,
                'department_id' => $this->department_id,
                'work_modality' => $this->work_modality,
                'status' => 'active',
            ], $newContractData);

            // Si se forzó indefinido, quitar fecha de fin
            if ($forceIndefinido) {
                $data['end_date'] = null;
            }

            return static::create($data);
        });
    }

    /**
     * Indica si el contrato puede renovarse: vigente o ya vencido (un contrato a plazo se vence a las 00:05 del día
     * siguiente a su fin, y desde entonces la renovación es la vía correcta para continuar), y no indefinido.
     */
    public function canBeRenewed(): bool
    {
        return in_array($this->status, ['active', 'expired'], true) && $this->type !== 'indefinido';
    }

    /**
     * Indica si al guardar una edición con esta fecha de fin un contrato vencido debe volver a Vigente: el vencimiento
     * es una consecuencia de la fecha, así que si la fecha ya no pasó el estado deja de ser coherente.
     *
     * @param  mixed  $newEndDate  Fecha de fin que se está guardando.
     */
    public function shouldReactivateOnEdit(mixed $newEndDate): bool
    {
        return $this->status === 'expired'
            && filled($newEndDate)
            && Carbon::parse($newEndDate)->startOfDay()->gte(Carbon::today());
    }

    /**
     * Verifica si al renovar este contrato se convertiría en indefinido (Art. 53 CLT)
     */
    public function wouldBecomeIndefiniteOnRenewal(): bool
    {
        if ($this->type !== 'plazo_fijo') {
            return false;
        }

        $previousRenewals = static::where('employee_id', $this->employee_id)
            ->where('status', 'renewed')
            ->where('type', 'plazo_fijo')
            ->count();

        // El contrato actual también cuenta como renovación al renovarse
        return $previousRenewals >= 1;
    }

    // ───────────────────────────────────────────
    // Scopes
    // ───────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeExpiringSoon(Builder $query, int $days = 30): Builder
    {
        return $query->where('status', 'active')
            ->whereNotNull('end_date')
            ->where('end_date', '>', now())
            ->where('end_date', '<=', now()->addDays($days));
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('status', 'active')
            ->whereNotNull('end_date')
            ->where('end_date', '<', now());
    }

    // ───────────────────────────────────────────
    // Formateadores
    // ───────────────────────────────────────────

    public static function formatCurrency(float|int|null $amount): string
    {
        if ($amount === null) {
            return 'Gs. 0';
        }

        return 'Gs. '.number_format((int) $amount, 0, ',', '.');
    }

    public function getFormattedSalaryAttribute(): string
    {
        $formatted = self::formatCurrency($this->salary);

        return $this->salary_type === 'jornal'
            ? $formatted.'/día'
            : $formatted.'/mes';
    }

    public function getFormattedStartDateAttribute(): string
    {
        return $this->start_date->format('d/m/Y');
    }

    public function getFormattedEndDateAttribute(): ?string
    {
        return $this->end_date?->format('d/m/Y');
    }

    /**
     * Obtiene el conteo de contratos por estado para badges
     */
    public static function getCounts(): array
    {
        return [
            'active' => static::where('status', 'active')->count(),
            'expiring' => static::expiringSoon(
                app(GeneralSettings::class)->contract_alert_days
            )->count(),
            'expired' => static::expired()->count(),
        ];
    }

    /**
     *  Calcula los días restantes del período de prueba (Art. 58 CLT)
     */
    public function getTrialDaysLeftAttribute(): ?int
    {
        if (! $this->trial_days || $this->status !== 'active') {
            return null;
        }

        $trialEnd = $this->start_date->copy()->addDays($this->trial_days);

        if (now()->gt($trialEnd)) {
            return 0;
        }

        return (int) now()->startOfDay()->diffInDays($trialEnd->startOfDay(), false);
    }

    /**
     * Formatea los campos auditados para su presentación en el RelationManager de historial.
     */
    public function formatAuditFieldsForPresentation(string $column, mixed $auditRecord): HtmlString
    {
        $values = $auditRecord->{$column} ?? [];

        if (empty($values)) {
            return new HtmlString('<span class="text-gray-400 text-xs">—</span>');
        }

        $fieldLabels = [
            'status' => 'Estado',
            'salary' => 'Salario',
            'salary_type' => 'Tipo de salario',
            'position_id' => 'Cargo',
            'department_id' => 'Departamento',
            'start_date' => 'Fecha de inicio',
            'end_date' => 'Fecha de fin',
            'trial_days' => 'Días de prueba',
            'work_modality' => 'Modalidad',
            'payment_method' => 'Método de pago',
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
            'salary_type' => $value === 'mensual' ? 'Mensual' : 'Jornal',
            'work_modality' => match ($value) {
                'presencial' => 'Presencial',
                'remoto' => 'Remoto',
                'hibrido' => 'Híbrido',
                default => (string) $value,
            },
            'payment_method' => match ($value) {
                'cash' => 'Efectivo',
                'debit' => 'Débito bancario',
                'check' => 'Cheque',
                default => (string) $value,
            },
            'salary' => 'Gs. '.number_format((int) $value, 0, ',', '.'),
            'position_id' => Position::find($value)?->name ?? "ID {$value}",
            'department_id' => Department::find($value)?->name ?? "ID {$value}",
            'start_date', 'end_date' => Carbon::parse($value)->format('d/m/Y'),
            'trial_days' => $value.' días',
            'notes' => Str::limit((string) $value, 120),
            default => (string) $value,
        };
    }
}
