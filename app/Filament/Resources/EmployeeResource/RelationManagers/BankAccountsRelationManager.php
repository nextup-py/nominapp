<?php

namespace App\Filament\Resources\EmployeeResource\RelationManagers;

use App\Models\EmployeeBankAccount;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Gestiona las cuentas bancarias del empleado desde su vista de detalle. */
class BankAccountsRelationManager extends RelationManager
{
    protected static string $relationship = 'bankAccounts';

    protected static ?string $title = 'Cuentas Bancarias';

    protected static ?string $modelLabel = 'cuenta bancaria';

    protected static ?string $pluralModelLabel = 'cuentas bancarias';

    public function isReadOnly(): bool
    {
        return false;
    }

    /**
     * Define el formulario para crear y editar cuentas bancarias.
     */
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('bank')
                    ->label('Banco')
                    ->options(EmployeeBankAccount::getBankOptions())
                    ->native(false)
                    ->searchable()
                    ->required()
                    ->columnSpanFull(),

                TextInput::make('account_number')
                    ->label('Número de cuenta')
                    ->required()
                    ->maxLength(30)
                    ->placeholder('Ej: 00123456789'),

                Select::make('account_type')
                    ->label('Tipo de cuenta')
                    ->options(EmployeeBankAccount::getAccountTypeOptions())
                    ->native(false)
                    ->required(),

                TextInput::make('holder_name')
                    ->label('Nombre del titular')
                    ->required()
                    ->maxLength(150)
                    ->default(fn () => $this->getOwnerRecord()->full_name),

                TextInput::make('holder_ci')
                    ->label('CI del titular')
                    ->integer()
                    ->minValue(1)
                    ->maxValue(99999999)
                    ->default(fn () => $this->getOwnerRecord()->ci)
                    ->helperText('Sin puntos ni guiones.'),

                Toggle::make('is_primary')
                    ->label('Cuenta principal')
                    ->helperText('La cuenta principal se preselecciona al generar nóminas por transferencia.')
                    ->default(fn () => $this->getOwnerRecord()->bankAccounts()->count() === 0),

