<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\UsesNavigationLayout;
use App\Filament\Resources\PayrollPeriodResource\Pages;
use App\Filament\Resources\PayrollPeriodResource\RelationManagers\AuditsRelationManager;
use App\Filament\Resources\PayrollPeriodResource\RelationManagers\PayrollsRelationManager;
use App\Models\Company;
use App\Models\PayrollPeriod;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Infolists\Components\Group;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PayrollPeriodResource extends Resource
{
    use UsesNavigationLayout;

    protected static ?string $model = PayrollPeriod::class;

    protected static ?string $navigationGroup = 'Nóminas';

    protected static ?string $navigationLabel = 'Planillas';

    protected static ?string $label = 'Planilla';

    protected static ?string $pluralLabel = 'Planillas';

    protected static ?string $slug = 'planillas';

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Información del Período')
                    ->schema([
                        Select::make('company_id')
                            ->label('Empresa')
                            ->options(Company::active()->orderBy('name')->pluck('name', 'id'))
                            ->native(false)
                            ->required()
                            ->searchable()
                            ->columnSpanFull(),

                        TextInput::make('name')
                            ->label('Nombre')
                            ->placeholder('Se generará automáticamente según la frecuencia y fechas')
                            ->maxLength(255)
                            ->hiddenOn('create')
                            ->columnSpan(1),

                        Select::make('frequency')
                            ->label('Frecuencia')
                            ->options(PayrollPeriod::frequencyOptions())
                            ->native(false)
                            ->required()
                            ->live()
                            ->columnSpan(fn (string $operation) => $operation === 'create' ? 2 : 1)
                            ->afterStateUpdated(function (?string $state, callable $set) {
                                $now = now();
                                match ($state) {
                                    'monthly' => [
                                        $set('start_date', $now->copy()->startOfMonth()->toDateString()),
                                        $set('end_date', $now->copy()->endOfMonth()->toDateString()),
                                    ],
                                    'biweekly' => $now->day <= 15
                                        ? [
                                            $set('start_date', $now->copy()->startOfMonth()->toDateString()),
                                            $set('end_date', $now->copy()->setDay(15)->toDateString()),
                                        ]
                                        : [
                                            $set('start_date', $now->copy()->setDay(16)->toDateString()),
                                            $set('end_date', $now->copy()->endOfMonth()->toDateString()),
                                        ],
                                    'weekly' => [
                                        $set('start_date', $now->copy()->startOfWeek()->toDateString()),
                                        $set('end_date', $now->copy()->endOfWeek()->toDateString()),
                                    ],
                                    default => null,
                                };
                            }),

                        DatePicker::make('start_date')
                            ->label('Fecha de Inicio')
                            ->displayFormat('d/m/Y')
                            ->native(false)
                            ->closeOnDateSelection()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (callable $set) => $set('end_date', null))
                            ->columnSpan(1),

                        DatePicker::make('end_date')
                            ->label('Fecha de Fin')
                            ->displayFormat('d/m/Y')
                            ->native(false)
                            ->closeOnDateSelection()
                            ->required()
                            ->minDate(fn ($get) => $get('start_date'))
                            ->disabled(fn ($get) => ! $get('start_date'))
                            ->helperText('La fecha de fin debe ser posterior a la fecha de inicio')
                            ->rules([
                                function ($get, $record) {
                                    return function (string $_attribute, $value, \Closure $fail) use ($get, $record) {
                                        $query = PayrollPeriod::where('company_id', $get('company_id'))
                                            ->where('frequency', $get('frequency'))
                                            ->where('start_date', $get('start_date'))
                                            ->where('end_date', $value);

                                        if ($record?->id) {
                                            $query->where('id', '!=', $record->id);
                                        }

                                        if ($query->exists()) {
                                            $fail('Ya existe un período con esta frecuencia y fechas para esa empresa.');
                                        }
                                    };
                                },
                            ])
                            ->columnSpan(1),

                        Textarea::make('notes')
                            ->label('Notas')
                            ->placeholder('Observaciones o comentarios sobre este período')
                            ->rows(1)
                            ->maxLength(65535)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->icon('heroicon-o-calendar-days')
                    ->iconColor('primary'),

                TextColumn::make('company.name')
                    ->label('Empresa')
                    ->icon('heroicon-o-building-office-2')
                    ->sortable()
                    ->visible(fn () => Company::active()->count() > 1),

                TextColumn::make('frequency')
                    ->label('Frecuencia')
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(fn (string $state): string => PayrollPeriod::frequencyOptions()[$state] ?? $state)
                    ->sortable(),

                TextColumn::make('start_date')
                    ->label('Inicio')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('end_date')
                    ->label('Fin')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('payrolls_count')
                    ->label('Recibos')
                    ->counts('payrolls')
                    ->alignCenter()
                    ->badge()
                    ->color('success')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state): string => PayrollPeriod::statusColors()[$state] ?? 'primary')
                    ->formatStateUsing(fn (string $state): string => PayrollPeriod::statusOptions()[$state] ?? $state)
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
            ->filters(array_filter([
                Company::active()->count() > 1
                    ? SelectFilter::make('company_id')
                        ->label('Empresa')
                        ->options(Company::active()->orderBy('name')->pluck('name', 'id'))
                        ->native(false)
                    : null,

                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(PayrollPeriod::statusOptions())
                    ->native(false),

                SelectFilter::make('frequency')
                    ->label('Frecuencia')
                    ->options(PayrollPeriod::frequencyOptions())
                    ->native(false),

                Filter::make('current_year')
                    ->label('Año Actual')
                    ->query(fn ($query) => $query->whereYear('start_date', now()->year))
                    ->default(),
            ]))
            ->actions([])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->action(function ($records) {
                            $deleted = 0;
                            foreach ($records as $record) {
                                if ($record->status === 'draft') {
                                    $record->delete();
                                    $deleted++;
                                }
                            }

                            if ($deleted > 0) {
                                Notification::make()
                                    ->success()
                                    ->title("Se eliminaron {$deleted} períodos en borrador")
                                    ->send();
                            } else {
                                Notification::make()
                                    ->warning()
                                    ->title('Solo se pueden eliminar períodos en borrador')
                                    ->send();
                            }
                        }),
                ]),
            ])
            // Precarga el conteo de recibos en borrador por período en una sola query,
            // evitando N+1 en el visible() de la acción "Regenerar Recibos".
            ->modifyQueryUsing(fn ($query) => $query->withCount([
                'payrolls as draft_payrolls_count' => fn ($q) => $q->where('status', 'draft'),
            ]))
            ->defaultSort('start_date', 'desc')
            ->emptyStateHeading('No hay períodos de nómina registrados')
            ->emptyStateDescription('Comienza a crear períodos de nómina para gestionar los pagos de los empleados.')
            ->emptyStateIcon('heroicon-o-calendar-days');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                InfolistSection::make('Información del Período')
                    ->schema([
                        Group::make([
                            TextEntry::make('company.name')
                                ->label('Empresa')
                                ->icon('heroicon-o-building-office-2')
                                ->placeholder('Sin empresa'),

                            TextEntry::make('name')
                                ->label('Nombre')
                                ->icon('heroicon-o-calendar-days'),

                            TextEntry::make('frequency')
                                ->label('Frecuencia')
                                ->badge()
                                ->color('info')
                                ->formatStateUsing(fn (string $state): string => PayrollPeriod::frequencyOptions()[$state] ?? $state),
                        ])->columns(3),

                        Group::make([
                            TextEntry::make('start_date')
                                ->label('Fecha de Inicio')
                                ->date('d/m/Y')
                                ->icon('heroicon-o-calendar'),

                            TextEntry::make('end_date')
                                ->label('Fecha de Fin')
                                ->date('d/m/Y')
                                ->icon('heroicon-o-calendar'),
                        ])->columns(2),
                    ])
                    ->collapsible(),

                InfolistSection::make('Estado del Período')
                    ->schema([
                        Group::make([
                            TextEntry::make('status')
                                ->label('Estado')
                                ->badge()
                                ->color(fn (string $state): string => PayrollPeriod::statusColors()[$state] ?? 'primary')
                                ->formatStateUsing(fn (string $state): string => PayrollPeriod::statusOptions()[$state] ?? $state),

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
                    ])
                    ->collapsible(),

                InfolistSection::make('Recibos')
                    ->schema([
                        Group::make([
                            TextEntry::make('total_payrolls_count')
                                ->label('Con recibo')
                                ->icon('heroicon-o-users')
                                ->suffix(' empleados'),

                            TextEntry::make('draft_payrolls_count')
                                ->label('Borrador')
                                ->badge()
                                ->color('gray'),

                            TextEntry::make('approved_payrolls_count')
                                ->label('Aprobados')
                                ->badge()
                                ->color('warning'),

                            TextEntry::make('paid_payrolls_count')
                                ->label('Pagados')
                                ->badge()
                                ->color('success'),
                        ])->columns(4),
                    ])
                    ->collapsible(),

                InfolistSection::make('Resumen de Nómina')
                    ->schema([
                        RepeatableEntry::make('perception_summary')
                            ->label('Percepciones')
                            ->schema([
                                TextEntry::make('description')->label('Concepto'),
                                TextEntry::make('employees_count')->label('Empleados'),
                                TextEntry::make('total_amount')
                                    ->label('Total')
                                    ->formatStateUsing(fn ($state) => 'Gs. '.number_format((float) $state, 0, ',', '.')),
                            ])
                            ->columns(3)
                            ->columnSpanFull(),

                        RepeatableEntry::make('deduction_summary')
                            ->label('Deducciones')
                            ->schema([
                                TextEntry::make('description')->label('Concepto'),
                                TextEntry::make('employees_count')->label('Empleados'),
                                TextEntry::make('total_amount')
                                    ->label('Total')
                                    ->formatStateUsing(fn ($state) => 'Gs. '.number_format((float) $state, 0, ',', '.')),
                            ])
                            ->columns(3)
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->visible(fn ($record) => $record->payrolls()->exists()),

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
                    ->collapsible(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            PayrollsRelationManager::class,
            AuditsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayrollPeriods::route('/'),
            'create' => Pages\CreatePayrollPeriod::route('/create'),
            'view' => Pages\ViewPayrollPeriod::route('/{record}'),
            'edit' => Pages\EditPayrollPeriod::route('/{record}/edit'),
        ];
    }
}
