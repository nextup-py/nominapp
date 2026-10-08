<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use OwenIt\Auditing\Contracts\Auditable;

/** Empresa empleadora: entidad legal de la que cuelgan sucursales, departamentos y empleados. */
class Company extends Model implements Auditable
{
    use HasFactory;
    use \OwenIt\Auditing\Auditable;

    /** @var array<int, string> Campos auditados en el historial de cambios (se omiten el logo en base64 y los timestamps). */
    protected array $auditInclude = [
        'name',
        'trade_name',
        'legal_type',
        'founded_at',
        'legal_rep_name',
        'legal_rep_ci',
        'ruc',
        'employer_number',
        'logo',
        'address',
        'phone',
        'email',
        'city',
        'is_active',
    ];

    protected $fillable = [
        'name',
        'trade_name',
        'legal_type',
        'founded_at',
        'legal_rep_name',
        'legal_rep_ci',
        'ruc',
        'employer_number',
        'logo',
        'logo_thumbnail',
        'address',
        'phone',
        'email',
        'city',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'founded_at' => 'date',
    ];

    /** Red de seguridad: ninguna ruta (panel, tinker, código) puede borrar una empresa con datos asociados. */
    protected static function booted(): void
    {
        static::deleting(function (Company $company): void {
            if ($company->deletionBlockers() !== []) {
                throw new \DomainException("No se puede eliminar la empresa: tiene {$company->deletionBlockersSummary()}. Desactívela en su lugar.");
            }
        });

        static::updating(function (Company $company): void {
            if ($company->isDirty('is_active') && ! $company->is_active && $company->activeEmployeesCount() > 0) {
                throw new \DomainException("No se puede desactivar la empresa: tiene {$company->activeEmployeesCount()} empleados activos. Desvincúlelos o transfiéralos primero.");
            }
        });
    }

    /**
     * Tipos societarios legales permitidos
     */
    public static array $legalTypes = [
        'EAS' => 'Empresa por Acciones Simplificadas (EAS)',
        'SA' => 'Sociedad Anónima (SA)',
        'SRL' => 'Sociedad de Resp. Limitada (SRL)',
        'SACI' => 'Sociedad Anónima de Cap. e Industria (SACI)',
        'SC' => 'Sociedad Colectiva (SC)',
        'EU' => 'Empresa Unipersonal',
        'Cooperativa' => 'Cooperativa',
        'Consorcio' => 'Consorcio',
        'Fundacion' => 'Fundación',
        'Asociacion' => 'Asociación',
    ];

    /**
     * Ciudades disponibles (pueden ser ampliadas según necesidades)
     */
    public static array $cities = [
        'Asunción',
        'Areguá',
        'Capiatá',
        'Fernando de la Mora',
        'Guarambaré',
        'Itá',
        'Itauguá',
        'J. Augusto Saldívar',
        'Lambaré',
        'Limpio',
        'Luque',
        'Mariano Roque Alonso',
        'Nueva Italia',
        'Ñemby',
        'San Antonio',
        'San Lorenzo',
        'Villa Elisa',
        'Villeta',
        'Ypacaraí',
        'Ypané',
    ];

