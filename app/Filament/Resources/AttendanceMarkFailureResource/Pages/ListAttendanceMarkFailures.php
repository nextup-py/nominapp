<?php

namespace App\Filament\Resources\AttendanceMarkFailureResource\Pages;

use App\Filament\Resources\AttendanceMarkFailureResource;
use App\Filament\Resources\AttendanceMarkFailureResource\Widgets\TopFailingEmployeesWidget;
use App\Models\AttendanceMarkFailure;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\ListRecords\Tab;
use Illuminate\Database\Eloquent\Builder;

/** Página de listado de intentos fallidos de marcación. */
class ListAttendanceMarkFailures extends ListRecords
{
    protected static string $resource = AttendanceMarkFailureResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * Widget de diagnóstico: empleados con fallos de marcación recurrentes. Va al pie para que
     * los pendientes (lo que hay que resolver) queden arriba, sin bajar la pantalla.
     *
     * @return array<class-string>
     */
    protected function getFooterWidgets(): array
    {
        return [
            TopFailingEmployeesWidget::class,
        ];
    }

    /** @var array{all: int, pending: int, terminal: int, mobile: int}|null Contadores de las pestañas (una sola consulta por ciclo). */
    protected ?array $failureCounts = null;

    /**
     * Contadores de las pestañas calculados con una única consulta agregada.
     *
     * @return array{all: int, pending: int, terminal: int, mobile: int}
     */
    protected function getFailureCounts(): array
    {
        if ($this->failureCounts === null) {
            $row = AttendanceMarkFailure::query()
                ->selectRaw("count(*) as total, sum(resolution_status = 'pending') as pending, sum(mode = 'terminal') as terminal, sum(mode = 'mobile') as mobile")
                ->toBase()
                ->first();

            $this->failureCounts = [
                'all' => (int) ($row->total ?? 0),
                'pending' => (int) ($row->pending ?? 0),
                'terminal' => (int) ($row->terminal ?? 0),
                'mobile' => (int) ($row->mobile ?? 0),
            ];
        }

        return $this->failureCounts;
    }

    /** Abre en los pendientes: lo que RR.HH. tiene que resolver. */
    public function getDefaultActiveTab(): string|int|null
    {
        return 'pending';
    }

    /**
     * Pestañas: pendientes de revisión, todos y por modo de marcación.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $counts = $this->getFailureCounts();

        return [
            'pending' => Tab::make('Pendientes')
                ->icon('heroicon-o-bell-alert')
                ->badge($counts['pending'])
                ->badgeColor($counts['pending'] > 0 ? 'danger' : 'gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('resolution_status', 'pending')),

            'all' => Tab::make('Todos')
                ->badge($counts['all']),

            'terminal' => Tab::make('Terminal')
                ->badge($counts['terminal'])
                ->badgeColor('info')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('mode', 'terminal')),

            'mobile' => Tab::make('Móvil')
                ->badge($counts['mobile'])
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('mode', 'mobile')),
        ];
    }
}
