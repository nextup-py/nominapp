<?php

namespace App\Notifications;

use App\Filament\Resources\HolidayResource;
use App\Notifications\Concerns\HonorsPreferences;
use Filament\Notifications\Actions\Action as FilamentAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Aviso a RR.HH. de que el año no tiene los feriados cargados (afecta vacaciones, asistencia y horas extra). */
class HolidaysMissingNotification extends Notification
{
    use HonorsPreferences;
    use Queueable;

    public function __construct(public readonly int $year) {}

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            ...FilamentNotification::make()
                ->title("Faltan los feriados de {$this->year}")
                ->body("Todavía no hay feriados cargados para {$this->year}. Sin ellos, las vacaciones descuentan días de más, las horas extra en feriado no se recargan y se generan ausencias esos días. Cargalos con «Cargar Feriados Nacionales» y sumá a mano los móviles (Semana Santa, traslados).")
                ->icon('heroicon-o-calendar-days')
                ->warning()
                ->actions([
                    FilamentAction::make('view')->label('Ir a Feriados')->url(HolidayResource::getUrl('index')),
                ])
                ->getDatabaseMessage(),
            'year' => $this->year,
        ];
    }

    /** Clave de este aviso en `NotificationCatalog`. */
    public function preferenceKey(): string
    {
        return 'holidays.missing';
    }
}
