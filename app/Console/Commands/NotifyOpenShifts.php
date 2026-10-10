<?php

namespace App\Console\Commands;

use App\Models\AttendanceDay;
use App\Models\User;
use App\Notifications\OpenShiftsPendingNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Avisa por la campanita a quienes gestionan asistencias de las jornadas que quedaron sin salida
 * (entrada sin cerrar) desde el día siguiente al turno. Un solo aviso resumen por usuario: si ya
 * tiene uno sin leer, no se repite.
 */
class NotifyOpenShifts extends Command
{
    /** Días hacia atrás que se consideran; más viejo ya no se avisa a diario. */
    private const LOOKBACK_DAYS = 30;

    protected $signature = 'attendance:notify-open-shifts';

    protected $description = 'Avisa a RR.HH. de las jornadas con entrada y sin salida desde el día siguiente al turno';

    public function handle(): int
    {
        $open = AttendanceDay::query()
            ->missingCheckOut()
            ->where('date', '>=', Carbon::today()->subDays(self::LOOKBACK_DAYS)->toDateString());

        $count = (clone $open)->count();

        if ($count === 0) {
            $this->info('Sin jornadas abiertas para avisar.');

            return self::SUCCESS;
        }

        $oldest = Carbon::parse((clone $open)->min('date'));
        $notified = 0;

        foreach (User::all() as $user) {
            if (! $user->can('view_any_attendance_day')) {
                continue;
            }

            $alreadyPending = $user->unreadNotifications()
                ->where('type', OpenShiftsPendingNotification::class)
                ->exists();

            if ($alreadyPending) {
                continue;
            }

            $user->notify(new OpenShiftsPendingNotification($count, $oldest));
            $notified++;
        }

        $this->info("{$count} jornada(s) sin salida; aviso enviado a {$notified} usuario(s).");

        return self::SUCCESS;
    }
}
