<?php

namespace App\Filament\Resources\EmployeeLeaveResource\Pages;

use App\Filament\Resources\EmployeeLeaveResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

class EditEmployeeLeaves extends EditRecord
{
    protected static string $resource = EmployeeLeaveResource::class;

    /** Título de la página con el nombre del empleado de la licencia. */
    public function getTitle(): string|Htmlable
    {
        return 'Editar licencia: '.($this->record->employee?->full_name ?? 'sin empleado');
    }

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
                ->modalHeading('¿Eliminar licencia?')
                ->modalDescription('Esta acción no se puede deshacer.')
                ->modalSubmitActionLabel('Sí, eliminar')
                ->successNotificationTitle('Licencia eliminada')
                ->successRedirectUrl($this->getResource()::getUrl('index')),
        ];
    }

    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Licencia actualizada con éxito')
            ->body('Los cambios en la licencia han sido guardados correctamente.')
            ->duration(5000);
    }
}
