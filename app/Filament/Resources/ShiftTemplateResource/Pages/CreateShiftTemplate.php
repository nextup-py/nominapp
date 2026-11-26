<?php

namespace App\Filament\Resources\ShiftTemplateResource\Pages;

use App\Filament\Resources\ShiftTemplateResource;
use App\Models\Company;
use App\Models\ShiftTemplate;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

/** Página de creación de turno. */
class CreateShiftTemplate extends CreateRecord
{
    protected static string $resource = ShiftTemplateResource::class;

    /**
     * Capitaliza el nombre y asigna la empresa única cuando el formulario no la pide.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['name'] = ShiftTemplate::capitalizeName($data['name']);
        $data['shift_type'] = $data['shift_type'] ?? 'diurno';
        $data['company_id'] ??= Company::active()->value('id');

        return $data;
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Turno creado')
            ->body('El turno fue creado correctamente.');
    }
}
