<?php

namespace App\Models;

use App\Notifications\TerminalLinkWindowExpiredNotification;
use App\Notifications\TerminalProvisionedNotification;
use App\Services\DeviceHintsParser;
use App\Settings\GeneralSettings;
use Carbon\CarbonInterface;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * Representa un dispositivo físico de marcación de asistencia en una sucursal.
 *
 * Puede autenticarse contra la API de sincronización offline (ver routes/api.php)
 * mediante un token Sanctum con ability `terminal:sync`, emitido al reclamar un
 * enlace de configuración (setup_token) generado desde TerminalResource.
 *
 * Implementa `Authenticatable` (no solo `HasApiTokens`) porque el terminal es
 * el "usuario" autenticado en las rutas de la API de sincronización — sin
 * esto, `$request->user()` funciona en producción (el guard de Sanctum
 * resuelve el usuario sin pasar por `Guard::setUser()`), pero el helper de
 * test `Sanctum::actingAs()` sí llama `setUser()` directamente, que exige
 * el contrato `Authenticatable` con tipado estricto.
 */
class Terminal extends Model implements AuthenticatableContract
{
    use Authenticatable, HasApiTokens;

    /** Mínimo de minutos configurable como umbral de desconexión por terminal (el heartbeat va cada ~90 s). */
    public const MIN_STALE_MINUTES = 5;

    /**
     * Días de la semana (ISO: 1 = lunes … 7 = domingo) para el horario de vigilancia.
     *
     * @var array<int, string>
     */
    public const WATCH_DAY_OPTIONS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

    /** Ability Sanctum requerida para consumir la API de sincronización de terminales. */
    public const SYNC_ABILITY = 'terminal:sync';

    protected $fillable = [
        'name',
        'code',
        'branch_id',
        'status',
        'device_brand',
        'device_model',
        'device_serial',
        'device_mac',
        'device_notes',
        'user_agent',
        'installed_at',
        'installed_by_id',
        'last_seen_at',
        'last_heartbeat_at',
        'linked_at',
        'linked_ip',
        'last_employee_sync_at',
        'last_event_sync_at',
        'last_pending_events_count',
        'last_conflict_events_count',
        'setup_token',
        'setup_token_expires_at',
        'setup_token_consumed_at',
        'link_window_until',
        'link_window_opened_by_id',
        'stale_after_minutes',
        'watch_days',
        'watch_from',
        'watch_to',
        'device_report',
        'device_report_at',
        'queue_backlog_since',
        'health_snapshot',
    ];

    protected $hidden = [
        'setup_token',
    ];

    protected $casts = [
        'installed_at' => 'date',
        'last_seen_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'linked_at' => 'datetime',
        'last_employee_sync_at' => 'datetime',
        'last_event_sync_at' => 'datetime',
        'last_pending_events_count' => 'integer',
        'last_conflict_events_count' => 'integer',
        'setup_token_expires_at' => 'datetime',
        'setup_token_consumed_at' => 'datetime',
        'link_window_until' => 'datetime',
        'stale_after_minutes' => 'integer',
        'watch_days' => 'array',
        'device_report' => 'array',
        'device_report_at' => 'datetime',
        'queue_backlog_since' => 'datetime',
        'health_snapshot' => 'array',
    ];

    // =========================================================================
    // BOOT
    // =========================================================================

    /** Genera el código único automáticamente al crear la terminal. */
    protected static function booted(): void
    {
        static::creating(function (Terminal $terminal) {
            if (empty($terminal->code)) {
                $terminal->code = static::generateUniqueCode();
            }
        });
    }

    // =========================================================================
    // RELACIONES
    // =========================================================================

    /** Solicitudes de vinculación por código de emparejamiento. */
    public function pairingRequests(): HasMany
    {
        return $this->hasMany(TerminalPairingRequest::class);
    }

    /** Bitácora de cambios de estado del terminal. */
    public function events(): HasMany
    {
        return $this->hasMany(TerminalEvent::class);
    }

    /** Comandos remotos enviados a este terminal. */
    public function commands(): HasMany
    {
        return $this->hasMany(TerminalCommand::class);
    }

