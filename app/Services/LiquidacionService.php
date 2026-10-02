<?php

namespace App\Services;

use App\Models\Absence;
use App\Models\Aguinaldo;
use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Models\Liquidacion;
use App\Models\Loan;
use App\Models\Payroll;
use App\Models\VacationBalance;
use App\Models\WarningSuspensionDay;
use App\Settings\PayrollSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LiquidacionService
{
    protected LiquidacionPDFGenerator $pdfGenerator;

    public function __construct(LiquidacionPDFGenerator $pdfGenerator)
    {
        $this->pdfGenerator = $pdfGenerator;
    }

    public function calculate(Liquidacion $liquidacion): Liquidacion
    {
        DB::beginTransaction();

        try {
            // Limpiar items previos si se recalcula
            $liquidacion->items()->delete();

            $settings = app(PayrollSettings::class);
            $employee = $liquidacion->employee;
            $terminationDate = Carbon::parse($liquidacion->termination_date);
            $hireDate = Carbon::parse($liquidacion->hire_date);

            $baseSalary = (float) $liquidacion->base_salary;
            // Usar daily_salary ya calculado (maneja jornal correctamente); recalcular solo si falta
            $dailySalary = $liquidacion->daily_salary > 0
                ? (float) $liquidacion->daily_salary
                : round($baseSalary / 30, 2);

            $isJornal = $liquidacion->salary_type === 'jornal';
            $unit = $isJornal ? 'jornales' : 'días';

            $yearsOfService = $hireDate->diffInYears($terminationDate);
            $monthsOfService = $hireDate->diffInMonths($terminationDate);
            $daysOfService = $hireDate->diffInDays($terminationDate);

            // Detectar período de prueba: usar trial_days del contrato activo (default 30)
            $trialDays = $employee->activeContract?->trial_days ?? 30;
            $inTrialPeriod = $daysOfService <= $trialDays;

            $averageSalary6m = $this->calculateAverageSalary6Months($employee, $terminationDate, $baseSalary);

            $items = [];
            $totalHaberes = 0;
            $totalDeductions = 0;

            // ===== COMPONENTE 1: PREAVISO =====
            $preavisoDays = 0;
            $preavisoAmount = 0;
            if (! $inTrialPeriod && Liquidacion::includesPreaviso($liquidacion->termination_type) && ! $liquidacion->preaviso_otorgado) {
                $preavisoDays = $this->calculatePreavisoDays($yearsOfService);
                if ($preavisoDays > 0) {
                    $preavisoAmount = round($preavisoDays * $dailySalary, 0);
                    $totalHaberes += $preavisoAmount;

                    $items[] = [
                        'type' => 'haber',
                        'category' => 'preaviso',
                        'description' => "Preaviso: {$preavisoDays} {$unit}",
                        'amount' => $preavisoAmount,
                        'metadata' => ['days' => $preavisoDays, 'daily_salary' => $dailySalary, 'unit' => $unit],
                    ];
                }
            }

            // ===== COMPONENTE 2: INDEMNIZACIÓN =====
            $indemnizacionAmount = 0;
            $indemnizacionEstabilidadAmount = 0;
            if (! $inTrialPeriod && Liquidacion::includesIndemnizacion($liquidacion->termination_type)) {
                $indemnizacionAmount = $this->calculateIndemnizacion($yearsOfService, $monthsOfService, $averageSalary6m);
                if ($indemnizacionAmount > 0) {
                    $totalHaberes += $indemnizacionAmount;

                    $daysPerYear = $settings->indemnizacion_days_per_year;
                    $remainingMonths = $monthsOfService % 12;
                    $units = $yearsOfService + ($remainingMonths > 6 ? 1 : 0);
                    $items[] = [
                        'type' => 'haber',
                        'category' => 'indemnizacion',
                        'description' => "Indemnización: {$units} unid. × {$daysPerYear} {$unit} = ".($units * $daysPerYear)." {$unit}",
                        'amount' => $indemnizacionAmount,
                        'metadata' => [
                            'years' => $yearsOfService,
                            'months_fraction' => $remainingMonths,
                            'units' => $units,
                            'average_salary_6m' => $averageSalary6m,
                            'daily_avg' => round($averageSalary6m / 30, 2),
                        ],
                    ];

                    // Estabilidad laboral propia: >10 años = indemnización doble (Art. 95 CLT)
                    if ($yearsOfService >= 10) {
                        $indemnizacionEstabilidadAmount = $indemnizacionAmount;
                        $totalHaberes += $indemnizacionEstabilidadAmount;
                        $items[] = [
                            'type' => 'haber',
                            'category' => 'indemnizacion_estabilidad',
                            'description' => 'Indemnización adicional — Estabilidad Laboral Propia (Art. 95 CLT)',
                            'amount' => $indemnizacionEstabilidadAmount,
                            'metadata' => ['base_indemnizacion' => $indemnizacionAmount],
                        ];
                    }
                }
            }

            // ===== COMPONENTE 3: VACACIONES PROPORCIONALES =====
            // La remuneración vacacional se calcula sobre el promedio de los últimos 6 meses (Art. 218 CLT),
            // no sobre la tarifa contractual diaria.
            [$vacacionesDays, $vacacionesAmount] = $this->calculateVacacionesProporcionales(
                $employee, $terminationDate, $yearsOfService, $daysOfService, $trialDays, $averageSalary6m
            );
            if ($vacacionesAmount > 0) {
                $totalHaberes += $vacacionesAmount;
                $items[] = [
                    'type' => 'haber',
                    'category' => 'vacaciones',
                    'description' => "Vacaciones proporcionales: {$vacacionesDays} {$unit}",
                    'amount' => $vacacionesAmount,
                    'metadata' => ['days' => $vacacionesDays, 'average_salary_6m' => $averageSalary6m, 'daily_avg' => round($averageSalary6m / 30, 2), 'unit' => $unit],
                ];
            }

            // ===== COMPONENTE 4: SALARIO PENDIENTE =====
            // Se calcula antes que el aguinaldo porque este lo usa como base cuando no hay nóminas previas.
            // La ventana arranca el día siguiente a la última nómina del mes (nunca vuelve a pagar días ya liquidados).
            $pendingWindowStart = $this->pendingWindowStart($employee, $terminationDate, $hireDate);

            [$salarioPendienteDays, $salarioPendienteAmount] = $this->calculateSalarioPendiente(
                $employee, $terminationDate, $dailySalary, $pendingWindowStart, $isJornal
            );
            if ($salarioPendienteAmount > 0) {
                $totalHaberes += $salarioPendienteAmount;
                $items[] = [
                    'type' => 'haber',
                    'category' => 'salario_pendiente',
                    'description' => "Salario pendiente: {$salarioPendienteDays} {$unit}".($isJornal ? ' trabajados' : ' del mes'),
                    'amount' => $salarioPendienteAmount,
                    'metadata' => ['days' => $salarioPendienteDays, 'daily_salary' => $dailySalary, 'unit' => $unit],
                ];
            }

            // Jornaleros: descanso semanal remunerado del tramo pendiente, con la misma regla que la nómina
            $descansoSemanalAmount = 0.0;
            if ($isJornal && $pendingWindowStart !== null) {
                $descansoSemanalAmount = round(
                    app(RestDayCalculator::class)->calculateForRange($employee, $pendingWindowStart, $terminationDate)['total'],
                    0
                );
                if ($descansoSemanalAmount > 0) {
                    $totalHaberes += $descansoSemanalAmount;
                    $items[] = [
                        'type' => 'haber',
                        'category' => 'descanso_semanal',
                        'description' => 'Descanso semanal remunerado',
                        'amount' => $descansoSemanalAmount,
                        'metadata' => ['from' => $pendingWindowStart->toDateString(), 'to' => $terminationDate->toDateString()],
                    ];
                }
            }

            // ===== COMPONENTE 5: AGUINALDO PROPORCIONAL =====
            $aguinaldoAmount = $this->calculateAguinaldoProporcional($employee, $terminationDate, $salarioPendienteAmount + $descansoSemanalAmount);
            if ($aguinaldoAmount > 0) {
                $totalHaberes += $aguinaldoAmount;
                $monthsInYear = $terminationDate->month;
                $items[] = [
                    'type' => 'haber',
                    'category' => 'aguinaldo',
                    'description' => "Aguinaldo proporcional: {$monthsInYear} meses del año {$terminationDate->year}",
                    'amount' => $aguinaldoAmount,
                    'metadata' => ['months_in_year' => $monthsInYear, 'year' => $terminationDate->year],
                ];
            }

            // ===== DEDUCCIONES =====

            // Ausencias injustificadas (revisadas por RR.HH.) del tramo que esta liquidación paga.
            // Las de períodos con nómina ya generada se descontaron allí (AUS-INJ).
            [$absenceDays, $absenceDeduction] = $this->calculateAbsenceDeductions(
                $employee, $pendingWindowStart, $terminationDate, $dailySalary, $isJornal
            );
            if ($absenceDeduction > 0) {
                $totalDeductions += $absenceDeduction;
                $items[] = [
                    'type' => 'deduction',
                    'category' => 'ausencias',
                    'description' => "Ausencias injustificadas: {$absenceDays} día(s)",
                    'amount' => $absenceDeduction,
                    'metadata' => ['days' => $absenceDays, 'daily_salary' => $dailySalary],
                ];
            }

            // Suspensión disciplinaria sin goce de sueldo dentro del tramo pagado (SUS-DIS no pasa por nómina aquí)
            [$suspensionDays, $suspensionDeduction] = $this->calculateSuspensionDeduction(
                $employee, $pendingWindowStart, $terminationDate, $dailySalary, $isJornal
            );
            if ($suspensionDeduction > 0) {
                $totalDeductions += $suspensionDeduction;
                $items[] = [
                    'type' => 'deduction',
                    'category' => 'suspension',
                    'description' => "Suspensión disciplinaria: {$suspensionDays} día(s)",
                    'amount' => $suspensionDeduction,
                    'metadata' => ['days' => $suspensionDays, 'daily_salary' => $dailySalary],
                ];
            }

            // IPS 9% sobre salario pendiente + vacaciones (preaviso/indemnización/aguinaldo exentos)
            // Solo se aplica si el empleado tiene activa la deducción IPS en su perfil
            $ipsRate = $settings->ips_employee_rate;
            $ipsDeductionCode = $settings->ips_deduction_code;
            $ipsBase = $salarioPendienteAmount + $descansoSemanalAmount + $vacacionesAmount;
            $hasIps = $employee->deductions()
                ->where('code', $ipsDeductionCode)
                ->wherePivotNull('end_date')
                ->exists();
            $ipsDeduction = ($hasIps && $ipsBase > 0) ? round($ipsBase * ($ipsRate / 100), 0) : 0;
            if ($ipsDeduction > 0) {
                $totalDeductions += $ipsDeduction;
                $items[] = [
                    'type' => 'deduction',
                    'category' => 'ips',
                    'description' => "Aporte IPS Obrero ({$ipsRate}%)",
                    'amount' => $ipsDeduction,
                    'metadata' => ['base' => $ipsBase, 'rate' => $ipsRate],
                ];
            }

            // Préstamos pendientes
            $loanDeduction = $this->calculatePendingLoans($employee);
            if ($loanDeduction > 0) {
                $totalDeductions += $loanDeduction;
                $items[] = [
                    'type' => 'deduction',
                    'category' => 'loan',
                    'description' => 'Saldo de préstamos/adelantos pendientes',
                    'amount' => $loanDeduction,
                    'metadata' => ['loan_ids' => $this->getPendingLoanIds($employee)],
                ];
            }

            $netAmount = $totalHaberes - $totalDeductions;

            // Persistir items
            $liquidacion->items()->createMany($items);

            // Actualizar liquidación
            $liquidacion->update([
                'daily_salary' => $dailySalary,
                'years_of_service' => $yearsOfService,
                'months_of_service' => $monthsOfService,
                'days_of_service' => $daysOfService,
                'average_salary_6m' => $averageSalary6m,
                'preaviso_days' => $preavisoDays,
                'preaviso_amount' => $preavisoAmount,
                'indemnizacion_amount' => $indemnizacionAmount,
                'indemnizacion_estabilidad_amount' => $indemnizacionEstabilidadAmount,
                'vacaciones_days' => $vacacionesDays,
                'vacaciones_amount' => $vacacionesAmount,
                'aguinaldo_proporcional_amount' => $aguinaldoAmount,
                'salario_pendiente_days' => $salarioPendienteDays,
                'salario_pendiente_amount' => $salarioPendienteAmount,
                'ips_deduction' => $ipsDeduction,
                'loan_deduction' => $loanDeduction,
                'total_haberes' => $totalHaberes,
                'total_deductions' => $totalDeductions,
                'net_amount' => $netAmount,
                'status' => 'calculated',
                'calculated_at' => now(),
            ]);

            // Generar PDF
            $pdfPath = $this->pdfGenerator->generate($liquidacion->fresh());
            $liquidacion->update(['pdf_path' => $pdfPath]);

            DB::commit();

            return $liquidacion->fresh();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error al calcular liquidación: '.$e->getMessage(), [
                'liquidacion_id' => $liquidacion->id,
                'employee_id' => $liquidacion->employee_id,
            ]);
            throw $e;
        }
    }

    public function close(Liquidacion $liquidacion): void
    {
        DB::transaction(function () use ($liquidacion) {
            $liquidacion->update([
                'status' => 'closed',
                'closed_at' => now(),
            ]);

            $liquidacion->employee->update(['status' => 'inactive']);

            $liquidacion->employee->activeContract?->update([
                'status' => 'terminated',
                'terminated_at' => now(),
                'end_date' => $liquidacion->termination_date,
            ]);

            $cancelledLoans = $this->getPendingLoanIds($liquidacion->employee);
            $this->cancelPendingLoans($liquidacion->employee);

            $empName = "{$liquidacion->employee->first_name} {$liquidacion->employee->last_name}";
            Log::info("Liquidación cerrada — {$empName}: Gs. {$liquidacion->net_amount} neto liquidado, empleado desactivado", [
                'liquidacion_id' => $liquidacion->id,
                'employee_id' => $liquidacion->employee_id,
                'employee_name' => $empName,
                'net_amount' => $liquidacion->net_amount,
                'cancelled_loan_ids' => $cancelledLoans,
            ]);
        });
    }

    protected function calculatePreavisoDays(int $yearsOfService): int
    {
        $tiers = config('payroll.liquidacion.preaviso_tiers', []);

        foreach ($tiers as $tier) {
            $min = $tier['min_years'];
            $max = $tier['max_years'];

            if ($max === null && $yearsOfService >= $min) {
                return $tier['days'];
            }

            if ($yearsOfService >= $min && $yearsOfService < $max) {
                return $tier['days'];
            }
        }

        return 30; // fallback
    }

    protected function calculateIndemnizacion(int $years, int $totalMonths, float $avgSalary6m): float
    {
        $daysPerYear = app(PayrollSettings::class)->indemnizacion_days_per_year;
        $dailyAvg = $avgSalary6m / 30;

        // Fracción superior a 6 meses cuenta como año completo (Art. 91 CLT)
        $remainingMonths = $totalMonths - ($years * 12);
        $units = $years + ($remainingMonths > 6 ? 1 : 0);

        return round($units * $daysPerYear * $dailyAvg, 0);
    }

    /**
     * NOTA (pendiente de confirmación legal/contable): esta base usa `gross_salary`
     * completo (incluye percepciones no salariales como viáticos y subsidios).
     * `AguinaldoService::calculateItemsFromPayrolls()` en cambio excluye esos
     * conceptos deliberadamente (`base_salary + ips_perceptions`) por no ser
     * salariales a efectos legales. No se unifica todavía porque no está
     * confirmado si el mismo criterio aplica al promedio de 6 meses de la
     * indemnización — requiere validación con asesoría legal/contable antes
     * de cambiar la fórmula (afectaría montos reales de liquidaciones).
     */
    protected function calculateAverageSalary6Months(Employee $employee, Carbon $terminationDate, float $fallbackSalary): float
    {
        $sixMonthsAgo = $terminationDate->copy()->subMonths(6)->startOfMonth();

        $payrolls = Payroll::where('employee_id', $employee->id)
            ->whereHas('period', function ($q) use ($sixMonthsAgo, $terminationDate) {
                $q->where('start_date', '>=', $sixMonthsAgo)
                    ->where('start_date', '<=', $terminationDate);
            })
            ->get();

        // Sin liquidaciones previas: usar salario base de la liquidación (no del empleado,
        // que puede haberse modificado desde el contrato original)
        if ($payrolls->isEmpty()) {
            return $fallbackSalary;
        }

        // Dividir por la cantidad real de meses encontrados (< 6 si el empleado tiene menos tiempo)
        $totalGross = $payrolls->sum('gross_salary');

        return round($totalGross / $payrolls->count(), 2);
    }

    protected function calculateVacacionesProporcionales(
        Employee $employee,
        Carbon $terminationDate,
        int $yearsOfService,
        int $daysOfService,
        int $trialDays,
        float $averageSalary6m
    ): array {
        // Sin derecho a vacaciones dentro del período de prueba
        if ($daysOfService <= $trialDays) {
            return [0, 0];
        }

        // La remuneración vacacional usa la tarifa diaria del promedio de los últimos 6 meses (Art. 218 CLT)
        $dailyAvg = round($averageSalary6m / 30, 2);

        $currentYear = $terminationDate->year;
        $balance = VacationBalance::where('employee_id', $employee->id)
            ->where('year', $currentYear)
            ->first();
        $usedDays = $balance?->used_days ?? 0;

        if ($yearsOfService < 1) {
            // Primer año incompleto: proporcional sobre 12 días base (1 día por mes trabajado)
            $monthsWorked = (int) $employee->hire_date->diffInMonths($terminationDate);
            $proportionalDays = max(0, $monthsWorked - $usedDays);
            $amount = round($proportionalDays * $dailyAvg, 0);

            return [$proportionalDays, $amount];
        }

        // Año completo o más: usar tabla de días según antigüedad
        $entitledDays = $this->getEntitledVacationDays($yearsOfService);

        // Calcular días proporcionales según meses trabajados desde el último aniversario
        $anniversaryDate = $employee->hire_date->copy()->year($currentYear);
        if ($anniversaryDate->gt($terminationDate)) {
            $anniversaryDate->subYear();
        }
        $monthsInPeriod = $anniversaryDate->diffInMonths($terminationDate);
        $proportionalDays = round(($entitledDays * $monthsInPeriod) / 12);

        $unusedDays = max(0, $proportionalDays - $usedDays);
        $amount = round($unusedDays * $dailyAvg, 0);

        return [$unusedDays, $amount];
    }

    protected function getEntitledVacationDays(int $yearsOfService): int
    {
        if ($yearsOfService < 1) {
            return 0;
        }

        $tiers = config('payroll.vacation.tiers', []);

        foreach ($tiers as $tier) {
            $min = $tier['min_years'];
            $max = $tier['max_years'];

            if ($max === null && $yearsOfService >= $min) {
                return $tier['days'];
            }

            if ($yearsOfService >= $min && $yearsOfService < $max) {
                return $tier['days'];
            }
        }

        return 12; // fallback
    }

    protected function calculateAguinaldoProporcional(Employee $employee, Carbon $terminationDate, float $salarioPendienteAmount = 0): float
    {
        $year = $terminationDate->year;

        // Verificar si ya se pagó aguinaldo del año
        $aguinaldoPagado = Aguinaldo::where('employee_id', $employee->id)
            ->whereHas('period', fn ($q) => $q->where('year', $year))
            ->exists();

        if ($aguinaldoPagado) {
            return 0;
        }

        // Sumar payrolls del año hasta la fecha de terminación
        $payrolls = Payroll::where('employee_id', $employee->id)
            ->whereHas('period', function ($q) use ($year, $terminationDate) {
                $q->whereYear('start_date', $year)
                    ->where('start_date', '<=', $terminationDate);
            })
            ->get();

        // Empleado nuevo sin nóminas previas: el aguinaldo se calcula sobre lo ganado en esta liquidación
        if ($payrolls->isEmpty()) {
            return $salarioPendienteAmount > 0 ? round($salarioPendienteAmount / 12, 0) : 0;
        }

        // Solo percepciones salariales (affects_ips) + horas extra; excluye viáticos y subsidios
        $totalEarned = $payrolls->sum(fn ($p) => (float) $p->base_salary + (float) $p->ips_perceptions);

        return round($totalEarned / 12, 0);
    }

    /**
     * Primer día del tramo que esta liquidación paga como salario pendiente.
     *
     * Es el día siguiente a la última nómina generada del mes de terminación (o el inicio del mes /
     * fecha de ingreso si no hay ninguna). Devuelve null si una nómina ya cubre hasta la terminación.
     * Un quincenal con solo la primera mitad generada deja pendiente únicamente la segunda mitad:
     * nunca se vuelven a pagar días ya liquidados.
     */
    protected function pendingWindowStart(Employee $employee, Carbon $terminationDate, Carbon $hireDate): ?Carbon
    {
        $periods = Payroll::where('employee_id', $employee->id)
            ->with('period')
            ->get()
            ->pluck('period')
            ->filter();

        if ($periods->contains(fn ($period) => $period->end_date->gte($terminationDate))) {
            return null;
        }

        $start = $terminationDate->copy()->startOfMonth();

        if ($hireDate->gt($start)) {
            $start = $hireDate->copy();
        }

        $lastEnd = $periods
            ->filter(fn ($period) => $period->end_date->gte($terminationDate->copy()->startOfMonth()))
            ->max(fn ($period) => $period->end_date);

        if ($lastEnd !== null && $lastEnd->copy()->addDay()->gt($start)) {
            $start = $lastEnd->copy()->addDay();
        }

        return $start->startOfDay();
    }

    /**
     * Salario pendiente del tramo [inicio de ventana, terminación].
     *
     * Mensuales: días calendario del tramo × salario diario (salario / 30). Jornaleros: solo los días
     * efectivamente presentes × jornal (los días suspendidos o ausentes no se pagan).
     *
     * @return array{0: int, 1: float} [días, monto]
     */
    protected function calculateSalarioPendiente(Employee $employee, Carbon $terminationDate, float $dailySalary, ?Carbon $windowStart, bool $isJornal = false): array
    {
        if ($windowStart === null || $windowStart->gt($terminationDate)) {
            return [0, 0];
        }

        $daysWorked = $isJornal
            ? AttendanceDay::where('employee_id', $employee->id)
                ->whereBetween('date', [$windowStart->toDateString(), $terminationDate->toDateString()])
                ->where('status', 'present')
                ->count()
            : (int) $windowStart->diffInDays($terminationDate) + 1;

        return [$daysWorked, round($daysWorked * $dailySalary, 0)];
    }

    /**
     * Descuento por ausencias injustificadas del tramo que se paga en esta liquidación.
     *
     * Cuenta solo `Absence` con status `unjustified` (revisadas por RR.HH.): las justificadas y las
     * pendientes no descuentan. Solo tiempo completo; para jornaleros la ausencia ya implica no cobrar el día.
     *
     * @return array{0: int, 1: float} [días, monto]
     */
    protected function calculateAbsenceDeductions(Employee $employee, ?Carbon $windowStart, Carbon $terminationDate, float $dailySalary, bool $isJornal = false): array
    {
        if ($isJornal || $employee->employment_type === 'day_laborer' || $windowStart === null) {
            return [0, 0];
        }

        $absentDays = Absence::where('employee_id', $employee->id)
            ->where('status', 'unjustified')
            ->whereHas('attendanceDay', fn ($q) => $q
                ->whereBetween('date', [$windowStart->toDateString(), $terminationDate->toDateString()])
                ->where(fn ($d) => $d->whereNull('is_holiday')->orWhere('is_holiday', false))
                ->where(fn ($d) => $d->whereNull('is_weekend')->orWhere('is_weekend', false)))
            ->count();

        return $absentDays === 0 ? [0, 0] : [$absentDays, round($absentDays * $dailySalary, 0)];
    }

    /**
     * Descuento por días de suspensión disciplinaria dentro del tramo que se paga en esta liquidación.
     *
     * Solo mensuales: el salario pendiente paga días calendario, así que los días suspendidos se descuentan.
     * Los jornaleros no cobran esos días porque no figuran como presentes.
     *
     * @return array{0: int, 1: float} [días, monto]
     */
    protected function calculateSuspensionDeduction(Employee $employee, ?Carbon $windowStart, Carbon $terminationDate, float $dailySalary, bool $isJornal = false): array
    {
        if ($isJornal || $windowStart === null) {
            return [0, 0];
        }

        $days = WarningSuspensionDay::where('employee_id', $employee->id)
            ->whereBetween('date', [$windowStart->toDateString(), $terminationDate->toDateString()])
            ->count();

        return $days === 0 ? [0, 0] : [$days, round($days * $dailySalary, 0)];
    }

    /**
     * Mensaje de advertencia (no bloqueante) si hay ausencias pendientes de revisión en el tramo liquidado.
     *
     * Esas ausencias no se descuentan; RR.HH. puede resolverlas y recalcular.
     */
    public function getPendingAbsencesWarning(Liquidacion $liquidacion): ?string
    {
        $employee = $liquidacion->employee;
        $terminationDate = Carbon::parse($liquidacion->termination_date);
        $windowStart = $this->pendingWindowStart($employee, $terminationDate, Carbon::parse($liquidacion->hire_date));

        if ($windowStart === null || $liquidacion->salary_type === 'jornal') {
            return null;
        }

        $pending = Absence::where('employee_id', $employee->id)
            ->where('status', 'pending')
            ->whereHas('attendanceDay', fn ($q) => $q->whereBetween('date', [$windowStart->toDateString(), $terminationDate->toDateString()]))
            ->count();

        return $pending > 0
            ? "Hay {$pending} ausencia(s) pendientes de revisión que no se descontaron. Resuélvalas en Ausencias y recalcule si corresponde."
            : null;
    }

    protected function calculatePendingLoans(Employee $employee): float
    {
        return Loan::getTotalActiveDebtForEmployee($employee->id);
    }

    protected function getPendingLoanIds(Employee $employee): array
    {
        return Loan::where('employee_id', $employee->id)
            ->whereIn('status', ['approved', 'pending'])
            ->pluck('id')
            ->toArray();
    }

    protected function cancelPendingLoans(Employee $employee): void
    {
        $activeLoans = Loan::where('employee_id', $employee->id)
            ->whereIn('status', ['approved', 'pending'])
            ->get();

        foreach ($activeLoans as $loan) {
            $loan->installments()->where('status', 'pending')->update([
                'status' => 'cancelled',
                'notes' => 'Cancelado por liquidación/finiquito',
            ]);
            $loan->update([
                'status' => 'cancelled',
                'notes' => ($loan->notes ? $loan->notes."\n\n" : '')
                    .'Cancelado por liquidación. Saldo deducido de la liquidación.',
            ]);
        }
    }
}
