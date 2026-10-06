<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\TerminalResource;
use App\Models\Terminal;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Collection;

/**
 * Salud de los terminales de marcación: cuántos están en línea, desconectados, sin vincular
 * o fuera de horario, con atajo al listado filtrado. Solo cuenta terminales activos y solo
 * lo ve quien puede ver terminales (con el módulo de marcación biométrica habilitado).
 */
class TerminalHealthWidget extends BaseWidget
{
    protected static ?int $sort = 3;

    protected static ?string $pollingInterval = '60s';

    /** Visible solo con el módulo habilitado y permiso para ver terminales. */
    public static function canView(): bool
    {
        return TerminalResource::canViewAny();
    }

    /**
     * Tarjetas por estado de conectividad.
     *
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        /** @var Collection<int, Terminal> $terminals */
        $terminals = Terminal::query()->withSyncTokenFlag()->with('branch')->where('status', 'active')->get();
        $byStatus = $terminals->groupBy('connectivity_status');

        $online = $byStatus->get('online', collect());
        $stale = $byStatus->get('stale', collect());
        $unlinked = $byStatus->get('unlinked', collect());
        $offHours = $byStatus->get('off_hours', collect());
        $withIssues = $terminals->filter(fn (Terminal $t) => $t->connectivity_status === 'online' && ($t->isQueueStuck() || ($t->health_snapshot['low_battery'] ?? false)));

        return [
            Stat::make('Terminales en línea', $online->count())
                ->description($terminals->isEmpty() ? 'Sin terminales activos' : "de {$terminals->count()} activos")
                ->descriptionIcon('heroicon-o-signal')
                ->color($terminals->isNotEmpty() && $online->count() === $terminals->count() ? 'success' : 'gray')
                ->icon('heroicon-o-computer-desktop')
                ->url(static::listUrl('online')),

            Stat::make('Desconectados', $stale->count())
                ->description($stale->isEmpty() ? 'Todos reportan' : static::names($stale))
                ->descriptionIcon($stale->isEmpty() ? 'heroicon-o-check-circle' : 'heroicon-o-signal-slash')
                ->color($stale->isEmpty() ? 'success' : 'danger')
                ->icon('heroicon-o-signal-slash')
                ->url(static::listUrl('stale')),

            Stat::make('Sin vincular', $unlinked->count())
                ->description($unlinked->isEmpty() ? 'Todos vinculados' : static::names($unlinked))
                ->descriptionIcon($unlinked->isEmpty() ? 'heroicon-o-check-circle' : 'heroicon-o-link-slash')
                ->color($unlinked->isEmpty() ? 'success' : 'danger')
                ->icon('heroicon-o-link-slash')
                ->url(static::listUrl('unlinked')),

            Stat::make('Con alertas de cola o batería', $withIssues->count())
                ->description($withIssues->isEmpty() ? ($offHours->isEmpty() ? 'Sin alertas' : "{$offHours->count()} fuera de horario") : static::names($withIssues))
                ->descriptionIcon($withIssues->isEmpty() ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle')
                ->color($withIssues->isEmpty() ? 'success' : 'warning')
                ->icon('heroicon-o-battery-50'),
        ];
    }

    /**
     * Nombres de hasta tres terminales (y cuántos más hay) para la descripción de la tarjeta.
     *
     * @param  Collection<int, Terminal>  $terminals
     */
    private static function names(Collection $terminals): string
    {
        $names = $terminals->take(3)->pluck('name')->implode(', ');
        $extra = $terminals->count() - 3;

        return $extra > 0 ? "{$names} y {$extra} más" : $names;
    }

    /** URL del listado de terminales filtrado por estado de conectividad. */
    private static function listUrl(string $status): string
    {
        return TerminalResource::getUrl('index', ['tableFilters' => ['connectivity_status' => ['value' => $status]]]);
    }
}
