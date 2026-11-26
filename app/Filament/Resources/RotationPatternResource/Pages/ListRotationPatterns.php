<?php

namespace App\Filament\Resources\RotationPatternResource\Pages;

use App\Filament\Resources\RotationPatternResource;
use App\Models\RotationPattern;
use App\Models\ShiftTemplate;
use Filament\Actions\CreateAction;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Página de listado de patrones con pestañas por estado. */
class ListRotationPatterns extends ListRecords
{
    protected static string $resource = RotationPatternResource::class;

    /** @var array{all: int, active: int, inactive: int}|null Conteos de las pestañas, calculados una sola vez por ciclo. */
    protected ?array $patternCounts = null;

    /** @var Collection<int, ShiftTemplate>|null Turnos de las secuencias, cargados una vez por ciclo. */
    protected ?Collection $shiftMap = null;

    /**
     * Turnos usados por los patrones, por id (lo lee la columna «Ciclo» sin consulta por fila).
     *
     * @return Collection<int, ShiftTemplate>
     */
    public function shiftMap(): Collection
    {
        return $this->shiftMap ??= RotationPattern::shiftsFor(RotationPattern::query()->get(['id', 'sequence']));
    }

    /**
     * Cuenta patrones por pestaña en una sola query.
     *
     * @return array{all: int, active: int, inactive: int}
     */
    protected function getPatternCounts(): array
    {
        if ($this->patternCounts === null) {
            $row = RotationPattern::query()
                ->selectRaw('COUNT(*) AS total')
                ->selectRaw('COALESCE(SUM(rotation_patterns.is_active = 1), 0) AS active')
                ->selectRaw('COALESCE(SUM(rotation_patterns.is_active = 0), 0) AS inactive')
                ->toBase()
                ->first();

            $this->patternCounts = [
                'all' => (int) $row->total,
                'active' => (int) $row->active,
                'inactive' => (int) $row->inactive,
            ];
        }

        return $this->patternCounts;
    }

    /**
     * Pestañas por estado; abre en Activos.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $counts = $this->getPatternCounts();

        return [
            'active' => Tab::make('Activos')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('rotation_patterns.is_active', true))
                ->badge($counts['active'])
                ->badgeColor('success')
                ->icon('heroicon-o-check-circle'),

            'inactive' => Tab::make('Inactivos')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('rotation_patterns.is_active', false))
                ->badge($counts['inactive'])
                ->badgeColor('danger')
                ->icon('heroicon-o-no-symbol'),

            'all' => Tab::make('Todos')
                ->badge($counts['all'])
                ->badgeColor('gray')
                ->icon('heroicon-o-arrow-path'),
        ];
    }

    /** Pestaña inicial: los patrones activos. */
    public function getDefaultActiveTab(): string|int|null
    {
        return 'active';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nuevo Patrón')->icon('heroicon-o-plus'),
        ];
    }
}
