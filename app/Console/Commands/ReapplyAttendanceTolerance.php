<?php

namespace App\Console\Commands;

use App\Models\AttendanceDay;
use App\Services\AttendanceCalculator;
use App\Services\OpenShiftCloser;
use App\Settings\GeneralSettings;
use Illuminate\Console\Command;

/**
 * Recalcula las jornadas recientes cuyas horas extra o tardanza pendientes caen dentro de las tolerancias vigentes.
 *
 * Una jornada ya calculada conserva sus valores aunque después se configure una tolerancia: quedan «extras» de 5 o
 * 10 minutos por aprobar que hoy no se contarían. Se recalcula con las horas esperadas guardadas (no relee el
 * horario) y se omiten las jornadas con aprobaciones, ajustes manuales o exclusión y las de períodos con nómina.
 */
class ReapplyAttendanceTolerance extends Command
{
    protected $signature = 'attendance:reapply-tolerance
                            {--days= : Ventana en días hacia atrás (por defecto, la de «Requieren atención»)}
                            {--dry-run : Muestra qué cambiaría sin guardar}';

    protected $description = 'Recalcula las extras y tardanzas pendientes que están dentro de las tolerancias configuradas';

    public function handle(OpenShiftCloser $closer): int
    {
        $settings = app(GeneralSettings::class);
        $overtimeTolerance = max(0, (int) $settings->attendance_overtime_tolerance_minutes);
        $lateTolerance = max(0, (int) $settings->attendance_late_tolerance_minutes);
        $from = $this->option('days')
            ? now()->subDays((int) $this->option('days'))->startOfDay()
            : AttendanceDay::attentionWindowStart();

        $days = AttendanceDay::query()
            ->where('attendance_days.date', '>=', $from)
            ->where(function ($query) use ($overtimeTolerance, $lateTolerance) {
                $query->where(fn ($q) => $q->whereRaw(AttendanceDay::PENDING_OVERTIME_SQL)->whereRaw('attendance_days.extra_hours * 60 <= ?', [$overtimeTolerance]))
                    ->orWhere(fn ($q) => $q->whereRaw(AttendanceDay::PENDING_TARDINESS_SQL)->where('attendance_days.late_minutes', '<=', $lateTolerance));
            })
            ->with('employee')
            ->orderBy('attendance_days.id')
            ->get();

        $counts = ['clean_overtime' => 0, 'clean_late' => 0, 'protected' => 0, 'locked' => 0, 'unchanged' => 0, 'status_changed' => 0];

        foreach ($days as $day) {
            if ($closer->isPayrollLocked($day)) {
                $counts['locked']++;

                continue;
            }

            if ($day->hasProtectedDecisions()) {
                $counts['protected']++;

                continue;
            }

            $before = ['extra' => (float) $day->extra_hours, 'late' => (int) $day->late_minutes, 'status' => $day->status];

            AttendanceCalculator::apply($day);

            $overtimeCleared = $before['extra'] > 0 && (float) $day->extra_hours <= 0;
            $lateCleared = $before['late'] > 0 && (int) $day->late_minutes <= 0;

            $overtimeCleared && $counts['clean_overtime']++;
            $lateCleared && $counts['clean_late']++;
            $day->status !== $before['status'] && $counts['status_changed']++;

            if (! $overtimeCleared && ! $lateCleared && $day->status === $before['status']) {
                $counts['unchanged']++;

                continue;
            }

            if (! $this->option('dry-run')) {
                $day->save();
            }
        }

        $this->line("Jornadas evaluadas: {$days->count()} (tolerancia extras {$overtimeTolerance} min, atraso {$lateTolerance} min).");
        $this->line("Extras que quedan en 0: {$counts['clean_overtime']} · tardanzas que quedan en 0: {$counts['clean_late']}.");
        $this->line("Sin cambios: {$counts['unchanged']} · estado distinto al recalcular: {$counts['status_changed']} · protegidas: {$counts['protected']} · con nómina: {$counts['locked']}.");
        $this->info($this->option('dry-run') ? 'Simulación: no se guardó nada.' : 'Cambios guardados.');

        return self::SUCCESS;
    }
}