    /** Sucursal a la que pertenece esta terminal. */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Usuario que instaló la terminal. */
    public function installedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'installed_by_id');
    }

    /** Eventos de asistencia registrados desde esta terminal. */
    public function attendanceEvents(): HasMany
    {
        return $this->hasMany(AttendanceEvent::class);
    }

    // =========================================================================
    // HELPERS ESTÁTICOS — LABELS, COLORES, OPCIONES
    // =========================================================================

    /**
     * Opciones de estado para Select en formularios.
     *
     * @return array<string, string>
     */
    public static function getStatusOptions(): array
    {
        return [
            'active' => 'Activo',
            'inactive' => 'Inactivo',
        ];
    }

    /**
     * Labels cortos para badges y columnas.
     *
     * @return array<string, string>
     */
    public static function getStatusLabels(): array
    {
        return [
            'active' => 'Activo',
            'inactive' => 'Inactivo',
        ];
    }

    /**
     * Colores semánticos para badges de Filament.
     *
     * @return array<string, string>
     */
    public static function getStatusColors(): array
    {
        return [
            'active' => 'success',
            'inactive' => 'danger',
        ];
    }

    /**
     * Opciones de estado de conectividad para filtros.
     *
     * @return array<string, string>
     */
    public static function getConnectivityStatusOptions(): array
    {
        return [
            'unlinked' => 'Sin vincular',
            'online' => 'En línea',
            'stale' => 'Desconectado',
            'off_hours' => 'Fuera de horario',
            'never_connected' => 'Nunca conectado',
        ];
    }

    /**
     * Labels cortos para badges de conectividad.
     *
     * @return array<string, string>
     */
    public static function getConnectivityStatusLabels(): array
    {
        return [
            'unlinked' => 'Sin vincular',
            'online' => 'En línea',
            'stale' => 'Desconectado',
            'off_hours' => 'Fuera de horario',
            'never_connected' => 'Nunca conectado',
        ];
    }

    /**
     * Colores semánticos para badges de conectividad.
     *
     * @return array<string, string>
     */
    public static function getConnectivityStatusColors(): array
    {
        return [
            'unlinked' => 'danger',
            'online' => 'success',
            'stale' => 'danger',
            'off_hours' => 'gray',
            'never_connected' => 'gray',
        ];
    }

    /**
     * Opciones de estado de cola de sincronización para filtros.
     *
     * @return array<string, string>
     */
    public static function getSyncQueueStatusOptions(): array
    {
        return [
            'ok' => 'Sin pendientes',
            'pending' => 'Con pendientes',
            'conflict' => 'Con conflictos',
        ];
    }

    /**
     * Labels cortos para badges de cola de sincronización.
     *
     * @return array<string, string>
     */
    public static function getSyncQueueStatusLabels(): array
    {
        return [
            'ok' => 'Sin pendientes',
            'pending' => 'Con pendientes',
            'conflict' => 'Con conflictos',
        ];
    }

    /**
     * Colores semánticos para badges de cola de sincronización.
     *
     * @return array<string, string>
     */
    public static function getSyncQueueStatusColors(): array
    {
        return [
            'ok' => 'gray',
            'pending' => 'warning',
            'conflict' => 'danger',
        ];
    }

    // =========================================================================
    // VERIFICADORES DE ESTADO
    // =========================================================================

    /** Indica si la terminal está activa. */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** Indica si la terminal está inactiva. */
    public function isInactive(): bool
    {
        return $this->status === 'inactive';
    }

    /**
     * Estado de conectividad del terminal. La vinculación tiene prioridad
     * sobre el heartbeat: sin un token de sincronización vigente el terminal
     * no puede sincronizar ni (en el cliente) marcar, aunque haya tenido
     * heartbeats antes de que se le revocara el token. A partir de
     * `last_heartbeat_at` (a diferencia de `last_seen_at`, que también se
     * actualiza con cada carga de página vía sesión y por eso no distingue un
     * terminal que quedó abierto offline días de uno que realmente sigue
     * sincronizando):
     * - 'unlinked': sin token Sanctum vigente con ability `terminal:sync`
     *   (nunca se provisionó, se revocó, o el enlace de setup no se reclamó).
     * - 'never_connected': vinculado pero sin ningún heartbeat exitoso todavía.
     * - 'online': el último heartbeat exitoso está dentro del umbral
     *   configurado (`GeneralSettings->terminal_stale_threshold_hours`).
     * - 'stale': el último heartbeat exitoso superó el umbral.
     */
    public function getConnectivityStatusAttribute(): string
    {
        if (! $this->hasActiveSyncToken()) {
            return 'unlinked';
        }

        if (! $this->last_heartbeat_at instanceof Carbon) {
            return 'never_connected';
        }

        if ($this->last_heartbeat_at->gte(now()->subMinutes($this->effectiveStaleMinutes()))) {
            return 'online';
        }

        return $this->isWithinWatchWindow() ? 'stale' : 'off_hours';
    }

    // =========================================================================
    // MONITOREO — UMBRAL, HORARIO DE VIGILANCIA Y REPORTE DEL DISPOSITIVO
    // =========================================================================

    /** Minutos sin heartbeat para considerarlo desconectado: el propio del terminal o, si no tiene, el global. */
    public function effectiveStaleMinutes(): int
    {
        return $this->stale_after_minutes ?? app(GeneralSettings::class)->terminal_stale_threshold_hours * 60;
    }

    /**
     * Indica si `$at` cae dentro del horario de vigilancia del terminal. Sin días ni
     * horas configurados se vigila siempre (24/7). Un rango que cruza medianoche
     * (ej. 22:00–06:00) pertenece al día en que empieza: a las 02:00 del martes
     * cuenta como el turno del lunes.
     */
    public function isWithinWatchWindow(?CarbonInterface $at = null): bool
    {
        $days = array_map('intval', $this->watch_days ?? []);
        $from = $this->watch_from ? Carbon::createFromTimeString($this->watch_from) : null;
        $to = $this->watch_to ? Carbon::createFromTimeString($this->watch_to) : null;

        if ($days === [] && ! $from && ! $to) {
            return true;
        }

        $at = Carbon::instance($at ?? now())->setTimezone(config('app.timezone'));
        $minutes = $at->hour * 60 + $at->minute;
        $dayOfWindow = $at->isoWeekday();
        $withinHours = true;

        if ($from && $to) {
            $fromMinutes = $from->hour * 60 + $from->minute;
            $toMinutes = $to->hour * 60 + $to->minute;

            if ($fromMinutes < $toMinutes) {
                $withinHours = $minutes >= $fromMinutes && $minutes < $toMinutes;
            } elseif ($fromMinutes > $toMinutes) {
                $withinHours = $minutes >= $fromMinutes || $minutes < $toMinutes;
                if ($minutes < $toMinutes) {
                    $dayOfWindow = $at->copy()->subDay()->isoWeekday();
                }
            }
        }

        return $withinHours && ($days === [] || in_array($dayOfWindow, $days, true));
    }

    /** Texto legible del horario de vigilancia (ej. "Lun a Vie · 08:00–20:00" o "Siempre (24/7)"). */
    public function watchScheduleLabel(): string
    {
        $days = collect($this->watch_days ?? [])->map(fn ($d) => (int) $d)->sort()->values();
        $hours = $this->watch_from && $this->watch_to
            ? Carbon::createFromTimeString($this->watch_from)->format('H:i').'–'.Carbon::createFromTimeString($this->watch_to)->format('H:i')
            : null;

        if ($days->isEmpty() && ! $hours) {
            return 'Siempre (24/7)';
        }

        $short = [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'];
        $daysLabel = $days->isEmpty() || $days->count() === 7
            ? 'Todos los días'
            : $days->map(fn (int $d) => $short[$d])->implode(', ');

        return $hours ? "{$daysLabel} · {$hours}" : $daysLabel;
    }

    /**
     * Terminales cuyo último heartbeat superó su umbral (el propio o el global).
     *
     * @param  Builder<Terminal>  $query
     * @return Builder<Terminal>
     */
    public function scopeHeartbeatStale(Builder $query): Builder
    {
        return $query->whereNotNull('last_heartbeat_at')
            ->whereRaw('TIMESTAMPDIFF(SECOND, last_heartbeat_at, ?) > COALESCE(stale_after_minutes, ?) * 60', [now()->toDateTimeString(), app(GeneralSettings::class)->terminal_stale_threshold_hours * 60]);
    }

    /**
     * Terminales con heartbeat dentro de su umbral.
     *
     * @param  Builder<Terminal>  $query
     * @return Builder<Terminal>
     */
    public function scopeHeartbeatFresh(Builder $query): Builder
    {
        return $query->whereNotNull('last_heartbeat_at')
            ->whereRaw('TIMESTAMPDIFF(SECOND, last_heartbeat_at, ?) <= COALESCE(stale_after_minutes, ?) * 60', [now()->toDateTimeString(), app(GeneralSettings::class)->terminal_stale_threshold_hours * 60]);
    }

    /**
     * IDs de los terminales que ahora mismo están fuera de su horario de vigilancia.
     * Se evalúa en PHP (días + rangos que cruzan medianoche): son pocos terminales.
     *
     * @return array<int, int>
     */
    public static function idsOutsideWatchWindow(): array
    {
        return static::query()
            ->where(fn (Builder $q) => $q->whereNotNull('watch_days')->orWhereNotNull('watch_from')->orWhereNotNull('watch_to'))
            ->get(['id', 'watch_days', 'watch_from', 'watch_to'])
            ->reject(fn (Terminal $terminal) => $terminal->isWithinWatchWindow())
            ->pluck('id')
            ->all();
    }

    /**
     * Registra un heartbeat: contadores de la cola offline, desde cuándo la cola no
     * está vacía y el último reporte de estado del dispositivo (si el cliente lo envía;
     * los clientes viejos no lo hacen).
     *
     * @param  array<string, mixed>|null  $deviceReport
     */
    public function recordHeartbeat(?int $pendingEvents, ?int $conflictEvents, ?array $deviceReport = null): void
    {
        $attributes = [
            'last_seen_at' => now(),
            'last_heartbeat_at' => now(),
            'last_pending_events_count' => $pendingEvents,
            'last_conflict_events_count' => $conflictEvents,
        ];

        if ($pendingEvents !== null || $conflictEvents !== null) {
            $backlog = ($pendingEvents ?? 0) + ($conflictEvents ?? 0);
            $attributes['queue_backlog_since'] = $backlog > 0 ? ($this->queue_backlog_since ?? now()) : null;
        }

        if ($deviceReport !== null) {
            $attributes['device_report'] = $deviceReport;
            $attributes['device_report_at'] = now();
        }

        $this->update($attributes);
    }

    /**
     * Encola un comando remoto que el terminal recibirá en su próximo heartbeat (≈90 s).
     * Si ya hay uno igual sin resolver devuelve ese (no se acumulan duplicados).
     * Solo terminales activos y vinculados pueden recibir comandos.
     *
     * @throws \DomainException si el terminal no puede recibir comandos o el comando no existe
     */
    public function sendCommand(string $command, ?User $by = null): TerminalCommand
    {
        if (! array_key_exists($command, TerminalCommand::getCommandLabels())) {
            throw new \DomainException('Comando desconocido.');
        }
        if (! $this->isActive() || ! $this->hasActiveSyncToken()) {
            throw new \DomainException('El terminal debe estar activo y vinculado para recibir comandos.');
        }

        return DB::transaction(function () use ($command, $by) {
            $existing = $this->commands()->open()->where('command', $command)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }

            $created = $this->commands()->create([
                'command' => $command,
                'status' => 'pending',
                'requested_by_id' => $by?->id,
                'expires_at' => now()->addMinutes(TerminalCommand::TTL_MINUTES),
            ]);
            TerminalEvent::record($this, 'command_sent', ['command' => $command], $by?->id);

            return $created;
        });
    }

    /**
     * Entrega los comandos pendientes (los marca `delivered`) en la respuesta del heartbeat.
     *
     * @return array<int, array{id: int, command: string}>
     */
    public function deliverPendingCommands(): array
    {
        TerminalCommand::expireStale($this);

        return DB::transaction(function () {
            $pending = $this->commands()->where('status', 'pending')->orderBy('id')->lockForUpdate()->get();

            foreach ($pending as $command) {
                $command->update(['status' => 'delivered', 'delivered_at' => now()]);
            }

            return $pending->map(fn (TerminalCommand $c) => ['id' => $c->id, 'command' => $c->command])->all();
        });
    }

    /**
     * Procesa las confirmaciones que el terminal envía en el heartbeat. Ignora ids que no
     * pertenezcan a este terminal o que ya no estén `delivered`.
     *
     * @param  array<int, array{id: int, status: string, message?: string|null}>  $acks
     */
    public function acknowledgeCommands(array $acks): void
    {
        foreach ($acks as $ack) {
            $command = $this->commands()->where('status', 'delivered')->find($ack['id']);
            if (! $command) {
                continue;
            }

            $ok = $ack['status'] === 'done';
            $message = isset($ack['message']) ? mb_substr((string) $ack['message'], 0, 255) : null;
            $command->update(['status' => $ok ? 'done' : 'failed', 'completed_at' => now(), 'result_message' => $message]);
            TerminalEvent::record($this, $ok ? 'command_done' : 'command_failed', ['command' => $command->command, 'message' => $message]);
        }
    }

    /**
     * Identificador de la versión de la app que sirve el servidor (huella del manifest de
     * Vite, que cambia con cada build). El terminal lo reporta en el heartbeat y el panel
     * lo compara para detectar dispositivos que siguen corriendo código viejo.
     */
    public static function currentAppVersion(): string
    {
        static $version = null;

        return $version ??= is_file($manifest = public_path('build/manifest.json'))
            ? substr(md5_file($manifest), 0, 10)
            : 'dev';
    }

    /** El dispositivo reportó una versión distinta de la que sirve hoy el servidor (null = sin dato). */
    public function isAppOutdated(): ?bool
    {
        $reported = $this->device_report['app_version'] ?? null;

        return $reported === null ? null : $reported !== static::currentAppVersion();
    }

    /** Cola atascada: el terminal sincroniza (en línea) pero la cola no se vacía hace más del umbral configurado. */
    public function isQueueStuck(): bool
    {
        if ($this->connectivity_status !== 'online' || ! $this->queue_backlog_since) {
            return false;
        }

        return $this->queue_backlog_since->lte(now()->subMinutes(app(GeneralSettings::class)->terminal_queue_stuck_minutes));
    }

    /** Nivel de batería informado en el último reporte (null si el navegador no lo informa). */
    public function reportedBatteryLevel(): ?int
    {
        $level = $this->device_report['battery_level'] ?? null;

        return $level === null ? null : (int) $level;
    }

    /** Indica si el último reporte dice que el dispositivo está enchufado (null si no se sabe). */
    public function reportedCharging(): ?bool
    {
        $charging = $this->device_report['battery_charging'] ?? null;

        return $charging === null ? null : (bool) $charging;
    }

    /**
     * Indica si el terminal tiene un token Sanctum vigente con ability
     * `terminal:sync`. Usa el flag precargado por `scopeWithSyncTokenFlag()`
     * cuando está disponible (listados: evita una query por fila) y cae a una
     * consulta puntual en caso contrario.
     */
    public function hasActiveSyncToken(): bool
    {
        if (array_key_exists('has_sync_token', $this->attributes)) {
            return (bool) $this->attributes['has_sync_token'];
        }

        return $this->tokens()->where(static::activeSyncTokenConstraint())->exists();
    }

    /**
     * Restricción sobre `personal_access_tokens` que define "token de
     * sincronización vigente": ability `terminal:sync` y no expirado. Única
     * fuente de verdad para el accessor, el flag precargado y los filtros.
     *
     * @return \Closure(Builder|Relation): void
     */
    public static function activeSyncTokenConstraint(): \Closure
    {
        return function ($query): void {
            $query->where('abilities', 'like', '%"'.self::SYNC_ABILITY.'"%')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
        };
    }

    /**
     * Precarga `has_sync_token` en una sola subconsulta EXISTS — usar en toda
     * query que liste terminales y muestre `connectivity_status`.
     *
     * @param  Builder<Terminal>  $query
     * @return Builder<Terminal>
     */
    public function scopeWithSyncTokenFlag(Builder $query): Builder
    {
        return $query->withExists(['tokens as has_sync_token' => static::activeSyncTokenConstraint()]);
    }

    /**
     * Terminales con token de sincronización vigente.
     *
     * @param  Builder<Terminal>  $query
     * @return Builder<Terminal>
     */
    public function scopeSyncLinked(Builder $query): Builder
    {
        return $query->whereHas('tokens', static::activeSyncTokenConstraint());
    }

    /**
     * Terminales sin token de sincronización vigente.
     *
     * @param  Builder<Terminal>  $query
     * @return Builder<Terminal>
     */
    public function scopeSyncUnlinked(Builder $query): Builder
    {
        return $query->whereDoesntHave('tokens', static::activeSyncTokenConstraint());
    }

    /**
     * Estado de la cola de sincronización offline del terminal, a partir de lo
     * reportado en el último heartbeat exitoso (`last_pending_events_count`/
     * `last_conflict_events_count`) — complementa `connectivity_status`: un
     * terminal puede verse "en línea" (el heartbeat llega con normalidad) y
     * aun así tener la cola de marcaciones atascada, típicamente por eventos
     * en conflicto que requieren revisión manual (ver `AttendanceMarkFailure`,
     * `failure_type: sync_conflict`).
     * - 'conflict': hay al menos un evento en conflicto.
     * - 'pending': sin conflictos, pero hay eventos pendientes de sincronizar.
     * - 'ok': sin pendientes ni conflictos (o el terminal nunca reportó el dato).
     */
    public function getSyncQueueStatusAttribute(): string
    {
        if (($this->last_conflict_events_count ?? 0) > 0) {
            return 'conflict';
        }

        if (($this->last_pending_events_count ?? 0) > 0) {
            return 'pending';
        }

        return 'ok';
    }

    // =========================================================================
    // HELPERS DE INSTANCIA
    // =========================================================================

    /**
     * Retorna la URL pública de la terminal.
     */
    public function getUrlAttribute(): string
    {
        return route('terminal.show', $this->code);
    }

    /**
     * Descripción del dispositivo (marca + modelo).
     */
    public function getDeviceDescriptionAttribute(): ?string
    {
        $parts = array_filter([$this->device_brand, $this->device_model]);

        return $parts ? implode(' ', $parts) : null;
    }

    // =========================================================================
    // HELPERS ESTÁTICOS
    // =========================================================================

    /**
     * Genera un código alfanumérico único de 8 caracteres para la terminal.
     */
    public static function generateUniqueCode(): string
    {
        do {
            $code = strtolower(Str::random(8));
        } while (static::where('code', $code)->exists());

        return $code;
    }

    // =========================================================================
    // PROVISIÓN — TOKEN DE CONFIGURACIÓN Y TOKEN SANCTUM
    // =========================================================================

    /** Vigencias (en minutos) que el admin puede elegir para el enlace de configuración. */
    public const SETUP_LINK_EXPIRY_OPTIONS = [30 => '30 minutos', 240 => '4 horas', 1440 => '24 horas'];

    /** Minutos que dura la ventana de vinculación. */
    public const LINK_WINDOW_MINUTES = 15;

    /** Hash con el que se guarda el token de configuración (nunca se persiste en claro). */
    public static function hashSetupToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Genera un token de configuración de un solo uso (para el enlace/QR de
     * provisión) y persiste solo su hash. Invalida cualquier enlace previo,
     * usado o no.
     *
     * @param  int  $expiresInMinutes  Vigencia del enlace, en minutos.
     * @return string El token plano a incluir en el fragmento (`#`) de la URL; no se puede recuperar después.
     */
    public function generateSetupToken(int $expiresInMinutes = 30): string
    {
        $token = Str::random(40);

        $this->forceFill([
            'setup_token' => static::hashSetupToken($token),
            'setup_token_expires_at' => now()->addMinutes($expiresInMinutes),
            'setup_token_consumed_at' => null,
        ])->save();

        return $token;
    }

    /**
     * Estado del token recibido contra el enlace vigente: `valid`, `expired`
     * (coincide pero venció), `consumed` (coincide pero ya se usó) o `invalid`
     * (no coincide: nunca existió o fue reemplazado por uno nuevo).
     */
    public function setupTokenState(string $token): string
    {
        if ($this->setup_token === null || ! hash_equals($this->setup_token, static::hashSetupToken($token))) {
            return 'invalid';
        }

        if ($this->setup_token_consumed_at !== null) {
            return 'consumed';
        }

        if (! ($this->setup_token_expires_at instanceof Carbon) || ! $this->setup_token_expires_at->isFuture()) {
            return 'expired';
        }

        return 'valid';
    }

    /** Indica si el token de configuración recibido es válido (existe, coincide, no se usó y no expiró). */
    public function isSetupTokenValid(string $token): bool
    {
        return $this->setupTokenState($token) === 'valid';
    }

    /** Hay un enlace de configuración generado, sin usar y sin vencer. */
    public function hasPendingSetupLink(): bool
    {
        return $this->setup_token !== null
            && $this->setup_token_consumed_at === null
            && $this->setup_token_expires_at?->isFuture() === true;
    }

    // =========================================================================
    // VENTANA DE VINCULACIÓN
    // =========================================================================

    /** Usuario que abrió la ventana de vinculación. */
    public function linkWindowOpenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'link_window_opened_by_id');
    }

    /** La ventana de vinculación está abierta (no venció ni se cerró). */
    public function hasOpenLinkWindow(): bool
    {
        return $this->link_window_until?->isFuture() === true;
    }

    /**
     * Abre la ventana de vinculación: durante `LINK_WINDOW_MINUTES` la primera
     * solicitud de código se aprueba sola; se cierra con el primer claim.
     */
    public function openLinkWindow(User $user): void
    {
        $this->forceFill([
            'link_window_until' => now()->addMinutes(self::LINK_WINDOW_MINUTES),
            'link_window_opened_by_id' => $user->id,
        ])->save();

        TerminalEvent::record($this, 'link_window_opened', ['until' => $this->link_window_until->toIso8601String()], $user->id);
    }

    /**
     * Terminales con la ventana de vinculación abierta y vigente.
     *
     * @param  Builder<Terminal>  $query
     * @return Builder<Terminal>
     */
    public function scopeWithOpenLinkWindow(Builder $query): Builder
    {
        return $query->where('link_window_until', '>', now());
    }

    /**
     * Cierra las ventanas de vinculación vencidas sin usarse y avisa a quien las
     * abrió. Una ventana aprovechada o cerrada a mano ya quedó en null, así que
     * toda ventana con fecha pasada venció sin que nadie la usara.
     *
     * @return int Cantidad de ventanas cerradas.
     */
    public static function expireLinkWindows(): int
    {
        $expired = static::query()
            ->whereNotNull('link_window_until')
            ->where('link_window_until', '<=', now())
            ->with('linkWindowOpenedBy')
            ->get();

        foreach ($expired as $terminal) {
            $opener = $terminal->linkWindowOpenedBy;

            $terminal->closeLinkWindow(null, 'expired');

            if (! $opener) {
                continue;
            }

            try {
                $opener->notify(new TerminalLinkWindowExpiredNotification($terminal));
            } catch (\Throwable $e) {
                Log::warning("No se pudo avisar el vencimiento de la ventana de vinculación del terminal '{$terminal->code}': {$e->getMessage()}", [
                    'terminal_id' => $terminal->id,
                ]);
            }
        }

        return $expired->count();
    }

    /** Cierra la ventana de vinculación (a mano, o al primer claim). */
    public function closeLinkWindow(?int $actorId = null, string $reason = 'manual'): void
    {
        if ($this->link_window_until === null) {
            return;
        }

        $this->forceFill(['link_window_until' => null])->save();

        TerminalEvent::record($this, 'link_window_closed', ['reason' => $reason], $actorId);
    }

    /**
     * Consume el token de configuración (single-use) y emite un token Sanctum
     * con la ability de sincronización. Revoca tokens `terminal:sync`
     * previos para que solo quede uno activo por terminal.
     *
     * @param  string|null  $userAgent  User-Agent del dispositivo que provisiona, para diagnóstico
     *                                  y como insumo de DeviceHintsParser (marca/modelo sugeridos, editables).
     * @param  string|null  $clientHintModel  Modelo reportado por Client Hints del navegador
     *                                        (`navigator.userAgentData.getHighEntropyValues(['model'])`), cuando está disponible —
     *                                        ver DeviceHintsParser para el detalle de qué navegadores lo soportan.
     * @param  string|null  $ip  IP desde la que se vinculó el dispositivo (se guarda en `linked_ip`).
     * @param  string  $via  Cómo se vinculó (`setup` = enlace de un solo uso, `pairing` = código aprobado por un admin) — queda en la bitácora.
     * @return string Token Sanctum en texto plano — solo se retorna una vez, nunca se persiste en claro.
     */
    public function claimSanctumToken(?string $userAgent = null, ?string $clientHintModel = null, ?string $ip = null, string $via = 'setup'): string
    {
        $this->tokens()->where('name', 'like', 'kiosk:%')->delete();

        $attributes = [
            'user_agent' => $userAgent,
            'linked_at' => now(),
            'linked_ip' => $ip,
        ];

        // Solo sugiere marca/modelo si el admin no los cargó ya a mano — nunca pisa una
        // corrección manual previa (ej. una reprovisión del mismo terminal físico).
        if (blank($this->device_brand) && blank($this->device_model)) {
            $guess = DeviceHintsParser::guess($userAgent, $clientHintModel);
            $attributes['device_brand'] = $guess['brand'];
            $attributes['device_model'] = $guess['model'];
        }

        // Un enlace de configuración usado conserva su hash con `consumed_at` (para decir
        // "ya se usó" en vez de "venció"); vincular por otra vía invalida el enlace pendiente.
        if ($via === 'setup') {
            $attributes['setup_token_consumed_at'] = $this->setup_token_consumed_at ?? now();
        } else {
            $attributes['setup_token'] = null;
            $attributes['setup_token_expires_at'] = null;
            $attributes['setup_token_consumed_at'] = null;
        }

        $this->forceFill($attributes)->save();

        // El primer claim cierra la ventana de vinculación, si había una abierta.
        $this->closeLinkWindow(null, 'claimed');

        // La provisión del terminal ya quedó persistida arriba — un fallo al notificar
        // (ej. mailer mal configurado) no debe convertirse en un 500 que deje al enlace
        // de configuración consumido pero sin token emitido (ver claim() en
        // TerminalSetupController, que retornaría "Server Error" con el setup_token ya
        // invalidado, sin forma de reintentar sin generar un enlace nuevo).
        try {
            User::all()->each(fn (User $user) => $user->notify(new TerminalProvisionedNotification($this)));
        } catch (\Throwable $e) {
            Log::warning("No se pudo notificar la provisión del terminal '{$this->code}': {$e->getMessage()}", [
                'terminal_id' => $this->id,
            ]);
        }

        TerminalEvent::record($this, 'linked', ['via' => $via, 'ip' => $ip]);

        return $this->createToken('kiosk:'.$this->code, [self::SYNC_ABILITY])->plainTextToken;
    }

    /**
     * Revoca todos los tokens Sanctum activos del terminal (fuerza re-provisión).
     * Deja registro en la bitácora con el usuario del panel que lo hizo.
     */
    public function revokeSyncTokens(): void
    {
        $revoked = $this->tokens()->delete();

        if ($revoked > 0) {
            TerminalEvent::record($this, 'revoked', ['tokens' => $revoked], Auth::id());
        }
    }

    /**
     * Usuarios del panel con permiso para gestionar terminales (`update_terminal`,
     * el mismo que protege el resto de la gestión; Super Admin lo tiene vía
     * `Gate::before`). Son los destinatarios de las solicitudes de vinculación.
     *
     * @return Collection<int, User>
     */
    public static function managers(): Collection
    {
        return User::all()->filter(fn (User $user) => $user->can('update_terminal'))->values();
    }
}
