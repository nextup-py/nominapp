<?php

namespace App\Filament\Resources\ShiftTemplateResource\Pages;

use App\Filament\Resources\ShiftTemplateResource;
use App\Models\ShiftTemplate;
use Filament\Actions\CreateAction;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

/** Página de listado de turnos con pestañas por estado. */
class ListShiftTemplates extends ListRecords
{
    protected static string $resource = ShiftTemplateResource::class;

    /** @var array{all: int, active: int, inactive: int}|null Conteos de las pestañas, calculados una sola vez por ciclo. */
    protected ?array $shiftCounts = null;

    /**
     * Cuenta turnos por pestaña en una sola query.
     *
     * @return array{all: int, active: int, inactive: int}
     */
    protected function getShiftCounts(): array
    {
        if ($this->shiftCounts === null) {
            $row = ShiftTemplate::query()
                ->selectRaw('COUNT(*) AS total')
                ->selectRaw('COALESCE(SUM(shift_templates.is_active = 1), 0) AS active')
                ->selectRaw('COALESCE(SUM(shift_templates.is_active = 0), 0) AS inactive')
                ->toBase()
                ->first();

            $this->shiftCounts = [
                'all' => (int) $row->total,
                'active' => (int) $row->active,
                'inactive' => (int) $row->inactive,
            ];
        }

        return $this->shiftCounts;
    }

    /**
     * Pestañas por estado; abre en Activos.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $counts = $this->getShiftCounts();

        return [
            'active' => Tab::make('Activos')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('shift_templates.is_active', true))
                ->badge($counts['active'])
                ->badgeColor('success')
                ->icon('heroicon-o-check-circle'),

            'inactive' => Tab::make('Inactivos')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('shift_templates.is_active', false))
                ->badge($counts['inactive'])
                ->badgeColor('danger')
                ->icon('heroicon-o-no-symbol'),

            'all' => Tab::make('Todos')
                ->badge($counts['all'])
                ->badgeColor('gray')
                ->icon('heroicon-o-table-cells'),
        ];
    }

    /** Pestaña inicial: los turnos activos. */
    public function getDefaultActiveTab(): string|int|null
    {
        return 'active';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nuevo Turno')->icon('heroicon-o-plus'),
        ];
    }
}
