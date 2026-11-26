<?php

namespace App\Filament\Resources\RotationPatternResource\Pages;

use App\Filament\Resources\RotationPatternResource;
use App\Models\RotationPattern;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/** Página de edición de patrón de rotación. */
class EditRotationPattern extends EditRecord
{
    protected static string $resource = RotationPatternResource::class;

    public function getTitle(): string
    {
        return 'Editar: '.$this->record->name;
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return RotationPatternResource::mutateFormDataBeforeFill($data);
    }

    /**
     * Capitaliza el nombre antes de guardar.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['name'] = RotationPattern::capitalizeName($data['name']);

        return $data;
    }

    /**
     * Eliminar solo aparece si el patrón nunca tuvo asignaciones; si las tuvo, el camino es desactivar (desde el detalle).
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()->icon('heroicon-o-eye')->color('primary'),
            DeleteAction::make()
                ->label('Eliminar')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn (RotationPattern $record): bool => $record->deletionBlockers() === [])
                ->modalHeading('Eliminar patrón')
                ->modalDescription('El patrón nunca se asignó a un empleado. Esta acción no se puede deshacer.')
                ->modalSubmitActionLabel('Sí, eliminar')
                ->successNotificationTitle('Patrón eliminado')
                ->successRedirectUrl(RotationPatternResource::getUrl('index')),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return RotationPatternResource::getUrl('view', ['record' => $this->record]);
    }

    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Patrón actualizado')
            ->body('Los cambios fueron guardados correctamente.');
    }
}
