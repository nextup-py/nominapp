<?php

namespace App\Filament\Resources\ScheduleResource\RelationManagers;

use App\Exports\ScheduleAssignmentsExport;
use App\Filament\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;

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

                TextColumn::make('notes')->label('Notas')->placeholder('Sin notas')->wrap()->toggleable(),
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

                Filter::make('period')
                    ->label('Tuvo el horario entre')
                    ->form([
                        DatePicker::make('from')->label('Desde')->native(false),
                        DatePicker::make('until')->label('Hasta')->native(false),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['until'] ?? null, fn (Builder $q, $until) => $q->where('valid_from', '<=', $until))
                        ->when($data['from'] ?? null, fn (Builder $q, $from) => $q->where(fn (Builder $range) => $range
                            ->whereNull('valid_until')
                            ->orWhere('valid_until', '>=', $from))))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['from'] ?? null) {
                            $indicators[] = 'Desde el '.Carbon::parse($data['from'])->format('d/m/Y');
                        }

                        if ($data['until'] ?? null) {
                            $indicators[] = 'Hasta el '.Carbon::parse($data['until'])->format('d/m/Y');
                        }

                        return $indicators;
                    }),

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
            ->headerActions([
                Action::make('export')
                    ->authorize(fn (): bool => (bool) auth()->user()?->can('view_any_schedule'))
                    ->label('Exportar')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('¿Exportar el historial de asignaciones?')
                    ->modalDescription('Se exportan las asignaciones que muestra la lista, con los filtros y la búsqueda aplicados.')
                    ->modalSubmitActionLabel('Sí, exportar')
                    ->action(function () {
                        $schedule = $this->getOwnerRecord();
                        $ids = $this->getFilteredTableQuery()->pluck('employee_schedule_assignments.id')->all();

                        Notification::make()
                            ->success()
                            ->title('Exportación lista')
                            ->body('El historial de asignaciones se está descargando.')
                            ->send();

                        return Excel::download(
                            new ScheduleAssignmentsExport($schedule->id, $ids),
                            'asignaciones_'.str($schedule->name)->slug().'_'.now()->format('Y_m_d').'.xlsx'
                        );
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
