<?php

namespace App\Services;

use App\Models\Absence;
use App\Models\AttendanceDay;
use App\Models\Deduction;
use App\Models\Employee;
use App\Models\EmployeeDeduction;
use App\Models\Holiday;
use App\Models\Payroll;
use App\Models\Warning;
use App\Models\WarningSuspensionDay;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Traduce la suspensión disciplinaria de una amonestación en deducciones SUS-DIS por día.
 *
 * Cada día laborable suspendido genera un `EmployeeDeduction` puntual (start_date = end_date)
 * que `DeductionCalculator` procesa como cualquier otra deducción. No usa `Absence` ni `AUS-INJ`
 * para que la sanción no se mezcle con faltas arbitrarias ni afecte otros cómputos.
 * Los días suspendidos quedan `on_leave` en asistencia (ver `AttendanceCalculator::isSuspended()`).
 */
class SuspensionService
{
    /** Tope de iteraciones de calendario al resolver días laborables (evita bucles sin horario). */
    private const MAX_CALENDAR_SCAN_DAYS = 60;

    /**
     * Resuelve los días laborables a suspender a partir de la fecha de inicio.
     *
     * Salta francos de rotación, días inactivos del horario fijo y feriados.
     *
     * @return Collection<int, Carbon>
     */
    public function resolveDates(Employee $employee, Carbon $start, int $days): Collection
    {
        $dates = collect();
        $cursor = $start->copy()->startOfDay();

        for ($i = 0; $i < self::MAX_CALENDAR_SCAN_DAYS && $dates->count() < $days; $i++, $cursor->addDay()) {
            if (Holiday::isHoliday($cursor->toDateString())) {
                continue;
            }

            if (AttendanceCalculator::resolveShiftDataFor($employee, $cursor)['check_in'] === null) {
                continue;
            }

            $dates->push($cursor->copy());
        }

        return $dates;
    }

    /**
     * Valida que la suspensión de la amonestación pueda aplicarse.
     *
     * @return Collection<int, Carbon> Fechas laborables resueltas (vacía si no hay suspensión).
     *
     * @throws ValidationException
     */
    public function validate(Warning $warning): Collection
    {
        $days = (int) $warning->suspension_days;
        $existingDates = $warning->exists
            ? $warning->suspensionDays()->pluck('date')->map(fn ($d) => Carbon::parse($d))
            : collect();

        // Quitar o reducir una suspensión ya aplicada exige que sus fechas no estén en nómina.
        $this->assertNoPayroll($warning->employee_id, $existingDates);

        if ($days === 0) {
            return collect();
        }

        if ($days < 0 || $days > Warning::MAX_SUSPENSION_DAYS) {
            $this->fail('suspension_days', 'La suspensión disciplinaria no puede superar '.Warning::MAX_SUSPENSION_DAYS.' días.');
        }

        if (! $warning->suspension_start_date) {
            $this->fail('suspension_start_date', 'Indique la fecha de inicio de la suspensión.');
        }

        if ($days >= Warning::SUMMARY_REQUIRED_FROM_DAYS && ! $warning->suspension_summary_done) {
            $this->fail('suspension_summary_done', 'Una suspensión de '.Warning::SUMMARY_REQUIRED_FROM_DAYS.' a '.Warning::MAX_SUSPENSION_DAYS.' días exige sumario administrativo previo instruido.');
        }

        $employee = Employee::findOrFail($warning->employee_id);

        if ($employee->status !== 'active') {
            $this->fail('employee_id', 'Solo se puede suspender a un empleado activo.');
        }

        if ($employee->getAbsenceDeductionAmount() <= 0) {
            $this->fail('employee_id', 'El empleado no tiene salario ni jornal definido para calcular el descuento.');
        }

        $dates = $this->resolveDates($employee, Carbon::parse($warning->suspension_start_date), $days);

        if ($dates->count() < $days) {
            $this->fail('suspension_start_date', 'No se encontraron suficientes días laborables según el horario o la rotación del empleado.');
        }

        $this->assertNoPayroll($employee->id, $dates);
        $this->assertDatesAvailable($employee, $dates, $warning);

        return $dates;
    }

    /**
     * Aplica la suspensión de la amonestación (reemplaza cualquier aplicación previa).
     *
     * @throws ValidationException
     */
    public function apply(Warning $warning): void
    {
        $dates = $this->validate($warning);

        DB::transaction(function () use ($warning, $dates) {
            $affected = $this->removeApplied($warning);

            if ($dates->isNotEmpty()) {
                $employee = Employee::findOrFail($warning->employee_id);
                $deduction = $this->resolveDeduction();
                $amount = $employee->getAbsenceDeductionAmount();

                foreach ($dates as $date) {
                    $employeeDeduction = EmployeeDeduction::create([
                        'employee_id' => $employee->id,
                        'deduction_id' => $deduction->id,
                        'start_date' => $date->toDateString(),
                        'end_date' => $date->toDateString(),
                        'custom_amount' => $amount,
                        'notes' => 'Suspensión disciplinaria del '.$date->format('d/m/Y').' (amonestación #'.$warning->id.')',
                    ]);

                    WarningSuspensionDay::create([
                        'warning_id' => $warning->id,
                        'employee_id' => $employee->id,
                        'date' => $date->toDateString(),
                        'employee_deduction_id' => $employeeDeduction->id,
                    ]);
                }

                $affected = $affected->merge($dates);
            }

            $this->recalculateAttendance($warning->employee_id, $affected);
        });

        $warning->unsetRelation('suspensionDays');
    }

