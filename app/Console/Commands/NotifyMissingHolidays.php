<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\HolidaysMissingNotification;
use App\Support\HolidayCatalog;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Avisa a quienes pueden cargar feriados cuando el año en curso (o, desde noviembre, el siguiente) no tiene los
 * feriados cargados. No repite el aviso mientras haya uno sin leer del mismo año.
 */
class NotifyMissingHolidays extends Command
{
    protected $signature = 'holidays:notify-missing {--dry-run : Muestra a quién avisaría sin enviar nada}';

    protected $description = 'Avisa a RR.HH. cuando faltan los feriados del año';

    /**
     * Evalúa el año en curso y, desde noviembre, el siguiente.
     */
    public function handle(): int
    {
        $years = array_filter([now()->year, now()->month >= 11 ? now()->year + 1 : null]);
        $sent = 0;

        foreach ($years as $year) {
            if (! HolidayCatalog::isMissing($year) || $this->alreadyNotified($year)) {
                continue;
            }

            $users = User::all()->filter(fn (User $user): bool => $user->can('create_holiday'));
            $this->line("Faltan los feriados de {$year}: se avisa a {$users->count()} usuario(s).");

            if (! $this->option('dry-run')) {
                $users->each(fn (User $user) => $user->notify(new HolidaysMissingNotification($year)));
            }

            $sent++;
        }

        $this->info($sent === 0 ? 'Los feriados están al día.' : "{$sent} año(s) sin feriados.");

        return self::SUCCESS;
    }

    /** Si ya hay un aviso sin leer de ese año. */
    private function alreadyNotified(int $year): bool
    {
        return DatabaseNotification::query()
            ->where('type', HolidaysMissingNotification::class)
            ->whereNull('read_at')
            ->get()
            ->contains(fn (DatabaseNotification $n): bool => ($n->data['year'] ?? null) === $year);
    }
}
