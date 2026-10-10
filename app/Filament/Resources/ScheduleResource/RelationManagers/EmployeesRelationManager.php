<?php

namespace App\Filament\Resources\ScheduleResource\RelationManagers;

use App\Exports\ScheduleEmployeesExport;
use App\Filament\Actions\ScheduleAssignmentActions;
use App\Filament\Resources\EmployeeResource;
use App\Models\Branch;
use App\Models\Employee;
use App\Services\ScheduleAssignmentService;
use Carbon\Carbon;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;

/** Muestra y gestiona los empleados con asignación activa al horario, usando el sistema de employee_schedule_assignments. */
class EmployeesRelationManager extends RelationManager
{
    protected static string $relationship = 'currentEmployees';

    protected static ?string $title = 'Empleados Asignados';

    protected static ?string $modelLabel = 'empleado';

    protected static ?string $pluralModelLabel = 'empleados';

    public function isReadOnly(): bool
    {
        return false;
    }

    /**
     * Formulario para asignar un empleado activo al horario, con filtro opcional por sucursal.
     */
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('filter_branch')
                    ->label('Filtrar por Sucursal')
                    ->options(fn () => Branch::pluck('name', 'id'))
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(fn (callable $set) => $set('employee_ids', []))
                    ->columnSpanFull(),

