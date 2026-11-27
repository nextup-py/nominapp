<?php

namespace App\Notifications;

use App\Filament\Pages\MyTeam;
use App\Filament\Resources\AttendanceDayResource;
use App\Notifications\Concerns\HonorsPreferences;
use Filament\Notifications\Actions\Action as FilamentAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Aviso de horas extra acumuladas: quienes superaron el tope semanal o mensual configurado. Cada destinatario recibe
 * solo a las personas que le corresponden (ver `OvertimeAccumulationService::rowsFor()`).
 */
class OvertimeAccumulationNotification extends Notification
{
    use HonorsPreferences;
    use Queueable;

    /** @param  array<int, array{employee_id: int, name: string, week: float, month: float, exceeded: array<int, string>}>  $rows */
    public function __construct(public readonly array $rows) {}

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $lines = array_map(fn (array $row): string => "{$row['name']}: {$row['week']} h en la semana, {$row['month']} h en el mes", array_slice($this->rows, 0, 5));
        $more = count($this->rows) - count($lines);
        $count = count($this->rows);
        $canSeeDays = $notifiable->can('view_any_attendance_day');

        return [
            ...FilamentNotification::make()
                ->title($count === 1 ? '1 persona superó el tope de horas extra' : "{$count} personas superaron el tope de horas extra")
                ->body(implode(' · ', $lines).($more > 0 ? " · y {$more} más" : ''))
                ->icon('heroicon-o-exclamation-triangle')
                ->warning()
                ->actions([FilamentAction::make('view')->label($canSeeDays ? 'Ver asistencias' : 'Ver mi equipo')->url($canSeeDays ? AttendanceDayResource::getUrl('index') : MyTeam::getUrl())])
                ->getDatabaseMessage(),
            'overtime_accumulation_count' => $count,
        ];
    }

    /** Clave de este aviso en `NotificationCatalog`. */
    public function preferenceKey(): string
    {
        return 'overtime.accumulation';
    }
}
