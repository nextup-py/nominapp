<?php

namespace App\Models;

use App\Notifications\TerminalProvisionedNotification;
use App\Services\DeviceHintsParser;
use App\Settings\GeneralSettings;
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

        $thresholdHours = app(GeneralSettings::class)->terminal_stale_threshold_hours;

        return $this->last_heartbeat_at->lt(now()->subHours($thresholdHours)) ? 'stale' : 'online';
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

    /**
     * Genera un token de configuración de un solo uso (para el enlace/QR de
     * provisión) y lo persiste con expiración corta. Invalida cualquier
     * enlace de configuración previo sin consumir.
     *
     * @param  int  $expiresInMinutes  Vigencia del enlace, en minutos.
     * @return string El token plano a incluir en la URL de configuración.
     */
    public function generateSetupToken(int $expiresInMinutes = 30): string
    {
        $token = Str::random(40);

        $this->forceFill([
            'setup_token' => $token,
            'setup_token_expires_at' => now()->addMinutes($expiresInMinutes),
        ])->save();

        return $token;
    }

    /** Indica si el token de configuración recibido es válido (existe, coincide y no expiró). */
    public function isSetupTokenValid(string $token): bool
    {
        return $this->setup_token !== null
            && hash_equals($this->setup_token, $token)
            && $this->setup_token_expires_at instanceof Carbon
            && $this->setup_token_expires_at->isFuture();
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
            'setup_token' => null,
            'setup_token_expires_at' => null,
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

        $this->forceFill($attributes)->save();

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
