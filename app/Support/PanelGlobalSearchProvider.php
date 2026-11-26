<?php

namespace App\Support;

use App\Filament\Pages\ReportsIndex;
use App\Filament\Resources\AttendanceEventResource;
use App\Filament\Resources\EmployeeResource;
use App\Filament\Resources\ScheduleResource;
use App\Filament\Resources\ShiftTemplateResource;
use Filament\Facades\Filament;
use Filament\GlobalSearch\Contracts\GlobalSearchProvider;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Búsqueda global del panel (Ctrl/Cmd+K y barra superior): empleados (nombre, apellido o CI, con los inactivos al final y
 * marcados) y pantallas del panel (listados de los recursos y páginas, incluidos los reportes). Solo aparece lo que el
 * usuario puede abrir: cada resultado respeta el `canViewAny()` / `canAccess()` de su módulo.
 */
class PanelGlobalSearchProvider implements GlobalSearchProvider
{
    /** Largo mínimo de lo escrito para buscar empleados (evita consultas con una sola letra). */
    public const MIN_EMPLOYEE_LENGTH = 2;

    /** Máximo de pantallas que se muestran. */
    public const SCREEN_LIMIT = 6;

    /**
     * Recursos que no están en el menú pero se encuentran con la búsqueda (pantallas de uso poco frecuente).
     *
     * @var array<int, class-string>
     */
    public const HIDDEN_SEARCHABLE = [AttendanceEventResource::class];

    public function getResults(string $query): ?GlobalSearchResults
    {
        $query = trim($query);
        $builder = GlobalSearchResults::make();

        if ($query === '') {
            return $builder;
        }

        $screens = $this->screens($query);

        if ($screens->isNotEmpty()) {
            $builder->category('Pantallas', $screens);
        }

        if (mb_strlen($query) >= self::MIN_EMPLOYEE_LENGTH && EmployeeResource::canGloballySearch()) {
            $employees = EmployeeResource::getGlobalSearchResults($query);

            if ($employees->isNotEmpty()) {
                $builder->category('Empleados', $employees);
            }
        }

        if (mb_strlen($query) >= self::MIN_EMPLOYEE_LENGTH && ScheduleResource::canGloballySearch()) {
            $schedules = ScheduleResource::getGlobalSearchResults($query);

            if ($schedules->isNotEmpty()) {
                $builder->category('Horarios', $schedules);
            }
        }

        if (mb_strlen($query) >= self::MIN_EMPLOYEE_LENGTH && ShiftTemplateResource::canGloballySearch()) {
            $shifts = ShiftTemplateResource::getGlobalSearchResults($query);

            if ($shifts->isNotEmpty()) {
                $builder->category('Turnos', $shifts);
            }
        }

        return $builder;
    }

    /**
     * Pantallas cuyo nombre contiene todas las palabras escritas (sin distinguir mayúsculas ni tildes).
     *
     * @return Collection<int, GlobalSearchResult>
     */
    public function screens(string $query): Collection
    {
        $words = array_filter(explode(' ', self::normalize($query)));

        return $this->catalog()
            ->filter(function (array $screen) use ($words): bool {
                $label = self::normalize($screen['label']);

                foreach ($words as $word) {
                    if (! str_contains($label, $word)) {
                        return false;
                    }
                }

                return true;
            })
            ->take(self::SCREEN_LIMIT)
            ->map(fn (array $screen): GlobalSearchResult => new GlobalSearchResult(
                title: $screen['label'],
                url: $screen['url'],
                details: array_filter(['Sección' => $screen['group']]),
            ))
            ->values();
    }

    /**
     * Pantallas que el usuario actual puede abrir: listados de los recursos y páginas del menú o de los reportes.
     *
     * @return Collection<int, array{label: string, group: ?string, url: string}>
     */
    private function catalog(): Collection
    {
        $screens = collect();

        foreach (Filament::getResources() as $resource) {
            if ((! $resource::shouldRegisterNavigation() && ! in_array($resource, self::HIDDEN_SEARCHABLE, true)) || ! $resource::hasPage('index') || ! $resource::canViewAny()) {
                continue;
            }

            $screens->push([
                'label' => (string) $resource::getNavigationLabel(),
                'group' => $resource::getNavigationGroup(),
                'url' => $resource::getUrl('index'),
            ]);
        }

        $reports = ReportsIndex::reportPages();

        foreach (Filament::getPages() as $page) {
            if (! $page::canAccess() || (! $page::shouldRegisterNavigation() && ! in_array($page, $reports, true))) {
                continue;
            }

            $screens->push([
                'label' => (string) $page::getNavigationLabel(),
                'group' => in_array($page, $reports, true) ? 'Reportes' : $page::getNavigationGroup(),
                'url' => $page::getUrl(),
            ]);
        }

        return $screens->unique('url')->sortBy('label')->values();
    }

    /** Minúsculas y sin tildes, para comparar lo escrito con los nombres. */
    private static function normalize(string $text): string
    {
        return Str::lower(Str::ascii($text));
    }
}
