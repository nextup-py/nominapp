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
     * @return list<array{id: int, name: string, branch: ?string, status: string}>
     */
    public static function items(): array
    {
        return Cache::remember('terminal-attention-banner', self::CACHE_SECONDS, function (): array {
            return Terminal::query()
                ->withSyncTokenFlag()
                ->with('branch')
                ->orderBy('name')
                ->get()
                ->filter(fn (Terminal $terminal): bool => ! $terminal->isInactive() && in_array($terminal->connectivity_status, ['unlinked', 'stale'], true))
                ->map(fn (Terminal $terminal): array => [
                    'id' => $terminal->id,
                    'name' => $terminal->name,
                    'branch' => $terminal->branch?->name,
                    'status' => $terminal->connectivity_status,
                ])
                ->values()
                ->all();
        });
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