                Select::make('status')
                    ->label('Estado')
                    ->options(EmployeeBankAccount::getStatusOptions())
                    ->native(false)
                    ->default('active')
                    ->required()
                    ->hiddenOn('create'),
            ])
            ->columns(2);
    }

    /**
     * Define la tabla de cuentas bancarias con columnas, filtros y acciones.
     */
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('account_number')
            ->columns([
                TextColumn::make('bank')
                    ->label('Banco')
                    ->formatStateUsing(fn ($state) => EmployeeBankAccount::getBankLabel($state))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('account_number')
                    ->label('Número de cuenta')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Número copiado'),

                TextColumn::make('account_type')
                    ->label('Tipo')
                    ->formatStateUsing(fn ($state) => EmployeeBankAccount::getAccountTypeLabel($state))
                    ->badge()
                    ->color('info'),

                TextColumn::make('holder_name')
                    ->label('Titular')
                    ->description(fn (EmployeeBankAccount $record) => $record->holder_ci ? "CI: {$record->holder_ci}" : null)
                    ->searchable()
                    ->toggleable(),

                IconColumn::make('is_primary')
                    ->label('Principal')
                    ->boolean()
                    ->trueIcon('heroicon-o-star')
                    ->falseIcon('heroicon-o-star')
                    ->trueColor('warning')
                    ->falseColor('gray'),

                TextColumn::make('status')
                    ->label('Estado')
                    ->formatStateUsing(fn ($state) => EmployeeBankAccount::getStatusLabel($state))
                    ->badge()
                    ->color(fn ($state) => EmployeeBankAccount::getStatusColor($state)),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(EmployeeBankAccount::getStatusOptions()),

                SelectFilter::make('account_type')
                    ->label('Tipo de cuenta')
                    ->options(EmployeeBankAccount::getAccountTypeOptions()),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Nueva Cuenta')
                    ->icon('heroicon-o-plus')
                    ->modalHeading('Registrar cuenta bancaria')
                    ->modalSubmitActionLabel('Guardar')
                    ->modalWidth('xl')
                    ->after(function (EmployeeBankAccount $record) {
                        // Si se creó como principal, desmarcar las demás
                        if ($record->is_primary) {
                            EmployeeBankAccount::where('employee_id', $record->employee_id)
                                ->where('id', '!=', $record->id)
                                ->update(['is_primary' => false]);
                        }
                    }),
            ])
            ->actions([
                Action::make('mark_primary')
                    ->authorize(fn (): bool => auth()->user()?->can('update_employee') ?? false)
                    ->label('Marcar como principal')
                    ->icon('heroicon-o-star')
                    ->color('warning')
                    ->visible(fn (EmployeeBankAccount $record) => ! $record->is_primary && $record->isActive())
                    ->requiresConfirmation()
                    ->modalHeading('Marcar como cuenta principal')
                    ->modalDescription('Esta cuenta será la preseleccionada al generar nóminas con pago por transferencia.')
                    ->modalSubmitActionLabel('Sí, marcar como principal')
                    ->action(function (EmployeeBankAccount $record) {
                        $result = $record->markAsPrimary();

                        Notification::make()
                            ->{$result['success'] ? 'success' : 'danger'}()
                            ->title($result['success'] ? 'Cuenta principal actualizada' : 'Error')
                            ->body($result['message'])
                            ->send();
                    }),

                Action::make('toggle_status')
                    ->authorize(fn (): bool => auth()->user()?->can('update_employee') ?? false)
                    ->label(fn (EmployeeBankAccount $record) => $record->isActive() ? 'Desactivar' : 'Reactivar')
                    ->icon(fn (EmployeeBankAccount $record) => $record->isActive() ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
                    ->color(fn (EmployeeBankAccount $record) => $record->isActive() ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->modalHeading(fn (EmployeeBankAccount $record) => $record->isActive() ? 'Desactivar cuenta' : 'Reactivar cuenta')
                    ->modalDescription(fn (EmployeeBankAccount $record) => $record->isActive()
                        ? 'La cuenta quedará inactiva y no podrá usarse en nuevas nóminas.'
                        : 'La cuenta volverá a estar disponible para usarse en nóminas.')
                    ->modalSubmitActionLabel(fn (EmployeeBankAccount $record) => $record->isActive() ? 'Sí, desactivar' : 'Sí, reactivar')
                    ->action(function (EmployeeBankAccount $record) {
                        $result = $record->isActive() ? $record->deactivate() : $record->reactivate();

                        Notification::make()
                            ->{$result['success'] ? 'success' : 'danger'}()
                            ->title($result['success'] ? 'Estado actualizado' : 'Error')
                            ->body($result['message'])
                            ->send();
                    }),

                EditAction::make()
                    ->label('Editar')
                    ->icon('heroicon-o-pencil-square')
                    ->color('primary')
                    ->modalHeading('Editar cuenta bancaria')
                    ->modalSubmitActionLabel('Guardar cambios')
                    ->modalWidth('xl')
                    ->after(function (EmployeeBankAccount $record) {
                        if ($record->is_primary) {
                            EmployeeBankAccount::where('employee_id', $record->employee_id)
                                ->where('id', '!=', $record->id)
                                ->update(['is_primary' => false]);
                        }
                    }),

                DeleteAction::make()
                    ->label('Eliminar')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->modalHeading('¿Eliminar cuenta bancaria?')
                    ->modalDescription('¿Estás seguro? Solo se puede eliminar si la cuenta nunca fue usada en una nómina.')
                    ->modalSubmitActionLabel('Sí, eliminar')
                    ->before(function (EmployeeBankAccount $record, DeleteAction $action) {
                        if ($record->payrolls()->exists()) {
                            Notification::make()
                                ->danger()
                                ->title('No se puede eliminar')
                                ->body('Esta cuenta fue utilizada en una o más nóminas. Podés desactivarla en su lugar.')
                                ->send();

                            $action->cancel();
                        }
                    }),
            ])
            ->defaultSort('is_primary', 'desc')
            ->emptyStateHeading('Sin cuentas bancarias')
            ->emptyStateDescription('Registrá las cuentas bancarias del empleado para la acreditación de salarios.')
            ->emptyStateIcon('heroicon-o-building-library');
    }
}
