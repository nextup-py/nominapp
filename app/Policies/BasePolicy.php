<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Policy CRUD genérica: resuelve el nombre del permiso por convención a
 * partir del nombre de la clase hija (ej. `EmployeePolicy` → `*_employee`),
 * para no repetir la misma lógica en las 33 Policies del proyecto.
 */
abstract class BasePolicy
{
    protected function permissionName(string $ability): string
    {
        $model = Str::snake(Str::replaceLast('Policy', '', class_basename(static::class)));

        return "{$ability}_{$model}";
    }

    public function viewAny(User $user): bool
    {
        return $user->can($this->permissionName('view_any'));
    }

    public function view(User $user, Model $model): bool
    {
        return $user->can($this->permissionName('view'));
    }

    public function create(User $user): bool
    {
        return $user->can($this->permissionName('create'));
    }

    public function update(User $user, Model $model): bool
    {
        return $user->can($this->permissionName('update'));
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->can($this->permissionName('delete'));
    }
}
