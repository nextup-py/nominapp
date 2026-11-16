<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\TerminalResource;
use App\Models\Terminal;
use App\Services\TerminalHealthBoard;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * Tabla de salud de los terminales activos con semáforo (verde, ámbar, rojo) y los motivos de cada alerta.
 * Solo lectura: cada fila enlaza al detalle del terminal, donde están las acciones.
 */
class TerminalHealthBoardWidget extends Widget
{
    protected static string $view = 'filament.widgets.terminal-health-board';

    protected static ?int $sort = 4;

    protected static ?string $pollingInterval = '60s';

    protected int|string|array $columnSpan = 'full';

    /** Visible solo con el módulo habilitado y permiso para ver terminales. */
    public static function canView(): bool
    {
        return TerminalResource::canViewAny();
    }

    /**
     * Datos de la vista: filas del semáforo y cuántos terminales hay por nivel.
     *
     * @return array{rows: Collection<int, array<string, mixed>>, counts: array<string, int>}
     */
    protected function getViewData(): array
    {
        $rows = app(TerminalHealthBoard::class)->rows();

        return [
            'rows' => $rows,
            'counts' => [
                'danger' => $rows->where('level', 'danger')->count(),
                'warning' => $rows->where('level', 'warning')->count(),
                'success' => $rows->where('level', 'success')->count(),
            ],
        ];
    }

    /** URL del detalle del terminal. */
    public static function terminalUrl(Terminal $terminal): string
    {
        return TerminalResource::getUrl('view', ['record' => $terminal]);
    }
}
