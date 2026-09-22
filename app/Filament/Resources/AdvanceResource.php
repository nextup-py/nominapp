<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AdvanceResource\Pages;
use App\Models\Advance;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Settings\PayrollSettings;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Components\Group;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Livewire\Component;

class AdvanceResource extends Resource
{
    protected static ?string $model = Advance::class;

    protected static ?string $navigationLabel = 'Adelantos';

    protected static ?string $label = 'adelanto';

    protected static ?string $pluralLabel = 'adelantos';

    protected static ?string $slug = 'adelantos';

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationGroup = 'Créditos';

    protected static ?int $navigationSort = 2;

    /**
     * Define el formulario de creación/edición de adelantos.
     */
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Información del Adelanto')
                    ->schema([
                        Select::make('employee_id')
                            ->label('Empleado')
                            ->relationship(
                                name: 'employee',
                                modifyQueryUsing: fn (Builder $query) => $query
                                    ->where('status', 'active')
                                    ->whereHas('activeContract', fn ($c) => $c->whereNotNull('salary')->where('salary', '>', 0))
                                    ->orderBy('first_name')
                                    ->orderBy('last_name'),
                            )
                            ->getOptionLabelFromRecordUsing(fn (Model $record) => $record->full_name_with_ci)
                            ->searchable(['first_name', 'last_name', 'ci'])
                            ->native(false)
                            ->columnSpanFull()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, Get $get) {
                                $set('amount', null);

                                $employee = $get('employee_id') ? Employee::find($get('employee_id')) : null;
                                $set('max_advance_amount', $employee?->getMaxAdvanceAmount());

                                $contractMethod = $employee?->activeContract?->payment_method;
                                $set('payment_method', $contractMethod === 'cash' ? 'cash' : 'transfer');
                            })
                            ->disabled(fn (string $operation) => $operation === 'edit')
                            ->hint(fn (string $operation) => $operation === 'edit' ? 'No editable' : null)
                            ->hintIcon(fn (string $operation) => $operation === 'edit' ? 'heroicon-o-lock-closed' : null)
                            ->hintColor('gray')
                            ->helperText(fn (string $operation) => $operation === 'edit'
                                ? 'El empleado no puede modificarse una vez creado el adelanto.'
                                : 'Solo se muestran empleados activos con salario definido.'),

                        Placeholder::make('advance_quota_summary')
                            ->label('Cuota disponible')
                            ->dehydrated(false)
                            ->columnSpanFull()
                            ->visible(fn (Get $get, string $operation) => $operation === 'create' && filled($get('employee_id')))
                            ->content(function (Get $get) {
                                $employeeId = $get('employee_id');
                                if (! $employeeId) {
                                    return '';
                                }

                                $employee = Employee::with('activeContract')->find($employeeId);
                                if (! $employee) {
                                    return '';
                                }

                                $salary = $employee->getAdvanceReferenceSalary();
                                if (! $salary) {
                                    return new HtmlString('<span class="text-danger-600 text-sm">El empleado no tiene salario de referencia definido.</span>');
                                }

                                $settings = app(PayrollSettings::class);
                                $maxPerAdvance = $employee->getMaxAdvanceAmount() ?? 0;
                                $maxPerPeriod = (int) $settings->advance_max_per_period;

                                $activeStats = Advance::where('employee_id', $employeeId)
                                    ->whereIn('status', ['pending', 'approved', 'disbursed'])
                                    ->selectRaw('SUM(amount) as total, COUNT(*) as count')
                                    ->first();

                                $activeTotal = (float) ($activeStats->total ?? 0);
                                $activeCount = (int) ($activeStats->count ?? 0);
                                $available = max(0, $maxPerAdvance - $activeTotal);
                                $availableColor = $available > 0 ? 'text-success-600' : 'text-danger-600';

                                $html = '<div class="flex flex-wrap items-center gap-x-5 gap-y-1 text-sm py-1">';
                                $html .= '<span class="text-gray-500">Disponible:</span> ';
                                $html .= '<span class="font-bold '.$availableColor.'">Gs. '.number_format($available, 0, ',', '.').'</span>';

                                if ($maxPerPeriod > 0) {
                                    $countColor = $activeCount >= $maxPerPeriod ? 'text-danger-600' : 'text-gray-700';
                                    $html .= '<span class="text-gray-300 select-none">·</span>';
                                    $html .= '<span class="text-gray-500">Adelantos activos:</span> ';
                                    $html .= '<span class="font-medium '.$countColor.'">'.$activeCount.' / '.$maxPerPeriod.'</span>';
                                }

                                $html .= '</div>';

                                return new HtmlString($html);
                            }),

