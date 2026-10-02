<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Amonestación laboral emitida a un empleado.
 *
 * Sin ciclo de vida. Puede llevar una suspensión disciplinaria sin goce de sueldo
 * (máx. {@see self::MAX_SUSPENSION_DAYS} días laborables) que SuspensionService traduce en
 * deducciones SUS-DIS por día; fuera de eso es un registro documental.
 */
class Warning extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    /** Máximo legal de días de suspensión disciplinaria. */
    public const MAX_SUSPENSION_DAYS = 8;

    /** Desde esta cantidad de días la ley exige sumario administrativo previo. */
    public const SUMMARY_REQUIRED_FROM_DAYS = 4;

    /** @var array<int, string> Campos auditados en el historial de cambios. */
    protected array $auditInclude = [
        'type',
        'reason',
        'description',
        'issued_at',
        'suspension_start_date',
        'suspension_days',
        'suspension_summary_done',
        'notes',
        'document_path',
    ];

    protected $fillable = [
        'employee_id',
        'type',
        'reason',
        'description',
        'issued_at',
        'issued_by_id',
        'suspension_start_date',
        'suspension_days',
        'suspension_summary_done',
        'notes',
        'document_path',
    ];

    protected $casts = [
        'issued_at' => 'date',
        'suspension_start_date' => 'date',
        'suspension_days' => 'integer',
        'suspension_summary_done' => 'boolean',
    ];

    // =========================================================================
    // RELACIONES
    // =========================================================================

    /** Empleado amonestado. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Usuario que emitió la amonestación. */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_id');
    }

    /** Días laborables efectivamente suspendidos (uno por deducción SUS-DIS). */
    public function suspensionDays(): HasMany
    {
        return $this->hasMany(WarningSuspensionDay::class)->orderBy('date');
    }

    /** Indica si la amonestación lleva una suspensión disciplinaria. */
    public function hasSuspension(): bool
    {
        return $this->suspension_days > 0 && $this->suspension_start_date !== null;
    }

    /** Último día laborable suspendido (null si no hay suspensión aplicada). */
    public function getSuspensionEndDateAttribute(): ?Carbon
    {
        return $this->suspensionDays->last()?->date;
    }

    // =========================================================================
    // HELPERS ESTÁTICOS — TIPOS
    // =========================================================================

    /**
     * Retorna las opciones de tipo para selects.
     *
     * @return array<string, string>
     */
    public static function getTypeOptions(): array
    {
        return [
            'verbal' => 'Verbal',
            'written' => 'Escrita',
            'severe' => 'Grave',
        ];
    }

    /**
     * Retorna el label legible de un tipo.
     */
    public static function getTypeLabel(string $type): string
    {
        return self::getTypeOptions()[$type] ?? 'Desconocido';
    }

    /**
     * Retorna el color semántico Filament para un tipo.
     */
    public static function getTypeColor(string $type): string
    {
        return match ($type) {
            'verbal' => 'warning',
            'written' => 'danger',
            'severe' => 'danger',
            default => 'gray',
        };
    }

    /**
     * Retorna el icono heroicon para un tipo.
     */
    public static function getTypeIcon(string $type): string
    {
        return match ($type) {
            'verbal' => 'heroicon-o-chat-bubble-left-ellipsis',
            'written' => 'heroicon-o-document-text',
            'severe' => 'heroicon-o-exclamation-triangle',
            default => 'heroicon-o-question-mark-circle',
        };
    }

    // =========================================================================
    // HELPERS ESTÁTICOS — MOTIVOS
    // =========================================================================

    /**
     * Retorna las opciones de motivo predefinidas para selects.
     *
     * @return array<string, string>
     */
    public static function getReasonOptions(): array
    {
        return [
            'tardanza' => 'Tardanza reiterada',
            'ausencia' => 'Ausencia injustificada',
            'incumplimiento' => 'Incumplimiento de normas',
            'conducta' => 'Conducta inapropiada',
            'negligencia' => 'Negligencia en el trabajo',
            'uso_indebido' => 'Uso indebido de recursos',
            'desobediencia' => 'Desobediencia a superiores',
            'conflicto' => 'Conflicto con compañeros',
            'rendimiento' => 'Bajo rendimiento',
            'otro' => 'Otro',
        ];
    }

    /**
     * Retorna el label legible de un motivo.
     */
    public static function getReasonLabel(string $reason): string
    {
        return self::getReasonOptions()[$reason] ?? $reason;
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
            'type' => 'Tipo',
            'reason' => 'Motivo',
            'description' => 'Descripción',
            'issued_at' => 'Fecha de emisión',
            'suspension_start_date' => 'Inicio de suspensión',
            'suspension_days' => 'Días de suspensión',
            'suspension_summary_done' => 'Sumario instruido',
            'notes' => 'Notas',
            'document_path' => 'Documento firmado',
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
            'type' => static::getTypeLabel($value),
            'reason' => static::getReasonLabel($value),
            'issued_at',
            'suspension_start_date' => Carbon::parse($value)->format('d/m/Y'),
            'suspension_summary_done' => $value ? 'Sí' : 'No',
            'document_path' => basename((string) $value),
            'description',
            'notes' => Str::limit((string) $value, 120),
            default => (string) $value,
        };
    }
}
