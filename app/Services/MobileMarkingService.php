<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;

/** Aplica el permiso de marcación desde el celular personal (ver `Employee::canMarkFromMobile()`). */
class MobileMarkingService
{
    /**
     * Revoca el celular vinculado de los empleados del conjunto que ya no pueden marcar desde él.
     *
     * @param  Builder<Employee>  $employees
     * @return int Cantidad de dispositivos revocados.
     */
    public static function revokeDisallowed(Builder $employees): int
    {
        $revoked = 0;

        $employees->whereNotNull('mobile_linked_at')->mobileMarking(false)->get()->each(function (Employee $employee) use (&$revoked): void {
            $employee->revokeMobileToken();
            $revoked++;
        });

        return $revoked;
    }
}
