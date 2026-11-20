<?php

namespace App\Console\Commands;

use App\Models\Absence;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Justifica con una nota las ausencias pendientes hasta una fecha, sin descuento de sueldo. Sirve para dejar en limpio las
 * ausencias acumuladas antes de empezar a marcar en el sistema. Nunca marca ausencias como injustificadas.
 */
class JustifyPendingAbsences extends Command
{
    protected $signature = 'absences:justify-pending
                            {--until= : Última fecha a justificar, inclusive; obligatoria}
                            {--reason= : Motivo que queda en la ausencia; obligatorio}
                            {--user= : Usuario que figura como revisor (por defecto, el primer Super Admin)}
                            {--dry-run : Muestra cuántas ausencias se justificarían sin guardar}';

    protected $description = 'Justifica con una nota las ausencias pendientes hasta una fecha, sin descuento';

    public function handle(): int
    {
        if (blank($this->option('until')) || blank($this->option('reason'))) {
            $this->error('Indicar --until=AAAA-MM-DD y --reason= (el motivo queda en cada ausencia).');

            return self::FAILURE;
        }

        $until = now()->parse($this->option('until'))->toDateString();
        $reviewerId = (int) ($this->option('user') ?: (User::role('Super Admin')->value('id') ?? User::query()->min('id')));
        $count = 0;

        Absence::query()
            ->where('absences.status', 'pending')
            ->whereHas('attendanceDay', fn ($q) => $q->where('attendance_days.date', '<=', $until))
            ->with('attendanceDay')
            ->orderBy('absences.id')
            ->each(function (Absence $absence) use ($reviewerId, &$count) {
                $count++;

                if (! $this->option('dry-run')) {
                    $absence->justify($reviewerId, (string) $this->option('reason'));
                }
            });

        $this->line("Ausencias justificadas hasta {$until}: {$count}.");
        $this->info($this->option('dry-run') ? 'Simulación: no se guardó nada.' : 'Cambios guardados.');

        return self::SUCCESS;
    }
}
