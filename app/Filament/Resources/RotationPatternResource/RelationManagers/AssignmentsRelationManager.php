<?php

namespace App\Filament\Resources\RotationPatternResource\RelationManagers;

use App\Exports\RotationAssignmentsExport;
use App\Filament\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\RotationAssignment;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Historial de solo lectura de las asignaciones del patrón: quién lo tuvo, desde y hasta cuándo, y quién lo asignó.
 * Quitar a un empleado solo cierra su asignación (no la borra), así que esta pestaña muestra lo que impide eliminar
 * el patrón; con solo asignaciones finalizadas, el camino es desactivarlo.
 */
class AssignmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'assignments';

    protected static ?string $title = 'Historial de asignaciones';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordUrl(fn (RotationAssignment $record) => EmployeeResource::getUrl('view', ['record' => $record->employee_id]))
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['employee', 'createdBy']))
            ->columns([
                TextColumn::make('employee')
                    ->label('Empleado')
                    ->getStateUsing(fn (RotationAssignment $record) => $record->employee?->full_name)
                    ->description(fn (RotationAssignment $record) => $record->employee ? 'CI: '.$record->employee->ci : null)
                    ->placeholder('Empleado eliminado')
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas(
                        'employee',
                        fn ($q) => $q->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('ci', 'like', "%{$search}%")
                    )),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->getStateUsing(fn (RotationAssignment $record) => $record->status())
                    ->formatStateUsing(fn (string $state) => RotationAssignment::getStatusLabels()[$state] ?? $state)
                    ->color(fn (string $state) => RotationAssignment::getStatusColors()[$state] ?? 'gray'),

                TextColumn::make('valid_from')->label('Desde')->date('d/m/Y')->sortable(),

                TextColumn::make('valid_until')
                    ->label('Hasta')
                    ->date('d/m/Y')
                    ->placeholder('Sin fecha de fin')
                    ->sortable(),

                TextColumn::make('start_index')
                    ->label('Día de inicio')
                    ->formatStateUsing(fn ($state) => 'Día '.((int) $state + 1))
                    ->badge()
                    ->color('gray'),

                TextColumn::make('createdBy.name')->label('Asignado por')->placeholder('Sin dato'),
            ])
            ->filters([
                SelectFilter::make('employee_id')
                    ->label('Empleado')
                    ->options(fn () => Employee::query()
                        ->whereIn('id', $this->getOwnerRecord()->assignments()->select('employee_id'))
                        ->orderBy('first_name')->orderBy('last_name')
                        ->get()
                        ->mapWithKeys(fn (Employee $employee) => [$employee->id => $employee->full_name.' (CI: '.$employee->ci.')'])
                        ->all())
                    ->searchable()
                    ->native(false)
                    ->placeholder('Todos'),

                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(RotationAssignment::getStatusLabels())
                    ->native(false)
                    ->placeholder('Todos')
                    ->query(function (Builder $query, array $data): Builder {
                        $today = Carbon::today()->toDateString();

                        return match ($data['value'] ?? null) {
                            RotationAssignment::STATUS_ENDED => $query->where('valid_until', '<', $today),
                            RotationAssignment::STATUS_UPCOMING => $query->where('valid_from', '>', $today),
                            RotationAssignment::STATUS_ACTIVE => $query->where('valid_from', '<=', $today)
                                ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $today)),
                            default => $query,
                        };
                    }),
            ])
            ->headerActions([
                Action::make('export')
                    ->authorize(fn (): bool => (bool) auth()->user()?->can('view_any_rotation_pattern'))
                    ->label('Exportar')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('¿Exportar el historial de asignaciones?')
                    ->modalDescription('Se exportan las asignaciones que muestra la lista, con los filtros y la búsqueda aplicados.')
                    ->modalSubmitActionLabel('Sí, exportar')
                    ->action(function () {
                        $pattern = $this->getOwnerRecord();
                        $ids = $this->getFilteredTableQuery()->pluck('rotation_assignments.id')->all();

                        Notification::make()->success()->title('Exportación lista')->body('El historial de asignaciones se está descargando.')->send();

                        return Excel::download(
                            new RotationAssignmentsExport($pattern->id, $ids),
                            'asignaciones_'.str($pattern->name)->slug().'_'.now()->format('Y_m_d').'.xlsx'
                        );
                    }),
            ])
            ->actions([])
            ->bulkActions([])
            ->defaultSort('valid_from', 'desc')
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading('Sin asignaciones')
            ->emptyStateDescription('Ningún empleado tuvo este patrón.')
            ->emptyStateIcon('heroicon-o-arrow-path');
    }
}
