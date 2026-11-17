<?php

namespace App\Filament\Resources\EmployeeResource\RelationManagers;

use App\Filament\Traits\HasVigenciaActions;
use App\Models\EmployeeDeduction;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Gestiona las deducciones asignadas al empleado desde su vista de detalle. */
class EmployeeDeductionsRelationManager extends RelationManager
{
    use HasVigenciaActions;

    protected static string $relationship = 'employeeDeductions';

    protected static ?string $title = 'Deducciones';

    protected static ?string $modelLabel = 'Deducción';

    protected static ?string $pluralModelLabel = 'Deducciones';

    public function isReadOnly(): bool
    {
        return false;
    }

    protected function getEntityField(): string
    {
        return 'deduction_id';
    }

    protected function getEntityName(): string
    {
        return 'Deducción';
    }

    protected function getEntityModelClass(): string
    {
        return EmployeeDeduction::class;
    }

    /**
     * Define el formulario para crear o editar las asignaciones de deducciones a empleados.
     */
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('deduction_id')
                    ->label('Deducción')
                    ->relationship(
                        name: 'deduction',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query) => $query->where('is_active', true)->orderBy('name')
                    )
                    ->getOptionLabelFromRecordUsing(
                        fn (Model $record) => $record->name.' ('.
                            ($record->isFixed()
                                ? '₲ '.number_format($record->amount, 0, ',', '.')
                                : $record->percent.'%'
                            ).')'
                    )
                    ->required()
                    ->searchable(['name', 'code'])
                    ->preload()
                    ->live()
                    ->native(false)
                    ->afterStateUpdated(fn (Set $set) => $set('custom_amount', null))
                    ->hiddenOn('edit')
                    ->helperText('Solo se pueden asignar deducciones que estén activas en el sistema.'),

                ...$this->vigenciaFormSchema(),
            ])
            ->columns(2);
    }

    /**
     * Define la tabla para mostrar las asignaciones de deducciones a empleados.
     */
    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('deduction'))
            ->columns([
                TextColumn::make('deduction.code')
                    ->label('Código')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->tooltip('Copiar código al portapapeles')
                    ->copyMessage('Código copiado al portapapeles')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('deduction.name')
                    ->label('Deducción')
                    ->sortable()
                    ->searchable()
                    ->wrap(),

                $this->vigenciaCalculationColumn(),
                $this->vigenciaAmountColumn(),
                ...$this->vigenciaDateColumns(),
            ])
            ->filters([$this->vigenciaEstadoFilter()])
            ->headerActions([
                Action::make('assign_mandatory')
                    ->authorize(fn (): bool => auth()->user()?->can('update_employee') ?? false)
                    ->label('Asignar obligatorias')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Asignar deducciones obligatorias')
                    ->modalDescription('¿Deseas asignar las deducciones obligatorias al empleado? Solo se asignarán las que aún no tenga.')
                    ->modalSubmitActionLabel('Sí, asignar')
                    ->action(function () {
                        $employee = $this->getOwnerRecord();
                        $assignedCount = $employee->assignMandatoryDeductions();

                        if ($assignedCount === 0) {
                            Notification::make()
                                ->info()
                                ->title('Sin cambios')
                                ->body('El empleado ya tiene todas las deducciones obligatorias asignadas, o no hay ninguna activa.')
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title('Deducciones asignadas exitosamente')
                            ->body('Se han asignado '.$assignedCount.' '.
                                ($assignedCount === 1 ? 'deducción obligatoria' : 'deducciones obligatorias').
                                ' a '.$employee->full_name.'.')
                            ->send();
                    }),

                CreateAction::make()
                    ->label('Agregar deducción')
                    ->icon('heroicon-o-plus')
                    ->before($this->vigenciaCreateBefore())
                    ->successNotificationTitle('Deducción asignada exitosamente'),
            ])
            ->actions([
                $this->vigenciaDeactivateAction(),
                $this->vigenciaReactivateAction(),
                ActionGroup::make([
                    EditAction::make()
                        ->label('Editar')
                        ->icon('heroicon-o-pencil-square')
                        ->color('primary')
                        ->modalHeading('Editar Asignación')
                        ->before($this->vigenciaEditBefore())
                        ->successNotificationTitle('Asignación actualizada exitosamente'),

                    DeleteAction::make()
                        ->label('Eliminar')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->modalHeading('¿Eliminar deducción?')
                        ->modalSubmitActionLabel('Sí, eliminar')
                        ->modalDescription('¿Está seguro de que desea eliminar permanentemente esta asignación del historial?')
                        ->successNotificationTitle('Asignación eliminada del historial'),
                ]),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    $this->vigenciaDeactivateBulkAction(),
                    $this->vigenciaReactivateBulkAction(),
                    DeleteBulkAction::make()
                        ->label('Eliminar del historial')
                        ->modalHeading('Eliminar Asignaciones')
                        ->modalDescription('¿Está seguro de que desea eliminar permanentemente estas asignaciones del historial?')
                        ->successNotificationTitle('Asignaciones eliminadas del historial'),
                ]),
            ])
            ->defaultSort('start_date', 'desc')
            ->emptyStateHeading('No hay deducciones')
            ->emptyStateDescription('Comienza agregando una deducción al empleado')
            ->emptyStateIcon('heroicon-o-minus-circle');
    }
}
