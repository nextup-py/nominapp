<?php

namespace App\Services;

use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Payroll;
use App\Models\Vacation;
use App\Models\VacationBalance;
use App\Settings\PayrollSettings;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VacationService
{
    /**
     * Días de vacaciones según antigüedad (Ley Paraguaya)
     * Configurado en config/payroll.php -> vacation.tiers
     */
    public static function getEntitledDays(int $yearsOfService): int
    {
        $minYearsService = app(PayrollSettings::class)->vacation_min_years_service;

        if ($yearsOfService < $minYearsService) {
            return 0;
        }

        $tiers = config('payroll.vacation.tiers', [
            ['min_years' => 1, 'max_years' => 5, 'days' => 12],
            ['min_years' => 5, 'max_years' => 10, 'days' => 18],
            ['min_years' => 10, 'max_years' => null, 'days' => 30],
        ]);

        foreach ($tiers as $tier) {
            $maxYears = $tier['max_years'] ?? PHP_INT_MAX;
            if ($yearsOfService >= $tier['min_years'] && $yearsOfService <= $maxYears) {
                return $tier['days'];
            }
        }

        // Si no encaja en ningún tier, devolver el último (máximo)
        return $tiers[count($tiers) - 1]['days'] ?? 0;
    }

    /**
     * Calcula los años de servicio del empleado
     */
    public static function getYearsOfService(Employee $employee, ?Carbon $asOfDate = null): int
    {
        if (! $employee->hire_date) {
            return 0;
        }

        $asOfDate = $asOfDate ?? Carbon::now();

        return (int) $employee->hire_date->diffInYears($asOfDate);
    }

    /**
     * Aprueba una solicitud de vacaciones, calcula el monto a pagar y actualiza el balance.
     *
     * El monto vacacional se calcula sobre el promedio de las últimas 6 remuneraciones
     * brutas (Art. 218 CLT). Si no hay nóminas previas, se usa el salario base del contrato.
     * El pago físico se registra por separado con recordPayment().
     */
    public static function approve(Vacation $vacation): void
    {
        DB::transaction(function () use ($vacation) {
            if ($vacation->vacation_balance_id && $vacation->vacationBalance) {
                $vacation->vacationBalance->confirmDays($vacation->business_days ?? 0);
            }

            $paymentAmount = self::calculateVacationPay($vacation->employee, $vacation);

            $vacation->update([
                'status' => 'approved',
                'payment_amount' => $paymentAmount,
                'payment_status' => 'unpaid',
            ]);

            self::createAttendanceDays($vacation);
        });
    }

    /**
     * Genera registros de attendance_days con status on_leave para cada día del período vacacional.
     * Usa updateOrInsert para no duplicar si ya existen registros para esas fechas.
     */
    private static function createAttendanceDays(Vacation $vacation): void
    {
        $period = CarbonPeriod::create($vacation->start_date, $vacation->end_date);
        $holidays = Holiday::whereBetween('date', [$vacation->start_date, $vacation->end_date])
            ->pluck('date')
            ->map(fn ($d) => $d->format('Y-m-d'))
            ->toArray();

        $now = now();

        foreach ($period as $date) {
            $dateStr = $date->format('Y-m-d');
            $isWeekend = $date->isWeekend();
            $isHoliday = in_array($dateStr, $holidays);

            AttendanceDay::withoutEvents(fn () => AttendanceDay::updateOrCreate(
                ['employee_id' => $vacation->employee_id, 'date' => $dateStr],
                [
                    'status' => 'on_leave',
                    'on_vacation' => true,
                    'is_weekend' => $isWeekend,
                    'is_holiday' => $isHoliday,
                    'notes' => 'Vacación aprobada #'.$vacation->id,
                    'is_calculated' => false,
                    'updated_at' => $now,
                ]
            ));
        }
    }

    /**
     * Calcula el monto a pagar por vacaciones remuneradas.
     *
     * Fórmula (Art. 218 CLT):
     *   promedio_mensual = sum(gross_salary de las últimas 6 nóminas) / cantidad_nóminas
     *   tarifa_diaria    = promedio_mensual / 30
     *   monto            = tarifa_diaria × business_days
     *
     * Si el empleado no tiene nóminas previas, se usa el salario base del contrato como fallback.
     */
    public static function calculateVacationPay(Employee $employee, Vacation $vacation): float
    {
        if (($vacation->business_days ?? 0) <= 0) {
            return 0.0;
        }

        $refDate = Carbon::parse($vacation->start_date);
        $sixMonthsAgo = $refDate->copy()->subMonths(6)->startOfMonth();

        $payrolls = Payroll::where('employee_id', $employee->id)
            ->whereHas('period', fn ($q) => $q
                ->where('start_date', '>=', $sixMonthsAgo)
                ->where('start_date', '<', $refDate)
            )
            ->orderByDesc('id')
            ->limit(6)
            ->get();

        if ($payrolls->isEmpty()) {
            $monthlySalary = (float) ($employee->base_salary ?? 0);
            Log::info("VacationService: sin nóminas previas para CI {$employee->ci} {$employee->first_name}, usando salario base Gs. {$monthlySalary} como fallback", [
                'employee_id' => $employee->id,
                'vacation_id' => $vacation->id,
                'monthly_salary' => $monthlySalary,
            ]);
        } else {
            $monthlySalary = round($payrolls->sum('gross_salary') / $payrolls->count(), 2);
        }

        if ($monthlySalary <= 0) {
            Log::warning("VacationService: salario inválido para CI {$employee->ci} {$employee->first_name} (vacación #{$vacation->id}), omitiendo cálculo", [
                'employee_id' => $employee->id,
                'vacation_id' => $vacation->id,
            ]);

            return 0.0;
        }

        $dailyRate = round($monthlySalary / 30, 2);
        $amount = round($dailyRate * $vacation->business_days, 2);

        Log::info("VacationService: monto vacacional calculado — CI {$employee->ci} {$employee->first_name}: Gs. {$amount} ({$vacation->business_days} días hábiles)", [
            'employee_id' => $employee->id,
            'vacation_id' => $vacation->id,
            'payrolls_used' => $payrolls->count(),
            'monthly_avg' => $monthlySalary,
            'daily_rate' => $dailyRate,
            'business_days' => $vacation->business_days,
            'payment_amount' => $amount,
        ]);

        return $amount;
    }

    /**
     * Registra el pago de la remuneración vacacional.
     *
     * Debe llamarse antes de la fecha de inicio de la vacación (Art. 218 CLT).
     * Emite un warning en el log si se registra después del inicio.
     *
     * @param  Carbon|null  $paidAt  Fecha del pago (default: ahora)
     */
    public static function recordPayment(Vacation $vacation, ?Carbon $paidAt = null): void
    {
        $paidAt = $paidAt ?? Carbon::now();

        if ($paidAt->greaterThan(Carbon::parse($vacation->start_date))) {
            Log::warning('VacationService: pago vacacional registrado después del inicio — incumple Art. 218 CLT', [
                'employee_id' => $vacation->employee_id,
                'vacation_id' => $vacation->id,
                'start_date' => $vacation->start_date,
                'paid_at' => $paidAt,
            ]);
        }

        $vacation->update([
            'payment_status' => 'paid',
            'paid_at' => $paidAt,
        ]);
    }

    /**
     * Revierte el registro de pago vacacional, dejando la vacación en estado aprobada sin pagar.
     *
     * Solo revierte el registro en el sistema — la devolución del dinero físico
     * debe gestionarse por fuera.
     */
    public static function reversePayment(Vacation $vacation): void
    {
        $vacation->update([
            'payment_status' => 'unpaid',
            'paid_at' => null,
        ]);
    }

    /**
     * Rechaza una solicitud de vacaciones y libera los días pendientes en una transacción.
     */
    public static function reject(Vacation $vacation): void
    {
        DB::transaction(function () use ($vacation) {
            if ($vacation->vacation_balance_id && $vacation->vacationBalance) {
                $vacation->vacationBalance->releasePendingDays($vacation->business_days ?? 0);
            }
            $vacation->update(['status' => 'rejected']);
        });
    }

    /**
     * Revierte una vacación aprobada a estado pendiente.
     *
     * Deshace el efecto de approve(): devuelve used_days al balance
     * y los re-registra como pending_days, y limpia el monto calculado.
     */
    public static function unapprove(Vacation $vacation): void
    {
        DB::transaction(function () use ($vacation) {
            if ($vacation->vacation_balance_id && $vacation->vacationBalance) {
                $days = $vacation->business_days ?? 0;
                $vacation->vacationBalance->returnUsedDays($days);
                $vacation->vacationBalance->addPendingDays($days);
            }

            $vacation->update([
                'status' => 'pending',
                'payment_amount' => 0,
                'payment_status' => 'unpaid',
            ]);
        });
    }

    /**
     * Libera los días del balance al eliminar una vacación (pendiente o aprobada).
     */
    public static function releaseOnDelete(Vacation $vacation): void
    {
        DB::transaction(function () use ($vacation) {
            if (! $vacation->vacation_balance_id || ! $vacation->vacationBalance) {
                return;
            }

            if ($vacation->isPending()) {
                $vacation->vacationBalance->releasePendingDays($vacation->business_days ?? 0);
            } elseif ($vacation->isApproved()) {
                $vacation->vacationBalance->returnUsedDays($vacation->business_days ?? 0);
            }
        });
    }

    /**
     * Retorna el total de días disponibles del empleado sumando todos sus balances.
     *
     * Suma entitled - used - pending por año (sin cap por año) para reflejar
     * correctamente el saldo acumulativo cuando una solicitud debitó más días
     * que los asignados en un año específico.
     */
    public static function getTotalAvailableDays(Employee $employee): int
    {
        $raw = VacationBalance::where('employee_id', $employee->id)
            ->get()
            ->sum(fn (VacationBalance $b) => $b->entitled_days - $b->used_days - $b->pending_days);

        return max(0, (int) $raw);
    }

    /**
     * Retorna el balance al cual debitar días usando criterio FIFO (más antiguo primero).
     *
     * Busca el balance más antiguo con días disponibles suficientes para cubrir la solicitud.
     * Si ninguno la cubre solo, retorna el más antiguo con cualquier saldo positivo.
     * Acepta parámetros opcionales para excluir días pendientes del propio balance ya asignado
     * (útil al editar una solicitud existente para no contaminar el conteo disponible).
     *
     * @param  int  $fallbackYear  Año al cual crear/buscar balance si ninguno tiene saldo
     * @param  int|null  $excludeFromBalanceId  ID del balance al que restar $excludePendingDays
     * @param  int  $excludePendingDays  Días pendientes a excluir del cómputo de disponibles
     */
    public static function findBalanceToDebit(
        Employee $employee,
        int $days,
        int $fallbackYear,
        ?int $excludeFromBalanceId = null,
        int $excludePendingDays = 0
    ): VacationBalance {
        $balances = VacationBalance::where('employee_id', $employee->id)->orderBy('year')->get();

        $available = fn (VacationBalance $b) => $b->entitled_days - $b->used_days - $b->pending_days
            + ($excludeFromBalanceId === $b->id ? $excludePendingDays : 0);

        foreach ($balances as $balance) {
            if ($available($balance) >= $days) {
                return $balance;
            }
        }

        foreach ($balances as $balance) {
            if ($available($balance) > 0) {
                return $balance;
            }
        }

        return self::getOrCreateBalance($employee, $fallbackYear);
    }

    /**
     * Obtiene o crea el balance de vacaciones del empleado para un año
     */
    public static function getOrCreateBalance(Employee $employee, int $year): VacationBalance
    {
        $balance = VacationBalance::where('employee_id', $employee->id)
            ->where('year', $year)
            ->first();

        if (! $balance) {
            $yearsOfService = self::getYearsOfService($employee, Carbon::create($year, 12, 31));
            $entitledDays = self::getEntitledDays($yearsOfService);

            $balance = VacationBalance::create([
                'employee_id' => $employee->id,
                'year' => $year,
                'years_of_service' => $yearsOfService,
                'entitled_days' => $entitledDays,
                'used_days' => 0,
                'pending_days' => 0,
            ]);
        } elseif ($balance->entitled_days === 0) {
            // Balance existente con entitled_days=0, posiblemente creado con la referencia
            // incorrecta (Jan 1 en lugar de Dec 31). Recalcular y corregir si corresponde.
            $yearsOfService = self::getYearsOfService($employee, Carbon::create($year, 12, 31));
            $entitledDays = self::getEntitledDays($yearsOfService);

            if ($entitledDays > 0) {
                $balance->update([
                    'entitled_days' => $entitledDays,
                    'years_of_service' => $yearsOfService,
                ]);
            }
        }

        return $balance;
    }

    /**
     * Desglose día por día de un período de vacaciones: si cada fecha se descuenta y, si no, por qué (`holiday`,
     * `day_off` según el horario del empleado, `not_working_day` según el ajuste global de días hábiles).
     *
     * @return list<array{date: Carbon, counts: bool, reason: ?string}>
     */
    public static function breakdown(Employee $employee, Carbon $startDate, Carbon $endDate): array
    {
        $settings = app(PayrollSettings::class);
        $holidays = Holiday::whereBetween('date', [$startDate->copy()->startOfDay(), $endDate->copy()->endOfDay()])
            ->pluck('date')
            ->map(fn ($date) => $date->format('Y-m-d'))
            ->all();
        $days = [];

        foreach (CarbonPeriod::create($startDate->copy()->startOfDay(), $endDate->copy()->startOfDay()) as $date) {
            $date = Carbon::instance($date);
            $days[] = ['date' => $date, ...self::classifyDay($employee, $date, $holidays, $settings)];
        }

        return $days;
    }

    /**
     * Clasifica una fecha: ¿se descuenta?, y el motivo si no.
     *
     * @param  list<string>  $holidays  Feriados (Y-m-d) del período.
     * @return array{counts: bool, reason: ?string}
     */
    private static function classifyDay(Employee $employee, Carbon $date, array $holidays, PayrollSettings $settings): array
    {
        if (in_array($date->format('Y-m-d'), $holidays, true)) {
            return ['counts' => false, 'reason' => 'holiday'];
        }

        if ($settings->vacation_count_by_schedule && self::hasScheduleOn($employee, $date)) {
            return AttendanceCalculator::resolveShiftDataFor($employee, $date)['check_in'] !== null
                ? ['counts' => true, 'reason' => null]
                : ['counts' => false, 'reason' => 'day_off'];
        }

        return in_array($date->dayOfWeekIso, $settings->vacation_business_days, true)
            ? ['counts' => true, 'reason' => null]
            : ['counts' => false, 'reason' => 'not_working_day'];
    }

    /** Si el empleado tiene algún horario fijo, rotación o turno puntual ese día (sin ninguno, se usa el ajuste global). */
    private static function hasScheduleOn(Employee $employee, Carbon $date): bool
    {
        return RotationService::getShiftForDate($employee, $date) !== null || $employee->getScheduleForDate($date) !== null;
    }

    /**
     * Calcula los días hábiles entre dos fechas: los del ajuste global (por defecto lunes a sábado) o, con
     * `vacation_count_by_schedule`, los días con turno del empleado; los feriados nunca cuentan.
     */
    public static function calculateBusinessDays(Employee $employee, Carbon $startDate, Carbon $endDate): int
    {
        return count(array_filter(self::breakdown($employee, $startDate, $endDate), fn (array $day): bool => $day['counts']));
    }

    /**
     * Verifica si una fecha es día laboral para el empleado (usado fuera del contexto de vacaciones)
     */
    public static function isWorkDay(Employee $employee, Carbon $date): bool
    {
        $schedule = $employee->getScheduleForDate($date);

        if (! $schedule) {
            $dayOfWeek = $date->dayOfWeekIso;

            return $dayOfWeek >= 1 && $dayOfWeek <= 6;
        }

        return ! $schedule->isDayOff($date->dayOfWeekIso);
    }

    /**
     * Calcula la fecha de reintegro: el primer día después de `end_date` que no es feriado y cuenta como día hábil
     * con el mismo criterio que el descuento de la vacación.
     */
    public static function calculateReturnDate(Employee $employee, Carbon $endDate): Carbon
    {
        $settings = app(PayrollSettings::class);
        $candidate = $endDate->copy()->addDay()->startOfDay();

        for ($i = 0; $i < 30; $i++) {
            $holidays = Holiday::isHoliday($candidate) ? [$candidate->format('Y-m-d')] : [];

            if (self::classifyDay($employee, $candidate, $holidays, $settings)['counts']) {
                return $candidate;
            }

            $candidate->addDay();
        }

        return $endDate->copy()->addDay();
    }

    /**
     * Valida una solicitud de vacaciones
     */
    public static function validateRequest(Employee $employee, Carbon $startDate, Carbon $endDate, ?Vacation $excludeVacation = null): array
    {
        $errors = [];
        $warnings = [];

        // 1. Verificar antigüedad mínima
        $minYearsService = app(PayrollSettings::class)->vacation_min_years_service;
        $yearsOfService = self::getYearsOfService($employee);
        if ($yearsOfService < $minYearsService) {
            $errors[] = "El empleado debe tener al menos {$minYearsService} año(s) de antigüedad para solicitar vacaciones. Antigüedad actual: {$employee->antiquity_description}";
        }

        // 2. Calcular días hábiles
        $businessDays = self::calculateBusinessDays($employee, $startDate, $endDate);

        if ($businessDays < 1) {
            $errors[] = 'El período seleccionado no contiene días hábiles.';
        }

        // 3. Verificar mínimo de días consecutivos (fraccionamiento)
        // Solo advertencia, no error
        $minConsecutiveDays = app(PayrollSettings::class)->vacation_min_consecutive_days;
        if ($businessDays > 0 && $businessDays < $minConsecutiveDays) {
            $warnings[] = "Según la ley, el fraccionamiento mínimo es de {$minConsecutiveDays} días hábiles consecutivos. Se solicitaron {$businessDays} días.";
        }

        // 4. Verificar días disponibles (saldo acumulativo entre todos los años)
        $year = $startDate->year;
        $balance = self::getOrCreateBalance($employee, $year);

        $availableDays = self::getTotalAvailableDays($employee);

        // Si estamos editando, devolver los días de la vacación actual al total
        if ($excludeVacation) {
            $availableDays += $excludeVacation->business_days ?? 0;
        }

        if ($businessDays > $availableDays) {
            $errors[] = "No hay suficientes días disponibles. Solicitados: {$businessDays}, Disponibles: {$availableDays}";
        }

        // 5. Verificar solapamiento con otras vacaciones
        $overlapping = Vacation::where('employee_id', $employee->id)
            ->whereIn('status', ['pending', 'approved'])
            ->where(function ($query) use ($startDate, $endDate) {
                $query->whereBetween('start_date', [$startDate, $endDate])
                    ->orWhereBetween('end_date', [$startDate, $endDate])
                    ->orWhere(function ($q) use ($startDate, $endDate) {
                        $q->where('start_date', '<=', $startDate)
                            ->where('end_date', '>=', $endDate);
                    });
            });

        if ($excludeVacation) {
            $overlapping->where('id', '!=', $excludeVacation->id);
        }

        if ($overlapping->exists()) {
            $errors[] = 'El período seleccionado se solapa con otra solicitud de vacaciones.';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'business_days' => $businessDays,
            'available_days' => $availableDays,
            'balance' => $balance,
        ];
    }

    /**
     * Genera balances para todos los empleados activos de un año
     */
    public static function generateBalancesForYear(int $year): array
    {
        $employees = Employee::where('status', 'active')->get();
        $created = 0;
        $skipped = 0;

        foreach ($employees as $employee) {
            $existing = VacationBalance::where('employee_id', $employee->id)
                ->where('year', $year)
                ->exists();

            if (! $existing) {
                self::getOrCreateBalance($employee, $year);
                $created++;
            } else {
                $skipped++;
            }
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'total' => $employees->count(),
        ];
    }

    /**
     * Recalcula el balance de un empleado basado en sus vacaciones aprobadas
     */
    public static function recalculateBalance(VacationBalance $balance): void
    {
        $usedDays = Vacation::where('vacation_balance_id', $balance->id)
            ->where('status', 'approved')
            ->sum('business_days');

        $pendingDays = Vacation::where('vacation_balance_id', $balance->id)
            ->where('status', 'pending')
            ->sum('business_days');

        $balance->update([
            'used_days' => $usedDays ?? 0,
            'pending_days' => $pendingDays ?? 0,
        ]);
    }
}
