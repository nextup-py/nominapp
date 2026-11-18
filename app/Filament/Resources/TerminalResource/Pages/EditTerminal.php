<?php

namespace App\Filament\Resources\TerminalResource\Pages;

use App\Filament\Resources\TerminalResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

/** Edición de una terminal de marcación. */
class EditTerminal extends EditRecord
{
    protected static string $resource = TerminalResource::class;

    /** Título de la página con el nombre del terminal que se edita. */
    public function getTitle(): string|Htmlable
    {
        return 'Editar: '.$this->record->name;
    }

    /**
     * Acciones del encabezado de la página de edición.
     */
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()->label('Ver')->icon('heroicon-o-eye')->color('gray'),
            DeleteAction::make()
                ->label('Eliminar')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->modalHeading('¿Eliminar terminal?')
                ->modalDescription('Se eliminan también su bitácora, comandos y estadísticas. Las marcaciones ya registradas se conservan. Para dejar de usarlo, es mejor desactivarlo.')
                ->modalSubmitActionLabel('Sí, eliminar'),
        ];
    }

    /**
     * Redirige a la vista de detalle tras guardar.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    /**
     * Notificación de éxito al guardar cambios.
     */
    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Terminal actualizado');
    }
}
