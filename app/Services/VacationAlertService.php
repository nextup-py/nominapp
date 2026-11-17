<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Vacation;
use App\Settings\PayrollSettings;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Calcula los avisos de vacaciones para RR.HH. Solo lee: no envía nada (ver `vacations:notify`).
 * Cada aviso se activa y se dimensiona desde `PayrollSettings` (`vacation_alert_*`).
 */
class VacationAlertService
{
    /** Días desde el inicio de una vacación aprobada sin pagar para considerarla olvidada. */
    public const UNPAID_AFTER_DAYS = 7;

    /** Cantidad máxima de nombres que se listan en el cuerpo de un aviso. */
    public const SAMPLE_SIZE = 5;

    public function __construct(private readonly PayrollSettings $settings) {}

    /**
     * Solicitudes pendientes de decisión desde hace más de `vacation_alert_pending_days`.
     *
     * @return array{count: int, oldest: ?Carbon, days: int}|null null si el aviso está apagado o no hay casos
     */
    public function pending(): ?array
    {
        $days = $this->settings->vacation_alert_pending_days;

        if ($days <= 0) {
            return null;
        }

        $query = Vacation::query()
            ->where('status', 'pending')
            ->where('created_at', '<', now()->subDays($days));

        $count = $query->count();

        return $count === 0 ? null : [
            'count' => $count,
            'oldest' => Carbon::parse($query->min('created_at')),
            'days' => $days,
        ];
    }

    /**
     * Vacaciones aprobadas, con inicio hace más de `UNPAID_AFTER_DAYS` días, que no figuran pagadas.
     *
     * @return array{count: int, oldest: ?Carbon}|null
     */
    public function unpaid(): ?array
    {
        if (! $this->settings->vacation_alert_unpaid_enabled) {
            return null;
        }

        $query = Vacation::query()
            ->where('status', 'approved')
            ->where('payment_status', '!=', 'paid')
            ->where('start_date', '<', now()->subDays(self::UNPAID_AFTER_DAYS)->toDateString());

        $count = $query->count();

        return $count === 0 ? null : ['count' => $count, 'oldest' => Carbon::parse($query->min('start_date'))];
    }

    /**
     * Empleados activos con el derecho anual completo (según su antigüedad de hoy) todavía sin tomar.
     *
     * @return array{count: int, names: list<string>}|null
     */
    public function unused(): ?array
    {
        if (! $this->settings->vacation_alert_unused_enabled) {
            return null;
        }

        $names = Employee::query()
            ->where('status', 'active')
            ->with('activeContract')
            ->get()
            ->filter(function (Employee $employee): bool {
                $entitled = VacationService::getEntitledDays(VacationService::getYearsOfService($employee));

                return $entitled > 0 && VacationService::getTotalAvailableDays($employee) >= $entitled;
            })
            ->map(fn (Employee $employee): string => trim("{$employee->first_name} {$employee->last_name}"))
            ->values();

        return $names->isEmpty() ? null : $this->summarize($names);
    }

    /**
     * Empleados activos que cumplen su primer año de antigüedad en los próximos `vacation_alert_anniversary_days` días.
     *
     * @return array{count: int, names: list<string>}|null
     */
    public function anniversary(): ?array
    {
        $days = $this->settings->vacation_alert_anniversary_days;

        if ($days <= 0) {
            return null;
        }

        $today = now()->startOfDay();

        $names = Employee::query()
            ->where('status', 'active')
            ->with('activeContract')
            ->get()
            ->filter(function (Employee $employee) use ($today, $days): bool {
                $first = $employee->hire_date?->copy()->addYear()->startOfDay();

                return $first !== null && $first->greaterThanOrEqualTo($today) && $first->lessThanOrEqualTo($today->copy()->addDays($days));
            })
            ->map(fn (Employee $employee): string => trim("{$employee->first_name} {$employee->last_name}"))
            ->values();

        return $names->isEmpty() ? null : $this->summarize($names);
    }

    /**
     * @param  Collection<int, string>  $names
     * @return array{count: int, names: list<string>}
     */
    private function summarize(Collection $names): array
    {
        return ['count' => $names->count(), 'names' => $names->sort()->take(self::SAMPLE_SIZE)->values()->all()];
    }
}
