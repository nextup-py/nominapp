<?php

namespace App\Services;

use App\Exceptions\TerminalPairingException;
use App\Models\Terminal;
use App\Models\TerminalEvent;
use App\Models\TerminalPairingRequest;
use App\Models\User;
use App\Notifications\TerminalLinkLostNotification;
use App\Notifications\TerminalMultipleDevicesNotification;
use App\Notifications\TerminalPairingRequestedNotification;
use Illuminate\Notifications\DatabaseNotification;
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
    /** Minutos durante los cuales una pérdida de vínculo del mismo terminal no se vuelve a registrar ni avisar. */
    public const LINK_LOST_DEDUPE_MINUTES = 30;

    /** Horas en las que dos equipos distintos pidiendo vincularse al mismo terminal se consideran un conflicto. */
    public const MULTIPLE_DEVICES_WINDOW_HOURS = 24;

    /** Segundos sin latido a partir de los cuales una solicitud con acceso vigente se toma como pérdida de vínculo (el terminal late cada ~90 s; menos que eso es otro dispositivo pidiendo mientras el verdadero sigue en línea). */
    public const LINK_LOST_MIN_SILENCE_SECONDS = Terminal::HEARTBEAT_ACTIVE_SECONDS;

    /**
     * Crea una solicitud de vinculación para el terminal.
     *
     * Serializa por terminal (lock de la fila) para respetar el tope de
     * pendientes sin carreras: si ya hay `MAX_PENDING_PER_TERMINAL`, vence la
     * más antigua. Notifica a los admins después de confirmar la transacción.
     *
     * Si el terminal todavía tiene un acceso vigente en el servidor, el dispositivo perdió su copia local (datos
     * borrados, ventana privada, otro perfil): se registra `link_lost` en la bitácora y se avisa por la campanita.
     *
     * @param  array{device_id?: string, reason?: string, had_marker?: bool, storage_available?: bool, storage_persisted?: bool|null, storage_quota_mb?: int|null}  $context  Motivo y estado del almacenamiento que informa el dispositivo (opcional).
     * @return array{request: TerminalPairingRequest, poll_secret: string}
     */
    public function request(Terminal $terminal, ?string $ip, ?string $userAgent, ?string $deviceModelHint, array $context = []): array
    {
        $secret = Str::random(40);
        $linkLost = false;

        $request = DB::transaction(function () use ($terminal, $ip, $userAgent, $deviceModelHint, $secret, $context, &$linkLost) {
            $locked = Terminal::query()->whereKey($terminal->id)->lockForUpdate()->firstOrFail();

            $pending = $locked->pairingRequests()->pending()->orderBy('created_at')->get();
            $overflow = $pending->count() - (TerminalPairingRequest::MAX_PENDING_PER_TERMINAL - 1);
            if ($overflow > 0) {
                $pending->take($overflow)->each(fn (TerminalPairingRequest $old) => $old->update(['status' => TerminalPairingRequest::STATUS_EXPIRED]));
            }

            $deviceId = $context['device_id'] ?? null;
            $activeDeviceId = $locked->hasActiveSyncToken() ? $locked->activeDevice?->device_id : null;
            $sameDevice = $deviceId && $activeDeviceId ? hash_equals($activeDeviceId, $deviceId) : null;

            $request = $locked->pairingRequests()->create([
                'code' => $this->uniqueCode($locked),
                'poll_secret_hash' => TerminalPairingRequest::hashSecret($secret),
                'ip_address' => $ip,
                'user_agent' => $userAgent !== null ? Str::limit($userAgent, 500, '') : null,
                'device_model_hint' => $deviceModelHint,
                'status' => TerminalPairingRequest::STATUS_PENDING,
                'replaces_active_device' => $locked->hasActiveSyncToken(),
                'device_id' => $deviceId,
                'same_device' => $sameDevice,
                'expires_at' => now()->addMinutes(TerminalPairingRequest::TTL_MINUTES),
            ]);

            $elsewhere = $request->linkedElsewhere();

            TerminalEvent::record($locked, 'pairing_requested', [
                'request_id' => $request->id,
                'ip' => $ip,
                'replaces_active_device' => $request->replaces_active_device,
                'device_id' => $deviceId ? substr($deviceId, 0, 8) : null,
                'same_device' => $sameDevice,
                'linked_elsewhere' => $elsewhere ? ['terminal_id' => $elsewhere->id, 'name' => $elsewhere->name] : null,
            ]);

            $linkLost = $request->replaces_active_device && $this->recordLinkLost($locked, $ip, $context, $sameDevice);

            if ($locked->hasOpenLinkWindow()) {
                $this->autoApprove($request, $locked);
            }

            return $request;
        });

        if (! $request->auto_approved) {
            $this->notifyManagers($request);
        }

        $this->noticeMultipleDevices($terminal);

        if ($linkLost) {
            $this->notifyLinkLost($terminal, $context);
        }

        return ['request' => $request, 'poll_secret' => $secret];
    }

    /**
     * Registra en la bitácora que el dispositivo perdió el vínculo (el servidor sigue con un acceso vigente y aun
     * así el navegador pide uno nuevo). Un dispositivo en bucle pide un código tras otro: solo cuenta uno por
     * `LINK_LOST_DEDUPE_MINUTES` para no llenar la bitácora ni la campanita.
     *
     * @param  array<string, mixed>  $context
     * @param  bool|null  $sameDevice  true si quien pide es el mismo dispositivo que está vinculado (por su identificador): es una pérdida segura aunque el terminal siga latiendo; false si es otro: nunca es pérdida; null si no se puede comparar y se decide por el latido.
     * @return bool true si se registró (hay que avisar). False si ya había uno reciente o si el terminal sigue
     *              latiendo: entonces el dispositivo vinculado está bien y quien pide es otro dispositivo.
     */
    private function recordLinkLost(Terminal $terminal, ?string $ip, array $context, ?bool $sameDevice = null): bool
    {
        if ($sameDevice === false || ($sameDevice === null && $terminal->isReportingNow())) {
            return false;
        }

        $recent = $terminal->events()
            ->where('type', 'link_lost')
            ->where('created_at', '>=', now()->subMinutes(self::LINK_LOST_DEDUPE_MINUTES))
            ->exists();

        if ($recent) {
            return false;
        }

        TerminalEvent::record($terminal, 'link_lost', array_filter([
            'ip' => $ip,
            'same_device' => $sameDevice,
            'lost_reason' => $context['reason'] ?? null,
            'had_marker' => $context['had_marker'] ?? null,
            'storage_available' => $context['storage_available'] ?? null,
            'storage_persisted' => $context['storage_persisted'] ?? null,
            'storage_quota_mb' => $context['storage_quota_mb'] ?? null,
            'seconds_since_heartbeat' => $terminal->last_heartbeat_at ? (int) $terminal->last_heartbeat_at->diffInSeconds(now(), true) : null,
        ], fn ($value) => $value !== null));

        return true;
    }

    /**
     * Aprueba sola una solicitud creada con la ventana de vinculación abierta,
     * a nombre de quien abrió la ventana. La ventana se cierra acá mismo (no
     * recién al claim): un único dispositivo puede aprovecharla, así dos
     * solicitudes casi simultáneas no quedan ambas aprobadas.
     */
    private function autoApprove(TerminalPairingRequest $request, Terminal $terminal): void
    {
        $openedById = $terminal->link_window_opened_by_id;

        $request->update([
            'status' => TerminalPairingRequest::STATUS_APPROVED,
            'auto_approved' => true,
            'approved_by_id' => $openedById,
            'approved_at' => now(),
        ]);

        TerminalEvent::record($terminal, 'pairing_auto_approved', ['request_id' => $request->id, 'window_opened_by_id' => $openedById]);

        $terminal->closeLinkWindow(null, 'auto_approved');
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

            $this->settleNotifications($locked->terminal_id);

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
            $this->settleNotifications($locked->terminal_id);

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
                $this->flagUnattended($request->terminal_id);
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
        $terminalIds = TerminalPairingRequest::query()->stale()->pluck('terminal_id')->unique();
        $expired = TerminalPairingRequest::query()->stale()->update(['status' => TerminalPairingRequest::STATUS_EXPIRED]);

        $terminalIds->each(fn (int $terminalId) => $this->flagUnattended($terminalId));

        return $expired;
    }

    /**
     * Emite el token y cierra la solicitud. Se llama ya dentro del lock de `poll()`.
     *
     * @return array{status: string, token: string, terminal: array<string, mixed>}
     */
    private function claim(TerminalPairingRequest $request, Terminal $terminal, ?string $ip, ?string $userAgent): array
    {
        $token = $terminal->claimSanctumToken($userAgent ?? $request->user_agent, $request->device_model_hint, $ip ?? $request->ip_address, 'pairing', $request->device_id);

        $request->update([
            'status' => TerminalPairingRequest::STATUS_CLAIMED,
            'claimed_at' => now(),
        ]);

        TerminalEvent::record($terminal, 'pairing_claimed', ['request_id' => $request->id, 'approved_by_id' => $request->approved_by_id]);

        $this->settleNotifications($terminal->id);

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
    /**
     * Avisa a quienes administran terminales que el dispositivo perdió el vínculo. Best-effort: un fallo del mailer
     * no debe invalidar la solicitud de vinculación ya creada.
     *
     * @param  array<string, mixed>  $context
     */
    private function notifyLinkLost(Terminal $terminal, array $context): void
    {
        try {
            Terminal::managers()->each(fn (User $user) => $user->notify(new TerminalLinkLostNotification($terminal, $context)));
        } catch (\Throwable $e) {
            Log::warning("No se pudo notificar la pérdida de vínculo del terminal #{$terminal->id}: {$e->getMessage()}", ['terminal_id' => $terminal->id]);
        }
    }

    /**
     * Avisa a quienes gestionan terminales. Mientras un gestor tenga sin leer un aviso de este mismo terminal, no se
     * crea otro: se actualiza el existente con la cantidad de veces que pidió vincularse (un equipo que se recarga una
     * y otra vez pedía código y llenaba la campanita).
     */
    private function notifyManagers(TerminalPairingRequest $request): void
    {
        try {
            Terminal::managers()->each(function (User $user) use ($request): void {
                $existing = DatabaseNotification::query()
                    ->where('notifiable_type', $user->getMorphClass())
                    ->where('notifiable_id', $user->getKey())
                    ->where('type', TerminalPairingRequestedNotification::class)
                    ->whereNull('read_at')
                    ->where('data->terminal_id', $request->terminal_id)
                    ->latest()
                    ->first();

                if ($existing === null) {
                    $user->notify(new TerminalPairingRequestedNotification($request));

                    return;
                }

                $count = (int) ($existing->data['requests_count'] ?? 1) + 1;
                $existing->forceFill([
                    'data' => (new TerminalPairingRequestedNotification($request, $count))->toDatabase($user),
                    'created_at' => now(),
                ])->save();
            });
        } catch (\Throwable $e) {
            Log::warning("No se pudo notificar la solicitud de vinculación del terminal #{$request->terminal_id}: {$e->getMessage()}", [
                'terminal_id' => $request->terminal_id,
                'request_id' => $request->id,
            ]);
        }
    }

    /**
     * Cuando las solicitudes de un terminal vencen sin que nadie las apruebe, el aviso de «solicitud» NO se marca como
     * leído (así desaparecía justo cuando nadie lo atendió): pasa a «Vinculación sin atender» y sigue sin leer hasta que
     * alguien lo abra o el terminal se vincule. Si todavía hay una solicitud pendiente no cambia nada.
     */
    private function flagUnattended(int $terminalId): void
    {
        if (TerminalPairingRequest::query()->where('terminal_id', $terminalId)->pending()->exists()) {
            return;
        }

        $terminal = Terminal::query()->with('branch')->find($terminalId);

        if ($terminal === null) {
            return;
        }

        try {
            DatabaseNotification::query()
                ->whereNull('read_at')
                ->where('type', TerminalPairingRequestedNotification::class)
                ->where('data->terminal_id', $terminalId)
                ->get()
                ->each(function (DatabaseNotification $notification) use ($terminal): void {
                    $notification->forceFill([
                        'data' => TerminalPairingRequestedNotification::unattendedData($terminal, (int) ($notification->data['requests_count'] ?? 1)),
                    ])->save();
                });
        } catch (\Throwable $e) {
            Log::warning("No se pudo marcar como sin atender la vinculación del terminal #{$terminalId}: {$e->getMessage()}", ['terminal_id' => $terminalId]);
        }
    }

    /**
     * Si dos o más equipos distintos (por su identificador) pidieron vincularse a este terminal en las últimas
     * `MULTIPLE_DEVICES_WINDOW_HOURS` horas, lo registra en la bitácora y avisa a quienes gestionan terminales, una
     * vez por ventana. Best-effort: la solicitud ya está creada.
     */
    private function noticeMultipleDevices(Terminal $terminal): void
    {
        try {
            $since = now()->subHours(self::MULTIPLE_DEVICES_WINDOW_HOURS);

            $devices = $terminal->pairingRequests()
                ->where('created_at', '>=', $since)
                ->whereNotNull('device_id')
                ->distinct()
                ->count('device_id');

            if ($devices < 2 || TerminalEvent::query()->where('terminal_id', $terminal->id)->where('type', 'multiple_devices')->where('created_at', '>=', $since)->exists()) {
                return;
            }

            TerminalEvent::record($terminal, 'multiple_devices', ['devices' => $devices]);

            Terminal::managers()->each(fn (User $user) => $user->notify(new TerminalMultipleDevicesNotification($terminal->loadMissing('branch'), $devices)));
        } catch (\Throwable $e) {
            Log::warning("No se pudo avisar que varios equipos usan el terminal #{$terminal->id}: {$e->getMessage()}", ['terminal_id' => $terminal->id]);
        }
    }

    /**
     * Cuando el terminal ya no tiene solicitudes pendientes (se aprobó, rechazó, venció o se vinculó), marca como
     * leídos los avisos de solicitud que quedaban sin leer: ya no hay nada que revisar.
     */
    private function settleNotifications(int $terminalId): void
    {
        if (TerminalPairingRequest::query()->where('terminal_id', $terminalId)->pending()->exists()) {
            return;
        }

        DatabaseNotification::query()
            ->whereNull('read_at')
            ->where('type', TerminalPairingRequestedNotification::class)
            ->where('data->terminal_id', $terminalId)
            ->update(['read_at' => now()]);
    }
}
