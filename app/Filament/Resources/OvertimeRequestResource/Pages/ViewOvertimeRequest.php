<?php

namespace App\Filament\Resources\OvertimeRequestResource\Pages;

use App\Filament\Actions\OvertimeRequestActions;
use App\Filament\Resources\OvertimeRequestResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewOvertimeRequest extends ViewRecord
{
    protected static string $resource = OvertimeRequestResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            OvertimeRequestActions::approve(Action::class),
            OvertimeRequestActions::reject(Action::class),
            OvertimeRequestActions::cancel(Action::class),
        ];
    }
}
