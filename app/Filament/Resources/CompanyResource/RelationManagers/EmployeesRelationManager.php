<?php

namespace App\Filament\Resources\CompanyResource\RelationManagers;

use App\Exports\CompanyEmployeesExport;
use App\Filament\Resources\EmployeeResource;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;

/**
 * RelationManager para listar los empleados de una empresa (a través de sus sucursales).
 * Solo lectura — crear y editar empleados se hace desde el módulo Empleados.
 */
class EmployeesRelationManager extends RelationManager
{
    protected static string $relationship = 'employees';

    protected static ?string $title = 'Empleados';

    public function isReadOnly(): bool
    {
        return true;
    }

    /**
     * Define la tabla de empleados de la empresa con filtros y exportación a Excel.
     */
    public function table(Table $table): Table
    {
        return $table
            ->recordUrl(fn (Employee $record) => EmployeeResource::getUrl('view', ['record' => $record]))
            ->columns([
                ImageColumn::make('photo')
                    ->label('Foto')
                    ->circular()
                    ->defaultImageUrl(fn ($record) => $record->avatar_url),

                TextColumn::make('full_name')
                    ->label('Nombre')
                    ->description(fn (Employee $record) => 'CI: '.$record->ci)
                    ->searchable(query: fn (Builder $query, string $search) => $query
                        ->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('ci', 'like', "%{$search}%")
                    )
                    ->sortable(['first_name', 'last_name'])
                    ->weight('medium'),

                TextColumn::make('branch.name')
                    ->label('Sucursal')
                    ->icon('heroicon-o-building-storefront')
                    ->placeholder('Sin sucursal')
                    ->sortable(),

                TextColumn::make('activeContract.position.name')
                    ->label('Cargo')
                    ->description(fn (Employee $record) => $record->activeContract?->position?->department?->name)
                    ->placeholder('Sin cargo')
                    ->badge()
                    ->color('info'),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (Employee $record): string => $record->status_color)
                    ->formatStateUsing(fn (string $state): string => Employee::getStatusOptions()[$state] ?? $state)
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(Employee::getStatusOptions())
                    ->native(false)
                    ->multiple(),

                SelectFilter::make('branch_id')
                    ->label('Sucursal')
                    ->options(fn () => Branch::where('company_id', $this->ownerRecord->id)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->toArray()
                    )
                    ->native(false),

                SelectFilter::make('department_id')
                    ->label('Departamento')
                    ->options(fn () => Department::where('company_id', $this->ownerRecord->id)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->toArray()
                    )
                    ->query(fn (Builder $query, array $data) => filled($data['value'])
                        ? $query->whereHas('activeContract', fn (Builder $contract) => $contract->where('contracts.department_id', (int) $data['value']))
                        : $query
                    )
                    ->native(false),

                SelectFilter::make('position_id')
                    ->label('Cargo')
                    ->options(fn () => Position::whereIn('department_id', Department::where('company_id', $this->ownerRecord->id)->select('id'))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->toArray()
                    )
                    ->query(fn (Builder $query, array $data) => filled($data['value'])
                        ? $query->whereHas('activeContract', fn (Builder $contract) => $contract->where('contracts.position_id', (int) $data['value']))
                        : $query
                    )
                    ->searchable()
                    ->native(false),
            ])
            ->headerActions([
                Action::make('export_excel')
                    ->label('Exportar')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('¿Exportar Empleados a Excel?')
                    ->modalDescription('Se exportarán todos los empleados de esta empresa.')
                    ->modalSubmitActionLabel('Sí, exportar')
                    ->action(function () {
                        Notification::make()
                            ->success()
                            ->title('Exportación lista')
                            ->body('El listado de empleados se está descargando.')
                            ->send();

                        return Excel::download(
                            new CompanyEmployeesExport($this->ownerRecord->id),
                            'empleados_empresa_'.now()->format('Y_m_d_H_i_s').'.xlsx'
                        );
                    }),
            ])
            ->actions([])
            ->bulkActions([])
            ->paginationPageOptions([10, 25, 50, 100])
            ->defaultSort('first_name')
            ->emptyStateHeading('No hay empleados registrados')
            ->emptyStateDescription('Los empleados se crean desde el módulo de Empleados.')
            ->emptyStateIcon('heroicon-o-users');
    }
}
