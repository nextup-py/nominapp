<?php

namespace App\Filament\Resources\ScheduleResource\Pages;

use App\Filament\Resources\ScheduleResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditSchedule extends EditRecord
{
    protected static string $resource = ScheduleResource::class;

    /**
     * Retorna las acciones del encabezado: ver registro y eliminar.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()
                ->label('Ver')
                ->icon('heroicon-o-eye')
                ->color('gray'),

            DeleteAction::make()
                ->label('Eliminar')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->modalHeading('¿Eliminar horario?')
                ->modalDescription(fn () => "¿Estás seguro de que deseas eliminar el horario \"{$this->record->name}\"? Esta acción no se puede deshacer.")
                ->modalSubmitActionLabel('Sí, eliminar')
                ->before(function (DeleteAction $action): void {
                    if ($this->record->deletionBlockers() !== []) {
                        Notification::make()
                            ->danger()
                            ->title('No se puede eliminar el horario')
                            ->body(ucfirst($this->record->deletionBlockersSummary()).'.')
                            ->persistent()
                            ->send();

                        $action->halt();
                    }
                })
                ->successNotificationTitle('Horario eliminado')
                ->successRedirectUrl($this->getResource()::getUrl('index')),
        ];
    }

    /**
     * Capitaliza el nombre del horario antes de guardar.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (isset($data['name'])) {
            $data['name'] = preg_replace_callback('/(?:^|\s)\S/u', fn ($m) => mb_strtoupper($m[0], 'UTF-8'), $data['name']);
        }

        return $data;
    }

    /**
     * Retorna la URL de redirección después de guardar, que es la vista del horario actualizado.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    /**
     * Retorna la notificación personalizada después de guardar, indicando que el horario ha sido actualizado correctamente.
     */
    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Horario actualizado')
            ->body("El horario \"{$this->record->name}\" ha sido actualizado correctamente.");
    }
}
