<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

/**
 * Índice de reportes: una sola entrada del menú con tarjetas por tema que enlazan a cada reporte. Cada tarjeta aparece
 * solo si el usuario puede abrir ese reporte (`canAccess()` de la página), así que los permisos se respetan sin más.
 */
class ReportsIndex extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $navigationLabel = 'Reportes';

    protected static ?int $navigationSort = -1;

    protected static ?string $title = 'Reportes';

    protected static ?string $slug = 'reportes';

    protected static string $view = 'filament.pages.reports-index';

    /**
     * Reportes por tema: tema => lista de [clase de la página, descripción].
     *
     * @return array<string, list<array{page: class-string<Page>, description: string}>>
     */
    /**
     * Páginas de reporte del catálogo (las usa la búsqueda global para encontrarlas aunque no estén en el menú).
     *
     * @return list<class-string<Page>>
     */
    public static function reportPages(): array
    {
        return collect(self::catalog())->flatten(1)->pluck('page')->all();
    }

    protected static function catalog(): array
    {
        return [
            'Personal' => [
                ['page' => EmployeeReport::class, 'description' => 'Listado de empleados con filtros, columnas a elección y exportación.'],
                ['page' => ContractReport::class, 'description' => 'Contratos por estado, tipo y vencimiento.'],
                ['page' => VacationReport::class, 'description' => 'Vacaciones tomadas y programadas.'],
                ['page' => SalaryReport::class, 'description' => 'Salarios y remuneración por empleado y cargo.'],
            ],
            'Asistencia' => [
                ['page' => AttendanceReport::class, 'description' => 'Horas trabajadas, tardanzas y ausencias por período.'],
                ['page' => HolidayWorkReport::class, 'description' => 'Trabajo en feriados y domingos, con el recargo que correspondería sobre el turno normal.'],
            ],
            'Créditos' => [
                ['page' => AdvanceReport::class, 'description' => 'Adelantos de salario por estado y período.'],
                ['page' => LoanReport::class, 'description' => 'Préstamos y estado de sus cuotas.'],
                ['page' => MerchandiseReport::class, 'description' => 'Retiros de mercadería y su saldo.'],
            ],
        ];
    }

    /**
     * Tarjetas que el usuario actual puede abrir, agrupadas por tema (los temas sin reportes accesibles se omiten).
     *
     * @return array<string, list<array{label: string, icon: ?string, description: string, url: string}>>
     */
    public function sections(): array
    {
        $sections = [];

        foreach (self::catalog() as $topic => $reports) {
            foreach ($reports as $report) {
                $page = $report['page'];

                if (! $page::canAccess()) {
                    continue;
                }

                $sections[$topic][] = [
                    'label' => $page::getNavigationLabel(),
                    'icon' => $page::getNavigationIcon(),
                    'description' => $report['description'],
                    'url' => $page::getUrl(),
                ];
            }
        }

        return $sections;
    }

    /** La entrada aparece si el usuario puede abrir al menos un reporte. */
    public static function canAccess(): bool
    {
        foreach (self::catalog() as $reports) {
            foreach ($reports as $report) {
                if ($report['page']::canAccess()) {
                    return true;
                }
            }
        }

        return false;
    }
}
