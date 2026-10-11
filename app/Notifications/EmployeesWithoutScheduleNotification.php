<?php

namespace App\Notifications;

use App\Filament\Resources\EmployeeResource;
use Filament\Notifications\Actions\Action as FilamentAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Aviso diario a quienes asignan horarios de los empleados activos que no tienen horario, rotación ni
 * turnos planificados: pueden marcar, pero no se les calcula tardanza ni extras y no se les detectan
 * faltas. Enlaza a Empleados con el filtro "Sin horario asignado"; no se repite mientras el aviso
 * anterior siga sin leer.
 */
class EmployeesWithoutScheduleNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly int $count) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Se arma con el builder de `Filament\Notifications\Notification` para que la campanita del
     * panel la muestre (ver TerminalProvisionedNotification::toDatabase()).
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $noun = $this->count === 1 ? 'empleado activo sin horario' : 'empleados activos sin horario';
        $url = EmployeeResource::getUrl('index', [
            'activeTab' => 'active',
            'tableFilters' => ['without_schedule' => ['isActive' => true]],
        ]);

        return [
            ...FilamentNotification::make()
                ->title("{$this->count} {$noun}")
                ->body('Pueden marcar, pero no se les calcula tardanza ni horas extra y no se les detectan faltas. Asignarles un horario o planificarles turnos.')
                ->icon('heroicon-o-clock')
                ->warning()
                ->actions([
                    FilamentAction::make('view')->label('Ver empleados')->url($url),
                ])
                ->getDatabaseMessage(),
            'employees_without_schedule_count' => $this->count,
        ];
    }
}
