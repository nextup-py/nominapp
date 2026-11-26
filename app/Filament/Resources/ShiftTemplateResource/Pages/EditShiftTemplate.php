<?php

namespace App\Filament\Resources\ShiftTemplateResource\Pages;

use App\Filament\Resources\ShiftTemplateResource;
use App\Models\ShiftTemplate;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/** Página de edición de turno. */
class EditShiftTemplate extends EditRecord
{
    protected static string $resource = ShiftTemplateResource::class;

    /**
     * Capitaliza el nombre antes de guardar.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['name'] = ShiftTemplate::capitalizeName($data['name']);

        return $data;
    }

    /**
     * Eliminar solo aparece si el turno nunca se usó; si tiene uso, el camino es desactivar (desde el detalle).
     *
     * @return array<Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()->icon('heroicon-o-eye')->color('primary'),
            DeleteAction::make()
                ->label('Eliminar')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn (ShiftTemplate $record): bool => $record->deletionBlockers() === [])
                ->modalHeading('Eliminar turno')
                ->modalDescription('El turno nunca se usó en un patrón ni en el Planificador. Esta acción no se puede deshacer.')
                ->modalSubmitActionLabel('Sí, eliminar')
                ->successNotificationTitle('Turno eliminado')
                ->successRedirectUrl(ShiftTemplateResource::getUrl('index')),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return ShiftTemplateResource::getUrl('view', ['record' => $this->record]);
    }

    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Turno actualizado')
            ->body('Los cambios fueron guardados correctamente.');
    }
}
