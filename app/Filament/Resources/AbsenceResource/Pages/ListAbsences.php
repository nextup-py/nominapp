<?php

namespace App\Filament\Resources\AbsenceResource\Pages;

use App\Filament\Resources\AbsenceResource;
use App\Models\Absence;
use App\Models\AttendanceDay;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\ListRecords\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListAbsences extends ListRecords
{
    protected static string $resource = AbsenceResource::class;

    /** @var array<string, int>|null */
    protected ?array $absenceCounts = null;

    protected function getHeaderActions(): array
    {
        return [
            AbsenceResource::getExcelExportAction(),

            CreateAction::make()
                ->label('Nueva Ausencia')
                ->icon('heroicon-o-plus'),
        ];
    }

    /** Retorna los counts por estado, calculados una sola vez por ciclo Livewire. */
    protected function getAbsenceCounts(): array
    {
        if ($this->absenceCounts === null) {
            $rows = Absence::query()
                ->leftJoin('attendance_days', 'attendance_days.id', '=', 'absences.attendance_day_id')
                ->selectRaw('absences.status, COUNT(*) as total, SUM(attendance_days.date >= ?) as recent', [AttendanceDay::attentionWindowStart()])
                ->groupBy('absences.status')
                ->get()
                ->keyBy('status');
            $counts = $rows->map(fn ($row) => (int) $row->total)->all();

            $this->absenceCounts = [
                'all' => array_sum($counts),
                'to_review' => (int) ($rows['pending']->recent ?? 0),
                'pending' => $counts['pending'] ?? 0,
                'justified' => $counts['justified'] ?? 0,
                'unjustified' => $counts['unjustified'] ?? 0,
            ];
        }

        return $this->absenceCounts;
    }

    /** Abre en lo que pide atención ahora; el historial sin resolver queda en «Todas las pendientes». */
    public function getDefaultActiveTab(): string|int|null
    {
        return 'to_review';
    }

    public function getTabs(): array
    {
        $counts = $this->getAbsenceCounts();

        return [
            'all' => Tab::make('Todas')
                ->badge($counts['all']),

            'to_review' => Tab::make('Por revisar (últimos '.AttendanceDay::ATTENTION_WINDOW_DAYS.' días)')
                ->badge($counts['to_review'])
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->pendingToReview()),

            'pending' => Tab::make('Todas las pendientes')
                ->badge($counts['pending'])
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending')),

            'justified' => Tab::make('Justificadas')
                ->badge($counts['justified'])
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'justified')),

            'unjustified' => Tab::make('Injustificadas')
                ->badge($counts['unjustified'])
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'unjustified')),
        ];
    }
}
