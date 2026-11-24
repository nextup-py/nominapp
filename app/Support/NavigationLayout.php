<?php

namespace App\Support;

use Filament\Facades\Filament;

/**
 * Agrupación del menú del panel en sus dos formatos. Cada pantalla declara su grupo y orden para la barra lateral
 * (`$navigationGroup`/`$navigationSort`) y esta clase traduce ese grupo al del menú superior: es la única definición
 * del segundo formato, así que una pantalla nueva no necesita tocar nada más.
 */
class NavigationLayout
{
    public const SIDEBAR = 'sidebar';

    public const TOP = 'top';

    /** Máximo de grupos que entran en el menú superior a 1280 px junto a Escritorio, Reportes y Mi equipo. */
    public const MAX_TOP_GROUPS = 7;

    /**
     * Grupo de la barra lateral → [grupo del menú superior, desplazamiento del orden]. El desplazamiento mantiene
     * separados los bloques que se fusionan en un mismo grupo superior.
     *
     * @var array<string, array{0: string, 1: int}>
     */
    public const TOP_GROUPS = [
        'Asistencias' => ['Asistencias', 0],
        'Horarios y turnos' => ['Asistencias', 10],
        'Terminales y dispositivos' => ['Terminales', 0],
        'Empleados' => ['Personal', 0],
        'Licencias y vacaciones' => ['Personal', 10],
        'Nóminas' => ['Nómina', 0],
        'Aguinaldo y liquidación' => ['Nómina', 20],
        'Créditos' => ['Nómina', 40],
        'Organización' => ['Organización', 0],
        'Catálogos' => ['Organización', 10],
        'Configuración' => ['Administración', 0],
        'Sistema' => ['Administración', 10],
        'Ayuda' => ['Ayuda', 0],
    ];

    /** @return array<string, string> */
    public static function options(): array
    {
        return [
            self::SIDEBAR => 'Barra lateral',
            self::TOP => 'Menú superior',
        ];
    }

    /** Si el usuario autenticado eligió el menú superior. */
    public static function isTop(): bool
    {
        try {
            return Filament::auth()->user()?->navigation_layout === self::TOP;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Grupo que corresponde a una pantalla según el formato elegido. */
    public static function group(?string $sidebarGroup): ?string
    {
        if ($sidebarGroup === null || ! self::isTop()) {
            return $sidebarGroup;
        }

        return self::TOP_GROUPS[$sidebarGroup][0] ?? $sidebarGroup;
    }

    /** Orden de una pantalla según el formato elegido. */
    public static function sort(?string $sidebarGroup, ?int $sort): ?int
    {
        if ($sort === null || $sidebarGroup === null || ! self::isTop()) {
            return $sort;
        }

        return $sort + (self::TOP_GROUPS[$sidebarGroup][1] ?? 0);
    }

    /**
     * Nombres de los grupos del menú superior en orden de aparición.
     *
     * @return list<string>
     */
    public static function topGroupNames(): array
    {
        return array_values(array_unique(array_column(self::TOP_GROUPS, 0)));
    }
}
