<?php

namespace App\Models;

use App\Notifications\MobileDeviceLinkedNotification;
use App\Notifications\MobileDeviceRelinkedNotification;
use App\Services\DeviceHintsParser;
use App\Services\RestDayCalculator;
use App\Settings\PayrollSettings;
use Carbon\Carbon;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\HasApiTokens;

/**
 * Implementa `Authenticatable` (no solo `HasApiTokens`) por el mismo motivo
 * que `Terminal`: el empleado es el "usuario" autenticado en la API de
 * marcación offline por dispositivo (`routes/api.php`, prefijo `v1/mobile`) — sin
 * esto, `$request->user()` funciona en producción igual, pero el helper de
 * test `Sanctum::actingAs()` exige el contrato con tipado estricto.
 */
class Employee extends Model implements AuthenticatableContract
{
    use Authenticatable, HasApiTokens, HasFactory;

    /** Ability Sanctum requerida para consumir la API de sincronización móvil del propio empleado. */
    public const MOBILE_SYNC_ABILITY = 'mobile:sync';

    /**
     * Cuando es true, el EmployeeObserver omite la asignación automática de deducciones obligatorias.
     * Usado por CreateEmployee para controlar cuáles se asignan según la selección del formulario.
     */
    public static bool $skipMandatoryDeductions = false;

    protected $fillable = [
        'photo',
        'photo_thumbnail',
        'first_name',
        'last_name',
        'ci',
        'birth_date',
        'maternity_protection_until',
        'phone',
        'email',
        'gender',
        'marital_status',
        'nationality',
        'branch_id',
        'reports_to_id',
        'schedule_id',
        'status',
        'face_descriptor',
        'mobile_linked_at',
        'mobile_last_heartbeat_at',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'maternity_protection_until' => 'date',
        'face_descriptor' => 'array',
        'mobile_linked_at' => 'datetime',
        'mobile_last_heartbeat_at' => 'datetime',
    ];

    // ───────────────────────────────────────────
    // Accessors delegados al contrato activo
    // ───────────────────────────────────────────

    public function getHireDateAttribute(): ?Carbon
    {
        return $this->activeContract?->start_date
            ?? $this->contracts()->oldest('start_date')->first()?->start_date;
    }

    public function getPaymentMethodAttribute(): ?string
    {
        return $this->activeContract?->payment_method;
    }

    public function getPositionIdAttribute(): ?int
    {
        return $this->activeContract?->position_id;
    }

    public function getPositionAttribute()
    {
        return $this->activeContract?->position;
    }

    public function getBaseSalaryAttribute(): ?float
    {
        $c = $this->activeContract;

        return ($c && $c->salary_type === 'mensual') ? (float) $c->salary : null;
    }

    public function getDailyRateAttribute(): ?float
    {
        $c = $this->activeContract;

        return ($c && $c->salary_type === 'jornal') ? (float) $c->salary : null;
    }

    public function getPayrollTypeAttribute(): ?string
    {
        return $this->activeContract?->payroll_type;
    }

    public function getEmploymentTypeAttribute(): ?string
    {
        $st = $this->activeContract?->salary_type;

        return $st ? ($st === 'jornal' ? 'day_laborer' : 'full_time') : null;
    }

    /**
     * Relación con el modelo Branch, un empleado pertenece a una sucursal
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Superior directo del empleado. */
    public function reportsTo(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reports_to_id');
    }

    /** Subordinados directos del empleado. */
    public function subordinates(): HasMany
    {
        return $this->hasMany(Employee::class, 'reports_to_id');
    }

    /**
     * Obtiene la empresa del empleado (a través de la sucursal)
     */
    public function getCompanyAttribute(): ?Company
    {
        return $this->branch?->company;
    }

    /**
     * Deducciones aplicadas al empleado
     */
    public function deductions(): BelongsToMany
    {
        return $this->belongsToMany(Deduction::class, 'employee_deductions')
            ->using(EmployeeDeduction::class)
            ->withPivot('start_date', 'end_date', 'custom_amount', 'notes')
            ->withTimestamps();
    }

    /**
     * Todas las percepciones asignadas al empleado (historial completo).
     */
    public function perceptions(): BelongsToMany
    {
        return $this->belongsToMany(Perception::class, 'employee_perceptions')
            ->using(EmployeePerception::class)
            ->withPivot('start_date', 'end_date', 'custom_amount', 'notes')
            ->withTimestamps();
    }

