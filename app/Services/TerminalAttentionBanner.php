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

        if (in_array($connectivity, ['unlinked', 'stale'], true)) {
            return $connectivity;
        }

        return $connectivity === 'online' && ($terminal->health_snapshot['outside'] ?? false) ? 'outside' : 'ok';
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
