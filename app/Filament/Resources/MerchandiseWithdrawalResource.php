<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MerchandiseWithdrawalResource\Pages;
use App\Filament\Resources\MerchandiseWithdrawalResource\RelationManagers\AuditsRelationManager;
use App\Filament\Resources\MerchandiseWithdrawalResource\RelationManagers\InstallmentsRelationManager;
use App\Filament\Resources\MerchandiseWithdrawalResource\RelationManagers\ItemsRelationManager;
use App\Filament\Traits\HasModuleAccess;
use App\Models\Branch;
use App\Models\Company;
use App\Models\MerchandiseWithdrawal;
use App\Settings\PayrollSettings;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Infolists\Components\Group;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/** Resource Filament para gestión de retiros de mercadería a crédito. */
class MerchandiseWithdrawalResource extends Resource
{
    use HasModuleAccess;

    protected static string $moduleFlag = 'merchandise_withdrawals_enabled';

    protected static ?string $model = MerchandiseWithdrawal::class;

    protected static ?string $navigationLabel = 'Retiros de Mercadería';

    protected static ?string $label = 'retiro de mercadería';

    protected static ?string $pluralLabel = 'retiros de mercadería';

    protected static ?string $slug = 'retiros-mercaderia';

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationGroup = 'Créditos';

    protected static ?int $navigationSort = 3;

