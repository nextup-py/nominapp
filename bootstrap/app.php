<?php

use App\Http\Controllers\MobileLinkController;
use App\Http\Middleware\EnsureTerminalIsActive;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'));

        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            'terminal.active' => EnsureTerminalIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Mensaje en español + tiempo de espera para el throttling de vinculación
        // de dispositivo — ver MobileLinkController::throttledResponse(). Sin esto,
        // el empleado ve el 429 genérico de Laravel ("Too Many Attempts.", en inglés,
        // sin contexto de cuánto esperar ni a quién contactar).
        // La API del terminal responde 401 con un `code` estable cuando el token no existe, se
        // revocó o expiró — el cliente (terminal-offline/sync.js) lo usa para pasar a la pantalla
        // de vinculación en vez de quedarse mudo. Sin esto el cuerpo era el genérico "Unauthenticated.".
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/v1/terminal/*')) {
                return null;
            }

            return response()->json([
                'ok' => false,
                'code' => 'token_revoked',
                'message' => 'El acceso de este terminal fue revocado o expiró.',
            ], 401);
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! $request->routeIs('device-link.claim')) {
                return null;
            }

            return MobileLinkController::throttledResponse($e, $request);
        });
    })->create();
