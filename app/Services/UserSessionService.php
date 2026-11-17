<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Sesiones abiertas de un usuario (driver `database`): listarlas y cerrar las demás. */
class UserSessionService
{
    /**
     * Sesiones del usuario, la más reciente primero.
     *
     * @return Collection<int, array{id: string, ip: ?string, os: ?string, browser: ?string, last_activity: Carbon, current: bool}>
     */
    public static function forUser(User $user, ?string $currentId): Collection
    {
        if (config('session.driver') !== 'database') {
            return collect();
        }

        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get()
            ->map(function (object $row) use ($currentId): array {
                $device = DeviceHintsParser::describe($row->user_agent);

                return [
                    'id' => $row->id,
                    'ip' => $row->ip_address,
                    'os' => $device['os'],
                    'browser' => $device['browser'],
                    'last_activity' => Carbon::createFromTimestamp($row->last_activity),
                    'current' => $row->id === $currentId,
                ];
            });
    }

    /**
     * Cierra todas las sesiones del usuario salvo la actual. Devuelve cuántas cerró.
     */
    public static function closeOthers(User $user, ?string $currentId): int
    {
        if (config('session.driver') !== 'database') {
            return 0;
        }

        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->when($currentId, fn ($q) => $q->where('id', '!=', $currentId))
            ->delete();
    }
}