                        TextInput::make('amount')
                            ->label('Monto')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->maxValue(fn (Get $get) => $get('max_advance_amount') ?? 9999999999)
                            ->prefix('Gs.')
                            ->hint(fn (string $operation) => $operation === 'edit' ? 'No editable' : null)
                            ->hintIcon(fn (string $operation) => $operation === 'edit' ? 'heroicon-o-lock-closed' : null)
                            ->hintColor('gray')
                            ->helperText(function (Get $get, string $operation) {
                                if ($operation === 'edit') {
                                    return 'El monto no puede modificarse una vez creado el adelanto.';
                                }

                                $max = $get('max_advance_amount');
                                $percent = (int) app(PayrollSettings::class)->advance_max_percent;

                                return $max
                                    ? 'Máximo: '.number_format($max, 0, ',', '.').' Gs. ('.$percent.'% del salario)'
                                    : 'Seleccione un empleado para ver el monto máximo';
                            })
                            ->disabled(fn (string $operation) => $operation === 'edit'),

                        Select::make('payment_method')
                            ->label('Método de pago')
                            ->options(Advance::getPaymentMethodOptions())
                            ->default('transfer')
                            ->required()
                            ->native(false)
                            ->helperText(fn (string $operation) => $operation === 'create'
                                ? 'Se completa automáticamente según el método de pago del contrato del empleado.'
                                : null),

                        Textarea::make('notes')
                            ->label('Notas')
                            ->placeholder('Motivo u observaciones...')
                            ->rows(2)
                            ->columnSpanFull(),

