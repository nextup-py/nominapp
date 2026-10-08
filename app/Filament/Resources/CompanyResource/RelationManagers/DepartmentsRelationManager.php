<?php

namespace App\Filament\Resources\CompanyResource\RelationManagers;

use App\Filament\Resources\DepartmentResource;
use App\Models\Department;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Departamentos de la empresa con la cantidad de cargos y de empleados activos — solo lectura.
 * Crear y editar departamentos y cargos se hace desde el módulo Departamentos / Cargos.
 */
class DepartmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'departments';

    protected static ?string $title = 'Departamentos';

    public function isReadOnly(): bool
    {
        return true;
    }

    /**
     * Define la tabla de departamentos con conteo de cargos y de empleados activos con contrato vigente.
     */
    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withCount('positions')
                ->selectRaw("(SELECT COUNT(DISTINCT e.id) FROM employees e JOIN contracts c ON c.employee_id = e.id WHERE c.department_id = departments.id AND c.status IN ('active', 'suspended') AND e.status = 'active') AS active_employees_count")
            )
            ->recordUrl(fn (Department $record) => DepartmentResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->icon('heroicon-o-building-library')
                    ->description(fn (Department $record) => $record->description)
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('cost_center')
                    ->label('Centro de costo')
                    ->badge()
                    ->color('gray')
                    ->placeholder('Sin centro de costo')
                    ->searchable(),

                TextColumn::make('positions_count')
                    ->label('Cargos')
                    ->badge()
                    ->color('info')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('active_employees_count')
                    ->label('Empleados Activos')
                    ->badge()
                    ->color('success')
                    ->alignCenter()
                    ->sortable(),
            ])
            ->actions([])
            ->bulkActions([])
            ->defaultSort('name')
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading('No hay departamentos registrados')
            ->emptyStateDescription('Los departamentos se crean desde Organización → Departamentos.')
            ->emptyStateIcon('heroicon-o-building-library');
    }
}
