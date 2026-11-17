<?php

namespace App\Filament\Resources\EmployeeResource\Pages;

use App\Exports\EmployeesTemplateExport;
use App\Filament\Actions\ReportingLineActions;
use App\Filament\Pages\EmployeeReport;
use App\Filament\Resources\EmployeeResource;
use App\Imports\EmployeesImport;
use App\Models\Employee;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Maatwebsite\Excel\Facades\Excel;

class ListEmployees extends ListRecords
{
    protected static string $resource = EmployeeResource::class;

    /**
     * Define las acciones del encabezado de la página de listado.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('go_to_report')
                ->label('Ver Reporte')
                ->icon('heroicon-o-chart-bar')
                ->color('gray')
                ->url(EmployeeReport::getUrl()),

            ReportingLineActions::rule(),

            ActionGroup::make([
                Action::make('download_employees_template')
                    ->label('Descargar Plantilla')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(fn () => Excel::download(
                        new EmployeesTemplateExport,
                        'plantilla_empleados_'.now()->format('Y_m_d_H_i_s').'.xlsx'
                    )),

                Action::make('import_employees')
                    ->label('Importar Empleados')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('gray')
                    ->modalHeading('Creación Masiva de Empleados')
                    ->modalSubmitActionLabel('Importar')
                    ->form([
                        Placeholder::make('import_info')
                            ->label('')
                            ->content(new HtmlString(
                                '<p class="text-sm text-gray-600 dark:text-gray-400">'.
                                'Subí el archivo Excel completado a partir de la plantilla. '.
                                'Se crearán empleados <strong>activos</strong> con los datos básicos para las filas válidas '.
                                '(CI, nombre, apellido, fecha de nacimiento, género y sucursal son obligatorios). '.
                                'El contrato, horario y enrolamiento facial se completan después desde la ficha de cada empleado. '.
                                'La sucursal debe coincidir exactamente con el nombre registrado en el sistema.'.
                                '</p>'
                            )),

                        FileUpload::make('file')
                            ->label('Archivo Excel (.xlsx)')
                            ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel'])
                            ->required()
                            ->disk('local')
                            ->directory('imports/employees')
                            ->maxSize(5120),
                    ])
                    ->action(function (array $data) {
                        $path = Storage::disk('local')->path($data['file']);

                        $import = new EmployeesImport;
                        Excel::import($import, $path);

                        Storage::disk('local')->delete($data['file']);

                        $created = $import->created;
                        $failures = $import->failures;

                        if ($created === 0 && count($failures) === 0) {
                            Notification::make()
                                ->warning()
                                ->title('Sin datos')
                                ->body('El archivo no contenía filas para procesar.')
                                ->send();

                            return;
                        }

                        $body = "Se crearon {$created} empleado(s) activo(s).";

                        if (count($failures) > 0) {
                            $lines = array_map(
                                fn ($f) => "• Fila {$f['row']} ({$f['name']}): {$f['reason']}",
                                array_slice($failures, 0, 10)
                            );
                            if (count($failures) > 10) {
                                $lines[] = '… y '.(count($failures) - 10).' más.';
                            }
                            $body .= ' '.count($failures).' fila(s) con error:<br>'.implode('<br>', $lines);
                        }

                        Notification::make()
                            ->title('Importación Completada')
                            ->body(new HtmlString($body))
                            ->{count($failures) > 0 ? 'warning' : 'success'}()
                            ->send()
                            ->persistent();
                    }),
            ])
                ->label('Creación masiva')
                ->icon('heroicon-o-square-3-stack-3d')
                ->color('warning')
                ->button(),

            CreateAction::make()
                ->label('Nuevo Empleado')
                ->icon('heroicon-o-plus'),
        ];
    }

    /**
     * Define las pestañas para filtrar los registros.
     */
    public function getTabs(): array
    {
        $counts = Employee::getTabCounts();

        return [
            'all' => Tab::make('Todos')
                ->badge($counts['all'] ?: null)
                ->badgeColor('gray')
                ->icon('heroicon-o-users'),

            'active' => Tab::make('Activo')
                ->modifyQueryUsing(fn (Builder $query) => $query->active())
                ->badge($counts['active'] ?: null)
                ->badgeColor('success')
                ->icon('heroicon-o-check-circle'),

            'inactive' => Tab::make('Inactivo')
                ->modifyQueryUsing(fn (Builder $query) => $query->inactive())
                ->badge($counts['inactive'] ?: null)
                ->badgeColor('danger')
                ->icon('heroicon-o-x-circle'),

            'weak_face' => Tab::make('Descriptor débil')
                ->modifyQueryUsing(fn (Builder $query) => $query->active()->withWeakFaceDescriptor())
                ->badge($counts['weak_face'] ?: null)
                ->badgeColor('danger')
                ->icon('heroicon-o-exclamation-triangle'),
        ];
    }

    /**
     * Define la pestaña activa por defecto.
     */
    public function getDefaultActiveTab(): string|int|null
    {
        return 'active';
    }
}
