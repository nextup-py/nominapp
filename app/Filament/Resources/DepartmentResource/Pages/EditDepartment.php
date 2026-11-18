<?php

namespace App\Filament\Resources\DepartmentResource\Pages;

use App\Filament\Resources\DepartmentResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

class EditDepartment extends EditRecord
{
    protected static string $resource = DepartmentResource::class;

    /** Título de la página con el nombre del departamento que se edita. */
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
                ->modalHeading('¿Eliminar departamento?')
                ->modalDescription(function (): string {
                    $summary = $this->record->deletionBlockersSummary();

                    return $summary !== ''
                        ? 'El departamento "'.$this->record->name.'" tiene '.$summary.' y no se puede eliminar. Moverlos a otro departamento primero.'
                        : 'Esta acción no se puede deshacer. Se eliminará el departamento "'.$this->record->name.'".';
                })
                ->modalSubmitActionLabel('Sí, eliminar')
                ->before(function (DeleteAction $action): void {
                    $summary = $this->record->deletionBlockersSummary();

                    if ($summary === '') {
                        return;
                    }

                    Notification::make()
                        ->danger()
                        ->title('No se puede eliminar el departamento')
                        ->body("Tiene {$summary}. Moverlos a otro departamento antes de eliminarlo.")
                        ->persistent()
                        ->send();

                    $action->halt();
                })
                ->successNotificationTitle('Departamento eliminado')
                ->successRedirectUrl($this->getResource()::getUrl('index')),
        ];
    }

    /**
     * Mutar los datos del formulario antes de guardar el registro, asegurando que el nombre del departamento tenga la primera letra de cada palabra en mayúscula.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (isset($data['name'])) {
            $data['name'] = preg_replace_callback('/(?:^|\s)\S/u', fn ($m) => mb_strtoupper($m[0], 'UTF-8'), $data['name']);
        }

        return $data;
    }

    /**
     * Definir la URL a la que se redirigirá después de guardar los cambios.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    /**
     * Definir la notificación personalizada que se muestra después de guardar un departamento.
     */
    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Departamento actualizado')
            ->body('El departamento "'.$this->record->name.'" de la empresa "'.$this->record->company->display_name.'" ha sido actualizado exitosamente.')
            ->send();
    }
}