    /**
     * Revierte la suspensión aplicada (deducciones y días) si no hay nómina de esas fechas.
     *
     * @throws ValidationException
     */
    public function revert(Warning $warning): void
    {
        $this->assertCanRevert($warning);

        DB::transaction(function () use ($warning) {
            $affected = $this->removeApplied($warning);
            $this->recalculateAttendance($warning->employee_id, $affected);
        });

        $warning->unsetRelation('suspensionDays');
    }

    /**
     * Verifica que la suspensión aplicada pueda revertirse (sin nómina de esas fechas).
     *
     * @throws ValidationException
     */
    public function assertCanRevert(Warning $warning): void
    {
        $dates = $warning->suspensionDays()->pluck('date')->map(fn ($d) => Carbon::parse($d));

        $this->assertNoPayroll($warning->employee_id, $dates);
    }

    /**
     * Borra deducciones y días de la suspensión actual.
     *
     * @return Collection<int, Carbon> Fechas que estaban suspendidas.
     */
    private function removeApplied(Warning $warning): Collection
    {
        $rows = $warning->suspensionDays()->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        $deductionIds = $rows->pluck('employee_deduction_id')->filter();
        $dates = $rows->pluck('date')->map(fn ($d) => Carbon::parse($d));

        $warning->suspensionDays()->delete();

        if ($deductionIds->isNotEmpty()) {
            EmployeeDeduction::whereIn('id', $deductionIds)->delete();
        }

        return $dates;
    }

    /** Obtiene (o crea on-demand) la deducción SUS-DIS. */
    private function resolveDeduction(): Deduction
    {
        return Deduction::firstOrCreate(
            ['code' => Deduction::CODE_DISCIPLINARY_SUSPENSION],
            [
                'name' => 'Suspensión Disciplinaria',
                'type' => 'other',
                'description' => 'Descuento por día de suspensión disciplinaria sin goce de sueldo. Generado desde la amonestación.',
                'calculation' => 'fixed',
                'is_mandatory' => false,
                'is_active' => true,
                'affects_irp' => false,
                'apply_judicial_limit' => false,
            ]
        );
    }

    /**
     * Bloquea si el empleado tiene nómina generada cuyo período solapa las fechas.
     *
     * @param  Collection<int, Carbon>  $dates
     *
     * @throws ValidationException
     */
    private function assertNoPayroll(int $employeeId, Collection $dates): void
    {
        if ($dates->isEmpty()) {
            return;
        }

        $from = $dates->min()->toDateString();
        $to = $dates->max()->toDateString();

        $exists = Payroll::where('employee_id', $employeeId)
            ->whereHas('period', fn ($q) => $q->where('start_date', '<=', $to)->where('end_date', '>=', $from))
            ->exists();

        if ($exists) {
            $this->fail('suspension_start_date', 'El empleado ya tiene nómina generada para el período de la suspensión. Elimine o regenere esa nómina antes de modificarla.');
        }
    }

    /**
     * Bloquea fechas con marcaciones reales, ausencias ya descontadas u otra suspensión.
     *
     * @param  Collection<int, Carbon>  $dates
     *
     * @throws ValidationException
     */
    private function assertDatesAvailable(Employee $employee, Collection $dates, Warning $warning): void
    {
        $strings = $dates->map->toDateString()->all();
        $own = $warning->exists ? $warning->suspensionDays()->pluck('date')->map(fn ($d) => Carbon::parse($d)->toDateString())->all() : [];
        $toCheck = array_values(array_diff($strings, $own));

        if ($toCheck !== []) {
            $withEvents = AttendanceDay::where('employee_id', $employee->id)
                ->whereIn('date', $toCheck)
                ->whereHas('events')
                ->count();

            if ($withEvents > 0) {
                $this->fail('suspension_start_date', 'El empleado tiene marcaciones de asistencia en alguna de las fechas de la suspensión.');
            }

            $withAbsenceDeduction = Absence::where('employee_id', $employee->id)
                ->whereNotNull('employee_deduction_id')
                ->whereHas('attendanceDay', fn ($q) => $q->whereIn('date', $toCheck))
                ->count();

            if ($withAbsenceDeduction > 0) {
                $this->fail('suspension_start_date', 'Alguna fecha ya tiene una ausencia con descuento aplicado; se descontaría dos veces.');
            }
        }

        $overlapsOther = WarningSuspensionDay::where('employee_id', $employee->id)
            ->whereIn('date', $strings)
            ->when($warning->exists, fn ($q) => $q->where('warning_id', '!=', $warning->id))
            ->exists();

        if ($overlapsOther) {
            $this->fail('suspension_start_date', 'Alguna fecha ya está cubierta por otra suspensión del empleado.');
        }
    }

    /**
     * Recalcula los días de asistencia ya existentes en las fechas afectadas.
     *
     * @param  Collection<int, Carbon>  $dates
     */
    private function recalculateAttendance(int $employeeId, Collection $dates): void
    {
        if ($dates->isEmpty()) {
            return;
        }

        AttendanceDay::where('employee_id', $employeeId)
            ->whereIn('date', $dates->map->toDateString()->unique()->all())
            ->get()
            ->each(function (AttendanceDay $day) {
                AttendanceCalculator::apply($day);
                $day->save();
            });
    }

    /**
     * @throws ValidationException
     */
    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
