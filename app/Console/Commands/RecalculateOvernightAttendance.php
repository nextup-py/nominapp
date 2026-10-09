<?php

namespace App\Console\Commands;

use App\Models\AttendanceDay;
use App\Services\AttendanceCalculator;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Corrige las jornadas de turnos que cruzan medianoche calculadas antes del arreglo de
 * `expected_hours` negativas: recalcula horas esperadas, extras, tardanza y salida anticipada.
 * Las jornadas con horas extra ya aprobadas o ajuste manual no se tocan; se listan para revisión.
 */
class RecalculateOvernightAttendance extends Command
{
    protected $signature = 'attendance:recalculate-overnight
        {--from= : Fecha inicial (Y-m-d). Por defecto, sin límite}
        {--to= : Fecha final (Y-m-d). Por defecto, sin límite}
        {--dry-run : Muestra qué se corregiría sin guardar nada}';

    protected $description = 'Recalcula jornadas de turnos nocturnos con horas esperadas incorrectas';

    /**
     * Ejecuta el recálculo (o la vista previa con --dry-run).
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $fixed = 0;
        $skipped = [];

        AttendanceDay::query()
            ->whereNotNull('expected_check_in')
            ->whereNotNull('expected_check_out')
            ->whereColumn('expected_check_out', '<=', 'expected_check_in')
            ->when($this->option('from'), fn ($q, $from) => $q->where('date', '>=', Carbon::parse($from)->toDateString()))
            ->when($this->option('to'), fn ($q, $to) => $q->where('date', '<=', Carbon::parse($to)->toDateString()))
            ->orderBy('date')
            ->chunkById(200, function ($days) use ($dryRun, &$fixed, &$skipped) {
                foreach ($days as $day) {
                    if ($day->overtime_approved || $day->manual_adjustment) {
                        $skipped[] = $day;

                        continue;
                    }

                    $fixed++;

                    if ($dryRun) {
                        continue;
                    }

                    $day->expected_hours = $this->expectedHours($day->expected_check_in, $day->expected_check_out);
                    AttendanceCalculator::apply($day);
                    $day->save();
                }
            });

        $this->info(($dryRun ? '[dry-run] Se corregirían ' : 'Corregidas ')."{$fixed} jornada(s).");

        if ($skipped !== []) {
            $this->warn(count($skipped).' jornada(s) omitidas por tener horas extra aprobadas o ajuste manual (revisar a mano):');
            foreach ($skipped as $day) {
                $this->line("  - día #{$day->id} · empleado {$day->employee_id} · {$day->date->toDateString()}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Horas esperadas de un turno que termina al día siguiente.
     */
    private function expectedHours(string $in, string $out): float
    {
        $start = Carbon::parse($in);
        $end = Carbon::parse($out)->addDay();

        return round($start->diffInMinutes($end) / 60, 2);
    }
}
