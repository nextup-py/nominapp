<?php

namespace App\Filament\Resources\RotationPatternResource\Pages;

use App\Filament\Resources\RotationPatternResource;
use App\Models\Company;
use App\Models\RotationPattern;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

/** Página de creación de patrón de rotación. */
class CreateRotationPattern extends CreateRecord
{
    protected static string $resource = RotationPatternResource::class;

    /**
     * Capitaliza el nombre y asigna la empresa única cuando el formulario no la pide.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['name'] = RotationPattern::capitalizeName($data['name']);
        $data['company_id'] ??= Company::active()->value('id');

        return $data;
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Patrón creado')
            ->body('El patrón de rotación fue creado correctamente.');
    }
}
