<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\AguinaldoPeriodResource;
use App\Models\AguinaldoPeriod;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Widget condicional de aguinaldo: visible solo cuando hay un período en draft o processing. */
class AguinaldoStatusWidget extends BaseWidget
{
    protected static ?int $sort = 7;

    protected static ?string $pollingInterval = '60s';

    /** Solo visible cuando hay un período de aguinaldo activo (no cerrado). */
    public static function canView(): bool
    {
        return AguinaldoPeriod::whereIn('status', ['draft', 'processing'])->exists();
    }

    /**
     * Retorna las tarjetas del estado del período de aguinaldo activo.
     *
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $period = AguinaldoPeriod::whereIn('status', ['draft', 'processing'])
            ->latest('year')
            ->first();

        if (! $period) {
            return [];
        }

        // Agrega counts y monto pendiente de pago en una sola query
        $stats = $period->aguinaldos()
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'pending' THEN aguinaldo_amount ELSE 0 END) as pending_amount
            ")
            ->first();

        $total = (int) ($stats->total ?? 0);
        $pending = (int) ($stats->pending ?? 0);
        $pendingAmount = (float) ($stats->pending_amount ?? 0);

        $statusLabel = AguinaldoPeriod::getStatusLabel($period->status);
        $periodColor = $period->status === 'processing' ? 'primary' : 'gray';
        $periodUrl = AguinaldoPeriodResource::getUrl('view', ['record' => $period->id]);

        return [
            Stat::make('Período de Aguinaldo', (string) $period->year)
                ->description('Estado: '.$statusLabel)
                ->descriptionIcon('heroicon-o-calendar')
                ->color($periodColor)
                ->icon('heroicon-o-gift')
                ->url($periodUrl),

            Stat::make('Aguinaldos Generados', $total)
                ->description('Empleados incluidos en el período')
                ->descriptionIcon('heroicon-o-user-group')
                ->color('gray')
                ->icon('heroicon-o-users')
                ->url($periodUrl),

            Stat::make('Pendientes de Pago', $pending)
                ->description($pending > 0 ? 'Sin marcar como pagados aún' : 'Todos los aguinaldos pagados')
                ->descriptionIcon($pending > 0 ? 'heroicon-o-clock' : 'heroicon-o-check-circle')
                ->color($pending > 0 ? 'warning' : 'success')
                ->icon('heroicon-o-clipboard-document-check')
                ->url($periodUrl),

            Stat::make('Total a Pagar', 'Gs. '.number_format($pendingAmount, 0, ',', '.'))
                ->description('Monto total de aguinaldos pendientes')
                ->descriptionIcon('heroicon-o-banknotes')
                ->color('info')
                ->icon('heroicon-o-currency-dollar')
                ->url($periodUrl),
        ];
    }
}
