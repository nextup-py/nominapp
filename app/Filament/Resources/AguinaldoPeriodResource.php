<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AguinaldoPeriodResource\Pages;
use App\Filament\Resources\AguinaldoPeriodResource\RelationManagers\AguinaldosRelationManager;
use App\Filament\Resources\AguinaldoPeriodResource\RelationManagers\AuditsRelationManager;
use App\Filament\Traits\HasModuleAccess;
use App\Models\AguinaldoPeriod;
use App\Models\Company;
use App\Services\AguinaldoService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists\Components\Group;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;

class AguinaldoPeriodResource extends Resource
{
    use HasModuleAccess;

    protected static string $moduleFlag = 'aguinaldo_enabled';

    protected static ?string $model = AguinaldoPeriod::class;

    protected static ?string $navigationGroup = 'Nóminas';

    protected static ?string $navigationLabel = 'Aguinaldo';

    protected static ?string $label = 'Período de Aguinaldo';

    protected static ?string $pluralLabel = 'Períodos de Aguinaldo';

    protected static ?string $slug = 'aguinaldo-periodos';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 2;

    /**
     * Define el formulario para crear o editar un período de aguinaldo, con campos para seleccionar la empresa, el año y agregar notas, incluyendo validaciones personalizadas.
     */
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('company_id')
                    ->label('Empresa')
                    ->relationship('company', 'name', fn ($query) => $query->active())
                    ->getOptionLabelFromRecordUsing(
                        fn (Company $record) => $record->name.($record->trade_name ? ' ('.$record->trade_name.')' : '')
                    )
                    ->searchable(['name', 'trade_name'])
                    ->preload()
                    ->required()
                    ->native(false)
                    ->helperText('Selecciona la empresa para este período de aguinaldo'),

                TextInput::make('year')
                    ->label('Año')
                    ->numeric()
                    ->minValue(2000)
                    ->maxValue(2100)
                    ->default(now()->year)
                    ->required()
                    ->rules(fn (Get $get, ?AguinaldoPeriod $record): array => [
                        Rule::unique('aguinaldo_periods', 'year')
                            ->where('company_id', $get('company_id'))
                            ->ignore($record?->id),
                    ])
                    ->validationMessages([
                        'unique' => 'Ya existe un período de aguinaldo para esta empresa y año.',
                    ])
                    ->helperText('El año para el cual se generarán los aguinaldos'),