    /**
     * Formulario de creación/edición del retiro (cabecera; los productos van en el RM).
     */
    public static function form(Form $form): Form
    {
        $settings = app(PayrollSettings::class);
        $maxInstallments = $settings->merchandise_max_installments;
        $defaultFirstInstallmentDays = $settings->merchandise_first_installment_days;

        return $form
            ->schema([
                Section::make('Información General')
                    ->schema([
                        Select::make('employee_id')
                            ->label('Empleado')
                            ->relationship(
                                name: 'employee',
                                modifyQueryUsing: fn (Builder $query) => $query
                                    ->where('status', 'active')
                                    ->orderBy('first_name')
                                    ->orderBy('last_name'),
                            )
                            ->getOptionLabelFromRecordUsing(fn (Model $record) => $record->full_name_with_ci)
                            ->searchable(['first_name', 'last_name', 'ci'])
                            ->native(false)
                            ->required()
                            ->disabled(fn (string $operation) => $operation === 'edit')
                            ->helperText('Solo se muestran empleados activos.'),

                        TextInput::make('installments_count')
                            ->label('Cantidad de Cuotas')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->maxValue($maxInstallments)
                            ->default(1)
                            ->disabled(fn (string $operation) => $operation === 'edit')
                            ->helperText("Máximo {$maxInstallments} cuotas."),

                        TextInput::make('first_installment_days')
                            ->label('Días hasta primera cuota')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->maxValue(365)
                            ->default($defaultFirstInstallmentDays)
                            ->suffix('días')
                            ->disabled(fn (string $operation) => $operation === 'edit')
                            ->helperText('Días desde la aprobación hasta el vencimiento de la primera cuota.'),

                        Textarea::make('notes')
                            ->label('Notas')
                            ->placeholder('Observaciones adicionales...')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * Infolist de visualización del retiro.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                InfolistSection::make('Información del Empleado')
                    ->schema([
                        Group::make([
                            TextEntry::make('employee.full_name')
                                ->label('Empleado')
                                ->icon('heroicon-o-user'),

                            TextEntry::make('employee.ci')
                                ->label('CI')
                                ->icon('heroicon-o-identification')
                                ->badge()
                                ->color('gray')
                                ->copyable(),

                            TextEntry::make('employee.activeContract.position.name')
                                ->label('Cargo')
                                ->icon('heroicon-o-briefcase')
                                ->badge()
                                ->color('info')
                                ->placeholder('-'),
                        ])->columns(3),
                    ]),

                InfolistSection::make('Datos del Retiro')
                    ->schema([
                        Group::make([
                            TextEntry::make('status')
                                ->label('Estado')
                                ->formatStateUsing(fn (string $state) => MerchandiseWithdrawal::getStatusLabel($state))
                                ->color(fn (string $state) => MerchandiseWithdrawal::getStatusColor($state))
                                ->icon(fn (string $state) => MerchandiseWithdrawal::getStatusIcon($state))
                                ->badge(),

                            TextEntry::make('total_amount')
                                ->label('Monto Total')
                                ->money('PYG', locale: 'es_PY')
                                ->icon('heroicon-o-banknotes'),

                            TextEntry::make('installments_count')
                                ->label('Progreso')
                                ->formatStateUsing(fn (MerchandiseWithdrawal $record) => $record->progress_description),
                        ])->columns(3),

                        Group::make([
                            TextEntry::make('installment_amount')
                                ->label('Cuota Mensual')
                                ->money('PYG', locale: 'es_PY')
                                ->placeholder('-')
                                ->visible(fn (MerchandiseWithdrawal $record) => $record->isApproved() || $record->isPaid()),

                            TextEntry::make('outstanding_balance')
                                ->label('Saldo Pendiente')
                                ->money('PYG', locale: 'es_PY')
                                ->icon('heroicon-o-banknotes')
                                ->visible(fn (MerchandiseWithdrawal $record) => $record->isApproved()),
                        ])->columns(2),

                        TextEntry::make('notes')
                            ->label('Notas')
                            ->placeholder('Sin notas')
                            ->columnSpanFull(),
                    ]),

                InfolistSection::make('Aprobación')
                    ->schema([
                        Group::make([
                            TextEntry::make('approved_at')
                                ->label('Fecha de Aprobación')
                                ->date('d/m/Y')
                                ->icon('heroicon-o-calendar')
                                ->placeholder('-'),

                            TextEntry::make('approvedBy.name')
                                ->label('Aprobado por')
                                ->icon('heroicon-o-user-circle')
                                ->placeholder('-'),
                        ])->columns(2),
                    ])
                    ->visible(fn (MerchandiseWithdrawal $record) => $record->isApproved() || $record->isPaid()),

                InfolistSection::make('Rechazo')
                    ->schema([
                        Group::make([
                            TextEntry::make('rejected_at')
                                ->label('Fecha de Rechazo')
                                ->date('d/m/Y')
                                ->icon('heroicon-o-calendar')
                                ->placeholder('-'),

                            TextEntry::make('rejectedBy.name')
                                ->label('Rechazado por')
                                ->icon('heroicon-o-user-circle')
                                ->placeholder('-'),
                        ])->columns(2),
                    ])
                    ->visible(fn (MerchandiseWithdrawal $record) => $record->isRejected()),
            ]);
    }

    /**
     * Tabla de listado de retiros.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(
                fn (Builder $query) => $query->with(['employee.branch.company', 'approvedBy'])
            )
            ->columns([
                ImageColumn::make('employee.photo')
                    ->label('Foto')
                    ->circular()
                    ->defaultImageUrl(fn ($record) => $record->employee?->avatar_url)
                    ->toggleable(),

                TextColumn::make('employee.full_name')
                    ->label('Empleado')
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas(
                        'employee',
                        fn ($q) => $q->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                    ))
                    ->sortable(['first_name', 'last_name'])
                    ->wrap(),

                TextColumn::make('employee.ci')
                    ->label('CI')
                    ->sortable()
                    ->searchable()
                    ->toggleable()
                    ->badge()
                    ->color('gray')
                    ->copyable()
                    ->tooltip('Haz clic para copiar')
                    ->copyMessage('CI copiada al portapapeles'),

                TextColumn::make('employee.branch.name')
                    ->label('Sucursal')
                    ->icon('heroicon-o-building-storefront')
                    ->badge()
                    ->color('info')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('total_amount')
                    ->label('Monto Total')
                    ->money('PYG', locale: 'es_PY')
                    ->sortable(),

                TextColumn::make('installments_count')
                    ->label('Cuotas')
                    ->formatStateUsing(fn (MerchandiseWithdrawal $record) => $record->progress_description)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('installment_amount')
                    ->label('Cuota')
                    ->money('PYG', locale: 'es_PY')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('-'),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => MerchandiseWithdrawal::getStatusLabel($state))
                    ->color(fn (string $state): string => MerchandiseWithdrawal::getStatusColor($state))
                    ->icon(fn (string $state): string => MerchandiseWithdrawal::getStatusIcon($state))
                    ->sortable(),

                TextColumn::make('approved_at')
                    ->label('Aprobado')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('-'),

                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(MerchandiseWithdrawal::getStatusOptions())
                    ->multiple()
                    ->native(false),

                SelectFilter::make('branch_id')
                    ->label('Sucursal')
                    ->options(fn () => Branch::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->native(false)
                    ->query(fn (Builder $query, array $data) => $data['value']
                        ? $query->whereHas('employee', fn ($q) => $q->where('branch_id', $data['value']))
                        : $query
                    ),

                SelectFilter::make('company_id')
                    ->label('Empresa')
                    ->options(fn () => Company::active()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->native(false)
                    ->visible(fn () => Company::active()->count() > 1)
                    ->query(fn (Builder $query, array $data) => $data['value']
                        ? $query->whereHas('employee.branch', fn ($q) => $q->where('company_id', $data['value']))
                        : $query
                    ),

                SelectFilter::make('employee_id')
                    ->label('Empleado')
                    ->relationship('employee', 'first_name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->full_name} (CI: {$record->ci})")
                    ->searchable(['first_name', 'last_name', 'ci'])
                    ->multiple()
                    ->native(false),
            ])
            ->actions([
                Action::make('approve')
                    ->label('Aprobar')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->visible(fn (MerchandiseWithdrawal $record) => $record->isPending() && auth()->user()->can('approve_merchandise_withdrawal'))
                    ->requiresConfirmation()
                    ->modalHeading('Aprobar Retiro')
                    ->modalDescription(fn (MerchandiseWithdrawal $record) => "Se generarán {$record->installments_count} cuota(s) y se descontarán en las próximas nóminas.")
                    ->modalSubmitActionLabel('Sí, aprobar')
                    ->action(function (MerchandiseWithdrawal $record) {
                        $result = $record->approve(Auth::id());

                        Notification::make()
                            ->title($result['success'] ? 'Retiro Aprobado' : 'Error')
                            ->body($result['message'])
                            ->{$result['success'] ? 'success' : 'danger'}()
                            ->send();
                    }),

                Action::make('reject')
                    ->label('Rechazar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (MerchandiseWithdrawal $record) => $record->isPending() && auth()->user()->can('reject_merchandise_withdrawal'))
                    ->requiresConfirmation()
                    ->modalHeading('Rechazar Retiro')
                    ->modalDescription('¿Está seguro de que desea rechazar esta solicitud? El retiro quedará en estado Rechazado.')
                    ->modalSubmitActionLabel('Sí, rechazar')
                    ->action(function (MerchandiseWithdrawal $record) {
                        $result = $record->reject(Auth::id());

                        Notification::make()
                            ->title($result['success'] ? 'Retiro Rechazado' : 'Error')
                            ->body($result['message'])
                            ->{$result['success'] ? 'success' : 'danger'}()
                            ->send();
                    }),

                Action::make('export_pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->visible(fn (MerchandiseWithdrawal $record) => $record->isApproved() || $record->isPaid())
                    ->url(fn (MerchandiseWithdrawal $record) => route('merchandise-withdrawals.pdf', $record))
                    ->openUrlInNewTab(),
            ])
            ->bulkActions([
                BulkActionGroup::make([]),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No hay retiros de mercadería aún')
            ->emptyStateDescription('Comenzá registrando el primer retiro.')
            ->emptyStateIcon('heroicon-o-shopping-bag');
    }

    /**
     * RelationManagers del resource.
     *
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
            InstallmentsRelationManager::class,
            AuditsRelationManager::class,
        ];
    }

    /**
     * Páginas del resource.
     *
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMerchandiseWithdrawals::route('/'),
            'create' => Pages\CreateMerchandiseWithdrawal::route('/create'),
            'view' => Pages\ViewMerchandiseWithdrawal::route('/{record}'),
            'edit' => Pages\EditMerchandiseWithdrawal::route('/{record}/edit'),
        ];
    }

    /**
     * Badge de navegación con conteo de retiros pendientes.
     */
    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('status', 'pending')->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}
