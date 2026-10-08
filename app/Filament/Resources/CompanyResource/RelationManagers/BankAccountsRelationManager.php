<?php

namespace App\Filament\Resources\CompanyResource\RelationManagers;

use App\Models\CompanyBankAccount;
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

/** Gestiona las cuentas bancarias de la empresa desde su vista de detalle. */
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
                    ->options(CompanyBankAccount::getBankOptions())
                    ->native(false)
                    ->searchable()
                    ->required()
                    ->columnSpanFull(),

                TextInput::make('bank_company_id')
                    ->label('ID Empresa (banco)')
                    ->maxLength(50)
                    ->placeholder('Ej: 12345')
                    ->helperText('ID asignado por el banco a la empresa para pagos en lote.')
                    ->columnSpanFull(),

                TextInput::make('account_number')
                    ->label('Número de cuenta')
                    ->required()
                    ->maxLength(30)
                    ->placeholder('Ej: 00123456789'),

                Select::make('account_type')
                    ->label('Tipo de cuenta')
                    ->options(CompanyBankAccount::getAccountTypeOptions())
                    ->native(false)
                    ->required(),

                TextInput::make('holder_name')
                    ->label('Nombre del titular')
                    ->required()
                    ->maxLength(150)
                    ->default(fn () => $this->getOwnerRecord()->name),

                TextInput::make('holder_ci')
                    ->label('CI / RUC del titular')
                    ->maxLength(30)
                    ->default(fn () => $this->getOwnerRecord()->ruc)
                    ->helperText('Sin puntos ni guiones.'),

                Toggle::make('is_primary')
                    ->label('Cuenta principal')
                    ->helperText('La cuenta principal aparece en documentos y reportes de nómina.')
                    ->default(fn () => $this->getOwnerRecord()->bankAccounts()->count() === 0),

                Select::make('status')
                    ->label('Estado')
                    ->options(CompanyBankAccount::getStatusOptions())
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
                    ->formatStateUsing(fn ($state) => CompanyBankAccount::getBankLabel($state))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('account_number')
                    ->label('Número de cuenta')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Número copiado'),

                TextColumn::make('bank_company_id')
                    ->label('ID Empresa (banco)')
                    ->placeholder('Sin ID')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('account_type')
                    ->label('Tipo')
                    ->formatStateUsing(fn ($state) => CompanyBankAccount::getAccountTypeLabel($state))
                    ->badge()
                    ->color('info'),

                TextColumn::make('holder_name')
                    ->label('Titular')
                    ->description(fn (CompanyBankAccount $record) => $record->holder_ci ? "CI/RUC: {$record->holder_ci}" : null)
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
                    ->formatStateUsing(fn ($state) => CompanyBankAccount::getStatusLabel($state))
                    ->badge()
                    ->color(fn ($state) => CompanyBankAccount::getStatusColor($state)),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(CompanyBankAccount::getStatusOptions()),

                SelectFilter::make('account_type')
                    ->label('Tipo de cuenta')
                    ->options(CompanyBankAccount::getAccountTypeOptions()),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Nueva Cuenta')
                    ->icon('heroicon-o-plus')
                    ->modalHeading('Registrar cuenta bancaria')
                    ->modalSubmitActionLabel('Guardar')
                    ->modalWidth('xl')
                    ->after(function (CompanyBankAccount $record) {
                        if ($record->is_primary) {
                            CompanyBankAccount::where('company_id', $record->company_id)
                                ->where('id', '!=', $record->id)
                                ->update(['is_primary' => false]);
                        }
                    }),
            ])
            ->actions([
                Action::make('mark_primary')
                    ->label('Marcar como principal')
                    ->icon('heroicon-o-star')
                    ->color('warning')
                    ->visible(fn (CompanyBankAccount $record) => ! $record->is_primary && $record->isActive())
                    ->requiresConfirmation()
                    ->modalHeading('Marcar como cuenta principal')
                    ->modalDescription('Esta cuenta será la que aparezca en documentos y reportes de nómina.')
                    ->modalSubmitActionLabel('Sí, marcar como principal')
                    ->action(function (CompanyBankAccount $record) {
                        $result = $record->markAsPrimary();

                        Notification::make()
                            ->{$result['success'] ? 'success' : 'danger'}()
                            ->title($result['success'] ? 'Cuenta principal actualizada' : 'Error')
                            ->body($result['message'])
                            ->send();
                    }),

                Action::make('toggle_status')
                    ->label(fn (CompanyBankAccount $record) => $record->isActive() ? 'Desactivar' : 'Reactivar')
                    ->icon(fn (CompanyBankAccount $record) => $record->isActive() ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
                    ->color(fn (CompanyBankAccount $record) => $record->isActive() ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->modalHeading(fn (CompanyBankAccount $record) => $record->isActive() ? 'Desactivar cuenta' : 'Reactivar cuenta')
                    ->modalDescription(fn (CompanyBankAccount $record) => $record->isActive()
                        ? 'La cuenta quedará inactiva y no aparecerá en nuevos documentos.'
                        : 'La cuenta volverá a estar disponible.')
                    ->modalSubmitActionLabel(fn (CompanyBankAccount $record) => $record->isActive() ? 'Sí, desactivar' : 'Sí, reactivar')
                    ->action(function (CompanyBankAccount $record) {
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
                    ->after(function (CompanyBankAccount $record) {
                        if ($record->is_primary) {
                            CompanyBankAccount::where('company_id', $record->company_id)
                                ->where('id', '!=', $record->id)
                                ->update(['is_primary' => false]);
                        }
                    }),

                DeleteAction::make()
                    ->label('Eliminar')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->modalHeading('¿Eliminar cuenta bancaria?')
                    ->modalDescription(fn (CompanyBankAccount $record) => $record->deletionBlocker()
                        ?? ($record->is_primary
                            ? 'Es la única cuenta de la empresa: al eliminarla quedará sin cuenta bancaria principal y no se podrán generar lotes de pago. Esta acción no se puede deshacer.'
                            : '¿Estás seguro? Esta acción no se puede deshacer.'))
                    ->modalSubmitActionLabel('Sí, eliminar')
                    ->before(function (CompanyBankAccount $record, DeleteAction $action) {
                        if (($message = $record->deletionBlocker()) !== null) {
                            Notification::make()->danger()->title('No se puede eliminar')->body($message)->send();
                            $action->halt();
                        }
                    }),
            ])
            ->bulkActions([])
            ->defaultSort('is_primary', 'desc')
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading('Sin cuentas bancarias')
            ->emptyStateDescription('Registrá las cuentas bancarias de la empresa.')
            ->emptyStateIcon('heroicon-o-building-library');
    }
}
