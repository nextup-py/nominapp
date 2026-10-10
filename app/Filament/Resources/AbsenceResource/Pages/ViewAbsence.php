<?php

namespace App\Filament\Resources\AbsenceResource\Pages;

use App\Filament\Actions\AbsenceActions;
use App\Filament\Resources\AbsenceResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAbsence extends ViewRecord
{
    protected static string $resource = AbsenceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AbsenceActions::registerAttendance(Action::class),

            AbsenceActions::justify(Action::class),

            AbsenceActions::markUnjustified(Action::class),

            EditAction::make()
                ->label('Editar')
                ->icon('heroicon-o-pencil-square')
                ->color('primary')
                ->tooltip('Editar datos de la ausencia'),

            DeleteAction::make()
                ->successRedirectUrl($this->getResource()::getUrl('index')),
        ];
    }
}
