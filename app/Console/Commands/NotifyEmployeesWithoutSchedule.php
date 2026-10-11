<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\User;
use App\Notifications\EmployeesWithoutScheduleNotification;
use Illuminate\Console\Command;

/**
 * Avisa por la campanita a quienes pueden asignar horarios de los empleados activos que no tienen
 * horario, rotación ni turnos planificados (definición única: `Employee::scopeWithoutSchedule()`).
 * Un solo aviso resumen por usuario: si ya tiene uno sin leer, no se repite.
 */
class NotifyEmployeesWithoutSchedule extends Command
{
    protected $signature = 'attendance:notify-no-schedule';

    protected $description = 'Avisa a RR.HH. de los empleados activos sin horario asignado';

    public function handle(): int
    {
        $count = Employee::query()->active()->withoutSchedule()->count();

        if ($count === 0) {
            $this->info('Todos los empleados activos tienen horario.');

            return self::SUCCESS;
        }

        $notified = 0;

        foreach (User::all() as $user) {
            if (! $user->can('update_schedule')) {
                continue;
            }

            $alreadyPending = $user->unreadNotifications()
                ->where('type', EmployeesWithoutScheduleNotification::class)
                ->exists();

            if ($alreadyPending) {
                continue;
            }

            $user->notify(new EmployeesWithoutScheduleNotification($count));
            $notified++;
        }

        $this->info("{$count} empleado(s) sin horario; aviso enviado a {$notified} usuario(s).");

        return self::SUCCESS;
    }
}
