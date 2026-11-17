<?php

namespace App\Filament\Resources\HolidayResource\Pages;

use App\Filament\Resources\HolidayResource;
use App\Models\Holiday;
use App\Support\HolidayCatalog;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageHolidays extends ManageRecords
{
    protected static string $resource = HolidayResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import_default')
                ->label('Cargar Feriados Nacionales')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('info')
                ->modalHeading('Cargar los feriados nacionales fijos')
                ->modalDescription('Agrega los feriados de fecha fija (Año Nuevo, Héroes, Trabajador, Independencia, Paz del Chaco, Fundación de Asunción, Boquerón, Caacupé y Navidad). Los móviles (Jueves y Viernes Santo, traslados a lunes y días no laborables) hay que cargarlos a mano cada año. Los que ya existan no se duplican.')
                ->modalSubmitActionLabel('Cargar')
                ->form([
                    Select::make('year')
                        ->label('Año')
                        ->options(fn (): array => collect(range(now()->year, now()->year + 2))->mapWithKeys(fn (int $y): array => [$y => (string) $y])->all())
                        ->default(now()->year)
                        ->native(false)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $imported = HolidayCatalog::load((int) $data['year']);

                    Notification::make()
                        ->title($imported > 0 ? "Se agregaron {$imported} feriados de {$data['year']}" : 'Todos los feriados fijos ya existen')
                        ->body('Recordá cargar a mano los móviles: Jueves y Viernes Santo, traslados a lunes y días no laborables.')
                        ->success()
                        ->send();
                }),

            Action::make('delete_past')
                ->label('Eliminar Feriados Pasados')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Eliminar Feriados Pasados')
                ->modalDescription('Esto eliminará todos los feriados anteriores al año actual. Esta acción no se puede deshacer.')
                ->action(function () {
                    $deleted = Holiday::where('date', '<', now()->startOfYear())->delete();

                    Notification::make()
                        ->title($deleted > 0 ? "Se eliminaron $deleted feriados" : 'No hay feriados pasados para eliminar')
                        ->success()
                        ->send();
                }),

            Action::make('duplicate_next_year')
                ->label('Copiar al Próximo Año')
                ->icon('heroicon-o-document-duplicate')
                ->requiresConfirmation()
                ->modalHeading('Copiar los feriados fijos al próximo año')
                ->modalDescription('Copia al próximo año solo los feriados de tipo «Nacional fijo» del año actual. Los móviles y los días no laborables cambian de fecha cada año y no se copian: hay que cargarlos a mano. Los que ya existan no se duplican.')
                ->modalSubmitActionLabel('Sí, copiar')
                ->action(function (): void {
                    $result = HolidayCatalog::copyFixed(now()->year);

                    Notification::make()
                        ->title("Se copiaron {$result['created']} feriados fijos al año ".(now()->year + 1))
                        ->body($result['skipped_moving'] === [] ? null : 'Cargá a mano los móviles: '.implode(', ', $result['skipped_moving']).'.')
                        ->success()
                        ->send();
                }),

            Action::make('calendar')
                ->label('Calendario')
                ->icon('heroicon-o-calendar-days')
                ->color('gray')
                ->modalHeading('Calendario de feriados')
                ->modalWidth('5xl')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Cerrar')
                ->modalContent(fn () => view('filament.holidays.calendar', [
                    'years' => [now()->year, now()->year + 1],
                    'holidays' => Holiday::whereYear('date', '>=', now()->year)->whereYear('date', '<=', now()->year + 1)->get()->keyBy(fn (Holiday $h): string => $h->date->toDateString()),
                ])),

            CreateAction::make()
                ->label('Nuevo Feriado')
                ->icon('heroicon-o-plus')
                ->successNotificationTitle('Feriado agregado exitosamente'),
        ];
    }
}
