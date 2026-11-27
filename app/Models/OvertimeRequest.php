<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Pedido previo de horas extra: autoriza a un empleado a hacer hasta `max_hours` horas extra por día entre
 * `date_from` y `date_to` (y, si se indica, dentro de la ventana `time_from`–`time_to`). Lo piden el jefe directo o
 * RR.HH.; lo aprueban el jefe y luego RR.HH. Un pedido aprobado autoriza y aprueba solas las horas extra de la jornada
 * que cubre, hasta el tope (ver `AttendanceCalculator`).
 */
class OvertimeRequest extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    public const STATUS_PENDING_BOSS = 'pending_boss';

    public const STATUS_PENDING_HR = 'pending_hr';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'employee_id', 'requested_by_id', 'date_from', 'date_to', 'time_from', 'time_to', 'max_hours', 'reason', 'notes',
        'status', 'boss_approved_by_id', 'boss_approved_at', 'hr_approved_by_id', 'hr_approved_at',
        'rejected_by_id', 'rejected_at', 'rejection_reason', 'cancelled_by_id', 'cancelled_at',
    ];

    /** @var array<int, string> */
    protected array $auditInclude = [
        'employee_id', 'date_from', 'date_to', 'time_from', 'time_to', 'max_hours', 'reason', 'status',
        'rejection_reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date_from' => 'date',
            'date_to' => 'date',
            'max_hours' => 'decimal:2',
            'boss_approved_at' => 'datetime',
            'hr_approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return array<string, string> Estado → etiqueta. */
    public static function getStatusLabels(): array
    {
        return [
            self::STATUS_PENDING_BOSS => 'Pendiente del jefe',
            self::STATUS_PENDING_HR => 'Pendiente de RR.HH.',
            self::STATUS_APPROVED => 'Aprobado',
            self::STATUS_REJECTED => 'Rechazado',
            self::STATUS_CANCELLED => 'Cancelado',
            self::STATUS_EXPIRED => 'Vencido',
        ];
    }

    /** @return array<string, string> Estado → color de badge. */
    public static function getStatusColors(): array
    {
        return [
            self::STATUS_PENDING_BOSS => 'warning',
            self::STATUS_PENDING_HR => 'warning',
            self::STATUS_APPROVED => 'success',
            self::STATUS_REJECTED => 'danger',
            self::STATUS_CANCELLED => 'gray',
            self::STATUS_EXPIRED => 'gray',
        ];
    }

    /** @return array<int, string> Estados que esperan una decisión. */
    public static function pendingStatuses(): array
    {
        return [self::STATUS_PENDING_BOSS, self::STATUS_PENDING_HR];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    /** @return HasMany<AttendanceDay, $this> Jornadas en las que se aplicó el pedido. */
    public function attendanceDays(): HasMany
    {
        return $this->hasMany(AttendanceDay::class);
    }

    /** Etiqueta del estado actual. */
    public function statusLabel(): string
    {
        return self::getStatusLabels()[$this->status] ?? $this->status;
    }

    /** Etiqueta del motivo elegido del catálogo de horas extra. */
    public function reasonLabel(): string
    {
        return AttendanceEvent::overtimeReasonLabel($this->reason);
    }

    /** Indica si espera una decisión (del jefe o de RR.HH.). */
    public function isPending(): bool
    {
        return in_array($this->status, self::pendingStatuses(), true);
    }

    /** Indica si el pedido cubre la fecha (AAAA-MM-DD) indicada. */
    public function coversDate(string $date): bool
    {
        return $this->date_from->toDateString() <= $date && $this->date_to->toDateString() >= $date;
    }

    /** Pedidos aprobados que cubren la fecha. */
    public function scopeCovering(Builder $query, int $employeeId, string $date): Builder
    {
        return $query->where('employee_id', $employeeId)
            ->where('status', self::STATUS_APPROVED)
            ->whereDate('date_from', '<=', $date)
            ->whereDate('date_to', '>=', $date);
    }

    /** Pedidos que esperan una decisión. */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', self::pendingStatuses());
    }

    /** Rango de fechas legible, por ejemplo «28/11/2026» o «28/11/2026 – 30/11/2026». */
    public function rangeLabel(): string
    {
        $from = $this->date_from->format('d/m/Y');

        return $this->date_from->equalTo($this->date_to) ? $from : $from.' – '.$this->date_to->format('d/m/Y');
    }

    /** Ventana horaria legible («18:00–21:00») o null si no tiene. */
    public function windowLabel(): ?string
    {
        return $this->time_from && $this->time_to ? substr((string) $this->time_from, 0, 5).'–'.substr((string) $this->time_to, 0, 5) : null;
    }

    /** @return array<string, string> Nombre legible del campo auditado. */
    public function formatAuditFieldsForPresentation(): array
    {
        return [];
    }
}