    /**
     * Sucursales de la empresa
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    /** Cuentas bancarias de la empresa. */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(CompanyBankAccount::class);
    }

    /** Cuenta bancaria principal activa de la empresa, si existe. */
    public function primaryBankAccount(): ?CompanyBankAccount
    {
        return $this->bankAccounts()
            ->where('is_primary', true)
            ->where('status', 'active')
            ->first();
    }

    /**
     * Empleados de la empresa (a través de sucursales)
     */
    public function employees(): HasManyThrough
    {
        return $this->hasManyThrough(Employee::class, Branch::class);
    }

    /** Empleados activos de la empresa (a través de sucursales). */
    public function activeEmployees(): HasManyThrough
    {
        return $this->hasManyThrough(Employee::class, Branch::class)
            ->where('employees.status', 'active');
    }

    /** Terminales de marcación de la empresa (a través de sus sucursales). */
    public function terminals(): HasManyThrough
    {
        return $this->hasManyThrough(Terminal::class, Branch::class);
    }

    /** Períodos de nómina de la empresa. */
    public function payrollPeriods(): HasMany
    {
        return $this->hasMany(PayrollPeriod::class);
    }

    /** Períodos de aguinaldo de la empresa. */
    public function aguinaldoPeriods(): HasMany
    {
        return $this->hasMany(AguinaldoPeriod::class);
    }

    /** Departamentos de la empresa. */
    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    /**
     * Nombre para mostrar (comercial o razón social)
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->trade_name ?? $this->name;
    }

    /**
     * Label del tipo societario
     */
    public function getLegalTypeLabelAttribute(): ?string
    {
        return $this->legal_type ? (static::$legalTypes[$this->legal_type] ?? $this->legal_type) : null;
    }

    /**
     * Opciones de ciudades para selects (nombre como clave y valor).
     *
     * Usa el catálogo oficial `py_cities` (opcionalmente acotado a un departamento) y, si esa tabla
     * todavía no fue sembrada en la instalación, cae a la lista fija histórica. `$current` agrega el
     * valor ya guardado cuando no figura en el catálogo, para que el select no lo muestre vacío.
     *
     * @return array<string, string>
     */
    public static function citiesOptions(?int $departmentId = null, ?string $current = null): array
    {
        $names = PyCity::query()
            ->when($departmentId, fn ($q) => $q->where('py_department_id', $departmentId))
            ->orderBy('name')
            ->pluck('name')
            ->all();

        if ($names === [] && ! $departmentId) {
            $names = static::$cities;
        }

        if (filled($current) && ! in_array($current, $names, true)) {
            $names[] = $current;
        }

        return array_combine($names, $names);
    }

    /** Id del departamento al que pertenece una ciudad del catálogo, para precargar el select dependiente. */
    public static function departmentIdForCity(?string $city): ?int
    {
        return filled($city) ? PyCity::where('name', $city)->value('py_department_id') : null;
    }

    /**
     * Cantidad de registros que impiden eliminar la empresa, por tipo. Vacío si se puede eliminar.
     *
     * Varias de esas tablas borran en cascada (departamentos, cuentas, períodos de aguinaldo, lotes
     * bancarios, turnos y rotaciones), otras fallarían por FK: ninguna debe perderse sin aviso.
     *
     * @return array<string, int>
     */
    public function deletionBlockers(): array
    {
        $counts = [
            'sucursales' => $this->branches()->count(),
            'departamentos' => $this->departments()->count(),
            'cuentas bancarias' => $this->bankAccounts()->count(),
            'períodos de nómina' => PayrollPeriod::where('company_id', $this->id)->count(),
            'períodos de aguinaldo' => AguinaldoPeriod::where('company_id', $this->id)->count(),
            'lotes de pago bancario' => DisbursementBatch::where('company_id', $this->id)->count(),
            'plantillas de contrato' => ContractTemplate::where('company_id', $this->id)->count(),
            'plantillas de turno' => ShiftTemplate::where('company_id', $this->id)->count(),
            'patrones de rotación' => RotationPattern::where('company_id', $this->id)->count(),
        ];

        return array_filter($counts);
    }

    /** Texto legible de {@see self::deletionBlockers()}, p. ej. "2 sucursales y 1 departamentos". */
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
     * Datos que usan los PDFs y los pagos y que todavía no están cargados.
     *
     * @return array<int, string>
     */
    public function missingDataWarnings(): array
    {
        $warnings = [];

        if (blank($this->logo)) {
            $warnings[] = 'Falta el logo: los PDFs (recibos, contratos, liquidaciones) salen sin él.';
        }
        if (blank($this->address) || blank($this->city)) {
            $warnings[] = 'Falta la dirección o la ciudad: aparecen en el encabezado de los PDFs.';
        }
        if (blank($this->legal_rep_name) || blank($this->legal_rep_ci)) {
            $warnings[] = 'Falta el representante legal: se usa en contratos y liquidaciones.';
        }
        if ($this->primaryBankAccount() === null) {
            $warnings[] = 'Sin cuenta bancaria principal activa: no se pueden generar lotes de pago bancario.';
        }

        return $warnings;
    }

    /** Cantidad de empleados activos de la empresa: mientras haya alguno, la empresa no se puede desactivar. */
    public function activeEmployeesCount(): int
    {
        return Employee::query()
            ->whereIn('branch_id', $this->branches()->select('id'))
            ->where('status', 'active')
            ->count();
    }

    /**
     * Motivos que impiden desactivar la empresa, por tipo. Vacío si se puede desactivar.
     *
     * @return array<string, int>
     */
    public function deactivationBlockers(): array
    {
        return array_filter(['empleados activos' => $this->activeEmployeesCount()]);
    }

    /**
     * Expresiones SQL (0/1) de cada dato pendiente, por alias, sobre la tabla `companies`.
     *
     * Son las cuatro condiciones que el listado marca como "datos pendientes": sin logo, sin
     * sucursales, sin cuenta bancaria principal activa y con empleados activos sin contrato vigente.
     *
     * @return array<string, string>
     */
    public static function pendingDataExpressions(): array
    {
        return [
            'missing_logo' => "(CASE WHEN companies.logo IS NULL OR companies.logo = '' THEN 1 ELSE 0 END)",
            'missing_branches' => '(CASE WHEN EXISTS (SELECT 1 FROM branches b WHERE b.company_id = companies.id) THEN 0 ELSE 1 END)',
            'missing_bank' => "(CASE WHEN EXISTS (SELECT 1 FROM company_bank_accounts a WHERE a.company_id = companies.id AND a.is_primary = 1 AND a.status = 'active') THEN 0 ELSE 1 END)",
            'missing_contracts' => "(CASE WHEN EXISTS (SELECT 1 FROM employees e JOIN branches eb ON eb.id = e.branch_id WHERE eb.company_id = companies.id AND e.status = 'active' AND NOT EXISTS (SELECT 1 FROM contracts c WHERE c.employee_id = e.id AND c.status IN ('active', 'suspended'))) THEN 1 ELSE 0 END)",
        ];
    }

    /** Etiquetas de cada dato pendiente (mismas claves que {@see self::pendingDataExpressions()}). */
    public static function pendingDataLabels(): array
    {
        return [
            'missing_logo' => 'Sin logo',
            'missing_branches' => 'Sin sucursales',
            'missing_bank' => 'Sin cuenta bancaria principal',
            'missing_contracts' => 'Empleados activos sin contrato',
        ];
    }

    /** Agrega a la consulta una columna 0/1 por cada dato pendiente (`missing_*`) y el total `pending_data_count`. */
    public function scopeWithPendingData(Builder $query): Builder
    {
        $total = implode(' + ', self::pendingDataExpressions());

        $query->addSelect('companies.*');

        foreach (self::pendingDataExpressions() as $alias => $sql) {
            $query->selectRaw("{$sql} AS {$alias}");
        }

        return $query->selectRaw("({$total}) AS pending_data_count");
    }

    /** Limita a las empresas con al menos un dato pendiente. */
    public function scopeHavingPendingData(Builder $query): Builder
    {
        return $query->whereRaw('('.implode(' + ', self::pendingDataExpressions()).') > 0');
    }

    /**
     * Etiquetas de los datos pendientes de una empresa cargada con {@see self::scopeWithPendingData()}.
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

    /** Empleados activos de la empresa sin contrato vigente (activo o suspendido). */
    public function activeEmployeesWithoutContractCount(): int
    {
        return Employee::query()
            ->whereIn('branch_id', $this->branches()->select('id'))
            ->where('status', 'active')
            ->whereDoesntHave('activeContract')
            ->count();
    }

    /** Terminales activos de las sucursales de la empresa. */
    public function activeTerminalsCount(): int
    {
        return Terminal::query()
            ->whereIn('branch_id', $this->branches()->select('id'))
            ->where('status', 'active')
            ->count();
    }

    /** Períodos de nómina de la empresa que empiezan en el año indicado (por defecto, el actual). */
    public function payrollPeriodsCount(?int $year = null): int
    {
        return PayrollPeriod::where('company_id', $this->id)
            ->whereYear('start_date', $year ?? now()->year)
            ->count();
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
            'name' => 'Razón social',
            'trade_name' => 'Nombre comercial',
            'legal_type' => 'Tipo societario',
            'founded_at' => 'Fecha de constitución',
            'legal_rep_name' => 'Representante legal',
            'legal_rep_ci' => 'CI del representante',
            'ruc' => 'RUC',
            'employer_number' => 'Nro. patronal IPS',
            'logo' => 'Logo',
            'address' => 'Dirección',
            'phone' => 'Teléfono',
            'email' => 'Correo electrónico',
            'city' => 'Ciudad',
            'is_active' => 'Estado',
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
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($key) {
            'legal_type' => static::$legalTypes[$value] ?? (string) $value,
            'founded_at' => Carbon::parse($value)->format('d/m/Y'),
            'is_active' => $value ? 'Activa' : 'Inactiva',
            'logo' => basename((string) $value),
            default => (string) $value,
        };
    }

    /**
     * Label de la ciudad
     */
    public function getCityLabelAttribute(): ?string
    {
        return $this->city ?? null;
    }

    /**
     * Resumen de empleados activos y total en una sola query
     */
    public function getEmployeesSummary(): string
    {
        $result = DB::table('employees')
            ->join('branches', 'branches.id', '=', 'employees.branch_id')
            ->where('branches.company_id', $this->id)
            ->selectRaw('COUNT(*) as total, SUM(employees.status = "active") as active_count')
            ->first();

        return ($result?->active_count ?? 0).' / '.($result?->total ?? 0);
    }

    /**
     * Contratos activos de todos los empleados de la empresa
     */
    public function activeContractsCount(): int
    {
        return Contract::whereIn(
            'employee_id',
            $this->employees()->select('employees.id')
        )->where('status', 'active')->count();
    }

    /**
     * Contratos activos a plazo fijo que vencen dentro de X días
     */
    public function expiringSoonContractsCount(int $days = 30): int
    {
        return Contract::whereIn(
            'employee_id',
            $this->employees()->select('employees.id')
        )->where('status', 'active')
            ->whereNotNull('end_date')
            ->where('end_date', '<=', now()->addDays($days))
            ->count();
    }

    /**
     * Scope para empresas activas
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
