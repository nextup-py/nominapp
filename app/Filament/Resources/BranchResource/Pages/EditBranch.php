<?php

namespace App\Filament\Resources\BranchResource\Pages;

use App\Filament\Resources\BranchResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/** Página de edición de una sucursal existente. */
class EditBranch extends EditRecord
{
    protected static string $resource = BranchResource::class;

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
                ->modalHeading('¿Eliminar sucursal?')
                ->modalDescription(fn () => $this->record->deletionBlockers() !== []
                    ? "Esta sucursal tiene {$this->record->deletionBlockersSummary()}. No es posible eliminarla mientras tenga datos asociados."
                    : "¿Estás seguro de que deseas eliminar la sucursal \"{$this->record->name}\"? Esta acción no se puede deshacer."
                )
                ->modalSubmitActionLabel('Sí, eliminar')
                ->before(function (Action $action) {
                    if ($this->record->deletionBlockers() !== []) {
                        Notification::make()
                            ->danger()
                            ->title('No se puede eliminar')
                            ->body("La sucursal tiene {$this->record->deletionBlockersSummary()}. Reasígnelos antes de eliminarla.")
                            ->send();

                        $action->halt();
                    }
                })
                ->successNotificationTitle('Sucursal eliminada')
                ->successRedirectUrl($this->getResource()::getUrl('index')),
        ];
    }

    /**
     * Capitaliza el nombre y limpia el teléfono antes de guardar.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (isset($data['name'])) {
            $data['name'] = preg_replace_callback('/(?:^|\s)\S/u', fn ($m) => mb_strtoupper($m[0], 'UTF-8'), $data['name']);
        }

        if (isset($data['phone'])) {
            $data['phone'] = preg_replace('/[\s\-]/', '', $data['phone']) ?: null;
        }

        return $data;
    }

    /**
     * Redirige a la página de detalle tras guardar los cambios.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    /**
     * Notificación de éxito tras actualizar la sucursal.
     */
    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Sucursal actualizada')
            ->body('Los datos de "'.$this->record->name.'" han sido actualizados correctamente.');
    }
}
