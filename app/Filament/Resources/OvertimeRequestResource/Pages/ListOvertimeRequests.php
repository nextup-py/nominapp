<?php

namespace App\Filament\Resources\OvertimeRequestResource\Pages;

use App\Filament\Actions\OvertimeRequestActions;
use App\Filament\Resources\OvertimeRequestResource;
use App\Models\OvertimeRequest;
use Filament\Actions\Action;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListOvertimeRequests extends ListRecords
{
    protected static string $resource = OvertimeRequestResource::class;

    /** @var array<string, int>|null Conteos de las pestañas (una sola consulta). */
    protected ?array $requestCounts = null;

    /** @return array<string, int> */
    protected function getRequestCounts(): array
    {
        if ($this->requestCounts === null) {
            $counts = OvertimeRequest::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->all();
            $this->requestCounts = [
                'pending_hr' => (int) ($counts[OvertimeRequest::STATUS_PENDING_HR] ?? 0),
                'pending_boss' => (int) ($counts[OvertimeRequest::STATUS_PENDING_BOSS] ?? 0),
                'approved' => (int) ($counts[OvertimeRequest::STATUS_APPROVED] ?? 0),
                'all' => array_sum($counts),
            ];
        }

        return $this->requestCounts;
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'pending_hr';
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $counts = $this->getRequestCounts();

        return [
            'pending_hr' => Tab::make('Pendientes de RR.HH.')->badge($counts['pending_hr'] ?: null)->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', OvertimeRequest::STATUS_PENDING_HR)),
            'pending_boss' => Tab::make('Pendientes del jefe')->badge($counts['pending_boss'] ?: null)
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', OvertimeRequest::STATUS_PENDING_BOSS)),
            'approved' => Tab::make('Aprobados')->badge($counts['approved'] ?: null)->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', OvertimeRequest::STATUS_APPROVED)),
            'all' => Tab::make('Todos')->badge($counts['all'] ?: null),
        ];
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [OvertimeRequestActions::request(Action::class)];
    }
}
