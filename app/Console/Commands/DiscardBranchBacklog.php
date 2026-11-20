<?php

namespace App\Console\Commands;

use App\Models\Absence;
use App\Models\AttendanceDay;
use App\Models\Branch;
use App\Models\User;
use App\Services\AttendanceCalculator;
use App\Services\OpenShiftCloser;
use Illuminate\Console\Command;

/**
 * Deja «en limpio» los pendientes de asistencia de una sucursal que nunca marcó en el sistema (p. ej. se instala el
 * terminal recién ahora): excluye del cálculo las jornadas con tardanza, extras, falta de salida o sin horario por
 * revisar, y justifica con una nota las ausencias pendientes. Nada genera descuento ni pago y las marcaciones se
 * conservan. Omite jornadas de períodos con nómina generada y las que ya tienen decisiones de RR.HH.
 */
class DiscardBranchBacklog extends Command
{
    protected $signature = 'attendance:discard-branch-backlog
                            {--branch= : Nombre o id de la sucursal}
                            {--until= : Última fecha a limpiar, inclusive (por defecto, ayer)}
                            {--reason= : Motivo que queda en las jornadas y ausencias}
                            {--user= : Usuario que figura como revisor (por defecto, el primer Super Admin)}
                            {--dry-run : Muestra cuántas jornadas y ausencias se limpiarían sin guardar}';

    protected $description = 'Excluye del cálculo los pendientes de asistencia de una sucursal y justifica sus ausencias pendientes';

    public function handle(OpenShiftCloser $closer): int
    {
        $branch = Branch::query()
            ->when(is_numeric($this->option('branch')), fn ($q) => $q->whereKey((int) $this->option('branch')), fn ($q) => $q->where('name', $this->option('branch')))
            ->first();

        if (! $branch) {
            $this->error('Indicar una sucursal existente con --branch= (nombre o id).');

            return self::FAILURE;
        }

        if (blank($this->option('reason'))) {
            $this->error('Indicar el motivo con --reason=.');

            return self::FAILURE;
        }

        $reviewerId = (int) ($this->option('user') ?: (User::role('Super Admin')->value('id') ?? User::query()->min('id')));
        $until = $this->option('until') ? now()->parse($this->option('until'))->toDateString() : now()->subDay()->toDateString();
        $from = AttendanceDay::attentionWindowStart();
        $reason = (string) $this->option('reason');
        $dryRun = (bool) $this->option('dry-run');
        $employeeIds = $branch->employees()->where('status', 'active')->pluck('id');

        $days = AttendanceDay::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('attendance_days.date', [$from, $until])
            ->where(fn ($q) => $q
                ->whereRaw(AttendanceDay::PENDING_TARDINESS_SQL)
                ->orWhereRaw(AttendanceDay::PENDING_OVERTIME_SQL)
                ->orWhereRaw(AttendanceDay::missingCheckOutSql(), AttendanceDay::missingCheckOutBindings())
                ->orWhereRaw(AttendanceDay::NO_EXPECTED_SHIFT_SQL))
            ->where(fn ($q) => $q->where('excluded_from_calculation', false)->orWhereNull('excluded_from_calculation'))
            ->with('employee')
            ->get();

        $counts = ['excluded' => 0, 'protected' => 0, 'locked' => 0, 'absences' => 0, 'absences_locked' => 0];

        foreach ($days as $day) {
            if ($closer->isPayrollLocked($day)) {
                $counts['locked']++;

                continue;
            }

            if ($day->overtime_approved || $day->tardiness_deduction_approved || $day->manual_adjustment || $day->second_shift_regular) {
                $counts['protected']++;

                continue;
            }

            $counts['excluded']++;

            if (! $dryRun) {
                $day->excluded_from_calculation = true;
                $day->exclusion_reason = $reason;
                $day->is_calculated = false;
                AttendanceCalculator::apply($day);
                $day->save();
            }
        }

        $absences = Absence::query()
            ->where('absences.status', 'pending')
            ->whereIn('absences.employee_id', $employeeIds)
            ->whereHas('attendanceDay', fn ($q) => $q->whereBetween('attendance_days.date', [$from, $until]))
            ->with('attendanceDay')
            ->get();

        foreach ($absences as $absence) {
            if ($closer->isPayrollLocked($absence->attendanceDay)) {
                $counts['absences_locked']++;

                continue;
            }

            $counts['absences']++;

            if (! $dryRun) {
                $absence->justify($reviewerId, $reason);
            }
        }

        $this->line("Sucursal {$branch->name}: hasta {$until}.");
        $this->line("Jornadas excluidas del cálculo: {$counts['excluded']} · protegidas: {$counts['protected']} · con nómina: {$counts['locked']}.");
        $this->line("Ausencias justificadas: {$counts['absences']} · con nómina: {$counts['absences_locked']}.");
        $this->info($dryRun ? 'Simulación: no se guardó nada.' : 'Cambios guardados.');

        return self::SUCCESS;
    }
}
