<?php

namespace App\Filament\Traits;

use App\Settings\ModuleSettings;

/**
 * Gatea el acceso real (no solo la visibilidad en el menú) de un Resource a un
 * flag booleano de ModuleSettings. El Resource que lo use debe declarar
 * `protected static string $moduleFlag` con el nombre exacto del campo en
 * ModuleSettings (ej. 'loans_enabled').
 *
 * Implementaciones: LoanResource, AdvanceResource, MerchandiseWithdrawalResource,
 * VacationResource, WarningResource, EmployeeLeaveResource, TerminalResource,
 * EmployeeDeviceResource, FaceEnrollmentResource, DisbursementBatchResource,
 * AguinaldoResource, AguinaldoPeriodResource.
 */
trait HasModuleAccess
{
    public static function isModuleEnabled(): bool
    {
        return app(ModuleSettings::class)->{static::$moduleFlag};
    }

    /**
     * El módulo debe estar activo Y el usuario debe tener el permiso de
     * negocio correspondiente (política CRUD estándar) — el flag de módulo
     * es una capa adicional, nunca un reemplazo del control de acceso por
     * rol/permiso ya existente.
     */
    public static function canViewAny(): bool
    {
        return static::isModuleEnabled() && static::can('viewAny');
    }

    public static function canCreate(): bool
    {
        return static::isModuleEnabled() && static::can('create');
    }

    public static function canEdit($record): bool
    {
        return static::isModuleEnabled() && static::can('update', $record);
    }

    public static function canDelete($record): bool
    {
        return static::isModuleEnabled() && static::can('delete', $record);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::isModuleEnabled();
    }
}
