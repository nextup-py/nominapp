<?php

namespace App\Filament\Resources\DepartmentResource\Pages;

use App\Exports\DepartmentsExport;
use App\Filament\Resources\DepartmentResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;

class ListDepartments extends ListRecords
{
    protected static string $resource = DepartmentResource::class;

    /**
     * Define las acciones disponibles en el encabezado de la página de listado de departamentos.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_excel')
                ->label('Exportar')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('¿Exportar Departamentos a Excel?')
                ->modalDescription('Se incluirán todos los departamentos en un archivo Excel descargable.')
                ->modalSubmitActionLabel('Sí, exportar')
                ->action(function () {
                    Notification::make()
                        ->success()
                        ->title('Exportación lista')
                        ->body('El listado de departamentos se está descargando.')
                        ->send();

                    return Excel::download(
                        new DepartmentsExport,
                        'departamentos_'.now()->format('Y_m_d_H_i_s').'.xlsx'
                    );
                }),

            CreateAction::make()
                ->label('Nuevo Departamento')
                ->icon('heroicon-o-plus'),
        ];
    }
}
