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
        return [
            AttendanceMarkFailureResource::getDismissUnrecognizedFacesAction(),
        ];
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

    /** @var array{all: int, review: int, faces: int, terminal: int, mobile: int}|null Contadores de las pestañas (una sola consulta por ciclo). */
    protected ?array $failureCounts = null;

    /**
     * Contadores de las pestañas calculados con una única consulta agregada.
     *
     * @return array{all: int, review: int, faces: int, terminal: int, mobile: int}
     */
    protected function getFailureCounts(): array
    {
        if ($this->failureCounts === null) {
            $row = AttendanceMarkFailure::query()
                ->selectRaw("count(*) as total, sum(resolution_status = 'pending' and failure_type not in (?, ?)) as review, sum(resolution_status = 'pending' and failure_type in (?, ?)) as faces, sum(mode = 'terminal') as terminal, sum(mode = 'mobile') as mobile", [...AttendanceMarkFailure::UNRECOGNIZED_FACE_TYPES, ...AttendanceMarkFailure::UNRECOGNIZED_FACE_TYPES])
                ->toBase()
                ->first();

            $this->failureCounts = [
                'all' => (int) ($row->total ?? 0),
                'review' => (int) ($row->review ?? 0),
                'faces' => (int) ($row->faces ?? 0),
                'terminal' => (int) ($row->terminal ?? 0),
                'mobile' => (int) ($row->mobile ?? 0),
            ];
        }

        return $this->failureCounts;
    }

    /** Abre en "Para revisar": lo que RR.HH. puede resolver, sin el ruido de rostros no reconocidos. */
    public function getDefaultActiveTab(): string|int|null
    {
        return 'review';
    }

    /**
     * Pestañas: para revisar, rostros no reconocidos, todos y por modo de marcación.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $counts = $this->getFailureCounts();

        return [
            'review' => Tab::make('Para revisar')
                ->icon('heroicon-o-bell-alert')
                ->badge($counts['review'])
                ->badgeColor($counts['review'] > 0 ? 'danger' : 'gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->pendingToReview()),

            'faces' => Tab::make('Rostros no reconocidos')
                ->icon('heroicon-o-face-frown')
                ->badge($counts['faces'])
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->pendingUnrecognizedFaces()),

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