                Textarea::make('notes')
                    ->label('Notas')
                    ->placeholder('Observaciones o comentarios sobre este período de aguinaldo')
                    ->rows(3)
                    ->maxLength(65535)
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    /**
     * Define la tabla para listar los períodos de aguinaldo, con columnas, filtros y acciones personalizadas.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('company.name')
                    ->label('Empresa')
                    ->icon('heroicon-o-building-office')
                    ->iconColor('primary')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('year')
                    ->label('Año')
                    ->badge()
                    ->color('info')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('aguinaldos_count')
                    ->label('Generados')
                    ->counts('aguinaldos')
                    ->alignCenter()
                    ->badge()
                    ->color('success')
                    ->sortable(),

                TextColumn::make('aguinaldos_paid_count')
                    ->label('Pagados')
                    ->counts(['aguinaldos as aguinaldos_paid_count' => fn ($q) => $q->where('status', 'paid')])
                    ->alignCenter()
                    ->badge()
                    ->color('info')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => AguinaldoPeriod::getStatusLabel($state))
                    ->color(fn (string $state) => AguinaldoPeriod::getStatusColor($state))
                    ->icon(fn (string $state) => AguinaldoPeriod::getStatusIcon($state))
                    ->sortable(),

                TextColumn::make('closed_at')
                    ->label('Cerrado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('Actualizado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('company_id')
                    ->label('Empresa')
                    ->relationship('company', 'name', fn ($query) => $query->active())
                    ->getOptionLabelFromRecordUsing(
                        fn (Company $record) => $record->name.($record->trade_name ? ' ('.$record->trade_name.')' : '')
                    )
                    ->searchable(['name', 'trade_name'])
                    ->preload()
                    ->native(false),

                SelectFilter::make('year')
                    ->label('Año')
                    ->options(function () {
                        $years = [];
                        for ($y = now()->year + 1; $y >= now()->year - 5; $y--) {
                            $years[$y] = $y;
                        }

                        return $years;
                    })
                    ->native(false),
            ])
            ->actions([
                Action::make('generate_aguinaldos')
                    ->label('Generar')
                    ->icon('heroicon-o-document-plus')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('¿Generar los aguinaldos?')
                    ->modalDescription(
                        fn (AguinaldoPeriod $record) => 'Esta acción generará los aguinaldos correspondientes al período de '.$record->year.' para la empresa '.($record->company?->name ?? '—').'. Si ya existen aguinaldos generados para este período, no se generarán duplicados.'
                    )
                    ->modalSubmitActionLabel('Sí, generar')
                    ->action(function (AguinaldoPeriod $record, AguinaldoService $aguinaldoService) {
                        $count = $aguinaldoService->generateForPeriod($record);

                        if ($count > 0) {
                            $record->update(['status' => 'processing']);

                            Notification::make()
                                ->success()
                                ->title('Aguinaldos generados')
                                ->body('Se generaron exitosamente '.$count.' aguinaldos para el período '.$record->year.' de '.($record->company?->name ?? '—').'.')
                                ->send();
                        } else {
                            Notification::make()
                                ->warning()
                                ->title('No se generaron aguinaldos')
                                ->body('Ya fueron generados o no hay nóminas para el período '.$record->year.' de '.($record->company?->name ?? '—').'.')
                                ->send();
                        }
                    })
                    ->visible(fn (AguinaldoPeriod $record) => $record->isDraft() && auth()->user()->can('generate_aguinaldos_period')),
            ])
            ->defaultSort('year', 'desc')
            ->emptyStateHeading('No hay períodos de aguinaldo registrados')
            ->emptyStateDescription('Comienza creando un período de aguinaldo para generar los recibos del 13° salario.')
            ->emptyStateIcon('heroicon-o-rectangle-stack');
    }

    /**
     * Define la infolist para mostrar los detalles de un período de aguinaldo, incluyendo información general, estado, resumen y datos del sistema.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                InfolistSection::make('Información del Período')
                    ->schema([
                        Group::make([
                            TextEntry::make('company.name')
                                ->label('Empresa')
                                ->icon('heroicon-o-building-office'),

                            TextEntry::make('year')
                                ->label('Año')
                                ->badge()
                                ->color('info'),
                        ])->columns(2),
                    ]),

                InfolistSection::make('Estado del Período')
                    ->schema([
                        Group::make([
                            TextEntry::make('status')
                                ->label('Estado')
                                ->badge()
                                ->formatStateUsing(fn (string $state) => AguinaldoPeriod::getStatusLabel($state))
                                ->color(fn (string $state) => AguinaldoPeriod::getStatusColor($state))
                                ->icon(fn (string $state) => AguinaldoPeriod::getStatusIcon($state)),

                            TextEntry::make('closed_at')
                                ->label('Fecha de Cierre')
                                ->dateTime('d/m/Y H:i')
                                ->icon('heroicon-o-lock-closed')
                                ->placeholder('No cerrado'),
                        ])->columns(2),

                        TextEntry::make('notes')
                            ->label('Notas')
                            ->placeholder('Sin notas')
                            ->columnSpanFull(),
                    ]),

                InfolistSection::make('Resumen')
                    ->schema([
                        Group::make([
                            TextEntry::make('aguinaldos_count')
                                ->label('Total Generados')
                                ->state(fn (AguinaldoPeriod $record) => $record->aguinaldos()->count())
                                ->badge()
                                ->color('success'),

                            TextEntry::make('aguinaldos_pending_count')
                                ->label('Pendientes de Pago')
                                ->state(fn (AguinaldoPeriod $record) => $record->pending_aguinaldos_count)
                                ->badge()
                                ->color('warning'),

                            TextEntry::make('total_amount')
                                ->label('Monto Total')
                                ->state(fn (AguinaldoPeriod $record) => 'Gs. '.number_format($record->aguinaldos()->sum('aguinaldo_amount'), 0, ',', '.'))
                                ->weight('bold')
                                ->color('success'),
                        ])->columns(3),
                    ]),

                InfolistSection::make('Información del Sistema')
                    ->schema([
                        Group::make([
                            TextEntry::make('created_at')
                                ->label('Creado')
                                ->dateTime('d/m/Y H:i'),

                            TextEntry::make('updated_at')
                                ->label('Actualizado')
                                ->dateTime('d/m/Y H:i'),
                        ])->columns(2),
                    ])
                    ->collapsed(),
            ]);
    }

    /**
     * Define las relaciones para el recurso, en este caso la relación con los aguinaldos generados para cada período.
     */
    public static function getRelations(): array
    {
        return [
            AguinaldosRelationManager::class,
            AuditsRelationManager::class,
        ];
    }

    /**
     * Define las páginas para el recurso, incluyendo la página de listado, creación, visualización y edición de períodos de aguinaldo.
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAguinaldoPeriods::route('/'),
            'create' => Pages\CreateAguinaldoPeriod::route('/create'),
            'view' => Pages\ViewAguinaldoPeriod::route('/{record}'),
            'edit' => Pages\EditAguinaldoPeriod::route('/{record}/edit'),
            'provision' => Pages\ProvisionReport::route('/{record}/provision'),
        ];
    }
}
