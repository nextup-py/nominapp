<?php

namespace App\Filament\Resources\WarningResource\Pages;

use App\Filament\Resources\WarningResource;
use App\Filament\Traits\HaltsOnSuspensionErrors;
use App\Services\SuspensionService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** Página de edición de una amonestación. */
class EditWarning extends EditRecord
{
    use HaltsOnSuspensionErrors;

    protected static string $resource = WarningResource::class;

    protected static ?string $title = 'Editar';

    /**
     * Define las acciones del encabezado de la página de edición.
     *
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()->label('Ver')->icon('heroicon-o-eye')->color('gray'),
            DeleteAction::make()
                ->label('Eliminar')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->modalHeading('Eliminar amonestación')
                ->modalDescription('¿Estás seguro de que deseas eliminar esta amonestación? Esta acción no se puede deshacer.')
                ->modalSubmitActionLabel('Sí, eliminar')
                ->before(function (DeleteAction $action, Model $record) {
                    try {
                        app(SuspensionService::class)->assertCanRevert($record);
                    } catch (ValidationException $e) {
                        Notification::make()->danger()->title('No se puede eliminar')->body(collect($e->errors())->flatten()->implode(' '))->persistent()->send();
                        $action->cancel();
                    }
                })
                ->successNotificationTitle('Amonestación eliminada'),
        ];
    }

    /**
     * Actualiza la amonestación; si la suspensión no es aplicable, notifica y detiene el guardado.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->haltOnSuspensionError(fn () => parent::handleRecordUpdate($record, $data));
    }

    /**
     * Redirige a la vista de detalle tras guardar.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    /**
     * Notificación de edición exitosa.
     */
    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Amonestación actualizada')
            ->body('Los cambios fueron guardados correctamente.');
    }
}
