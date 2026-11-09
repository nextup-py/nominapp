<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\AbsenceResource;
use App\Filament\Resources\EmployeeResource;
use App\Models\AttendanceDay;
use App\Models\Employee;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Widget de resumen estadístico del dashboard: empleados, asistencia y cumpleaños. */
class StatsOverview extends BaseWidget
{
    /** Solo lo ven quienes tienen permiso sobre el módulo que resume. */
    public static function canView(): bool
    {
        return EmployeeResource::canViewAny();
    }

    protected static ?int $sort = 1;

    /** Refresca los datos cada 60 segundos sin recargar la página. */
    protected static ?string $pollingInterval = '60s';

    /**
     * Retorna los stats del dashboard.
     *
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $today = Carbon::today();

        // Una sola query para total y activos
        $counts = Employee::selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active
        ")->first();

        $totalEmpleados = (int) $counts->total;
        $empleadosActivos = (int) $counts->active;

        $presentesHoy = AttendanceDay::where('date', $today)
            ->where('status', 'present')
            ->whereHas('employee', fn ($q) => $q->where('status', 'active'))
            ->count();

        // max(0, ...) por si $presentesHoy supera $empleadosActivos (reactivaciones intraday)
        $ausentesHoy = max(0, $empleadosActivos - $presentesHoy);

        $porcentajeAsistencia = $empleadosActivos > 0
            ? round(($presentesHoy / $empleadosActivos) * 100, 1)
            : 0;

        return [
            Stat::make('Empleados Activos', $empleadosActivos)
                ->description('Total de '.$totalEmpleados.' empleados')
                ->descriptionIcon('heroicon-o-users')
                ->color('success')
                ->icon('heroicon-o-check-circle')
                ->chart($this->getEmployeeTrend()),

            Stat::make('Presentes Hoy', $presentesHoy)
                ->description($porcentajeAsistencia.'% de asistencia')
                ->descriptionIcon('heroicon-o-arrow-trending-up')
                ->color($porcentajeAsistencia >= 90 ? 'success' : ($porcentajeAsistencia >= 70 ? 'warning' : 'danger'))
                ->icon('heroicon-o-user-group')
                ->chart($this->getAttendanceTrend($empleadosActivos)),

            Stat::make('Ausentes Hoy', $ausentesHoy)
                ->description('Faltas registradas hoy')
                ->descriptionIcon('heroicon-o-arrow-trending-down')
                ->color($ausentesHoy === 0 ? 'success' : 'danger')
                ->icon('heroicon-o-user-minus')
                ->chart($this->getAbsenceTrend($empleadosActivos))
                ->url(AbsenceResource::getUrl('index')),

            Stat::make('Cumpleaños del Mes', $this->getBirthdaysThisMonth())
                ->description('Celebraciones este mes')
                ->descriptionIcon('heroicon-o-cake')
                ->color('primary')
                ->icon('heroicon-o-gift')
                ->url(route('filament.admin.resources.employees.index', [
                    'tableFilters' => [
                        'birthday_month' => ['value' => now()->month],
                    ],
                ])),
        ];
    }

    /**
     * Tendencia de empleados activos en los últimos 7 días (2 queries en lugar de 7).
     * Nota: usa created_at como proxy de fecha de alta; no refleja reactivaciones.
     *
     * @return array<int, int>
     */
    protected function getEmployeeTrend(): array
    {
        $windowStart = Carbon::today()->subDays(6)->startOfDay();

        $baseCount = Employee::where('status', 'active')
            ->where('created_at', '<', $windowStart)
            ->count();

        $perDay = Employee::where('status', 'active')
            ->where('created_at', '>=', $windowStart)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->pluck('count', 'date')
            ->map(fn ($c) => (int) $c);

        $trend = [];
        $cumulative = $baseCount;

        for ($i = 6; $i >= 0; $i--) {
            $dateStr = Carbon::today()->subDays($i)->toDateString();
            $cumulative += $perDay->get($dateStr, 0);
            $trend[] = $cumulative;
        }

        return $trend;
    }

    /**
     * Tendencia de asistencia (%) en los últimos 7 días (1 query en lugar de 7).
     *
     * @return array<int, int>
     */
    protected function getAttendanceTrend(int $empleadosActivos): array
    {
        $windowStart = Carbon::today()->subDays(6);

        $presentesPorDia = AttendanceDay::where('date', '>=', $windowStart)
            ->where('status', 'present')
            ->whereHas('employee', fn ($q) => $q->where('status', 'active'))
            ->selectRaw('date, COUNT(*) as count')
            ->groupBy('date')
            ->pluck('count', 'date')
            ->mapWithKeys(fn ($count, $date) => [Carbon::parse($date)->toDateString() => (int) $count]);

        $trend = [];
        for ($i = 6; $i >= 0; $i--) {
            $dateStr = Carbon::today()->subDays($i)->toDateString();
            $presentes = $presentesPorDia->get($dateStr, 0);
            $trend[] = $empleadosActivos > 0
                ? round(($presentes / $empleadosActivos) * 100)
                : 0;
        }

        return $trend;
    }

    /**
     * Tendencia de ausencias (%) en los últimos 7 días, derivada de la asistencia.
     *
     * @return array<int, int>
     */
    protected function getAbsenceTrend(int $empleadosActivos): array
    {
        $attendanceTrend = $this->getAttendanceTrend($empleadosActivos);

        return array_map(fn (int $pct) => max(0, 100 - $pct), $attendanceTrend);
    }

    /**
     * Cuenta empleados activos con cumpleaños en el mes actual.
     */
    protected function getBirthdaysThisMonth(): int
    {
        return Employee::where('status', 'active')
            ->whereMonth('birth_date', now()->month)
            ->count();
    }
}
