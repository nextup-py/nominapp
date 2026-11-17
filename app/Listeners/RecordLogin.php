<?php

namespace App\Listeners;

use App\Models\User;
use App\Notifications\NewLoginLocationNotification;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Log;

/**
 * Guarda cada ingreso al panel y, si la IP nunca se había usado con esa cuenta (y ya había ingresos previos), avisa al
 * propio usuario por campanita. Nunca debe impedir el ingreso: cualquier falla solo se registra en el log.
 */
class RecordLogin
{
    /**
     * Registra el ingreso del usuario autenticado.
     */
    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User || $event->guard !== 'web') {
            return;
        }

        try {
            $ip = request()->ip();
            $hadHistory = $user->loginHistories()->exists();
            $isNewIp = $hadHistory && $ip !== null && ! $user->loginHistories()->where('ip_address', $ip)->exists();

            $user->loginHistories()->create([
                'ip_address' => $ip,
                'user_agent' => mb_substr((string) request()->userAgent(), 0, 500) ?: null,
                'is_new_ip' => $isNewIp,
                'logged_in_at' => now(),
            ]);

            if ($isNewIp) {
                $user->notify(new NewLoginLocationNotification($ip));
            }
        } catch (\Throwable $e) {
            Log::warning('No se pudo registrar el ingreso: '.$e->getMessage());
        }
    }
}
