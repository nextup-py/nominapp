<?php

namespace App\Filament\Resources\WarningResource\Pages;

use App\Filament\Resources\WarningResource;
use App\Filament\Traits\HaltsOnSuspensionErrors;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/** Página de creación de amonestaciones. */
class CreateWarning extends CreateRecord
{
    use HaltsOnSuspensionErrors;

    protected static string $resource = WarningResource::class;

    /**
     * Inyecta la fecha de hoy y el usuario logueado antes de crear el registro.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['issued_at'] = now()->toDateString();
        $data['issued_by_id'] = Auth::id();

        return $data;
    }

    /**
     * Crea la amonestación; si la suspensión no es aplicable, notifica y detiene el guardado.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return $this->haltOnSuspensionError(fn () => parent::handleRecordCreation($data));
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Amonestación registrada')
            ->body('La amonestación fue registrada correctamente.');
    }
}
