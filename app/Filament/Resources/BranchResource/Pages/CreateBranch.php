<?php

namespace App\Filament\Resources\BranchResource\Pages;

use App\Filament\Resources\BranchResource;
use App\Models\Company;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

/** Página de creación de una nueva sucursal. */
class CreateBranch extends CreateRecord
{
    protected static string $resource = BranchResource::class;

    /**
     * Capitaliza el nombre y limpia el teléfono antes de crear el registro.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] ??= Company::active()->value('id');

        if (isset($data['name'])) {
            $data['name'] = preg_replace_callback('/(?:^|\s)\S/u', fn ($m) => mb_strtoupper($m[0], 'UTF-8'), $data['name']);
        }

        if (isset($data['phone'])) {
            $data['phone'] = preg_replace('/[\s\-]/', '', $data['phone']) ?: null;
        }

        return $data;
    }

    /**
     * Redirige a la página de detalle de la sucursal recién creada.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    /**
     * Notificación de éxito tras registrar la sucursal.
     */
    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Sucursal registrada')
            ->body('La sucursal "'.$this->record->name.'" ha sido creada correctamente.');
    }
}