                        Hidden::make('max_advance_amount')->dehydrated(false),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * Define el infolist de detalle de un adelanto.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                InfolistSection::make('Empleado')
                    ->schema([
                        Group::make([
                            TextEntry::make('employee.full_name')
                                ->label('Nombre')
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

                InfolistSection::make('Datos del Adelanto')
                    ->schema([
                        Group::make([
                            TextEntry::make('amount')
                                ->label('Monto')
                                ->money('PYG', locale: 'es_PY')
                                ->icon('heroicon-o-banknotes'),

                            TextEntry::make('status')
                                ->label('Estado')
                                ->formatStateUsing(fn (string $state) => Advance::getStatusLabel($state))
                                ->color(fn (string $state) => Advance::getStatusColor($state))
                                ->icon(fn (string $state) => Advance::getStatusIcon($state))
                                ->badge(),

                            TextEntry::make('payment_method')
                                ->label('Método de pago')
                                ->formatStateUsing(fn (string $state) => Advance::getPaymentMethodLabel($state))
                                ->color(fn (string $state) => Advance::getPaymentMethodColor($state))
                                ->icon(fn (string $state) => Advance::getPaymentMethodIcon($state))
                                ->badge(),

                            TextEntry::make('created_at')
                                ->label('Solicitado')
                                ->dateTime('d/m/Y H:i')
                                ->icon('heroicon-o-calendar'),
                        ])->columns(4),

                        TextEntry::make('notes')
                            ->label('Notas')
                            ->placeholder('Sin notas')
                            ->columnSpanFull(),
                    ]),

                InfolistSection::make('Aprobación y Entrega')
                    ->schema([
                        Group::make([
                            TextEntry::make('approved_at')
                                ->label('Fecha de Aprobación')
                                ->dateTime('d/m/Y H:i')
                                ->icon('heroicon-o-calendar')
                                ->placeholder('-'),

                            TextEntry::make('approvedBy.name')
                                ->label('Aprobado por')
                                ->icon('heroicon-o-user-circle')
                                ->placeholder('-'),

                            TextEntry::make('disbursed_at')
                                ->label('Fecha de Entrega')
                                ->dateTime('d/m/Y H:i')
                                ->icon('heroicon-o-banknotes')
                                ->placeholder('Pendiente de entrega'),

                            TextEntry::make('disbursedBy.name')
                                ->label('Entregado por')
                                ->icon('heroicon-o-user-circle')
                                ->placeholder('-'),

                            TextEntry::make('payroll.period.name')
                                ->label('Período de Nómina')
                                ->icon('heroicon-o-document-text')
                                ->placeholder('Pendiente de nómina'),
                        ])->columns(4),

                        Group::make([
                            TextEntry::make('transfer_receipt_path')
                                ->label('Comprobante')
                                ->formatStateUsing(fn (?string $state) => $state ? 'Ver comprobante' : 'Sin comprobante')
                                ->icon(fn (?string $state) => $state ? 'heroicon-o-paper-clip' : 'heroicon-o-no-symbol')
                                ->color(fn (?string $state) => $state ? 'success' : 'gray')
                                ->badge()
                                ->url(fn (Advance $record) => $record->transfer_receipt_path
                                    ? asset('storage/'.$record->transfer_receipt_path)
                                    : null)
                                ->openUrlInNewTab(),

                            TextEntry::make('disbursement_batch_id')
                                ->label('Lote bancario')
                                ->formatStateUsing(fn (?string $state) => $state ? "Lote #{$state}" : null)
                                ->placeholder('Sin lote')
                                ->badge()
                                ->color('info')
                                ->icon('heroicon-o-building-library')
                                ->url(fn (Advance $record) => $record->disbursement_batch_id
                                    ? DisbursementBatchResource::getUrl('view', ['record' => $record->disbursement_batch_id])
                                    : null)
                                ->openUrlInNewTab(),

                            TextEntry::make('bank_rejection_reason')
                                ->label('Rechazo bancario')
                                ->formatStateUsing(fn (?string $state) => Advance::getBankRejectionReasonLabel($state))
                                ->badge()
                                ->color('danger')
                                ->placeholder('-')
                                ->visible(fn (Advance $record) => $record->bank_rejection_reason !== null),
                        ])->columns(3),
                    ])
                    ->visible(fn (Advance $record) => $record->isApproved() || $record->isDisbursed() || $record->isPaid()),
            ]);
    }

    /**
     * Define la tabla de listado de adelantos.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(
                fn (Builder $query) => $query->with(['employee', 'approvedBy', 'payroll.period'])
            )
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

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
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('employee.branch.company.name')
                    ->label('Empresa')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('amount')
                    ->label('Monto')
                    ->money('PYG', locale: 'es_PY')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Advance::getStatusLabel($state))
                    ->color(fn (string $state) => Advance::getStatusColor($state))
                    ->icon(fn (string $state) => Advance::getStatusIcon($state))
                    ->sortable(),

                TextColumn::make('payment_method')
                    ->label('Método de pago')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Advance::getPaymentMethodLabel($state))
                    ->color(fn (string $state) => Advance::getPaymentMethodColor($state))
                    ->icon(fn (string $state) => Advance::getPaymentMethodIcon($state))
                    ->toggleable(),

                TextColumn::make('payroll.period.name')
                    ->label('Período descontado')
                    ->icon('heroicon-o-document-text')
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('approved_at')
                    ->label('Aprobado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('approvedBy.name')
                    ->label('Aprobado por')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('Editado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(Advance::getStatusOptions())
                    ->multiple()
                    ->native(false),

                SelectFilter::make('payment_method')
                    ->label('Método de pago')
                    ->options(Advance::getPaymentMethodOptions())
                    ->native(false),

                SelectFilter::make('employee_id')
                    ->label('Empleado')
                    ->relationship('employee', 'first_name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->full_name} (CI: {$record->ci})")
                    ->searchable(['first_name', 'last_name', 'ci'])
                    ->multiple()
                    ->native(false),

                Filter::make('company_branch')
                    ->label('Empresa / Sucursal')
                    ->form([
                        Select::make('company_id')
                            ->label('Empresa')
                            ->options(Company::orderBy('name')->get()->pluck('display_name', 'id'))
                            ->native(false)
                            ->live()
                            ->placeholder('Todas')
                            ->afterStateUpdated(fn (Set $set) => $set('branch_id', null)),

                        Select::make('branch_id')
                            ->label('Sucursal')
                            ->options(fn (Get $get) => Branch::when(
                                $get('company_id'),
                                fn ($q, $id) => $q->where('company_id', $id)
                            )->orderBy('name')->pluck('name', 'id'))
                            ->native(false)
                            ->placeholder('Todas'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            $data['branch_id'] ?? null,
                            fn ($q, $id) => $q->whereHas('employee', fn ($e) => $e->where('branch_id', $id))
                        )
                        ->when(
                            ! empty($data['company_id']) && empty($data['branch_id']),
                            fn ($q) => $q->whereHas('employee.branch', fn ($b) => $b->where('company_id', $data['company_id']))
                        ))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if (! empty($data['company_id'])) {
                            $name = Company::find($data['company_id'])?->display_name;
                            if ($name) {
                                $indicators[] = Indicator::make('Empresa: '.$name)->removeField('company_id');
                            }
                        }

                        if (! empty($data['branch_id'])) {
                            $name = Branch::find($data['branch_id'])?->name;
                            if ($name) {
                                $indicators[] = Indicator::make('Sucursal: '.$name)->removeField('branch_id');
                            }
                        }

                        return $indicators;
                    }),

                Filter::make('approved_at')
                    ->label('Fecha de Aprobación')
                    ->form([
                        DatePicker::make('from')
                            ->label('Desde')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->closeOnDateSelection(),
                        DatePicker::make('until')
                            ->label('Hasta')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->closeOnDateSelection(),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'], fn ($q, $date) => $q->whereDate('approved_at', '>=', $date))
                        ->when($data['until'], fn ($q, $date) => $q->whereDate('approved_at', '<=', $date))),
            ])
            ->actions([
                ActionGroup::make([
                    Action::make('approve')
                        ->label('Aprobar')
                        ->icon('heroicon-o-check')
                        ->color('success')
                        ->visible(fn (Advance $record) => $record->isPending() && auth()->user()->can('approve_advance'))
                        ->modalHeading('Aprobar Adelanto')
                        ->modalDescription(fn (Advance $record) => 'Se aprobará el adelanto de '.number_format((float) $record->amount, 0, ',', '.').' Gs. para '.($record->employee?->full_name ?? 'empleado eliminado').'. Se descontará automáticamente en la próxima liquidación de nómina.')
                        ->modalSubmitActionLabel('Sí, aprobar')
                        ->form([
                            Select::make('payment_method')
                                ->label('Método de pago')
                                ->options(Advance::getPaymentMethodOptions())
                                ->default(fn (Advance $record) => $record->payment_method)
                                ->required()
                                ->native(false),
                        ])
                        ->action(function (Advance $record, array $data) {
                            $result = $record->approve(Auth::id(), $data['payment_method']);

                            Notification::make()
                                ->title($result['success'] ? 'Adelanto Aprobado' : 'Error')
                                ->body($result['message'])
                                ->{$result['success'] ? 'success' : 'danger'}()
                                ->send();
                        }),

                    Action::make('reject')
                        ->label('Rechazar')
                        ->icon('heroicon-o-x-circle')
                        ->color('warning')
                        ->visible(fn (Advance $record) => $record->isPending() && auth()->user()->can('reject_advance'))
                        ->requiresConfirmation()
                        ->modalHeading('Rechazar Adelanto')
                        ->modalDescription(fn (Advance $record) => 'Se rechazará el adelanto de '.number_format((float) $record->amount, 0, ',', '.').' Gs. para '.($record->employee?->full_name ?? 'empleado eliminado').'. El adelanto quedará en estado Rechazado.')
                        ->modalSubmitActionLabel('Sí, rechazar')
                        ->form([
                            Textarea::make('reason')
                                ->label('Motivo del rechazo')
                                ->placeholder('Ingrese el motivo...')
                                ->rows(3),
                        ])
                        ->action(function (Advance $record, array $data) {
                            $result = $record->reject($data['reason'] ?? null);

                            Notification::make()
                                ->title($result['success'] ? 'Adelanto Rechazado' : 'Error')
                                ->body($result['message'])
                                ->{$result['success'] ? 'warning' : 'danger'}()
                                ->send();
                        }),

                    Action::make('revert_to_pending')
                        ->label('Desaprobar')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('warning')
                        ->visible(fn (Advance $record) => $record->isApproved() && $record->disbursement_batch_id === null && auth()->user()->can('revert_advance'))
                        ->requiresConfirmation()
                        ->modalHeading('Desaprobar adelanto')
                        ->modalDescription(fn (Advance $record) => 'El adelanto de '.number_format((float) $record->amount, 0, ',', '.').' Gs. de '.($record->employee?->full_name ?? 'empleado eliminado').' volverá a estado Pendiente.')
                        ->modalSubmitActionLabel('Sí, desaprobar')
                        ->action(function (Advance $record) {
                            $result = $record->revertToPending();

                            Notification::make()
                                ->title($result['success'] ? 'Adelanto desaprobado' : 'Error')
                                ->body($result['message'])
                                ->{$result['success'] ? 'warning' : 'danger'}()
                                ->send();
                        }),

                    Action::make('mark_disbursed')
                        ->label('Marcar Entregado')
                        ->icon('heroicon-o-banknotes')
                        ->color('primary')
                        ->visible(fn (Advance $record) => $record->isApproved() && auth()->user()->can('disburse_advance'))
                        ->modalHeading('Marcar Adelanto como Entregado')
                        ->modalDescription(fn (Advance $record) => 'Se confirmará que el adelanto de '.number_format((float) $record->amount, 0, ',', '.').' Gs. fue entregado a '.($record->employee?->full_name ?? 'empleado eliminado').'. Se descontará en la próxima liquidación de nómina.')
                        ->modalSubmitActionLabel('Sí, marcar como entregado')
                        ->form(fn (Advance $record) => [
                            DateTimePicker::make('disbursed_at')
                                ->label('Fecha y hora de Entrega')
                                ->required()
                                ->native(false)
                                ->default(now())
                                ->displayFormat('d/m/Y H:i'),
                            FileUpload::make('transfer_receipt_path')
                                ->label('Comprobante')
                                ->disk('public')
                                ->directory('advances/receipts')
                                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                                ->maxSize(5120)
                                ->required($record->payment_method === 'transfer')
                                ->getUploadedFileNameForStorageUsing(function ($file) use ($record): string {
                                    $ext = $file->getClientOriginalExtension();

                                    return 'comprobante_adelanto_'.$record->id.'_'.now()->format('Y-m-d_H-i-s').'.'.$ext;
                                })
                                ->helperText(($record->payment_method === 'transfer'
                                    ? 'Obligatorio para acreditación bancaria. '
                                    : 'Opcional. ')
                                    .'Formatos aceptados: PDF, JPG, PNG, WEBP. Tamaño máximo: 5 MB.'),
                        ])
                        ->action(function (Advance $record, array $data) {
                            $result = $record->markAsDisbursed(
                                $data['disbursed_at'],
                                Auth::id(),
                                $data['transfer_receipt_path'] ?? null,
                            );

                            Notification::make()
                                ->title($result['success'] ? 'Adelanto Entregado' : 'Error')
                                ->body($result['message'])
                                ->{$result['success'] ? 'success' : 'danger'}()
                                ->send();
                        }),

                    Action::make('revert_to_approved')
                        ->label('Revertir')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('warning')
                        ->visible(fn (Advance $record) => $record->isDisbursed() && $record->payroll_id === null && auth()->user()->can('revert_advance'))
                        ->requiresConfirmation()
                        ->modalHeading('Revertir Adelanto a Aprobado')
                        ->modalDescription(fn (Advance $record) => 'El adelanto de '.number_format((float) $record->amount, 0, ',', '.').' Gs. para '.($record->employee?->full_name ?? 'empleado eliminado').' volverá al estado Aprobado.')
                        ->modalSubmitActionLabel('Sí, revertir')
                        ->action(function (Advance $record) {
                            $result = $record->revertToApproved();

                            Notification::make()
                                ->title($result['success'] ? 'Adelanto Revertido' : 'Error')
                                ->body($result['message'])
                                ->{$result['success'] ? 'warning' : 'danger'}()
                                ->send();
                        }),

                    Action::make('export_pdf')
                        ->label('PDF')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->color('gray')
                        ->visible(fn (Advance $record) => $record->isApproved() || $record->isDisbursed() || $record->isPaid())
                        ->url(fn (Advance $record) => route('advances.pdf', $record))
                        ->openUrlInNewTab(),
                ])->tooltip('Acciones'),

            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('approveBulk')
                        ->label('Aprobar')
                        ->icon('heroicon-o-check')
                        ->color('success')
                        ->visible(fn () => auth()->user()->can('approve_advance'))
                        ->modalHeading('Aprobar Adelantos')
                        ->modalDescription('Se aprobarán los adelantos seleccionados que estén en estado Pendiente. Los demás serán ignorados.')
                        ->modalSubmitActionLabel('Sí, aprobar seleccionados')
                        ->form([
                            Select::make('payment_method')
                                ->label('Método de pago')
                                ->options(Advance::getPaymentMethodOptions())
                                ->default('transfer')
                                ->required()
                                ->native(false)
                                ->helperText('Se aplicará a todos los adelantos seleccionados.'),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $approved = 0;
                            $skipped = 0;
                            $failed = 0;

                            foreach ($records as $record) {
                                if (! $record->isPending()) {
                                    $skipped++;

                                    continue;
                                }

                                $result = $record->approve(Auth::id(), $data['payment_method']);
                                $result['success'] ? $approved++ : $failed++;
                            }

                            $body = "Se aprobaron {$approved} adelantos.";
                            if ($skipped > 0) {
                                $body .= " {$skipped} ignorados por no estar en estado Pendiente.";
                            }
                            if ($failed > 0) {
                                $body .= " {$failed} no pudieron aprobarse.";
                            }

                            Notification::make()
                                ->title('Aprobación Completada')
                                ->body($body)
                                ->{($skipped + $failed) > 0 ? 'warning' : 'success'}()
                                ->send()
                                ->persistent();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('rejectBulk')
                        ->label('Rechazar')
                        ->icon('heroicon-o-x-circle')
                        ->color('warning')
                        ->visible(fn () => auth()->user()->can('reject_advance'))
                        ->requiresConfirmation()
                        ->modalHeading('Rechazar Adelantos')
                        ->modalDescription('Se rechazarán los adelantos seleccionados que estén en estado Pendiente. Los demás serán ignorados.')
                        ->modalSubmitActionLabel('Sí, rechazar seleccionados')
                        ->form([
                            Textarea::make('reason')
                                ->label('Motivo del rechazo')
                                ->placeholder('Ingrese el motivo...')
                                ->rows(3),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $rejected = 0;
                            $skipped = 0;

                            foreach ($records as $record) {
                                if (! $record->isPending()) {
                                    $skipped++;

                                    continue;
                                }

                                $record->reject($data['reason'] ?? null);
                                $rejected++;
                            }

                            $body = "Se rechazaron {$rejected} adelantos.";
                            if ($skipped > 0) {
                                $body .= " {$skipped} ignorados por no estar en estado Pendiente.";
                            }

                            Notification::make()
                                ->warning()
                                ->title('Rechazo Completado')
                                ->body($body)
                                ->send()
                                ->persistent();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('markDisbursedBulk')
                        ->label('Marcar como Entregados (Efectivo)')
                        ->icon('heroicon-o-banknotes')
                        ->color('primary')
                        ->visible(fn () => auth()->user()->can('disburse_advance'))
                        ->modalHeading('Marcar Adelantos en Efectivo como Entregados')
                        ->modalDescription('Se marcarán como Entregados los adelantos seleccionados en estado Aprobado con método de pago Efectivo. Los adelantos por transferencia deben gestionarse desde Nóminas → Pagos Bancarios.')
                        ->modalSubmitActionLabel('Sí, marcar como entregados')
                        ->form([
                            DateTimePicker::make('disbursed_at')
                                ->label('Fecha y hora de Entrega')
                                ->required()
                                ->native(false)
                                ->default(now())
                                ->displayFormat('d/m/Y H:i'),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $disbursed = 0;
                            $skippedStatus = 0;
                            $skippedTransfer = 0;

                            foreach ($records as $record) {
                                if (! $record->isApproved()) {
                                    $skippedStatus++;

                                    continue;
                                }

                                if ($record->payment_method !== 'cash') {
                                    $skippedTransfer++;

                                    continue;
                                }

                                $record->markAsDisbursed($data['disbursed_at'], Auth::id());
                                $disbursed++;
                            }

                            $body = "Se marcaron {$disbursed} adelantos en efectivo como Entregados.";
                            if ($skippedTransfer > 0) {
                                $body .= " {$skippedTransfer} por transferencia ignorados (usar Pagos Bancarios).";
                            }
                            if ($skippedStatus > 0) {
                                $body .= " {$skippedStatus} ignorados por no estar en estado Aprobado.";
                            }

                            Notification::make()
                                ->title('Entrega Registrada')
                                ->body($body)
                                ->{($skippedStatus + $skippedTransfer) > 0 ? 'warning' : 'success'}()
                                ->send()
                                ->persistent();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('revertBulk')
                        ->label('Revertir a Aprobado')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('warning')
                        ->visible(fn () => auth()->user()->can('revert_advance'))
                        ->requiresConfirmation()
                        ->modalHeading('Revertir Adelantos a Aprobado')
                        ->modalDescription('Se revertirán los adelantos seleccionados que estén en estado Entregado y sin nómina asociada. Los demás serán ignorados.')
                        ->modalSubmitActionLabel('Sí, revertir seleccionados')
                        ->action(function (Collection $records) {
                            $reverted = 0;
                            $skipped = 0;

                            foreach ($records as $record) {
                                if (! $record->isDisbursed() || $record->payroll_id !== null) {
                                    $skipped++;

                                    continue;
                                }

                                $record->revertToApproved();
                                $reverted++;
                            }

                            $body = "Se revirtieron {$reverted} adelantos a Aprobado.";
                            if ($skipped > 0) {
                                $body .= " {$skipped} ignorados (no estaban en estado Entregado o tenían nómina asociada).";
                            }

                            Notification::make()
                                ->title('Reversión Completada')
                                ->body($body)
                                ->{$skipped > 0 ? 'warning' : 'success'}()
                                ->send()
                                ->persistent();
                        })
                        ->deselectRecordsAfterCompletion(),

                ]),

                BulkAction::make('pdf_masivo')
                    ->label('Descargar PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->visible(fn () => auth()->user()->can('export_advance'))
                    ->action(function (Collection $records, Component $livewire) {
                        $ids = $records->pluck('id')->implode(',');
                        $url = route('advances.pdf.bulk', ['ids' => $ids]);
                        $livewire->js("window.open('{$url}', '_blank')");
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('No se encontraron adelantos')
            ->emptyStateDescription('Verifique que se hayan registrado adelantos o intente ajustar los filtros.')
            ->emptyStateIcon('heroicon-o-currency-dollar');
    }

    /**
     * Define las relaciones del recurso.
     *
     * @return array<int, string>
     */
    public static function getRelations(): array
    {
        return [
            AdvanceResource\RelationManagers\AdvanceAuditsRelationManager::class,
        ];
    }

    /**
     * Define las páginas del recurso.
     *
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAdvances::route('/'),
            'create' => Pages\CreateAdvance::route('/create'),
            'view' => Pages\ViewAdvance::route('/{record}'),
            'edit' => Pages\EditAdvance::route('/{record}/edit'),
        ];
    }

    /**
     * Badge de navegación con conteo de adelantos pendientes.
     */
    public static function getNavigationBadge(): ?string
    {
        return (string) Advance::getPendingCount() ?: null;
    }

    /**
     * Color del badge de navegación.
     */
    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}
