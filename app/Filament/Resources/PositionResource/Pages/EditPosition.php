<?php

namespace App\Filament\Resources\PositionResource\Pages;

use App\Filament\Resources\PositionResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

class EditPosition extends EditRecord
{
    protected static string $resource = PositionResource::class;

    /** Título de la página con el nombre del cargo que se edita. */
    public function getTitle(): string|Htmlable
    {
        return 'Editar: '.$this->record->name;
    }

    /**
     * Definir las acciones que se muestran en el encabezado de la página de edición.
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
                ->visible(fn (): bool => $this->record->deletionBlockers() === [])
                ->modalHeading('¿Eliminar cargo?')
                ->modalDescription(function (): string {
                    $summary = $this->record->deletionBlockersSummary();

                    return $summary !== ''
                        ? 'El cargo "'.$this->record->name.'" tiene '.$summary.' y no se puede eliminar. Moverlos a otro cargo con Fusionar primero.'
                        : 'Esta acción no se puede deshacer. Se eliminará el cargo "'.$this->record->name.'".';
                })
                ->modalSubmitActionLabel('Sí, eliminar')
                ->before(function (DeleteAction $action): void {
                    $summary = $this->record->deletionBlockersSummary();

                    if ($summary === '') {
                        return;
                    }

                    Notification::make()
                        ->danger()
                        ->title('No se puede eliminar el cargo')
                        ->body("Tiene {$summary}. Moverlos a otro cargo con Fusionar antes de eliminarlo.")
                        ->persistent()
                        ->send();

                    $action->halt();
                })
                ->successNotificationTitle('Cargo eliminado')
                ->successRedirectUrl($this->getResource()::getUrl('index')),
        ];
    }

    /**
     * Mutar los datos del formulario antes de guardar el registro, asegurando que el nombre del cargo tenga la primera letra de cada palabra en mayúscula.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (isset($data['name'])) {
            $data['name'] = preg_replace_callback('/(?:^|\s)\S/u', fn ($m) => mb_strtoupper($m[0], 'UTF-8'), $data['name']);
        }

        return $data;
    }

    /** Tras guardar (ya con las sucursales elegidas), avisa a los terminales de que cambió quién puede marcar en ellos. */
    protected function afterSave(): void
    {
        $this->record->touchEmployees();
    }

    /**
     * Definir la URL a la que se redirigirá después de guardar los cambios.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    /**
     * Definir la notificación personalizada que se muestra después de guardar un cargo.
     */
    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Cargo actualizado')
            ->body("El cargo \"{$this->record->name}\" del departamento \"{$this->record->department->name}\" ha sido actualizado correctamente.")
            ->send();
    }
}
