<?php

namespace App\Filament\Resources\ScheduleResource\RelationManagers;

use App\Filament\Resources\EmployeeResource;
use App\Models\EmployeeScheduleAssignment;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Historial de solo lectura de las asignaciones del horario: quién lo tuvo, desde y hasta cuándo, y quién lo asignó.
 * Quitar a un empleado de un horario solo cierra su asignación (no la borra), así que esta pestaña es donde se ve lo
 * que impide eliminarlo; con solo asignaciones finalizadas, el camino es desactivar el horario.
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
            ->recordUrl(fn (EmployeeScheduleAssignment $record) => EmployeeResource::getUrl('view', ['record' => $record->employee_id]))
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['employee', 'createdBy']))
            ->columns([
                TextColumn::make('employee')
                    ->label('Empleado')
                    ->getStateUsing(fn (EmployeeScheduleAssignment $record) => $record->employee?->full_name)
                    ->description(fn (EmployeeScheduleAssignment $record) => $record->employee ? 'CI: '.$record->employee->ci : null)
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
                    ->getStateUsing(fn (EmployeeScheduleAssignment $record) => $record->status())
                    ->formatStateUsing(fn (string $state) => EmployeeScheduleAssignment::getStatusLabels()[$state] ?? $state)
                    ->color(fn (string $state) => EmployeeScheduleAssignment::getStatusColors()[$state] ?? 'gray'),

                TextColumn::make('valid_from')->label('Desde')->date('d/m/Y')->sortable(),

                TextColumn::make('valid_until')
                    ->label('Hasta')
                    ->date('d/m/Y')
                    ->placeholder('Sin fecha de fin')
                    ->sortable(),

                TextColumn::make('createdBy.name')->label('Asignado por')->placeholder('Sin dato'),

                TextColumn::make('notes')->label('Notas')->placeholder('Sin notas')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(EmployeeScheduleAssignment::getStatusLabels())
                    ->native(false)
                    ->placeholder('Todos')
                    ->query(function (Builder $query, array $data): Builder {
                        $today = Carbon::today()->toDateString();

                        return match ($data['value'] ?? null) {
                            EmployeeScheduleAssignment::STATUS_ENDED => $query->where('valid_until', '<', $today),
                            EmployeeScheduleAssignment::STATUS_UPCOMING => $query->where('valid_from', '>', $today),
                            EmployeeScheduleAssignment::STATUS_ACTIVE => $query->where('valid_from', '<=', $today)
                                ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $today)),
                            default => $query,
                        };
                    }),
            ])
            ->actions([])
            ->bulkActions([])
            ->defaultSort('valid_from', 'desc')
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading('Sin asignaciones')
            ->emptyStateDescription('Ningún empleado tuvo este horario.')
            ->emptyStateIcon('heroicon-o-clock');
    }
}
