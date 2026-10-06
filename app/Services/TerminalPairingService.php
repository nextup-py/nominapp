<?php

namespace App\Services;

use App\Exceptions\TerminalPairingException;
use App\Models\Terminal;
use App\Models\TerminalEvent;
use App\Models\TerminalPairingRequest;
use App\Models\User;
use App\Notifications\TerminalPairingRequestedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Vinculación de terminales por código de emparejamiento + aprobación de un
 * admin. El dispositivo (sin token) pide un código, lo muestra en pantalla y
 * consulta el estado con un secreto propio; un usuario con permiso sobre
 * terminales lo aprueba tipeando ese código, y recién entonces el servidor
 * emite el token Sanctum (una sola vez, vía `Terminal::claimSanctumToken()`,
 * que revoca el token anterior). Sin aprobación explícita no se emite token:
 * el terminal descarga descriptores faciales (datos sensibles).
 */
class TerminalPairingService
{
    /**
     * Crea una solicitud de vinculación para el terminal.
     *
     * Serializa por terminal (lock de la fila) para respetar el tope de
     * pendientes sin carreras: si ya hay `MAX_PENDING_PER_TERMINAL`, vence la
     * más antigua. Notifica a los admins después de confirmar la transacción.
     *
     * @return array{request: TerminalPairingRequest, poll_secret: string}
     */
    public function request(Terminal $terminal, ?string $ip, ?string $userAgent, ?string $deviceModelHint): array
    {
        $secret = Str::random(40);

        $request = DB::transaction(function () use ($terminal, $ip, $userAgent, $deviceModelHint, $secret) {
            $locked = Terminal::query()->whereKey($terminal->id)->lockForUpdate()->firstOrFail();

            $pending = $locked->pairingRequests()->pending()->orderBy('created_at')->get();
            $overflow = $pending->count() - (TerminalPairingRequest::MAX_PENDING_PER_TERMINAL - 1);
            if ($overflow > 0) {
                $pending->take($overflow)->each(fn (TerminalPairingRequest $old) => $old->update(['status' => TerminalPairingRequest::STATUS_EXPIRED]));
            }

            $request = $locked->pairingRequests()->create([
                'code' => $this->uniqueCode($locked),
                'poll_secret_hash' => TerminalPairingRequest::hashSecret($secret),
                'ip_address' => $ip,
                'user_agent' => $userAgent !== null ? Str::limit($userAgent, 500, '') : null,
                'device_model_hint' => $deviceModelHint,
                'status' => TerminalPairingRequest::STATUS_PENDING,
                'replaces_active_device' => $locked->hasActiveSyncToken(),
                'expires_at' => now()->addMinutes(TerminalPairingRequest::TTL_MINUTES),
            ]);

            TerminalEvent::record($locked, 'pairing_requested', [
                'request_id' => $request->id,
                'ip' => $ip,
                'replaces_active_device' => $request->replaces_active_device,
            ]);

            return $request;
        });

        $this->notifyManagers($request);

        return ['request' => $request, 'poll_secret' => $secret];
    }