                Select::make('employee_ids')
                    ->label('Empleados')
                    ->options(function (callable $get) {
                        $scheduleId = $this->getOwnerRecord()->id;
                        $today = Carbon::today();

                        $query = Employee::query()->where('status', 'active');

                        $filterBranch = $get('filter_branch');
                        if ($filterBranch) {
                            $query->where('branch_id', $filterBranch);
                        }

                        return $query
                            ->orderBy('first_name')
                            ->orderBy('last_name')
                            ->get()
                            ->mapWithKeys(function (Employee $employee) use ($scheduleId, $today) {
                                $current = $employee->getScheduleForDate($today);

                                if ($current?->id === $scheduleId) {
                                    return [];
                                }

                                $label = "{$employee->full_name} - CI: {$employee->ci}";
                                if ($current) {
                                    $label .= " ⚠ (Horario actual: {$current->name})";
                                }

                                return [$employee->id => $label];
                            })
                            ->filter();
                    })
                    ->multiple()
                    ->searchable()
                    ->required()
                    ->native(false)
                    ->helperText('⚠ Los empleados con horario actual lo cambiarán a este horario')
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Tabla de empleados asignados actualmente al horario, con acciones para asignar y remover.
     */
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('first_name')
            ->recordUrl(fn (Employee $record) => EmployeeResource::getUrl('view', ['record' => $record]))
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->addSelect([
                    'employees.*',
                    'employee_schedule_assignments.valid_from as assignment_start_date',
                    'users.name as assignment_created_by',
                ])
                ->leftJoin('users', 'users.id', '=', 'employee_schedule_assignments.created_by')
                ->with(['activeContract.position.department', 'branch']))
            ->columns([
                ImageColumn::make('photo')
                    ->label('Foto')
                    ->circular()
                    ->defaultImageUrl(fn ($record) => $record->avatar_url),

                TextColumn::make('full_name')
                    ->label('Nombre completo')
                    ->getStateUsing(fn (Employee $record) => $record->first_name.' '.$record->last_name)
                    ->description(fn (Employee $record) => 'CI: '.$record->ci)
                    ->searchable(query: fn (Builder $query, string $search) => $query
                        ->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('ci', 'like', "%{$search}%")
                    )
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('activeContract.position.name')
                    ->label('Cargo')
                    ->icon('heroicon-o-briefcase')
                    ->default('-')
                    ->badge()
                    ->color('info'),

                TextColumn::make('branch.name')
                    ->label('Sucursal')
                    ->icon('heroicon-o-building-storefront')
                    ->default('-')
                    ->badge()
                    ->color('success'),

                TextColumn::make('branch.company.name')
                    ->label('Empresa')
                    ->icon('heroicon-o-building-office-2')
                    ->default('-')
                    ->badge()
                    ->color('primary'),

                TextColumn::make('assignment_start_date')
                    ->label('Vigente desde')
                    ->description(fn ($record) => Carbon::parse($record->assignment_start_date)->diffForHumans())
                    ->date('d/m/Y')
                    ->sortable()
                    ->alignCenter(),

                TextColumn::make('assignment_created_by')
                    ->label('Asignado por')
                    ->icon('heroicon-o-user')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('branch_id')
                    ->label('Sucursal')
                    ->relationship('branch', 'name')
                    ->searchable()
                    ->preload()
                    ->native(false),
            ])
            ->headerActions([
                Action::make('export')
                    ->label('Exportar')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('¿Exportar empleados del horario?')
                    ->modalDescription('Se exportarán todos los empleados con asignación vigente a este horario.')
                    ->modalSubmitActionLabel('Sí, exportar')
                    ->action(function () {
                        $schedule = $this->getOwnerRecord();

                        Notification::make()
                            ->success()
                            ->title('Exportación lista')
                            ->body('El listado de empleados se está descargando.')
                            ->send();

                        return Excel::download(
                            new ScheduleEmployeesExport($schedule->id),
                            'empleados_'.str($schedule->name)->slug().'_'.now()->format('Y_m_d').'.xlsx'
                        );
                    }),

                Action::make('assign')
                    ->label('Asignar')
                    ->icon('heroicon-o-user-plus')
                    ->color('primary')
                    ->modalHeading('Asignar Empleados al Horario')
                    ->modalSubmitActionLabel('Asignar')
                    ->modalWidth('2xl')
                    ->form(fn () => [...$this->form($this->makeForm())->getComponents(), ...ScheduleAssignmentActions::periodFields()])
                    ->action(function (array $data) {
                        $schedule = $this->getOwnerRecord();
                        $employees = Employee::whereIn('id', $data['employee_ids'])->get();
                        $assigned = 0;
                        $errors = [];
                        $assignedEmployees = collect();

                        foreach ($employees as $employee) {
                            try {
                                ScheduleAssignmentService::assign(
                                    employee: $employee,
                                    schedule: $schedule,
                                    validFrom: Carbon::parse($data['valid_from'])->startOfDay(),
                                    validUntil: filled($data['valid_until'] ?? null) ? Carbon::parse($data['valid_until'])->startOfDay() : null,
                                    notes: $data['notes'] ?? null,
                                );
                                $assigned++;
                                $assignedEmployees->push($employee);
                            } catch (\Exception) {
                                $errors[] = $employee->full_name;
                            }
                        }

                        if ($assigned > 0) {
                            Notification::make()
                                ->success()
                                ->title('Empleados asignados')
                                ->body("Se asignó \"{$schedule->name}\" a {$assigned} empleado(s) a partir del ".Carbon::parse($data['valid_from'])->format('d/m/Y').'.')
                                ->send();
                        }

                        if ($data['recalculate'] ?? false) {
                            ScheduleAssignmentActions::recalculateAssigned(
                                $assignedEmployees,
                                Carbon::parse($data['valid_from'])->startOfDay(),
                                filled($data['valid_until'] ?? null) ? Carbon::parse($data['valid_until'])->startOfDay() : null,
                            );
                        }

                        if (! empty($errors)) {
                            Notification::make()
                                ->warning()
                                ->title('Algunos empleados no pudieron asignarse')
                                ->body('Revisar solapamientos en: '.implode(', ', $errors))
                                ->persistent()
                                ->send();
                        }
                    }),
            ])
            ->actions([
                Action::make('remove')
                    ->label('Remover')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Remover Empleado del Horario')
                    ->modalDescription(fn ($record) => "¿Está seguro de que desea remover a {$record->full_name} de este horario?")
                    ->modalSubmitActionLabel('Sí, remover')
                    ->action(function (Employee $record) {
                        ScheduleAssignmentService::closeActive($record, Carbon::today());

                        Notification::make()
                            ->success()
                            ->title('Empleado removido')
                            ->body("Se cerró la asignación de {$record->full_name} con fecha de hoy.")
                            ->send();
                    }),
            ])
            ->bulkActions([
                BulkAction::make('remove')
                    ->label('Remover seleccionados')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Remover Empleados del Horario')
                    ->modalDescription('¿Está seguro de que desea remover los empleados seleccionados de este horario?')
                    ->modalSubmitActionLabel('Sí, remover')
                    ->action(function ($records) {
                        $count = $records->count();

                        foreach ($records as $record) {
                            ScheduleAssignmentService::closeActive($record, Carbon::today());
                        }

                        Notification::make()
                            ->success()
                            ->title('Empleados removidos')
                            ->body("Se cerró la asignación de {$count} empleado(s) con fecha de hoy.")
                            ->send();
                    }),
            ])
            ->defaultSort('assignment_start_date', 'desc')
            ->emptyStateHeading('No hay empleados asignados')
            ->emptyStateDescription('Comience asignando empleados a este horario.')
            ->emptyStateIcon('heroicon-o-user-group');
    }
}
