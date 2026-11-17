<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Policy base de los datos que cuelgan de un empleado y no tienen permisos propios (domicilios, hijos, cuentas
 * bancarias, percepciones y deducciones asignadas). Sin policy, Filament deja abiertas sus pestañas a cualquiera que
 * abra la ficha: aquí se atan a los permisos de Empleados (ver listado/ficha para consultar, editar para modificar).
 */
abstract class EmployeeOwnedPolicy
{
    /** Ver la pestaña: listado de empleados. */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_employee');
    }

    /** Ver un registro: ficha del empleado. */
    public function view(User $user, Model $record): bool
    {
        return $user->can('view_employee');
    }

    /** Crear: quien puede editar empleados. */
    public function create(User $user): bool
    {
        return $user->can('update_employee');
    }

    /** Editar: quien puede editar empleados. */
    public function update(User $user, Model $record): bool
    {
        return $user->can('update_employee');
    }

    /** Eliminar: quien puede editar empleados. */
    public function delete(User $user, Model $record): bool
    {
        return $user->can('update_employee');
    }
}
