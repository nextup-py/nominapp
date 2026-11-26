<?php

namespace App\Filament\Resources\ScheduleResource\RelationManagers;

use App\Exports\ScheduleEmployeesExport;
use App\Filament\Actions\ScheduleAssignmentActions;
use App\Filament\Resources\EmployeeResource;
use App\Filament\Resources\ScheduleResource;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Services\ScheduleAssignmentService;
use Carbon\Carbon;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\DatePicker;
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
                    'employee_schedule_assignments.valid_until as assignment_end_date',
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
                    ->placeholder('Sin cargo')
                    ->badge()
                    ->color('info'),

                TextColumn::make('branch.name')
                    ->label('Sucursal')
                    ->icon('heroicon-o-building-storefront')
                    ->placeholder('Sin sucursal')
                    ->badge()
                    ->color('success'),

                TextColumn::make('branch.company.name')
                    ->label('Empresa')
                    ->icon('heroicon-o-building-office-2')
                    ->placeholder('Sin empresa')
                    ->badge()
                    ->color('primary')
                    ->visible(fn () => Company::active()->count() > 1),

                TextColumn::make('assignment_start_date')
                    ->label('Vigente desde')
                    ->description(fn ($record) => Carbon::parse($record->assignment_start_date)->diffForHumans())
                    ->date('d/m/Y')
                    ->sortable()
                    ->alignCenter(),

                TextColumn::make('assignment_end_date')
                    ->label('Hasta')
                    ->date('d/m/Y')
                    ->placeholder('Sin fecha de fin')
                    ->badge()
                    ->color(fn ($state) => $state ? 'warning' : 'gray')
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

                SelectFilter::make('position_id')
                    ->label('Cargo')
                    ->options(fn () => Position::query()->with('department')->orderBy('name')->get()
                        ->mapWithKeys(fn (Position $position) => [$position->id => $position->name.($position->department ? ' - '.$position->department->name : '')])
                        ->all())
                    ->searchable()
                    ->native(false)
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('activeContract', fn (Builder $contract) => $contract->where('position_id', (int) $data['value']))
                        : $query),

                SelectFilter::make('department_id')
                    ->label('Departamento')
                    ->options(fn () => Department::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->native(false)
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('activeContract.position', fn (Builder $position) => $position->where('department_id', (int) $data['value']))
                        : $query),
            ])
            ->headerActions([
                Action::make('export')
                    ->authorize(fn (): bool => (bool) auth()->user()?->can('view_any_schedule'))
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
                    ->authorize(fn (): bool => ScheduleResource::canManageAssignments())
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
                    ->authorize(fn (): bool => ScheduleResource::canManageAssignments())
                    ->label('Remover')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->modalHeading('Remover Empleado del Horario')
                    ->modalDescription(fn ($record) => "{$record->full_name} dejará de tener este horario. La asignación queda en el historial.")
                    ->form(self::removalFields())
                    ->modalSubmitActionLabel('Sí, remover')
                    ->action(function (Employee $record, array $data) {
                        $until = Carbon::parse($data['valid_until']);
                        $closed = ScheduleAssignmentService::closeActive($record, $until);

                        Notification::make()
                            ->{$closed > 0 ? 'success' : 'warning'}()
                            ->title($closed > 0 ? 'Empleado removido' : 'No se pudo remover')
                            ->body($closed > 0
                                ? "{$record->full_name} tendrá este horario hasta el {$until->format('d/m/Y')} inclusive."
                                : 'La fecha elegida es anterior al inicio de la asignación.')
                            ->send();
                    }),
            ])
            ->bulkActions([
                BulkAction::make('remove')
                    ->authorize(fn (): bool => ScheduleResource::canManageAssignments())
                    ->label('Remover seleccionados')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->modalHeading('Remover Empleados del Horario')
                    ->modalDescription('Los empleados seleccionados dejarán de tener este horario. Las asignaciones quedan en el historial.')
                    ->form(self::removalFields())
                    ->modalSubmitActionLabel('Sí, remover')
                    ->action(function ($records, array $data) {
                        $until = Carbon::parse($data['valid_until']);
                        $closed = 0;

                        foreach ($records as $record) {
                            $closed += ScheduleAssignmentService::closeActive($record, $until);
                        }

                        $skipped = $records->count() - $closed;

                        Notification::make()
                            ->success()
                            ->title('Empleados removidos')
                            ->body("Se cerró la asignación de {$closed} empleado(s); tendrán este horario hasta el {$until->format('d/m/Y')} inclusive."
                                .($skipped > 0 ? " {$skipped} no se modificaron (la fecha es anterior al inicio de su asignación)." : ''))
                            ->send();
                    }),
            ])
            ->defaultSort('assignment_start_date', 'desc')
            ->emptyStateHeading('No hay empleados asignados')
            ->emptyStateDescription('Comience asignando empleados a este horario. Los empleados que se quitaron figuran en «Historial de asignaciones».')
            ->emptyStateIcon('heroicon-o-user-group');
    }

    /**
     * Campo de las acciones de remover: último día en que la persona conserva este horario (inclusive).
     * Por defecto hoy; una fecha futura programa el cambio.
     *
     * @return array<int, Component>
     */
    private static function removalFields(): array
    {
        return [
            DatePicker::make('valid_until')
                ->label('Tiene este horario hasta')
                ->helperText('Último día, inclusive. Con hoy o una fecha pasada deja de figurar en esta lista; una fecha futura programa el cambio.')
                ->default(Carbon::today())
                ->native(false)
                ->required(),
        ];
    }
}
