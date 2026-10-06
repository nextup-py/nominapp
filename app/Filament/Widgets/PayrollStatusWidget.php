<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\PayrollPeriodResource;
use App\Models\Payroll;
use App\Models\PayrollPeriod;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Widget de estado de nómina: muestra el período activo y el progreso de aprobación. */
class PayrollStatusWidget extends BaseWidget
{
    protected static ?int $sort = 3;

    protected static ?string $pollingInterval = '60s';

    /**
     * Retorna las tarjetas del estado de nómina del período activo.
     *
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        // Prioriza períodos activos; cae al último cerrado si no hay ninguno
        $period = PayrollPeriod::whereIn('status', ['draft', 'processing'])
            ->latest('start_date')
            ->first()
            ?? PayrollPeriod::where('status', 'closed')
                ->latest('start_date')
                ->first();

        if (! $period) {
            return [
                Stat::make('Período de Nómina', '—')
                    ->description('No hay períodos registrados')
                    ->descriptionIcon('heroicon-o-information-circle')
                    ->color('gray')
                    ->icon('heroicon-o-calendar'),
            ];
        }

        // Agrega counts y suma en una sola query
        $payrollStats = Payroll::where('payroll_period_id', $period->id)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status IN ('draft', 'approved') THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'approved' THEN net_salary ELSE 0 END) as total_to_pay
            ")
            ->first();

        $totalPayrolls = (int) ($payrollStats->total ?? 0);
        $pendingCount = (int) ($payrollStats->pending ?? 0);
        $totalToPay = (float) ($payrollStats->total_to_pay ?? 0);

        $periodColor = match ($period->status) {
            'processing' => 'primary',
            'closed' => 'success',
            default => 'gray',
        };

        $statusLabel = PayrollPeriod::statusOptions()[$period->status] ?? $period->status;
        $periodUrl = PayrollPeriodResource::getUrl('view', ['record' => $period->id]);

        return [
            Stat::make('Período de Nómina', $period->name)
                ->description('Estado: '.$statusLabel)
                ->descriptionIcon('heroicon-o-calendar')
                ->color($periodColor)
                ->icon('heroicon-o-document-text')
                ->url($periodUrl),

            Stat::make('Nóminas Generadas', $totalPayrolls)
                ->description('Recibos en '.$period->name)
                ->descriptionIcon('heroicon-o-user-group')
                ->color('gray')
                ->icon('heroicon-o-users')
                ->url($periodUrl),

            Stat::make('Por Aprobar / Pagar', $pendingCount)
                ->description($pendingCount > 0 ? 'Nóminas en estado draft o aprobadas' : 'Todas las nóminas al día')
                ->descriptionIcon($pendingCount > 0 ? 'heroicon-o-clock' : 'heroicon-o-check-circle')
                ->color($pendingCount > 0 ? 'danger' : 'success')
                ->icon('heroicon-o-clipboard-document-check')
                ->url($periodUrl),

            Stat::make('Total Neto a Pagar', 'Gs. '.number_format($totalToPay, 0, ',', '.'))
                ->description('Suma de nóminas aprobadas')
                ->descriptionIcon('heroicon-o-banknotes')
                ->color('info')
                ->icon('heroicon-o-currency-dollar')
                ->url($periodUrl),
        ];
    }
}
