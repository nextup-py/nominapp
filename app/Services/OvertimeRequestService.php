<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Notifications\OvertimeRequestNotification;
use App\Settings\GeneralSettings;
use App\Settings\PayrollSettings;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ciclo de vida del pedido previo de horas extra: alta (jefe o RR.HH.), aprobación del jefe, aprobación de RR.HH.,
 * rechazo, cancelación y vencimiento. Toda regla de negocio vive acá; las pantallas solo llaman a estos métodos.
 */
class OvertimeRequestService
{
    public const HR_APPROVE = 'approve_overtime_request';

    public const HR_CREATE = 'create_overtime_request';

    public function __construct(private readonly TeamScopeService $teamScope) {}

    /** Indica si el usuario es de RR.HH. a estos efectos (puede aprobar pedidos: decide en la última instancia). */
    public function isHr(User $user): bool
    {
        return $user->can(self::HR_APPROVE);
    }

    /** Indica si el usuario puede pedir horas extra para el empleado (RR.HH. para cualquiera; un jefe para su equipo). */
    public function canRequestFor(User $user, Employee $employee): bool
    {
        if ($user->can(self::HR_CREATE)) {
            return true;
        }

        return in_array($employee->id, $this->teamScope->employeeIds($user), true);
    }

    /** Indica si el usuario puede decidir como jefe (el empleado está en su equipo). */
    public function canDecideAsBoss(User $user, OvertimeRequest $request): bool
    {
        return $request->status === OvertimeRequest::STATUS_PENDING_BOSS
            && in_array($request->employee_id, $this->teamScope->employeeIds($user), true);
    }

    /** Indica si el usuario puede decidir como RR.HH. un pedido que espera esa decisión. */
    public function canDecideAsHr(User $user, OvertimeRequest $request): bool
    {
        return $request->status === OvertimeRequest::STATUS_PENDING_HR && $this->isHr($user);
    }

