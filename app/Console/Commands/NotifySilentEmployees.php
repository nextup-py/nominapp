<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\SilentEmployeesNotification;
use App\Services\SilentEmployeesService;
use Illuminate\Console\Command;

/**
 * Avisa por la campanita a quienes pueden ver asistencias de los empleados activos que no marcaron nada en los
 * últimos días (`SilentEmployeesService`). Un resumen por usuario; si ya tiene uno sin leer, no se repite.
 */
class NotifySilentEmployees extends Command
{
    protected $signature = 'attendance:notify-silent-employees
        {--days=7 : Días sin ninguna marcación para avisar}
        {--dry-run : Lista a los empleados sin enviar el aviso}';

    protected $description = 'Avisa a RR.HH. de los empleados activos que no marcaron nada en los últimos días';

    /**
     * Busca a los empleados en silencio y envía el resumen (o lo lista con --dry-run).
     */
    public function handle(SilentEmployeesService $service): int
    {
        $days = max(1, (int) $this->option('days'));
        $employees = $service->find($days);

        if ($employees->isEmpty()) {
            $this->info("Todos los empleados activos marcaron algo en los últimos {$days} días.");

            return self::SUCCESS;
        }

        $names = $employees->map(fn ($e) => $e->full_name.' ('.($e->branch?->name ?? 'sin sucursal').')')->all();

        if ($this->option('dry-run')) {
            foreach ($names as $name) {
                $this->line($name);
            }

            $this->info("{$employees->count()} empleado(s) sin marcaciones en {$days} días. Simulación: no se envió nada.");

            return self::SUCCESS;
        }

        $notified = 0;

        foreach (User::all() as $user) {
            if (! $user->can('view_any_attendance_day')) {
                continue;
            }

            if ($user->unreadNotifications()->where('type', SilentEmployeesNotification::class)->exists()) {
                continue;
            }

            $user->notify(new SilentEmployeesNotification($employees->count(), $days, $names));
            $notified++;
        }

        $this->info("{$employees->count()} empleado(s) sin marcaciones en {$days} días; aviso enviado a {$notified} usuario(s).");

        return self::SUCCESS;
    }
}
