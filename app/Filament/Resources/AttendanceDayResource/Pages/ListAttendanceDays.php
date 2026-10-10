<?php

namespace App\Filament\Resources\AttendanceDayResource\Pages;

use App\Filament\Pages\AttendanceReport;
use App\Filament\Resources\AttendanceDayResource;
use App\Models\AttendanceDay;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

/** Página de listado de asistencias diarias — muestra únicamente registros con estado presente. */
class ListAttendanceDays extends ListRecords
{
    protected static string $resource = AttendanceDayResource::class;

    /**
     * Define las acciones del encabezado de la página
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('go_to_report')
                ->label('Ver Reporte')
                ->icon('heroicon-o-chart-bar')
                ->color('gray')
                ->url(AttendanceReport::getUrl()),

            AttendanceDayResource::getApproveOvertimeRangeAction(),

            AttendanceDayResource::getRegisterExtraHoursAction(),
        ];
    }

    /**
     * Define las pestañas de filtrado. Todos los contadores salen de una sola consulta que reutiliza
     * las condiciones SQL del modelo (`AttendanceDay::*_SQL`), así pestaña, columna "Pendiente" y
     * contador no pueden desfasarse.
     */
    public function getTabs(): array
    {
        $today = Carbon::today()->toDateString();
        $yesterday = Carbon::yesterday()->toDateString();
        $attention = '('.AttendanceDay::missingCheckOutSql().') or ('.AttendanceDay::PENDING_TARDINESS_SQL.') or ('.AttendanceDay::PENDING_OVERTIME_SQL.')';

        $stats = AttendanceDay::where('status', 'present')->selectRaw(
            "COUNT(*) as total,
            SUM(CASE WHEN is_calculated = 1 THEN 1 ELSE 0 END) as calculated,
            SUM(CASE WHEN is_calculated = 0 THEN 1 ELSE 0 END) as not_calculated,
            SUM(CASE WHEN total_hours IS NULL THEN 1 ELSE 0 END) as incomplete,
            SUM(CASE WHEN date = ? THEN 1 ELSE 0 END) as today,
            SUM(CASE WHEN date = ? THEN 1 ELSE 0 END) as yesterday,
            SUM(CASE WHEN {$attention} THEN 1 ELSE 0 END) as attention",
            [$today, $yesterday, ...AttendanceDay::missingCheckOutBindings()]
        )->first();

        return [
            'attention' => Tab::make('Requieren atención')
                ->modifyQueryUsing(fn (Builder $query) => $query->needsAttention())
                ->badge($stats->attention ?: null)
                ->badgeColor('danger')
                ->icon('heroicon-o-bell-alert'),

            'yesterday' => Tab::make('Ayer')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('date', Carbon::yesterday()->toDateString()))
                ->badge($stats->yesterday ?: null)
                ->badgeColor('gray')
                ->icon('heroicon-o-calendar'),

            'today' => Tab::make('Hoy')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('date', Carbon::today()->toDateString()))
                ->badge($stats->today ?: null)
                ->badgeColor('gray')
                ->icon('heroicon-o-sun'),

            'all' => Tab::make('Todos')
                ->badge($stats->total)
                ->badgeColor('gray')
                ->icon('heroicon-o-calendar-days'),

            'calculated' => Tab::make('Calculados')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_calculated', true))
                ->badge($stats->calculated)
                ->badgeColor('info')
                ->icon('heroicon-o-calculator'),

            'not_calculated' => Tab::make('Sin calcular')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_calculated', false))
                ->badge($stats->not_calculated)
                ->badgeColor('warning')
                ->icon('heroicon-o-exclamation-triangle'),

            'incomplete' => Tab::make('Incompletos')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('total_hours'))
                ->badge($stats->incomplete ?: null)
                ->badgeColor('danger')
                ->icon('heroicon-o-exclamation-circle'),
        ];
    }

    /**
     * Define la pestaña activa por defecto
     */
    public function getDefaultActiveTab(): string|int|null
    {
        return 'attention';
    }
}
