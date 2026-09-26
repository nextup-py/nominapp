<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Detecta si la instalación actual ya tiene datos de negocio o es genuinamente nueva. */
class InstallationDetector
{
    /**
     * Ninguna instalación real opera sin al menos una Company: Branch, Employee,
     * Contract y la nómina dependen de ella. Antes de completar el wizard de
     * configuración inicial, `companies` está siempre vacía.
     */
    public static function hasExistingCompany(): bool
    {
        return Schema::hasTable('companies') && DB::table('companies')->exists();
    }
}
