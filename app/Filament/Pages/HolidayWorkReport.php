<?php

namespace App\Filament\Pages;

use App\Exports\HolidayWorkExport;
use App\Filament\Concerns\UsesNavigationLayout;
use App\Filament\Resources\AttendanceDayResource;
use App\Filament\Resources\ContractResource;
use App\Models\Branch;
use App\Services\HolidayWorkReportService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Reporte «Trabajo en feriado y domingo»: horas trabajadas, extras ya registradas y una estimación del recargo que
 * correspondería sobre el turno normal. Para conversar con el asesor legal y el contador; solo consulta. Requiere ver
 * asistencias y contratos (incluye valores de salario).
 */
class HolidayWorkReport extends Page
{
    use UsesNavigationLayout;

    protected static ?string $slug = 'reporte-trabajo-feriado';

    protected static ?string $title = 'Trabajo en feriado y domingo';

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Trabajo en feriado y domingo';

    protected static ?string $navigationGroup = 'Reportes';

    /** Se llega desde la página Reportes, no desde el sidebar. */
    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.holiday-work-report';

    /** Desde (Y-m-d); por defecto el inicio del año. */
    public ?string $from = null;

    /** Hasta (Y-m-d); por defecto hoy. */
    public ?string $until = null;

    public string $kind = HolidayWorkReportService::KIND_ALL;

    public ?string $branchId = null;

    /** Acceso: ver asistencias y contratos (el reporte muestra salarios). */
    public static function canAccess(): bool
    {
        return AttendanceDayResource::canViewAny() && ContractResource::canViewAny();
    }

    /** Valores iniciales de los filtros. */
    public function mount(): void
    {
        $this->from ??= now()->startOfYear()->toDateString();
        $this->until ??= today()->toDateString();
    }

    /**
     * Filas del período filtrado.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(): Collection
    {
        return HolidayWorkReportService::rows(
            Carbon::parse($this->from ?: now()->startOfYear()),
            Carbon::parse($this->until ?: today()),
            $this->kind,
            $this->branchId ? (int) $this->branchId : null,
        );
    }

    /**
     * Totales del período filtrado.
     *
     * @return array<string, int|float>
     */
    public function totals(): array
    {
        return HolidayWorkReportService::totals($this->rows());
    }

    /**
     * Sucursales para el filtro.
     *
     * @return array<int, string>
     */
    public function branches(): array
    {
        return Branch::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_excel')
                ->label('Exportar a Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(fn () => Excel::download(new HolidayWorkExport($this->rows()), 'trabajo_feriado_domingo_'.now()->format('Y_m_d_H_i_s').'.xlsx')),
        ];
    }
}