    /**
     * Aprueba la solicitud. Exige tipear el código que el dispositivo muestra en
     * su pantalla — así el admin no aprueba por error la solicitud de otro
     * dispositivo cuando hay más de una pendiente.
     *
     * @throws TerminalPairingException
     */
    public function approve(TerminalPairingRequest $request, User $approver, string $typedCode): TerminalPairingRequest
    {
        return DB::transaction(function () use ($request, $approver, $typedCode) {
            $locked = TerminalPairingRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw new TerminalPairingException('La solicitud ya no está pendiente (se resolvió o venció).');
            }

            if (! hash_equals($locked->code, TerminalPairingRequest::normalizeCode($typedCode))) {
                throw new TerminalPairingException('El código no coincide con el que muestra la pantalla del terminal.');
            }

            $locked->update([
                'status' => TerminalPairingRequest::STATUS_APPROVED,
                'approved_by_id' => $approver->id,
                'approved_at' => now(),
            ]);

            TerminalEvent::record($locked->terminal, 'pairing_approved', ['request_id' => $locked->id], $approver->id);

            return $locked;
        });
    }

    /**
     * Rechaza la solicitud.
     *
     * @throws TerminalPairingException
     */
    public function deny(TerminalPairingRequest $request, User $actor): TerminalPairingRequest
    {
        return DB::transaction(function () use ($request, $actor) {
            $locked = TerminalPairingRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw new TerminalPairingException('La solicitud ya no está pendiente (se resolvió o venció).');
            }

            $locked->update(['status' => TerminalPairingRequest::STATUS_DENIED]);

            TerminalEvent::record($locked->terminal, 'pairing_denied', ['request_id' => $locked->id], $actor->id);

            return $locked;
        });
    }

    /**
     * Estado de la solicitud para el dispositivo que la creó (identificado por
     * su secreto, nunca por URL). Si está aprobada, emite el token Sanctum y la
     * marca `claimed`, todo bajo lock de la fila: dos consultas simultáneas no
     * pueden emitir dos tokens, la segunda ve `claimed` sin token.
     *
     * @return array{status: string, expires_at?: string, token?: string, terminal?: array<string, mixed>}
     */
    public function poll(string $secret, ?string $ip, ?string $userAgent): array
    {
        return DB::transaction(function () use ($secret, $ip, $userAgent) {
            $request = TerminalPairingRequest::query()
                ->where('poll_secret_hash', TerminalPairingRequest::hashSecret($secret))
                ->lockForUpdate()
                ->first();

            if (! $request) {
                return ['status' => 'invalid'];
            }

            $terminal = $request->terminal;

            if ($terminal->isInactive()) {
                return ['status' => 'terminal_inactive'];
            }

            if ($request->status === TerminalPairingRequest::STATUS_PENDING && ! $request->expires_at->isFuture()) {
                $request->update(['status' => TerminalPairingRequest::STATUS_EXPIRED]);
            }

            return match ($request->status) {
                TerminalPairingRequest::STATUS_PENDING => ['status' => 'pending', 'expires_at' => $request->expires_at->toIso8601String()],
                TerminalPairingRequest::STATUS_APPROVED => $this->claim($request, $terminal, $ip, $userAgent),
                default => ['status' => $request->status],
            };
        });
    }

    /**
     * Marca como vencidas las solicitudes pendientes cuyo plazo ya pasó.
     *
     * @return int Cantidad de solicitudes vencidas.
     */
    public function expireStale(): int
    {
        return TerminalPairingRequest::query()->stale()->update(['status' => TerminalPairingRequest::STATUS_EXPIRED]);
    }

    /**
     * Emite el token y cierra la solicitud. Se llama ya dentro del lock de `poll()`.
     *
     * @return array{status: string, token: string, terminal: array<string, mixed>}
     */
    private function claim(TerminalPairingRequest $request, Terminal $terminal, ?string $ip, ?string $userAgent): array
    {
        $token = $terminal->claimSanctumToken($userAgent ?? $request->user_agent, $request->device_model_hint, $ip ?? $request->ip_address, 'pairing');

        $request->update([
            'status' => TerminalPairingRequest::STATUS_CLAIMED,
            'claimed_at' => now(),
        ]);

        TerminalEvent::record($terminal, 'pairing_claimed', ['request_id' => $request->id, 'approved_by_id' => $request->approved_by_id]);

        return [
            'status' => 'claimed',
            'token' => $token,
            'terminal' => [
                'id' => $terminal->id,
                'code' => $terminal->code,
                'name' => $terminal->name,
                'branch_id' => $terminal->branch_id,
            ],
        ];
    }

    /** Código que no colisiona con otra solicitud pendiente del mismo terminal. */
    private function uniqueCode(Terminal $terminal): string
    {
        do {
            $code = TerminalPairingRequest::randomCode();
        } while ($terminal->pairingRequests()->pending()->where('code', $code)->exists());

        return $code;
    }

    /**
     * Avisa por la campanita a los usuarios con permiso sobre terminales.
     * Best-effort: la solicitud ya está persistida, un fallo al notificar no
     * debe romper el endpoint (mismo criterio que `claimSanctumToken()`).
     */
    private function notifyManagers(TerminalPairingRequest $request): void
    {
        try {
            Terminal::managers()->each(fn (User $user) => $user->notify(new TerminalPairingRequestedNotification($request)));
        } catch (\Throwable $e) {
            Log::warning("No se pudo notificar la solicitud de vinculación del terminal #{$request->terminal_id}: {$e->getMessage()}", [
                'terminal_id' => $request->terminal_id,
                'request_id' => $request->id,
            ]);
        }
    }
}
