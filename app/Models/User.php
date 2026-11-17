<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasAvatar
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    protected $guard_name = 'web';

    /** @var array<string, mixed> Valores por defecto de un usuario recién creado en memoria. */
    protected $attributes = ['is_active' => true];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar_path',
        'employee_id',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /** Solo entra al panel un usuario activo y con al menos un rol. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $this->roles()->exists();
    }

    /** Usuarios activos. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('users.is_active', true);
    }

    /**
     * Motivo por el que no se puede desactivar ni eliminar a este usuario, o null si se puede: no a uno mismo y
     * nunca al último Super Admin activo (nadie podría volver a administrar el sistema).
     */
    public function lockoutBlocker(?User $actor): ?string
    {
        if ($actor !== null && $actor->is($this)) {
            return 'No podés hacerlo sobre tu propio usuario.';
        }

        if ($this->is_active && $this->hasRole('Super Admin')) {
            $others = static::query()->active()->whereHas('roles', fn ($q) => $q->where('name', 'Super Admin'))
                ->whereKeyNot($this->getKey())->exists();

            if (! $others) {
                return 'Es el último Super Admin activo: el sistema quedaría sin administrador.';
            }
        }

        return null;
    }

    /** URL de la foto de perfil para el avatar del panel (null = iniciales). */
    public function getFilamentAvatarUrl(): ?string
    {
        return $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null;
    }

    /** Ficha de empleado vinculada (opcional). */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Ingresos al panel, del más reciente al más viejo. */
    public function loginHistories(): HasMany
    {
        return $this->hasMany(LoginHistory::class)->latest('logged_in_at');
    }
}