    /**
     * Percepciones activas del empleado: ya iniciadas y sin fecha de fin o con fecha de fin futura.
     */
    public function activePerceptions(): BelongsToMany
    {
        return $this->belongsToMany(Perception::class, 'employee_perceptions')
            ->using(EmployeePerception::class)
            ->withPivot('start_date', 'end_date', 'custom_amount', 'notes')
            ->withTimestamps()
            ->wherePivot('start_date', '<=', now())
            ->where(fn ($q) => $q
                ->whereNull('employee_perceptions.end_date')
                ->orWhere('employee_perceptions.end_date', '>=', now())
            );
    }

    /**
     * Historial completo de asignaciones de deducciones.
     */
    public function employeeDeductions(): HasMany
    {
        return $this->hasMany(EmployeeDeduction::class);
    }

    /**
     * Asignaciones de deducciones activas: ya iniciadas y sin fecha de fin o con fecha de fin futura.
     */
    public function activeEmployeeDeductions(): HasMany
    {
        return $this->hasMany(EmployeeDeduction::class)
            ->where('start_date', '<=', now())
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', now()));
    }

    /**
     * Asignaciones de deducciones inactivas: con fecha de fin ya vencida.
     */
    public function inactiveEmployeeDeductions(): HasMany
    {
        return $this->hasMany(EmployeeDeduction::class)
            ->whereNotNull('end_date')
            ->where('end_date', '<', now());
    }

    /**
     * Historial completo de asignaciones de percepciones.
     */
    public function employeePerceptions(): HasMany
    {
        return $this->hasMany(EmployeePerception::class);
    }

    /**
     * Asignaciones de percepciones activas: ya iniciadas y sin fecha de fin o con fecha de fin futura.
     */
    public function activeEmployeePerceptions(): HasMany
    {
        return $this->hasMany(EmployeePerception::class)
            ->where('start_date', '<=', now())
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', now()));
    }

    /**
     * Asignaciones de percepciones inactivas: con fecha de fin ya vencida.
     */
    public function inactiveEmployeePerceptions(): HasMany
    {
        return $this->hasMany(EmployeePerception::class)
            ->whereNotNull('end_date')
            ->where('end_date', '<', now());
    }

    public function payrolls(): HasMany
    {
        return $this->hasMany(Payroll::class);
    }

    // Relación con el modelo Attendance, un empleado puede tener muchas asistencias
    public function attendanceDays(): HasMany
    {
        return $this->hasMany(AttendanceDay::class);
    }

    /**
     * Relación con el modelo Absence, un empleado puede tener muchas ausencias
     */
    public function absences(): HasMany
    {
        return $this->hasMany(Absence::class);
    }

    /**
     * Relación con el modelo Loan, un empleado puede tener muchos préstamos
     */
    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    /** Adelantos de salario del empleado. */
    public function advances(): HasMany
    {
        return $this->hasMany(Advance::class);
    }

    /**
     * Relación con el modelo Liquidacion, un empleado puede tener varias liquidaciones
     */
    public function liquidaciones(): HasMany
    {
        return $this->hasMany(Liquidacion::class);
    }

    /** Amonestaciones laborales emitidas al empleado. */
    public function warnings(): HasMany
    {
        return $this->hasMany(Warning::class);
    }

    /** Direcciones postales del empleado. */
    public function addresses(): HasMany
    {
        return $this->hasMany(EmployeeAddress::class);
    }

    /** Todos los hijos registrados del empleado. */
    public function children(): HasMany
    {
        return $this->hasMany(EmployeeChild::class);
    }

    /** Hijos menores de 18 años, elegibles para la bonificación familiar (Arts. 253-262 CLT). */
    public function eligibleChildren(): HasMany
    {
        return $this->hasMany(EmployeeChild::class)
            ->where('birth_date', '>', now()->subYears(18)->toDateString());
    }

    // Obtener todos los eventos de asistencia a través de los días
    public function attendanceEvents(): HasManyThrough
    {
        return $this->hasManyThrough(
            AttendanceEvent::class,
            AttendanceDay::class,
            'employee_id',        // FK en attendance_days
            'attendance_day_id',   // FK en attendance_events
            'id',                  // PK en employees
            'id'                   // PK en attendance_days
        );
    }

    /**
     * Horario asignado directamente al empleado (campo legacy, usar getScheduleForDate()).
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    /**
     * Historial completo de asignaciones de horario del empleado.
     */
    public function scheduleAssignments(): HasMany
    {
        return $this->hasMany(EmployeeScheduleAssignment::class);
    }

    /**
     * Historial completo de asignaciones de patrón rotativo del empleado.
     */
    public function rotationAssignments(): HasMany
    {
        return $this->hasMany(RotationAssignment::class);
    }

