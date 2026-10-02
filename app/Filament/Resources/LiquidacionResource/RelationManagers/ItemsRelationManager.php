<?php

namespace App\Filament\Resources\LiquidacionResource\RelationManagers;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Desglose de la Liquidación';

    protected static ?string $modelLabel = 'concepto';

    protected static ?string $pluralModelLabel = 'conceptos';

    public function canCreate(): bool
    {
        return false;
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('type')
                ->label('Tipo')
                ->options([
                    'haber' => 'Haber',
                    'deduction' => 'Descuento',
                ])
                ->native(false)
                ->required(),

            Select::make('category')
                ->label('Categoría')
                ->options([
                    'preaviso' => 'Preaviso',
                    'indemnizacion' => 'Indemnización',
                    'vacaciones' => 'Vacaciones',
                    'aguinaldo' => 'Aguinaldo',
                    'salario_pendiente' => 'Salario Pendiente',
                    'descanso_semanal' => 'Descanso Semanal',
                    'ausencias' => 'Ausencias',
                    'suspension' => 'Suspensión Disciplinaria',
                    'ips' => 'IPS',
                    'loan' => 'Préstamo',
                    'other' => 'Otro',
                ])
                ->native(false)
                ->required(),

            TextInput::make('description')
                ->label('Descripción')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),

            TextInput::make('amount')
                ->label('Monto')
                ->numeric()
                ->required()
                ->prefix('Gs.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'haber' => 'success',
                        'deduction' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'haber' => 'Haber',
                        'deduction' => 'Descuento',
                        default => $state,
                    }),

                TextColumn::make('category')
                    ->label('Categoría')
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'preaviso' => 'Preaviso',
                        'indemnizacion' => 'Indemnización',
                        'vacaciones' => 'Vacaciones',
                        'aguinaldo' => 'Aguinaldo',
                        'salario_pendiente' => 'Salario Pendiente',
                        'descanso_semanal' => 'Descanso Semanal',
                        'ausencias' => 'Ausencias',
                        'suspension' => 'Suspensión Disciplinaria',
                        'ips' => 'IPS',
                        'loan' => 'Préstamo',
                        'other' => 'Otro',
                        default => $state,
                    }),

                TextColumn::make('description')
                    ->label('Descripción')
                    ->wrap(),

                TextColumn::make('amount')
                    ->label('Monto')
                    ->money('PYG', locale: 'es_PY')
                    ->weight('bold')
                    ->color(fn ($record) => $record->type === 'haber' ? 'success' : 'danger')
                    ->summarize([
                        Summarizer::make()
                            ->label('Total Haberes')
                            ->using(fn ($query) => $query->where('type', 'haber')->sum('amount'))
                            ->money('PYG', locale: 'es_PY'),
                        Summarizer::make()
                            ->label('Total Descuentos')
                            ->using(fn ($query) => $query->where('type', 'deduction')->sum('amount'))
                            ->money('PYG', locale: 'es_PY'),
                    ]),
            ])
            ->actions([
                EditAction::make()
                    ->label('Editar')
                    ->icon('heroicon-o-pencil-square')
                    ->color('primary')
                    ->visible(fn () => ! $this->getOwnerRecord()->isClosed())
                    ->after(fn () => $this->getOwnerRecord()->recalculateTotals()),
                DeleteAction::make()
                    ->label('Eliminar')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->modalHeading('¿Eliminar ítem?')
                    ->modalSubmitActionLabel('Sí, eliminar')
                    ->visible(fn () => ! $this->getOwnerRecord()->isClosed())
                    ->after(fn () => $this->getOwnerRecord()->recalculateTotals()),
            ])
            ->paginated(false)
            ->emptyStateHeading('Sin conceptos calculados')
            ->emptyStateDescription('Los conceptos aparecerán aquí una vez que se calcule la liquidación.')
            ->emptyStateIcon('heroicon-o-calculator');
    }
}
