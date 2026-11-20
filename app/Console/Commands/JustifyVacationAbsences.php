<?php

namespace App\Console\Commands;

use App\Models\Absence;
use App\Models\User;
use App\Models\Vacation;
use Illuminate\Console\Command;

/**
 * Justifica las ausencias pendientes que quedaron dentro de vacaciones aprobadas (se generaron antes de cargar o
 * aprobar la vacación). No vincula una licencia: deja el período de la vacación en las notas de revisión.
 */
class JustifyVacationAbsences extends Command
{
    protected $signature = 'vacations:justify-absences
                            {--vacation= : Limita la reparación a una vacación (id)}
                            {--user= : Usuario que figura como revisor (por defecto, el primer Super Admin)}
                            {--dry-run : Muestra cuántas ausencias se justificarían sin guardar}';

    protected $description = 'Justifica las ausencias pendientes que quedaron dentro de vacaciones aprobadas';

    public function handle(): int
    {
        $vacations = Vacation::query()
            ->where('status', 'approved')
            ->when($this->option('vacation'), fn ($query, $id) => $query->whereKey($id))
            ->get();

        $reviewerId = (int) ($this->option('user') ?: (User::role('Super Admin')->value('id') ?? User::query()->min('id')));

        if (! $this->option('dry-run') && ! User::whereKey($reviewerId)->exists()) {
            $this->error('No hay un usuario para registrar como revisor. Indicar uno con --user=.');

            return self::FAILURE;
        }

        $total = 0;

        foreach ($vacations as $vacation) {
            $absences = Absence::query()
                ->where('employee_id', $vacation->employee_id)
                ->where('status', 'pending')
                ->whereHas('attendanceDay', fn ($q) => $q->whereBetween('date', [$vacation->start_date, $vacation->end_date]))
                ->with('attendanceDay')
                ->get();

            if ($absences->isEmpty()) {
                continue;
            }

            if (! $this->option('dry-run')) {
                $period = $vacation->start_date->format('d/m/Y').' al '.$vacation->end_date->format('d/m/Y');

                foreach ($absences as $absence) {
                    $absence->justify($reviewerId, "Justificada por vacaciones aprobadas ({$period})");
                }
            }

            $this->line("Vacación {$vacation->id} ({$vacation->start_date->format('Y-m-d')} a {$vacation->end_date->format('Y-m-d')}): {$absences->count()} ausencia(s).");
            $total += $absences->count();
        }

        $this->info($this->option('dry-run') ? "Se justificarían {$total} ausencia(s)." : "Justificadas {$total} ausencia(s).");

        return self::SUCCESS;
    }
}
