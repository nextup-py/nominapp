<?php

namespace App\Filament\Widgets;

use App\Models\AttendanceDay;
use App\Models\Branch;
use Filament\Widgets\ChartWidget;

/** Widget de gráfico: presencias y ausencias por sucursal para el día de hoy. */
class BranchAttendanceToday extends ChartWidget
{
    protected static ?string $heading = 'Presencias / Ausencias por Sucursal';

    protected static ?string $description = 'Estado de asistencia del día de hoy';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 4;

    /** Refresca el gráfico cada 60 segundos. */
    protected static ?string $pollingInterval = '60s';

    /**
     * Obtiene los datos del gráfico agrupando presentes por sucursal en 2 queries
     * (1 para sucursales con empleados activos, 1 para presentes del día).
     *
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $today = now()->toDateString();

        $branches = Branch::active()->withCount(['employees' => fn ($q) => $q->where('status', 'active')])
            ->having('employees_count', '>', 0)
            ->get();

        // Una sola query agrupada por sucursal en lugar de una por branch (evita N+1)
        $presentesPorSucursal = AttendanceDay::where('attendance_days.date', $today)
            ->where('attendance_days.status', 'present')
            ->join('employees', 'attendance_days.employee_id', '=', 'employees.id')
            ->where('employees.status', 'active')
            ->selectRaw('employees.branch_id, COUNT(*) as count')
            ->groupBy('employees.branch_id')
            ->pluck('count', 'branch_id')
            ->map(fn ($c) => (int) $c);

        $labels = [];
        $presentes = [];
        $ausentes = [];

        foreach ($branches as $branch) {
            $total = $branch->employees_count;
            $presentes_branch = $presentesPorSucursal->get($branch->id, 0);

            $labels[] = $branch->name." ({$total})";
            $presentes[] = $presentes_branch;
            $ausentes[] = max(0, $total - $presentes_branch);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Presentes',
                    'data' => $presentes,
                    'backgroundColor' => '#10b981',
                    'borderColor' => '#059669',
                    'borderWidth' => 1,
                    'barPercentage' => 0.6,
                    'categoryPercentage' => 0.8,
                ],
                [
                    'label' => 'Ausentes',
                    'data' => $ausentes,
                    'backgroundColor' => '#ef4444',
                    'borderColor' => '#dc2626',
                    'borderWidth' => 1,
                    'barPercentage' => 0.6,
                    'categoryPercentage' => 0.8,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'top',
                ],
                'tooltip' => [
                    'enabled' => true,
                    'mode' => 'index',
                    'intersect' => false,
                ],
            ],
            'indexAxis' => 'y',
            'scales' => [
                'x' => [
                    'stacked' => false,
                    'beginAtZero' => true,
                    'ticks' => ['stepSize' => 1, 'precision' => 0],
                    'grid' => ['display' => true, 'drawBorder' => false],
                ],
                'y' => [
                    'stacked' => false,
                    'grid' => ['display' => false],
                ],
            ],
            'responsive' => true,
            'maintainAspectRatio' => false,
        ];
    }
}