    /**
     * Crea un pedido. RR.HH. lo deja aprobado de una vez (puede regularizar fechas pasadas); un jefe lo deja pendiente
     * de RR.HH. (su pedido es su aprobación). Notifica a quien corresponde.
     *
     * @param  array{date_from: string, date_to?: string|null, max_hours: float|int|string, time_from?: ?string, time_to?: ?string, reason?: ?string, notes?: ?string}  $data
     *
     * @throws DomainException Si el pedido no cumple las reglas.
     */
    public function create(User $user, Employee $employee, array $data): OvertimeRequest
    {
        if (! $this->canRequestFor($user, $employee)) {
            throw new DomainException('No puede pedir horas extra para este empleado.');
        }

        $from = Carbon::parse($data['date_from'])->startOfDay();
        $to = Carbon::parse($data['date_to'] ?? $data['date_from'])->startOfDay();
        $timeFrom = filled($data['time_from'] ?? null) ? substr((string) $data['time_from'], 0, 5) : null;
        $timeTo = filled($data['time_to'] ?? null) ? substr((string) $data['time_to'], 0, 5) : null;
        $maxHours = round((float) $data['max_hours'], 2);
        $asHr = $user->can(self::HR_APPROVE);

        $this->validate($user, $employee, $from, $to, $timeFrom, $timeTo, $maxHours, $asHr);

        $request = DB::transaction(function () use ($user, $employee, $data, $from, $to, $timeFrom, $timeTo, $maxHours, $asHr): OvertimeRequest {
            $now = now();

            return OvertimeRequest::create([
                'employee_id' => $employee->id,
                'requested_by_id' => $user->id,
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
                'time_from' => $timeFrom,
                'time_to' => $timeTo,
                'max_hours' => $maxHours,
                'reason' => $data['reason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => $asHr ? OvertimeRequest::STATUS_APPROVED : OvertimeRequest::STATUS_PENDING_HR,
                'boss_approved_by_id' => $asHr ? null : $user->id,
                'boss_approved_at' => $asHr ? null : $now,
                'hr_approved_by_id' => $asHr ? $user->id : null,
                'hr_approved_at' => $asHr ? $now : null,
            ]);
        });

        if ($request->status === OvertimeRequest::STATUS_PENDING_HR) {
            $this->notifyHr($request, $user);
        }

        return $request;
    }

    /**
     * Aprobación del jefe: pasa a pendiente de RR.HH. (o directo a aprobado si quien decide es de RR.HH.).
     *
     * @throws DomainException
     */
    public function bossApprove(User $user, OvertimeRequest $request): OvertimeRequest
    {
        if (! $this->canDecideAsBoss($user, $request)) {
            throw new DomainException('Este pedido no está esperando su aprobación.');
        }

        $request->update([
            'status' => OvertimeRequest::STATUS_PENDING_HR,
            'boss_approved_by_id' => $user->id,
            'boss_approved_at' => now(),
        ]);
        $this->notifyHr($request, $user);

        return $request;
    }

    /**
     * Aprobación de RR.HH.: deja el pedido aprobado (RR.HH. también puede aprobar uno que espera al jefe).
     *
     * @throws DomainException
     */
    public function hrApprove(User $user, OvertimeRequest $request): OvertimeRequest
    {
        if (! $this->isHr($user) || ! $request->isPending()) {
            throw new DomainException('Este pedido no está esperando su aprobación.');
        }

        $request->update([
            'status' => OvertimeRequest::STATUS_APPROVED,
            'hr_approved_by_id' => $user->id,
            'hr_approved_at' => now(),
        ]);
        $this->notifyRequester($request, 'approved', $user);

        return $request;
    }

    /**
     * Rechaza un pedido pendiente (el jefe en su paso, RR.HH. en cualquiera).
     *
     * @throws DomainException
     */
    public function reject(User $user, OvertimeRequest $request, string $reason): OvertimeRequest
    {
        if (! $request->isPending() || ! ($this->canDecideAsBoss($user, $request) || $this->isHr($user))) {
            throw new DomainException('Este pedido no está esperando su decisión.');
        }

        $request->update([
            'status' => OvertimeRequest::STATUS_REJECTED,
            'rejected_by_id' => $user->id,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);
        $this->notifyRequester($request, 'rejected', $user);

        return $request;
    }

    /**
     * Cancela un pedido pendiente o aprobado que todavía no se usó (quien lo pidió o RR.HH.).
     *
     * @throws DomainException
     */
    public function cancel(User $user, OvertimeRequest $request): OvertimeRequest
    {
        if ($blocker = $this->cancellationBlocker($user, $request)) {
            throw new DomainException($blocker);
        }

        $request->update([
            'status' => OvertimeRequest::STATUS_CANCELLED,
            'cancelled_by_id' => $user->id,
            'cancelled_at' => now(),
        ]);

        return $request;
    }

    /** Motivo por el que no se puede cancelar, o null si se puede. */
    public function cancellationBlocker(User $user, OvertimeRequest $request): ?string
    {
        if (! $request->isPending() && $request->status !== OvertimeRequest::STATUS_APPROVED) {
            return 'Solo se cancelan pedidos pendientes o aprobados.';
        }
        if ($request->requested_by_id !== $user->id && ! $this->isHr($user)) {
            return 'Solo quien lo pidió o RR.HH. pueden cancelarlo.';
        }
        if ($request->attendanceDays()->exists()) {
            return 'El pedido ya se aplicó a una jornada: no se puede cancelar.';
        }

        return null;
    }

    /** Marca como vencidos los pedidos pendientes cuya última fecha ya pasó. Devuelve cuántos. */
    public function expireStale(): int
    {
        return OvertimeRequest::query()->pending()->whereDate('date_to', '<', today())->update(['status' => OvertimeRequest::STATUS_EXPIRED]);
    }

    /**
     * @throws DomainException
     */
    private function validate(User $user, Employee $employee, Carbon $from, Carbon $to, ?string $timeFrom, ?string $timeTo, float $maxHours, bool $asHr): void
    {
        if ($employee->status !== 'active') {
            throw new DomainException('El empleado no está activo.');
        }
        if ($to->lt($from)) {
            throw new DomainException('La fecha final no puede ser anterior a la inicial.');
        }
        if ($from->diffInDays($to) > 31) {
            throw new DomainException('Un pedido puede cubrir como máximo 32 días.');
        }
        $maxDaily = (int) app(PayrollSettings::class)->overtime_max_daily_hours;
        if ($maxHours < 0.25 || $maxHours > $maxDaily) {
            throw new DomainException("Las horas por día deben estar entre 0,25 y {$maxDaily} (límite legal diario).");
        }
        if (($timeFrom === null) !== ($timeTo === null)) {
            throw new DomainException('Indique la hora desde y la hora hasta, o deje ambas vacías.');
        }
        if ($timeFrom !== null && $timeFrom === $timeTo) {
            throw new DomainException('La hora desde y la hora hasta no pueden ser iguales.');
        }

        if (! $asHr) {
            if ($to->lt(today())) {
                throw new DomainException('Solo RR.HH. puede cargar un pedido de fechas pasadas.');
            }
            $notice = (int) app(GeneralSettings::class)->attendance_overtime_request_min_notice_hours;
            $start = $from->copy()->setTimeFromTimeString($timeFrom ?? '00:00');
            if ($notice > 0 && now()->diffInHours($start, false) < $notice) {
                throw new DomainException("El pedido debe hacerse con al menos {$notice} horas de anticipación.");
            }
        }

        $overlaps = OvertimeRequest::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', [...OvertimeRequest::pendingStatuses(), OvertimeRequest::STATUS_APPROVED])
            ->whereDate('date_from', '<=', $to->toDateString())
            ->whereDate('date_to', '>=', $from->toDateString())
            ->exists();
        if ($overlaps) {
            throw new DomainException('El empleado ya tiene un pedido pendiente o aprobado que se superpone con esas fechas.');
        }
    }

    /** Avisa a RR.HH. (quienes pueden aprobar) que un pedido espera su decisión. */
    private function notifyHr(OvertimeRequest $request, User $from): void
    {
        try {
            User::all()
                ->filter(fn (User $u): bool => $u->id !== $from->id && $u->can(self::HR_APPROVE))
                ->each->notify(new OvertimeRequestNotification($request, 'hr'));
        } catch (\Throwable $e) {
            Log::warning('No se pudo avisar a RR.HH. de un pedido de horas extra: '.$e->getMessage());
        }
    }

    /** Avisa a quien hizo el pedido el resultado (aprobado o rechazado). */
    private function notifyRequester(OvertimeRequest $request, string $result, User $decider): void
    {
        try {
            $requester = $request->requestedBy;
            if ($requester && $requester->id !== $decider->id) {
                $requester->notify(new OvertimeRequestNotification($request, 'result'));
            }
        } catch (\Throwable $e) {
            Log::warning('No se pudo avisar el resultado de un pedido de horas extra: '.$e->getMessage());
        }
    }
}
