<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\NoExitTodayNotification;
use App\Services\LiveAttendanceMonitor;
use App\Settings\GeneralSettings;
use Illuminate\Console\Command;

/**
 * Avisa por la campanita, a la hora configurada, de los empleados que se fueron sin marcar la salida hoy (los que el
 * Monitor en vivo muestra como «Sin salida»), para que RR.HH. les consulte mientras se acuerdan. Un solo aviso por
 * usuario: si ya tiene uno sin leer, no se repite.
 */
class NotifyNoExitToday extends Command
{
    protected $signature = 'attendance:notify-no-exit-today
        {--force : Envía aunque el aviso esté apagado en Configuración General}';

    protected $description = 'Avisa a RR.HH. de quienes se fueron hoy sin marcar la salida (si está activado)';

    /**
     * Arma la lista del día desde el monitor y avisa a quienes ven asistencias.
     */
    public function handle(): int
    {
        if (! $this->option('force') && ! app(GeneralSettings::class)->attendance_no_exit_notice_enabled) {
            $this->info('El aviso diario está apagado en Configuración General.');

            return self::SUCCESS;
        }

        $names = collect(LiveAttendanceMonitor::snapshot(['only_scheduled' => true])['rows'])
            ->where('state', 'no_exit')
            ->pluck('name')
            ->values()
            ->all();

        if ($names === []) {
            $this->info('Nadie se fue hoy sin marcar la salida.');

            return self::SUCCESS;
        }

        $notified = 0;

        foreach (User::all() as $user) {
            if (! $user->can('view_any_attendance_day')) {
                continue;
            }

            if ($user->unreadNotifications()->where('type', NoExitTodayNotification::class)->exists()) {
                continue;
            }

            $user->notify(new NoExitTodayNotification($names));
            $notified++;
        }

        $this->info(count($names)." empleado(s) sin salida hoy; aviso enviado a {$notified} usuario(s).");

        return self::SUCCESS;
    }
}
