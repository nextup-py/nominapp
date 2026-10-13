<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use OwenIt\Auditing\Contracts\Auditable;

/** Sucursal de una empresa: lugar físico donde trabajan los empleados y se instalan los terminales. */
class Branch extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    /** @var array<int, string> Campos auditados en el historial de cambios (se omiten los timestamps). */
    protected array $auditInclude = [
        'company_id',
        'name',
        'phone',
        'email',
        'address',
        'city',
        'coordinates',
    ];

    protected $fillable = [
        'company_id',
        'name',
        'phone',
        'email',
        'address',
        'city',
        'coordinates',
    ];

    protected $casts = [
        'coordinates' => 'array',
    ];

    /** Red de seguridad: ninguna ruta puede borrar una sucursal con empleados, terminales o fallas de marcación asociadas. */
    protected static function booted(): void
    {
        static::deleting(function (Branch $branch): void {
            if ($branch->deletionBlockers() !== []) {
                throw new \DomainException("No se puede eliminar la sucursal: tiene {$branch->deletionBlockersSummary()}. Reasígnelos antes de eliminarla.");
            }
        });
    }

    /**
     * Empresa a la que pertenece la sucursal
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Empleados de la sucursal
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * Empleados activos de la sucursal
     */
    public function activeEmployees(): HasMany
    {
        return $this->hasMany(Employee::class)->where('status', 'active');
    }

    /**
     * Terminales de marcación de la sucursal
     */
    public function terminals(): HasMany
    {
        return $this->hasMany(Terminal::class);
    }

    /**
     * Cantidad de registros que impiden eliminar la sucursal, por tipo. Vacío si se puede eliminar.
     *
     * Los terminales tienen FK `restrict` (el borrado fallaría en la base) y las fallas de marcación
     * perderían su sucursal en silencio (`nullOnDelete`): ninguna debe quedar sin aviso.
     *
     * @return array<string, int>
     */
    public function deletionBlockers(): array
    {
        return array_filter([
            'empleados' => $this->employees()->count(),
            'terminales' => $this->terminals()->count(),
            'fallas de marcación' => AttendanceMarkFailure::where('branch_id', $this->id)->count(),
        ]);
    }

    /** Texto legible de {@see self::deletionBlockers()}, p. ej. "3 empleados y 1 terminales". */
    public function deletionBlockersSummary(): string
    {
        $parts = collect($this->deletionBlockers())
            ->map(fn (int $count, string $label) => "{$count} {$label}")
            ->values();

        return $parts->count() > 1
            ? $parts->slice(0, -1)->implode(', ').' y '.$parts->last()
            : (string) $parts->first();
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
            'company_id' => 'Empresa',
            'name' => 'Nombre',
            'phone' => 'Teléfono',
            'email' => 'Correo electrónico',
            'address' => 'Dirección',
            'city' => 'Ciudad',
            'coordinates' => 'Coordenadas',
        ];

        $html = '<ul class="space-y-0.5 text-sm">';
        foreach ($values as $key => $value) {
            $label = $fieldLabels[$key] ?? Str::headline($key);
            $formatted = e($this->formatAuditValue($key, $value));
            $html .= "<li><span class=\"text-gray-500\">{$label}:</span> <span class=\"font-medium\">{$formatted}</span></li>";
        }

        return new HtmlString($html.'</ul>');
    }

    /** Formatea un valor individual del audit a texto legible. */
    private function formatAuditValue(string $key, mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '—';
        }

        if ($key === 'coordinates') {
            $coordinates = is_string($value) ? json_decode($value, true) : $value;

            return is_array($coordinates) && isset($coordinates['lat'], $coordinates['lng'])
                ? $coordinates['lat'].', '.$coordinates['lng']
                : '—';
        }

        if ($key === 'company_id') {
            return Company::find($value)?->display_name ?? "Empresa #{$value}";
        }

        return (string) $value;
    }
}
