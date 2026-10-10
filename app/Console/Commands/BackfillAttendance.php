<?php

namespace App\Console\Commands;

use App\Models\AttendanceDay;
use App\Services\AttendanceCalculator;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Repara el histórico de asistencia de un rango de fechas cuando el scheduler estuvo apagado: genera las
 * ausencias que `attendance:check-missing` no creó (días con turno planificado y sin marcación) y calcula las
 * jornadas que quedaron sin calcular (`app:calculate-attendance` no corrió). Solo toca jornadas sin calcular:
 * no recalcula las ya calculadas, así que no pisa aprobaciones ni ajustes de RR.HH. Las ausencias nuevas quedan
 * como pendientes de revisión (no generan descuento hasta que RR.HH. las marque como injustificadas).
 */
class BackfillAttendance extends Command
{
    protected $signature = 'attendance:backfill
                            {--from= : Primera fecha a reparar (Y-m-d)}
                            {--to= : Última fecha a reparar (Y-m-d). Por defecto, ayer}
                            {--dry-run : Muestra qué se haría sin guardar nada}';

    protected $description = 'Genera ausencias y calcula jornadas pendientes de un rango pasado (para reparar un scheduler apagado)';

    /** Ejecuta la reparación (o la vista previa con --dry-run). */
    public function handle(): int
    {
        if (! $this->option('from')) {
            $this->error('Falta --from=YYYY-MM-DD.');

            return self::INVALID;
        }

        $from = Carbon::parse($this->option('from'))->startOfDay();
        $yesterday = Carbon::yesterday();
        $to = $this->option('to') ? Carbon::parse($this->option('to'))->startOfDay() : $yesterday->copy();

        if ($to->gt($yesterday)) {
            $to = $yesterday->copy();
        }

        if ($from->gt($to)) {
            $this->error('El rango es inválido: --from debe ser anterior o igual a --to (como máximo, ayer).');

            return self::INVALID;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->info(($dryRun ? '[dry-run] ' : '')."Reparando asistencia del {$from->toDateString()} al {$to->toDateString()}");

        $rows = [];
        $absences = 0;

        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            Artisan::call('attendance:check-missing', array_filter([
                '--date' => $date->toDateString(),
                '--only-date' => true,
                '--dry-run' => $dryRun,
            ]));

            $created = preg_match('/Ausencias creadas\s*\|\s*(\d+)/', Artisan::output(), $m) ? (int) $m[1] : 0;
            $absences += $created;

            if ($created > 0) {
                $rows[] = [$date->toDateString(), $created];
            }
        }

        if ($rows !== []) {
            $this->table(['Fecha', $dryRun ? 'Ausencias que se crearían' : 'Ausencias creadas'], $rows);
        }

        $calculated = 0;
        $failed = 0;
        $pending = AttendanceDay::query()
            ->where('is_calculated', false)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()]);

        $toCalculate = (clone $pending)->count();

        if (! $dryRun) {
            $pending->orderBy('id')->chunkById(100, function ($days) use (&$calculated, &$failed): void {
                foreach ($days as $day) {
                    try {
                        AttendanceCalculator::apply($day);
                        $day->save();
                        $calculated++;
                    } catch (\Throwable $e) {
                        $failed++;
                        Log::error("attendance:backfill: error calculando AttendanceDay {$day->id}: {$e->getMessage()}");
                    }
                }
            });
        }

        $this->newLine();
        $this->table(['Resultado', 'Cantidad'], [
            [$dryRun ? 'Ausencias que se crearían' : 'Ausencias creadas', $absences],
            [$dryRun ? 'Jornadas sin calcular que se calcularían' : 'Jornadas calculadas', $dryRun ? $toCalculate : $calculated],
            ['Errores al calcular', $failed],
        ]);

        return self::SUCCESS;
    }
}
