<?php

namespace App\Services;

use App\Filament\Resources\TerminalResource;
use App\Models\Terminal;
use Illuminate\Support\Facades\Cache;

/**
 * Terminales que necesitan atención ahora (sin vincular o sin conexión dentro de su horario de vigilancia), para el
 * cartel fijo del panel. Se calcula como mucho una vez por minuto para no consultar en cada pantalla.
 */
class TerminalAttentionBanner
{
    /** Segundos que se reutiliza el resultado. */
    public const CACHE_SECONDS = 60;

    /** Minutos sin latido que debe llevar un terminal para figurar «sin conexión» en el cartel (evita parpadeos por cortes cortos). */
    public const MIN_OFFLINE_MINUTES = 30;

    /**
     * Terminales activos que figuran Sin vincular o Desconectados (los que están fuera de su horario no cuentan).
     *
     * `pending` cuenta los códigos de vinculación que esperan aprobación (con el terminal sin vincular, es lo que lo destraba).
     *
     * @return list<array{id: int, name: string, branch: ?string, status: string, pending: int}>
     */
    public static function items(): array
    {
        return Cache::remember('terminal-attention-banner', self::CACHE_SECONDS, function (): array {
            return Terminal::query()
                ->withSyncTokenFlag()
                ->withCount(['pairingRequests as pending_requests_count' => fn ($query) => $query->pending()])
                ->with('branch')
                ->orderBy('name')
                ->get()
                ->map(fn (Terminal $terminal): array => [
                    'id' => $terminal->id,
                    'name' => $terminal->name,
                    'branch' => $terminal->branch?->name,
                    'status' => $terminal->isInactive() ? 'ok' : self::statusOf($terminal),
                    'pending' => (int) $terminal->pending_requests_count,
                ])
                ->filter(fn (array $item): bool => $item['status'] !== 'ok')
                ->values()
                ->all();
        });
    }

    /**
     * Qué le pasa al terminal: `unlinked`, `stale` (sin conexión dentro de su horario), `outside` (equipo móvil fuera de la
     * sucursal, según su último aviso) u `ok`.
     */
    private static function statusOf(Terminal $terminal): string
    {
        $connectivity = $terminal->connectivity_status;

        if ($connectivity === 'unlinked') {
            return $connectivity;
        }

        if ($connectivity === 'stale') {
            return $terminal->last_heartbeat_at?->diffInMinutes(now()) >= self::MIN_OFFLINE_MINUTES ? 'stale' : 'ok';
        }

        return $connectivity === 'online' && ($terminal->health_snapshot['outside'] ?? false) ? 'outside' : 'ok';
    }

    /**
     * Cuántos terminales hay por motivo, para el resumen del cartel (solo los motivos presentes).
     *
     * @param  list<array{status: string}>  $items
     * @return array<string, int>
     */
    public static function countsByStatus(array $items): array
    {
        $counts = array_count_values(array_column($items, 'status'));

        return array_filter([
            'stale' => $counts['stale'] ?? 0,
            'unlinked' => $counts['unlinked'] ?? 0,
            'outside' => $counts['outside'] ?? 0,
        ]);
    }

    /** Pantallas del panel donde se muestra el cartel (donde se actúa sobre terminales o asistencia). */
    public const PAGE_ROUTES = [
        'filament.admin.pages.dashboard',
        'filament.admin.resources.terminales.*',
        'filament.admin.pages.por-resolver',
        'filament.admin.pages.monitor-en-vivo',
        'filament.admin.pages.terminal-pairing-inbox',
    ];

    /** Si la pantalla actual es una de las que muestran el cartel. */
    public static function showsOnCurrentPage(): bool
    {
        return request()->routeIs(...self::PAGE_ROUTES);
    }

    /**
     * Huella de lo que muestra el cartel (terminales, motivos y códigos pendientes): al cambiar, un cartel que el
     * usuario cerró vuelve a aparecer.
     *
     * @param  list<array{id: int, status: string, pending: int}>  $items
     */
    public static function signature(array $items): string
    {
        return md5(collect($items)->map(fn (array $item): string => "{$item['id']}:{$item['status']}:{$item['pending']}")->implode('|'));
    }

    /** Si el usuario actual debe ver el cartel: gestiona terminales y el módulo está disponible para su rol. */
    public static function visibleToCurrentUser(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->can('update_terminal') && TerminalResource::canViewAny();
    }

    /** Olvida el resultado guardado (lo usan los tests y los cambios que deben verse de inmediato). */
    public static function forget(): void
    {
        Cache::forget('terminal-attention-banner');
    }
}
