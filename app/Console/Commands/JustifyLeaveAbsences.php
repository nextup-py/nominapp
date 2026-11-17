<?php

namespace App\Console\Commands;

use App\Models\EmployeeLeave;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Repara licencias aprobadas cuyas ausencias quedaron sin justificar (por ejemplo, aprobadas editando el estado
 * en el formulario, que no justificaba nada). Solo toca ausencias `pending` y solo de licencias de día completo.
 */
class JustifyLeaveAbsences extends Command
{
    protected $signature = 'leaves:justify-absences
                            {--leave= : Limita la reparación a una licencia (id)}
                            {--user= : Usuario que figura como revisor (por defecto, el primer Super Admin)}
                            {--dry-run : Muestra cuántas ausencias se justificarían sin guardar}';

    protected $description = 'Justifica las ausencias pendientes que quedaron dentro de licencias aprobadas';

    public function handle(): int
    {
        $leaves = EmployeeLeave::query()
            ->where('status', 'approved')
            ->when($this->option('leave'), fn ($query, $id) => $query->whereKey($id))
            ->get();

        $reviewerId = (int) ($this->option('user') ?: (User::role('Super Admin')->value('id') ?? User::query()->min('id')));

        if (! $this->option('dry-run') && ! User::whereKey($reviewerId)->exists()) {
            $this->error('No hay un usuario para registrar como revisor. Indicá uno con --user=.');

            return self::FAILURE;
        }

        $total = 0;

        foreach ($leaves as $leave) {
            if ($leave->isPartialDay()) {
                continue;
            }

            if ($this->option('dry-run')) {
                $count = $leave->employee->absences()
                    ->whereHas('attendanceDay', fn ($q) => $q->whereBetween('date', [$leave->start_date, $leave->end_date]))
                    ->where('status', 'pending')
                    ->count();
            } else {
                $count = count($leave->justifyAbsences($reviewerId, ['pending']));
            }

            if ($count > 0) {
                $this->line("Licencia {$leave->id} ({$leave->start_date->format('Y-m-d')} a {$leave->end_date->format('Y-m-d')}): {$count} ausencia(s).");
                $total += $count;
            }
        }

        $this->info($this->option('dry-run') ? "Se justificarían {$total} ausencia(s)." : "Justificadas {$total} ausencia(s).");

        return self::SUCCESS;
    }
}