    /**
     * Overrides puntuales de turno del empleado.
     */
    public function shiftOverrides(): HasMany
    {
        return $this->hasMany(ShiftOverride::class);
    }

    /**
     * Retorna el horario vigente del empleado para una fecha dada.
     * Prioriza la tabla de asignaciones; si no existe, cae al campo legacy schedule_id.
     *
     * @param  Carbon|null  $date  Fecha de consulta (por defecto hoy).
     */
    public function getScheduleForDate(?Carbon $date = null): ?Schedule
    {
        $date ??= Carbon::today();

        $assignment = $this->scheduleAssignments()
            ->where('valid_from', '<=', $date)
            ->where(fn ($q) => $q
                ->whereNull('valid_until')
                ->orWhere('valid_until', '>=', $date)
            )
            ->latest('valid_from')
            ->first();

        return $assignment?->schedule ?? $this->schedule;
    }

    /**
     * Relación con el modelo Document, un empleado puede tener muchos documentos
     */
    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    /**
     * Relación con el modelo Vacation, un empleado puede tener muchas vacaciones
     */
    public function vacations(): HasMany
    {
        return $this->hasMany(Vacation::class);
    }

    /** Cuentas bancarias del empleado. */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(EmployeeBankAccount::class);
    }

    /** Cuenta bancaria principal activa del empleado, si existe. */
    public function primaryBankAccount(): ?EmployeeBankAccount
    {
        return $this->bankAccounts()
            ->where('is_primary', true)
            ->where('status', 'active')
            ->first();
    }

    /**
     * Relación con el modelo VacationBalance, un empleado puede tener balances por año
     */
    public function vacationBalances(): HasMany
    {
        return $this->hasMany(VacationBalance::class);
    }

    /**
     * Relación con el modelo EmployeeLeave, un empleado puede tener muchos permisos
     */
    public function leaves(): HasMany
    {
        return $this->hasMany(EmployeeLeave::class);
    }

    /**
     * Relación con el modelo Contract, un empleado puede tener muchos contratos (historial)
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    /**
     * Relación con registros de enrolamiento facial
     */
    public function faceEnrollments(): HasMany
    {
        return $this->hasMany(FaceEnrollment::class);
    }

    /**
     * Obtiene el contrato vigente del empleado (activo o suspendido).
     * Un contrato suspendido sigue siendo el contrato vigente — el vínculo laboral continúa.
     */
    public function activeContract()
    {
        return $this->hasOne(Contract::class)
            ->whereIn('status', ['active', 'suspended'])
            ->latest('start_date');
    }

    public function getTodayScheduledCheckInAttribute()
    {
        $today = Carbon::now()->dayOfWeekIso;
        $scheduleDay = $this->getScheduleForDate(Carbon::today())?->days()->where('day_of_week', $today)->first();

        return $scheduleDay?->start_time;
    }

    public function getTodayScheduledCheckOutAttribute()
    {
        $today = Carbon::now()->dayOfWeekIso;
        $scheduleDay = $this->getScheduleForDate(Carbon::today())?->days()->where('day_of_week', $today)->first();

        return $scheduleDay?->end_time;
    }

    public function getTodayExpectedBreakMinutesAttribute()
    {
        $today = Carbon::now()->dayOfWeekIso;
        $scheduleDay = $this->getScheduleForDate(Carbon::today())?->days()->where('day_of_week', $today)->first();

        return $scheduleDay?->total_break_minutes;
    }

    public function scopeWithPayrollTypeAndSalary($query, string $type)
    {
        return $query->whereHas('activeContract', fn ($q) => $q->where('payroll_type', $type)->whereNotNull('salary')
        );
    }

    /**
     * Scope para filtrar empleados activos
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope para filtrar empleados inactivos
     */
    public function scopeInactive($query)
    {
        return $query->where('status', 'inactive');
    }

    /**
     * Obtiene el nombre completo del empleado con CI
     */
    public function getFullNameWithCiAttribute(): string
    {
        return "{$this->first_name} {$this->last_name} (CI: {$this->ci})";
    }

    /**
     * Obtiene el nombre completo del empleado
     */
    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    /**
     * URL del avatar generado automáticamente con las iniciales del empleado
     */
    public function getAvatarUrlAttribute(): string
    {
        return 'https://ui-avatars.com/api/?name='.urlencode($this->full_name);
    }

    /**
     * Calcula el monto de deducción por un día de ausencia
     * - Tiempo completo: salario_base / 30
     * - Jornalero: tarifa_diaria (daily_rate)
     */
    public function getAbsenceDeductionAmount(): float
    {
        if ($this->employment_type === 'day_laborer') {
            return (float) $this->daily_rate;
        }

        // Tiempo completo: dividir salario base entre 30 días
        return (float) ($this->base_salary / 30);
    }

    /**
     * Scope para filtrar empleados con rostro registrado
     */
    public function scopeWithFace($query)
    {
        return $query->whereNotNull('face_descriptor');
    }

    /**
     * Scope para filtrar empleados sin rostro registrado
     */
    public function scopeWithoutFace($query)
    {
        return $query->whereNull('face_descriptor');
    }

    /**
     * Scope para filtrar empleados enrolados con descriptor de calidad baja o sin score registrado.
     * Identifica empleados que no tienen ningún enrollment aprobado con face_score >= 0.70.
     * Cubre: score bajo (< 0.70), score nulo (enrolados antes de registrar la métrica) y
     * casos sin enrollment aprobado vinculado.
     */
    public function scopeWithWeakFaceDescriptor($query)
    {
        return $query->whereNotNull('face_descriptor')
            ->whereDoesntHave('faceEnrollments', fn ($e) => $e
                ->where('status', 'approved')
                ->where('face_score', '>=', 0.70)
            );
    }

    /**
     * Obtiene los conteos de empleados por estado y rostro de forma optimizada
     */
    public static function getTabCounts(): array
    {
        $statusCounts = static::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $faceCounts = static::selectRaw('
            SUM(CASE WHEN face_descriptor IS NOT NULL THEN 1 ELSE 0 END) as with_face,
            SUM(CASE WHEN face_descriptor IS NULL THEN 1 ELSE 0 END) as without_face
        ')
            ->first();

        return [
            'all' => static::count(),
            'active' => $statusCounts['active'] ?? 0,
            'inactive' => $statusCounts['inactive'] ?? 0,
            'with_face' => $faceCounts->with_face ?? 0,
            'without_face' => $faceCounts->without_face ?? 0,
            'weak_face' => static::active()->withWeakFaceDescriptor()->count(),
        ];
    }

    /**
     * Obtiene el salario base formateado
     */
    public function getBaseSalaryFormattedAttribute()
    {
        if ($this->base_salary === null) {
            return null;
        }

        return number_format((int) $this->base_salary, 0, '', '.');
    }

    /**
     * Obtiene la tarifa diaria formateada
     */
    public function getDailyRateFormattedAttribute()
    {
        if ($this->daily_rate === null) {
            return null;
        }

        return number_format((int) $this->daily_rate, 0, '', '.');
    }

    /**
     * Obtiene la URL de la foto del empleado o la imagen por defecto
     */
    public function getPhotoUrlAttribute(): string
    {
        return $this->photo ?: url('/images/default-avatar.png');
    }

    /**
     * Obtiene las opciones de tipo de empleo para filtros y selects
     */
    public static function getEmploymentTypeOptions(): array
    {
        return [
            'full_time' => 'Tiempo Completo',
            'day_laborer' => 'Jornalero',
        ];
    }

    /**
     * Obtiene las opciones de tipo de nómina para filtros y selects
     */
    public static function getPayrollTypeOptions(): array
    {
        return [
            'monthly' => 'Mensual',
            'biweekly' => 'Quincenal',
            'weekly' => 'Semanal',
        ];
    }

    /**
     * Obtiene las opciones de método de pago para filtros y selects
     */
    public static function getPaymentMethodOptions(): array
    {
        return [
            'debit' => 'Tarjeta de Débito',
            'cash' => 'Efectivo',
            'check' => 'Cheque',
        ];
    }

    /**
     * Obtiene las opciones de estado para filtros y selects
     */
    public static function getStatusOptions(): array
    {
        return [
            'active' => 'Activo',
            'inactive' => 'Inactivo',
        ];
    }

    /**
     * Obtiene las opciones de género para filtros y selects.
     *
     * @return array<string, string>
     */
    public static function getGenderOptions(): array
    {
        return [
            'masculino' => 'Masculino',
            'femenino' => 'Femenino',
        ];
    }

    /**
     * Obtiene los colores de badge por género.
     *
     * @return array<string, string>
     */
    public static function getGenderColors(): array
    {
        return [
            'masculino' => 'info',
            'femenino' => 'primary',
        ];
    }

    /**
     * Obtiene los íconos por género.
     *
     * @return array<string, string>
     */
    public static function getGenderIcons(): array
    {
        return [
            'masculino' => 'heroicon-o-user',
            'femenino' => 'heroicon-o-user',
        ];
    }

    /**
     * Obtiene la etiqueta formateada del género del empleado.
     */
    public function getGenderLabelAttribute(): ?string
    {
        return self::getGenderOptions()[$this->gender] ?? null;
    }

    /**
     * Obtiene el color del badge para el género del empleado.
     */
    public function getGenderColorAttribute(): string
    {
        return self::getGenderColors()[$this->gender] ?? 'gray';
    }

    /**
     * Opciones de estado civil para formularios.
     *
     * @return array<string, string>
     */
    public static function getMaritalStatusOptions(): array
    {
        return [
            'soltero' => 'Soltero/a',
            'casado' => 'Casado/a',
            'divorciado' => 'Divorciado/a',
            'viudo' => 'Viudo/a',
            'union_libre' => 'Unión libre',
        ];
    }

    /**
     * Etiqueta formateada del estado civil del empleado.
     */
    public function getMaritalStatusLabelAttribute(): ?string
    {
        return self::getMaritalStatusOptions()[$this->marital_status] ?? null;
    }

    /**
     * Obtiene las opciones de meses para filtros
     */
    public static function getMonthOptions(): array
    {
        return [
            1 => 'Enero',
            2 => 'Febrero',
            3 => 'Marzo',
            4 => 'Abril',
            5 => 'Mayo',
            6 => 'Junio',
            7 => 'Julio',
            8 => 'Agosto',
            9 => 'Septiembre',
            10 => 'Octubre',
            11 => 'Noviembre',
            12 => 'Diciembre',
        ];
    }

    /**
     * Obtiene el color del badge para el tipo de empleo
     */
    public function getEmploymentTypeColorAttribute(): string
    {
        return match ($this->employment_type) {
            'full_time' => 'success',
            'day_laborer' => 'warning',
            default => 'gray',
        };
    }

    /**
     * Obtiene el ícono para el tipo de empleo
     */
    public function getEmploymentTypeIconAttribute(): string
    {
        return match ($this->employment_type) {
            'full_time' => 'heroicon-o-clock',
            'day_laborer' => 'heroicon-o-calendar-days',
            default => 'heroicon-o-question-mark-circle',
        };
    }

    /**
     * Obtiene la etiqueta formateada del tipo de empleo
     */
    public function getEmploymentTypeLabelAttribute(): ?string
    {
        return self::getEmploymentTypeOptions()[$this->employment_type] ?? $this->employment_type;
    }

    /**
     * Obtiene el color del badge para el tipo de nómina
     */
    public function getPayrollTypeColorAttribute(): string
    {
        return match ($this->payroll_type) {
            'monthly' => 'info',
            'biweekly' => 'success',
            'weekly' => 'warning',
            default => 'gray',
        };
    }

    /**
     * Obtiene el ícono para el tipo de nómina
     */
    public function getPayrollTypeIconAttribute(): string
    {
        return match ($this->payroll_type) {
            'monthly' => 'heroicon-o-calendar',
            'biweekly' => 'heroicon-o-calendar-days',
            'weekly' => 'heroicon-o-clock',
            default => 'heroicon-o-question-mark-circle',
        };
    }

    /**
     * Obtiene la etiqueta formateada del tipo de nómina
     */
    public function getPayrollTypeLabelAttribute(): ?string
    {
        return self::getPayrollTypeOptions()[$this->payroll_type] ?? $this->payroll_type;
    }

    /**
     * Obtiene el color del badge para el estado del empleado
     */
    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'active' => 'success',
            'inactive' => 'danger',
            default => 'gray',
        };
    }

    /**
     * Obtiene el ícono para el estado del empleado
     */
    public function getStatusIconAttribute(): string
    {
        return match ($this->status) {
            'active' => 'heroicon-o-check-circle',
            'inactive' => 'heroicon-o-x-circle',
            default => 'heroicon-o-question-mark-circle',
        };
    }

    /**
     * Obtiene la etiqueta formateada del estado del empleado
     */
    public function getStatusLabelAttribute(): string
    {
        return self::getStatusOptions()[$this->status] ?? $this->status;
    }

    /**
     * Obtiene la URL de contacto (WhatsApp o Email)
     */
    public function getContactUrlAttribute(): ?string
    {
        if ($this->phone) {
            return 'https://api.whatsapp.com/send?phone=595'.ltrim($this->phone, '0');
        }

        if ($this->email) {
            return 'mailto:'.$this->email;
        }

        return null;
    }

    /**
     * Obtiene el texto de contacto formateado
     */
    public function getContactTextAttribute(): string
    {
        return $this->phone ?: 'Sin datos';
    }

    /**
     * Verifica si el empleado tiene rostro registrado
     */
    public function getHasFaceAttribute(): bool
    {
        return filled($this->face_descriptor);
    }

    /**
     * Obtiene el tooltip para el rostro
     */
    public function getFaceTooltipAttribute(): string
    {
        return $this->has_face ? 'Rostro registrado' : 'Sin rostro registrado';
    }

    /**
     * Obtiene la descripción de antigüedad
     */
    public function getAntiquityDescriptionAttribute(): string
    {
        return $this->hire_date
            ? $this->hire_date->diffForHumans(null, true).' en la empresa'
            : 'Sin fecha de ingreso';
    }

    /**
     * Obtiene la descripción de la edad
     */
    public function getAgeDescriptionAttribute(): string
    {
        return $this->birth_date ? $this->birth_date->age.' años' : '';
    }

    /**
     * Indica si la empleada está en período de protección de maternidad (Ley 5508/15).
     * Devuelve true si maternity_protection_until existe y es hoy o en el futuro.
     */
    public function isUnderMaternityProtection(): bool
    {
        return $this->maternity_protection_until !== null
            && $this->maternity_protection_until->isFuture();
    }

    /**
     * Calcula el salario devengado de referencia para adelantos.
     * - Mensual: salario base fijo del contrato activo.
     * - Jornalero: (días efectivamente trabajados en el período actual × jornal diario) más el
     *   descanso semanal remunerado devengado, calculado con la misma regla que la nómina
     *   (`RestDayCalculator`). El adelanto solo puede solicitarse sobre trabajo ya realizado (Art. MTESS).
     */
    public function getAdvanceReferenceSalary(): ?float
    {
        if ($this->base_salary && $this->base_salary > 0) {
            return (float) $this->base_salary;
        }

        if ($this->daily_rate && $this->daily_rate > 0) {
            $today = Carbon::today()->toDateString();

            $period = PayrollPeriod::where('frequency', $this->payroll_type)
                ->where('start_date', '<=', $today)
                ->where('end_date', '>=', $today)
                ->first();

            if (! $period) {
                return null;
            }

            $workedDays = $this->attendanceDays()
                ->whereBetween('date', [$period->start_date, $period->end_date])
                ->where('status', 'present')
                ->count();

            if ($workedDays === 0) {
                return null;
            }

            $restDay = app(RestDayCalculator::class)->calculateForRange($this, $period->start_date, $period->end_date)['total'];

            return (float) ($workedDays * $this->daily_rate) + $restDay;
        }

        return null;
    }

    /**
     * Calcula el monto máximo de adelanto según el porcentaje configurado en PayrollSettings.
     *
     * @return float|null Monto máximo en Gs., o null si el empleado no tiene salario definido.
     */
    public function getMaxAdvanceAmount(): ?float
    {
        $reference = $this->getAdvanceReferenceSalary();

        if (! $reference) {
            return null;
        }

        $percent = app(PayrollSettings::class)->advance_max_percent;

        return round($reference * $percent / 100, 2);
    }

    /**
     * Verifica si el empleado puede solicitar un adelanto
     */
    public function canRequestAdvance(): bool
    {
        // Debe tener salario de referencia definido (mensual o jornalero)
        if (! $this->getAdvanceReferenceSalary()) {
            return false;
        }

        // Debe estar activo
        if ($this->status !== 'active') {
            return false;
        }

        // No debe tener un adelanto activo o pendiente
        return Loan::getActiveLoanForEmployee($this->id, 'advance') === null;
    }

    /**
     * Obtiene el color del badge para el método de pago
     */
    public function getPaymentMethodColorAttribute(): string
    {
        return match ($this->payment_method) {
            'debit' => 'success',
            'cash' => 'warning',
            'check' => 'info',
            default => 'gray',
        };
    }

    /**
     * Obtiene el ícono para el método de pago
     */
    public function getPaymentMethodIconAttribute(): string
    {
        return match ($this->payment_method) {
            'debit' => 'heroicon-o-credit-card',
            'cash' => 'heroicon-o-banknotes',
            'check' => 'heroicon-o-document-text',
            default => 'heroicon-o-question-mark-circle',
        };
    }

    /**
     * Obtiene la etiqueta formateada del método de pago
     */
    public function getPaymentMethodLabelAttribute(): ?string
    {
        return self::getPaymentMethodOptions()[$this->payment_method] ?? $this->payment_method;
    }

    /**
     * Obtiene la descripción formateada de created_at
     */
    public function getCreatedAtDescriptionAttribute(): string
    {
        return $this->created_at->format('d/m/Y H:i');
    }

    /**
     * Obtiene la fecha formateada tipo SINCE de created_at
     */
    public function getCreatedAtSinceAttribute(): string
    {
        return $this->created_at->diffForHumans();
    }

    /**
     * Obtiene la descripción formateada de updated_at
     */
    public function getUpdatedAtDescriptionAttribute(): string
    {
        return $this->updated_at->format('d/m/Y H:i');
    }

    /**
     * Obtiene la fecha formateada tipo SINCE de updated_at
     */
    public function getUpdatedAtSinceAttribute(): string
    {
        return $this->updated_at->diffForHumans();
    }

    /**
     * Sanitiza y normaliza los datos del formulario de empleado
     */
    public static function sanitizeFormData(array $data, bool $isCreating = false): array
    {
        // Convertir nombres y apellidos a mayúsculas
        if (isset($data['first_name'])) {
            $data['first_name'] = mb_strtoupper($data['first_name'], 'UTF-8');
        }

        if (isset($data['last_name'])) {
            $data['last_name'] = mb_strtoupper($data['last_name'], 'UTF-8');
        }

        // Convertir email a minúsculas y limpiar espacios
        if (isset($data['email'])) {
            $data['email'] = strtolower(trim($data['email']));
        }

        // Limpiar CI: eliminar ceros a la izquierda y espacios
        if (isset($data['ci'])) {
            $data['ci'] = ltrim(str_replace(' ', '', $data['ci']), '0') ?: '0';
        }

        // Limpiar teléfono: eliminar espacios y guiones; conservar el 0 inicial (formato Paraguay)
        if (isset($data['phone'])) {
            $cleaned = preg_replace('/[\s\-]/', '', $data['phone'] ?? '');
            $data['phone'] = $cleaned !== '' ? $cleaned : null;
        }

        // Si es creación, asegurar que face_descriptor sea null
        if ($isCreating) {
            $data['face_descriptor'] = null;
        }

        return $data;
    }

    /**
     * Asigna todas las deducciones obligatorias activas que el empleado aún no tenga.
     * Si se pasa $restrictToIds, solo asigna las que estén en ese array.
     *
     * @param  array<int>|null  $restrictToIds  IDs de deducciones a asignar; null = todas las obligatorias activas.
     * @return int Cantidad de deducciones asignadas (0 si ya las tenía todas)
     */
    public function assignMandatoryDeductions(?array $restrictToIds = null): int
    {
        $query = Deduction::where('is_mandatory', true)->where('is_active', true);

        if ($restrictToIds !== null) {
            $query->whereIn('id', $restrictToIds);
        }

        $mandatoryIds = $query->pluck('id');

        if ($mandatoryIds->isEmpty()) {
            return 0;
        }

        $alreadyActiveIds = $this->employeeDeductions()
            ->whereIn('deduction_id', $mandatoryIds)
            ->where('start_date', '<=', now())
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', now()))
            ->pluck('deduction_id');

        $toAssignIds = $mandatoryIds->diff($alreadyActiveIds);

        if ($toAssignIds->isEmpty()) {
            return 0;
        }

        $now = now();
        $today = $now->toDateString();

        // Reactivar registros que ya tienen start_date=hoy (evita violar el unique key)
        $reactivateIds = $this->employeeDeductions()
            ->whereIn('deduction_id', $toAssignIds)
            ->whereDate('start_date', $today)
            ->pluck('deduction_id');

        if ($reactivateIds->isNotEmpty()) {
            $this->employeeDeductions()
                ->whereIn('deduction_id', $reactivateIds)
                ->whereDate('start_date', $today)
                ->update(['end_date' => null, 'updated_at' => $now]);
        }

        // Insertar solo los que realmente no tienen registro hoy
        $newIds = $toAssignIds->diff($reactivateIds);

        if ($newIds->isNotEmpty()) {
            $this->employeeDeductions()->insert(
                $newIds->map(fn ($id) => [
                    'employee_id' => $this->id,
                    'deduction_id' => $id,
                    'start_date' => $today,
                    'end_date' => null,
                    'custom_amount' => null,
                    'notes' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->values()->toArray()
            );
        }

        return $toAssignIds->count();
    }

    // =========================================================================
    // MARCACIÓN OFFLINE POR DISPOSITIVO — VINCULACIÓN Y TOKEN SANCTUM
    // =========================================================================

    /** Historial completo de dispositivos vinculados (uno por cada vinculación real). */
    public function devices(): HasMany
    {
        return $this->hasMany(EmployeeDevice::class)->latest('linked_at');
    }

    /** Dispositivo actualmente vinculado (unlinked_at null) — a lo sumo uno por empleado. */
    public function activeDevice(): HasOne
    {
        return $this->hasOne(EmployeeDevice::class)->whereNull('unlinked_at')->latest('linked_at');
    }

    /**
     * Emite un token Sanctum (ability `mobile:sync`) para el dispositivo personal
     * del empleado, tras validarse con CI + fecha de nacimiento (ver
     * `MobileLinkController`). Revoca cualquier token móvil previo — un solo
     * dispositivo vinculado a la vez, igual que `Terminal::claimSanctumToken()`.
     *
     * @param  string|null  $clientHintModel  Modelo reportado por Client Hints del navegador
     *                                        (`navigator.userAgentData.getHighEntropyValues(['model'])`), cuando está disponible —
     *                                        ver DeviceHintsParser para el detalle de qué navegadores lo soportan.
     * @return string Token Sanctum en texto plano — solo se retorna una vez, nunca se persiste en claro.
     */
    public function claimMobileToken(?string $userAgent = null, ?string $clientHintModel = null): string
    {
        // Se evalúa antes de pisar mobile_linked_at — distingue una vinculación
        // nueva (informativa) de una re-vinculación (ver MobileDeviceRelinkedNotification:
        // CI+fecha es una credencial débil, esto da visibilidad a un admin ante un
        // posible acoso/DoS dirigido).
        $isRelink = $this->hasMobileLinked();
        $linkedAt = now();

        $this->tokens()->where('name', 'like', 'mobile:%')->delete();

        // Cierra el dispositivo activo anterior (si había) antes de abrir uno
        // nuevo — nunca quedan dos registros con unlinked_at null a la vez.
        $this->activeDevice?->update(['unlinked_at' => $linkedAt]);
        // Marca/modelo sugeridos (best-effort, editables) a partir del User-Agent
        // y, si el navegador lo soporta, de Client Hints — ver DeviceHintsParser.
        // Siempre se aplican acá: cada vinculación crea un EmployeeDevice nuevo,
        // no hay un valor manual previo que pisar (a diferencia de Terminal, que
        // reutiliza el mismo registro entre reprovisiones).
        $guess = DeviceHintsParser::guess($userAgent, $clientHintModel);
        $newDevice = $this->devices()->create([
            'linked_at' => $linkedAt,
            'user_agent' => $userAgent,
            'device_brand' => $guess['brand'],
            'device_model' => $guess['model'],
        ]);
        // setRelation en vez de dejar que quede cacheado el activeDevice anterior (o
        // null) en esta misma instancia — sin esto, $employee->activeDevice después de
        // llamar a este método devolvería el valor viejo hasta un refresh() explícito.
        $this->setRelation('activeDevice', $newDevice);

        $this->forceFill(['mobile_linked_at' => $linkedAt])->save();

        $notification = $isRelink
            ? new MobileDeviceRelinkedNotification($this, $userAgent)
            : new MobileDeviceLinkedNotification($this, $userAgent);

        // La vinculación ya quedó persistida arriba — un fallo al notificar (ej. mailer
        // mal configurado) no debe convertirse en un 500 que deje el enlace/CI+fecha
        // consumido sin token emitido (mismo gotcha corregido en
        // Terminal::claimSanctumToken()).
        try {
            User::all()->each(fn (User $user) => $user->notify($notification));
        } catch (\Throwable $e) {
            Log::warning("No se pudo notificar la vinculación de dispositivo móvil del empleado #{$this->id}: {$e->getMessage()}", [
                'employee_id' => $this->id,
            ]);
        }

        return $this->createToken('mobile:'.$this->id, [self::MOBILE_SYNC_ABILITY])->plainTextToken;
    }

    /** Revoca el token móvil del empleado (dispositivo perdido/robado, o desvinculación manual desde Filament). */
    public function revokeMobileToken(): void
    {
        $this->tokens()->where('name', 'like', 'mobile:%')->delete();
        $this->activeDevice?->update(['unlinked_at' => now()]);
        $this->setRelation('activeDevice', null);
        $this->forceFill(['mobile_linked_at' => null, 'mobile_last_heartbeat_at' => null])->save();
    }

    /** Indica si el empleado tiene un dispositivo vinculado actualmente. */
    public function hasMobileLinked(): bool
    {
        return $this->mobile_linked_at !== null;
    }
}
