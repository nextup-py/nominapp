<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DisbursementBatchResource\Pages;
use App\Filament\Resources\DisbursementBatchResource\RelationManagers;
use App\Filament\Traits\HasModuleAccess;
use App\Models\Advance;
use App\Models\Company;
use App\Models\DisbursementBatch;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Components\Group;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Recurso Filament para gestionar lotes de acreditación bancaria masiva de adelantos y nóminas. */
class DisbursementBatchResource extends Resource
{
    use HasModuleAccess;

    protected static string $moduleFlag = 'disbursement_batches_enabled';

    protected static ?string $model = DisbursementBatch::class;

    protected static ?string $navigationLabel = 'Pagos Bancarios';

    protected static ?string $label = 'lote de pago';

    protected static ?string $pluralLabel = 'lotes de pago';

    protected static ?string $slug = 'pagos-bancarios';

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationGroup = 'Nóminas';

    protected static ?int $navigationSort = 3;

    /**
     * Define el formulario de creación de un lote.
     */
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Datos del lote')
                    ->schema([
                        Select::make('company_id')
                            ->label('Empresa')
                            ->options(Company::orderBy('name')->get()->pluck('display_name', 'id'))
                            ->required()
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(function (Set $set, Get $get) {
                                $companyId = $get('company_id');

                                if (! $companyId) {
                                    $set('advance_ids', []);

                                    return;
                                }

                                $ids = Advance::query()
                                    ->where('status', 'approved')
                                    ->where('payment_method', 'transfer')
                                    ->whereNull('disbursement_batch_id')
                                    ->whereHas('employee.branch', fn ($q) => $q->where('company_id', $companyId))
                                    ->pluck('id')
                                    ->toArray();

                                $set('advance_ids', $ids);
                            }),

                        DatePicker::make('fecha_credito')
                            ->label('Fecha de acreditación')
                            ->required()
                            ->native(false)
                            ->default(today())
                            ->displayFormat('d/m/Y')
                            ->helperText('Fecha en que el banco acreditará los fondos en las cuentas de los empleados.'),

                        Textarea::make('notes')
                            ->label('Notas')
                            ->placeholder('Observaciones opcionales...')
                            ->rows(2)
                            ->columnSpanFull(),

                        Hidden::make('type')->default('advances'),
                    ])
                    ->columns(2),

