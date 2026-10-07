<?php

namespace App\Filament\Resources\CompanyResource\Pages;

use App\Filament\Resources\CompanyResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditCompany extends EditRecord
{
    protected static string $resource = CompanyResource::class;

    /**
     * Define las acciones que estarán disponibles en la vista de edición de la empresa.
     */
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()
                ->label('Ver')
                ->icon('heroicon-o-eye')
                ->color('primary'),

            DeleteAction::make()
                ->label('Eliminar')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->modalHeading('¿Eliminar empresa?')
                ->modalDescription(function (): string {
                    $summary = $this->record->deletionBlockersSummary();

                    return $summary !== ''
                        ? 'La empresa "'.$this->record->display_name.'" tiene '.$summary.' y no se puede eliminar. Desactívela en su lugar.'
                        : 'Esta acción no se puede deshacer. Se eliminará la empresa "'.$this->record->display_name.'".';
                })
                ->modalSubmitActionLabel('Sí, eliminar')
                ->before(function (DeleteAction $action): void {
                    $summary = $this->record->deletionBlockersSummary();

                    if ($summary === '') {
                        return;
                    }

                    Notification::make()
                        ->danger()
                        ->title('No se puede eliminar la empresa')
                        ->body("Tiene {$summary}. Para dejar de usarla, desmarque «Activa» en su configuración: las empresas inactivas no aparecen en los selectores.")
                        ->persistent()
                        ->send();

                    $action->halt();
                })
                ->successNotificationTitle('Empresa eliminada')
                ->successRedirectUrl($this->getResource()::getUrl('index')),
        ];
    }

    /**
     * Mutar los datos del formulario antes de guardarlos para asegurar que los campos "name" y "trade_name" estén en mayúscula.
     *
     * @param  array  $data  Los datos del formulario a mutar.
     * @return array Los datos mutados listos para ser guardados.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        foreach (['name', 'trade_name'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = preg_replace_callback('/(?:^|\s)\S/u', fn ($m) => mb_strtoupper($m[0], 'UTF-8'), $data[$field]);
            }
        }

        return $data;
    }

    /**
     * Define la URL a la que se redirigirá después de guardar los cambios en la empresa.
     *
     * @return string La URL de redirección.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    /**
     * Define la notificación que se mostrará después de guardar los cambios en la empresa.
     *
     * @return Notification La notificación personalizada.
     */
    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Empresa actualizada')
            ->body('La empresa "'.$this->record->display_name.'" ha sido actualizada correctamente.');
    }
}
