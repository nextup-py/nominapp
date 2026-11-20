<?php

namespace App\Console\Commands;

use App\Models\AttendanceDay;
use App\Services\OpenShiftCloser;
use Illuminate\Console\Command;

/**
 * Da por revisadas (sin pago ni descuento) las horas extra o tardanzas pendientes anteriores a una fecha: las marca como
 * descartadas, no las aprueba ni las borra. Sirve para limpiar el historial de «Por resolver» al empezar a usar el sistema.
 */
class DismissPendingAttendance extends Command
{
    protected $signature = 'attendance:dismiss-pending
                            {--overtime : Descartar las horas extra pendientes}
                            {--tardiness : Descartar las tardanzas pendientes}
                            {--before= : Solo jornadas anteriores a esta fecha (excluida); obligatoria}
                            {--dry-run : Muestra cuántas jornadas se descartarían sin guardar}';

    protected $description = 'Descarta (sin pago ni descuento) las horas extra o tardanzas pendientes anteriores a una fecha';

    public function handle(OpenShiftCloser $closer): int
    {
        if (! $this->option('overtime') && ! $this->option('tardiness')) {
            $this->error('Indicar al menos --overtime o --tardiness.');

            return self::FAILURE;
        }

        if (blank($this->option('before'))) {
            $this->error('Indicar la fecha con --before=AAAA-MM-DD (se descartan las jornadas anteriores).');

            return self::FAILURE;
        }

        $before = now()->parse($this->option('before'))->toDateString();
        $dryRun = (bool) $this->option('dry-run');

        foreach (['overtime' => ['Horas extra', AttendanceDay::PENDING_OVERTIME_SQL, 'overtime_dismissed_at'], 'tardiness' => ['Tardanzas', AttendanceDay::PENDING_TARDINESS_SQL, 'tardiness_dismissed_at']] as $option => [$label, $sql, $column]) {
            if (! $this->option($option)) {
                continue;
            }

            $dismissed = 0;
            $locked = 0;

            AttendanceDay::query()->whereRaw($sql)->where('attendance_days.date', '<', $before)->eachById(function (AttendanceDay $day) use ($closer, $column, $dryRun, &$dismissed, &$locked) {
                if ($closer->isPayrollLocked($day)) {
                    $locked++;

                    return;
                }

                $dismissed++;

                if (! $dryRun) {
                    $day->{$column} = now();
                    $day->save();
                }
            });

            $this->line("{$label} descartadas: {$dismissed} · con nómina generada (no se tocan): {$locked}.");
        }

        $this->info($dryRun ? 'Simulación: no se guardó nada.' : 'Cambios guardados.');

        return self::SUCCESS;
    }
}