                Section::make('Adelantos a incluir')
                    ->schema([
                        Select::make('advance_ids')
                            ->label('Adelantos')
                            ->multiple()
                            ->dehydrated(false)
                            ->native(false)
                            ->searchable()
                            ->required()
                            ->options(function (Get $get) {
                                $companyId = $get('company_id');

                                if (! $companyId) {
                                    return [];
                                }

                                return Advance::query()
                                    ->where('status', 'approved')
                                    ->where('payment_method', 'transfer')
                                    ->whereNull('disbursement_batch_id')
                                    ->whereHas('employee.branch', fn ($q) => $q->where('company_id', $companyId))
                                    ->with('employee')
                                    ->orderBy('created_at', 'desc')
                                    ->get()
                                    ->mapWithKeys(fn (Advance $advance) => [
                                        $advance->id => $advance->employee->full_name
                                            .' — Gs. '.number_format((float) $advance->amount, 0, ',', '.'),
                                    ])
                                    ->toArray();
                            })
                            ->helperText('Solo se muestran adelantos aprobados por transferencia bancaria que no estén ya en otro lote.')
                            ->columnSpanFull()
                            ->visible(fn (Get $get) => filled($get('company_id'))),

                        Placeholder::make('select_company_first')
                            ->label('')
                            ->content('Seleccioná una empresa para ver los adelantos disponibles.')
                            ->columnSpanFull()
                            ->visible(fn (Get $get) => ! filled($get('company_id'))),
                    ]),
            ]);
    }

    /**
     * Define el infolist de detalle de un lote.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                InfolistSection::make('Datos del Lote')
                    ->schema([
                        Group::make([
                            TextEntry::make('company.display_name')
                                ->label('Empresa')
                                ->icon('heroicon-o-building-office-2'),

                            TextEntry::make('type')
                                ->label('Tipo')
                                ->formatStateUsing(fn (string $state) => DisbursementBatch::getTypeLabel($state))
                                ->badge()
                                ->color('info'),

                            TextEntry::make('fecha_credito')
                                ->label('Fecha de acreditación')
                                ->date('d/m/Y')
                                ->icon('heroicon-o-calendar'),

                            TextEntry::make('status')
                                ->label('Estado')
                                ->formatStateUsing(fn (string $state) => DisbursementBatch::getStatusLabel($state))
                                ->color(fn (string $state) => DisbursementBatch::getStatusColor($state))
                                ->icon(fn (string $state) => DisbursementBatch::getStatusIcon($state))
                                ->badge(),

                            TextEntry::make('items_count')
                                ->label('Cantidad')
                                ->icon('heroicon-o-banknotes')
                                ->getStateUsing(fn (DisbursementBatch $record) => match ($record->type) {
                                    'payroll' => $record->payrolls()->count(),
                                    'loan' => $record->loans()->count(),
                                    'aguinaldo' => $record->aguinaldos()->count(),
                                    default => $record->advances()->count(),
                                }),

                            TextEntry::make('items_total')
                                ->label('Monto total')
                                ->icon('heroicon-o-currency-dollar')
                                ->getStateUsing(fn (DisbursementBatch $record) => 'Gs. '.number_format(
                                    (float) match ($record->type) {
                                        'payroll' => $record->payrolls()->sum('net_salary'),
                                        'loan' => $record->loans()->sum('amount'),
                                        'aguinaldo' => $record->aguinaldos()->sum('aguinaldo_amount'),
                                        default => $record->advances()->sum('amount'),
                                    },
                                    0, ',', '.'
                                )),
                        ])->columns(3),

                        TextEntry::make('notes')
                            ->label('Notas')
                            ->columnSpanFull()
                            ->visible(fn (DisbursementBatch $record) => filled($record->notes)),
                    ]),

                InfolistSection::make('Archivos')
                    ->schema([
                        Group::make([
                            TextEntry::make('file_path')
                                ->label('Archivo TXT (banco)')
                                ->formatStateUsing(fn (?string $state) => $state ? 'Descargar TXT' : 'No generado')
                                ->icon(fn (?string $state) => $state ? 'heroicon-o-document-arrow-down' : 'heroicon-o-no-symbol')
                                ->color(fn (?string $state) => $state ? 'primary' : 'gray')
                                ->badge()
                                ->url(fn (DisbursementBatch $record) => $record->file_path
                                    ? asset('storage/'.$record->file_path)
                                    : null)
                                ->openUrlInNewTab(),

                            TextEntry::make('bank_confirmation_path')
                                ->label('Comprobante bancario')
                                ->formatStateUsing(fn (?string $state) => $state ? 'Ver comprobante' : 'No adjuntado')
                                ->icon(fn (?string $state) => $state ? 'heroicon-o-paper-clip' : 'heroicon-o-no-symbol')
                                ->color(fn (?string $state) => $state ? 'success' : 'gray')
                                ->badge()
                                ->url(fn (DisbursementBatch $record) => $record->bank_confirmation_path
                                    ? asset('storage/'.$record->bank_confirmation_path)
                                    : null)
                                ->openUrlInNewTab(),
                        ])->columns(2),
                    ])
                    ->hidden(fn (DisbursementBatch $record) => ! $record->file_path && ! $record->bank_confirmation_path),

                InfolistSection::make('Auditoría')
                    ->schema([
                        Group::make([
                            TextEntry::make('createdBy.name')
                                ->label('Creado por')
                                ->icon('heroicon-o-user-circle')
                                ->placeholder('-'),

                            TextEntry::make('created_at')
                                ->label('Creado')
                                ->dateTime('d/m/Y H:i')
                                ->icon('heroicon-o-calendar'),
                        ])->columns(2)
                            ->visible(fn (DisbursementBatch $record) => $record->status === 'pending'),

                        Group::make([
                            TextEntry::make('createdBy.name')
                                ->label('Creado por')
                                ->icon('heroicon-o-user-circle')
                                ->placeholder('-'),

                            TextEntry::make('created_at')
                                ->label('Creado')
                                ->dateTime('d/m/Y H:i')
                                ->icon('heroicon-o-calendar'),

                            TextEntry::make('confirmedBy.name')
                                ->label('Confirmado por')
                                ->icon('heroicon-o-user-circle')
                                ->placeholder('-'),

                            TextEntry::make('confirmed_at')
                                ->label('Confirmado')
                                ->dateTime('d/m/Y H:i')
                                ->placeholder('-'),
                        ])->columns(4)
                            ->visible(fn (DisbursementBatch $record) => $record->status !== 'pending'),
                    ]),
            ]);
    }

    /**
     * Define la tabla de listado de lotes.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['company', 'createdBy'])
                ->withCount(['advances', 'payrolls', 'loans', 'aguinaldos'])
                ->withSum('advances', 'amount')
                ->withSum('payrolls', 'net_salary')
                ->withSum('loans', 'amount')
                ->withSum('aguinaldos', 'aguinaldo_amount')
            )
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('company.name')
                    ->label('Empresa')
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => DisbursementBatch::getTypeLabel($state))
                    ->color(fn (string $state) => DisbursementBatch::getTypeColor($state))
                    ->sortable(),

                TextColumn::make('fecha_credito')
                    ->label('Fecha crédito')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => DisbursementBatch::getStatusLabel($state))
                    ->color(fn (string $state) => DisbursementBatch::getStatusColor($state))
                    ->icon(fn (string $state) => DisbursementBatch::getStatusIcon($state))
                    ->sortable(),

                TextColumn::make('items_count')
                    ->label('Ítems')
                    ->getStateUsing(fn (DisbursementBatch $record) => match ($record->type) {
                        'payroll' => $record->payrolls_count,
                        'loan' => $record->loans_count ?? $record->loans()->count(),
                        'aguinaldo' => $record->aguinaldos_count ?? $record->aguinaldos()->count(),
                        default => $record->advances_count,
                    })
                    ->badge()
                    ->color('gray')
                    ->sortable(false),

                TextColumn::make('items_total')
                    ->label('Total (Gs.)')
                    ->getStateUsing(fn (DisbursementBatch $record) => match ($record->type) {
                        'payroll' => $record->payrolls_sum_net_salary,
                        'loan' => $record->loans_sum_amount ?? $record->loans()->sum('amount'),
                        'aguinaldo' => $record->aguinaldos_sum_aguinaldo_amount ?? $record->aguinaldos()->sum('aguinaldo_amount'),
                        default => $record->advances_sum_amount,
                    })
                    ->numeric(0, ',', '.')
                    ->sortable(false),

                TextColumn::make('createdBy.name')
                    ->label('Creado por')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(DisbursementBatch::getTypeOptions())
                    ->native(false),

                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(DisbursementBatch::getStatusOptions())
                    ->native(false),

                SelectFilter::make('company_id')
                    ->label('Empresa')
                    ->relationship('company', 'name')
                    ->native(false),
            ])
            ->actions([
                Action::make('view')
                    ->label('Ver')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn (DisbursementBatch $record) => static::getUrl('view', ['record' => $record])),
            ])
            ->bulkActions([])
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('No hay lotes de pago registrados')
            ->emptyStateDescription('Creá un lote para gestionar la acreditación bancaria masiva de adelantos o nóminas.')
            ->emptyStateIcon('heroicon-o-building-library');
    }

    /**
     * @return array<int, string>
     */
    public static function getRelations(): array
    {
        return [
            RelationManagers\AdvancesRelationManager::class,
            RelationManagers\PayrollsRelationManager::class,
            RelationManagers\LoansRelationManager::class,
            RelationManagers\AguinaldosRelationManager::class,
            RelationManagers\AuditsRelationManager::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDisbursementBatches::route('/'),
            'create' => Pages\CreateDisbursementBatch::route('/create'),
            'view' => Pages\ViewDisbursementBatch::route('/{record}'),
        ];
    }
}
