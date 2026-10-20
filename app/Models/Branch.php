<?php

namespace App\Models;

use App\Services\MobileMarkingService;
use Illuminate\Database\Eloquent\Builder;
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
        'is_active',
        'watch_days',
        'watch_from',
        'watch_to',
        'mobile_marking_allowed',
    ];

    protected $fillable = [
        'company_id',
        'name',
        'phone',
        'email',
        'address',
        'city',
        'coordinates',
        'is_active',
        'watch_days',
        'watch_from',
        'watch_to',
        'mobile_marking_allowed',
    ];

    /** @var array<string, mixed> Las sucursales nuevas nacen activas (igual que el default de la base). */
    protected $attributes = [
        'is_active' => true,
    ];

    protected $casts = [
        'coordinates' => 'array',
        'is_active' => 'boolean',
        'watch_days' => 'array',
        'mobile_marking_allowed' => 'boolean',
    ];

    /** Red de seguridad: ninguna ruta puede borrar una sucursal con empleados, terminales o fallas de marcación asociadas. */
    protected static function booted(): void
    {
        static::deleting(function (Branch $branch): void {
            if ($branch->deletionBlockers() !== []) {
                throw new \DomainException("No se puede eliminar la sucursal: tiene {$branch->deletionBlockersSummary()}. Reasígnelos antes de eliminarla.");
            }
        });

        static::saving(function (Branch $branch): void {
            $days = collect($branch->watch_days ?? [])->map(fn ($d) => (int) $d)->unique()->sort()->values()->all();
            $branch->watch_days = $days === [] ? null : $days;
            $branch->watch_from = filled($branch->watch_from) ? $branch->watch_from : null;
            $branch->watch_to = filled($branch->watch_to) ? $branch->watch_to : null;
        });

        static::updating(function (Branch $branch): void {
            if ($branch->isDirty('is_active') && ! $branch->is_active && $branch->activeEmployeesCount() > 0) {
                throw new \DomainException("No se puede desactivar la sucursal: tiene {$branch->activeEmployeesCount()} empleados activos. Reasígnelos primero.");
            }
        });

        static::updated(function (Branch $branch): void {
            if ($branch->wasChanged('mobile_marking_allowed')) {
                MobileMarkingService::revokeDisallowed(Employee::query()->where('branch_id', $branch->id));
            }
        });
    }

    /** Limita a las sucursales activas (las inactivas no se ofrecen al asignar empleados o terminales). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('branches.is_active', true);
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
            'is_active' => 'Estado',
            'watch_days' => 'Días de atención',
            'watch_from' => 'Atiende desde',
            'watch_to' => 'Atiende hasta',
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

        if ($key === 'is_active') {
            return $value ? 'Activa' : 'Inactiva';
        }

        if ($key === 'watch_days') {
            $days = is_string($value) ? json_decode($value, true) : $value;

            return collect($days)->map(fn ($d) => Terminal::WATCH_DAY_OPTIONS[(int) $d] ?? $d)->implode(', ');
        }

        if (in_array($key, ['watch_from', 'watch_to'], true)) {
            return substr((string) $value, 0, 5);
        }

        if ($key === 'company_id') {
            return Company::find($value)?->display_name ?? "Empresa #{$value}";
        }

        return (string) $value;
    }

    /** Cantidad de empleados activos de la sucursal: mientras haya alguno, la sucursal no se puede desactivar. */
    public function activeEmployeesCount(): int
    {
        return $this->employees()->where('status', 'active')->count();
    }

    /**
     * Motivos que impiden desactivar la sucursal, por tipo. Vacío si se puede desactivar.
     *
     * @return array<string, int>
     */
    public function deactivationBlockers(): array
    {
        return array_filter(['empleados activos' => $this->activeEmployeesCount()]);
    }

    /** Terminales activos de la sucursal (no se modifican al desactivarla; solo se informan). */
    public function activeTerminalsCount(): int
    {
        return $this->terminals()->where('status', 'active')->count();
    }

    /** Empleados activos de la sucursal sin contrato vigente (activo o suspendido). */
    public function activeEmployeesWithoutContractCount(): int
    {
        return $this->employees()->where('status', 'active')->whereDoesntHave('activeContract')->count();
    }

    /**
     * Expresiones SQL (0/1) de cada dato pendiente, por alias, sobre la tabla `branches`.
     *
     * Solo cuentan en sucursales activas (una sucursal cerrada no tiene nada pendiente): sin ubicación
     * en el mapa, sin ciudad, sin teléfono, con empleados activos pero sin terminal activo y con
     * empleados activos sin contrato vigente.
     *
     * @return array<string, string>
     */
    public static function pendingDataExpressions(): array
    {
        $activeEmployees = "EXISTS (SELECT 1 FROM employees e WHERE e.branch_id = branches.id AND e.status = 'active')";

        return [
            'missing_coordinates' => "(CASE WHEN branches.is_active = 1 AND (branches.coordinates IS NULL OR JSON_EXTRACT(branches.coordinates, '$.lat') IS NULL) THEN 1 ELSE 0 END)",
            'missing_city' => "(CASE WHEN branches.is_active = 1 AND (branches.city IS NULL OR branches.city = '') THEN 1 ELSE 0 END)",
            'missing_phone' => "(CASE WHEN branches.is_active = 1 AND (branches.phone IS NULL OR branches.phone = '') THEN 1 ELSE 0 END)",
            'missing_terminal' => "(CASE WHEN branches.is_active = 1 AND {$activeEmployees} AND NOT EXISTS (SELECT 1 FROM terminals t WHERE t.branch_id = branches.id AND t.status = 'active') THEN 1 ELSE 0 END)",
            'missing_contracts' => "(CASE WHEN branches.is_active = 1 AND EXISTS (SELECT 1 FROM employees e WHERE e.branch_id = branches.id AND e.status = 'active' AND NOT EXISTS (SELECT 1 FROM contracts c WHERE c.employee_id = e.id AND c.status IN ('active', 'suspended'))) THEN 1 ELSE 0 END)",
        ];
    }

    /**
     * Etiquetas de cada dato pendiente (mismas claves que {@see self::pendingDataExpressions()}).
     *
     * @return array<string, string>
     */
    public static function pendingDataLabels(): array
    {
        return [
            'missing_coordinates' => 'Sin ubicación en el mapa',
            'missing_city' => 'Sin ciudad',
            'missing_phone' => 'Sin teléfono',
            'missing_terminal' => 'Con empleados pero sin terminal activo',
            'missing_contracts' => 'Empleados activos sin contrato',
        ];
    }

    /** Agrega a la consulta una columna 0/1 por cada dato pendiente (`missing_*`) y el total `pending_data_count`. */
    public function scopeWithPendingData(Builder $query): Builder
    {
        $total = implode(' + ', self::pendingDataExpressions());

        $query->addSelect('branches.*');

        foreach (self::pendingDataExpressions() as $alias => $sql) {
            $query->selectRaw("{$sql} AS {$alias}");
        }

        return $query->selectRaw("({$total}) AS pending_data_count");
    }

    /** Limita a las sucursales activas con al menos un dato pendiente. */
    public function scopeHavingPendingData(Builder $query): Builder
    {
        return $query->whereRaw('('.implode(' + ', self::pendingDataExpressions()).') > 0');
    }

    /**
     * Etiquetas de los datos pendientes de una sucursal cargada con {@see self::scopeWithPendingData()}.
     *
     * @return array<int, string>
     */
    public function pendingDataList(): array
    {
        return collect(self::pendingDataLabels())
            ->filter(fn (string $label, string $key) => (int) $this->getAttribute($key) === 1)
            ->values()
            ->all();
    }

    /**
     * Avisos de lo que falta cargar en la sucursal, para la alerta del detalle.
     *
     * @return array<int, string>
     */
    public function missingDataWarnings(): array
    {
        if (! $this->is_active) {
            return [];
        }

        $warnings = [];

        if (! isset($this->coordinates['lat'], $this->coordinates['lng'])) {
            $warnings[] = 'Falta ubicarla en el mapa.';
        }
        if (blank($this->city)) {
            $warnings[] = 'Falta la ciudad.';
        }
        if (blank($this->phone)) {
            $warnings[] = 'Falta el teléfono de contacto.';
        }
        if ($this->activeEmployeesCount() > 0 && $this->activeTerminalsCount() === 0) {
            $warnings[] = 'Tiene empleados activos pero ningún terminal activo para marcar asistencia.';
        }
        if (($withoutContract = $this->activeEmployeesWithoutContractCount()) > 0) {
            $warnings[] = "Tiene {$withoutContract} empleados activos sin contrato vigente.";
        }

        return $warnings;
    }

    /** Indica si la sucursal tiene días u horas de atención configurados. */
    public function hasWatchSchedule(): bool
    {
        return ! empty($this->watch_days) || filled($this->watch_from) || filled($this->watch_to);
    }

    /**
     * Texto legible del horario de atención (ej. "Lun, Mar · 06:00–23:00") o "Sin horario definido"
     * cuando no tiene: en ese caso sus terminales se vigilan 24/7 salvo que tengan horario propio.
     */
    public function watchScheduleLabel(): string
    {
        return $this->hasWatchSchedule()
            ? Terminal::windowLabel($this->watch_days ?? [], $this->watch_from, $this->watch_to)
            : 'Sin horario definido';
    }
}
