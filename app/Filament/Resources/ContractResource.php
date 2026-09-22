<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ContractResource\Pages;
use App\Filament\Resources\ContractResource\RelationManagers;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\Position;
use App\Services\ContractService;
use App\Settings\GeneralSettings;
use App\Settings\PayrollSettings;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Components\Actions\Action as InfoAction;
use Filament\Infolists\Components\Actions as InfoActions;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

class ContractResource extends Resource
{
    // Configuración general del recurso
    protected static ?string $model = Contract::class;

    protected static ?string $navigationLabel = 'Contratos';

    protected static ?string $label = 'contrato';

    protected static ?string $pluralLabel = 'contratos';

    protected static ?string $slug = 'contratos';

    protected static ?string $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $navigationGroup = 'Empleados';

    protected static ?int $navigationSort = 2;

    /**
     * Definición del formulario para crear/editar contratos
     */
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Información del Contrato')
                    ->description('Datos generales del contrato laboral')
                    ->icon('heroicon-o-document-text')
                    ->schema([
                        Select::make('employee_id')
                            ->label('Empleado')
                            ->relationship(
                                name: 'employee',
                                modifyQueryUsing: fn (Builder $query) => $query
                                    ->where('status', 'active')
                                    ->whereDoesntHave('contracts', fn (Builder $q) => $q->whereIn('status', ['active', 'draft']))
                                    ->orderBy('first_name')
                                    ->orderBy('last_name'),
                            )
                            ->getOptionLabelFromRecordUsing(fn (Model $record) => $record->full_name_with_ci)
                            ->searchable(['first_name', 'last_name', 'ci'])
                            ->native(false)
                            ->required()
                            ->preload()
                            ->live()
                            ->disabled(fn (string $operation) => $operation === 'edit')
                            ->columnSpan(2)
                            ->helperText('Solo se muestran empleados activos sin contrato vigente o en borrador'),

                        Select::make('type')
                            ->label('Tipo de Contrato')
                            ->options(Contract::getTypeOptions())
                            ->required()
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(function (?string $state, Set $set) {
                                if ($state === 'indefinido') {
                                    $set('end_date', null);
                                }
                                $set('trial_days', 30);
                            }),

                        Placeholder::make('template_warning')
                            ->label('')
                            ->content(new HtmlString(
                                '<div style="color:#b45309;font-size:12px;">⚠️ No hay plantilla configurada para este tipo de contrato en esta empresa. El PDF se generará sin cláusulas predefinidas.</div>'
                            ))
                            ->visible(function (Get $get) {
                                $type = $get('type');
                                $employeeId = $get('employee_id');
                                if (! $type || ! $employeeId) {
                                    return false;
                                }
                                $employee = Employee::with('branch')->find($employeeId);
                                $companyId = $employee?->branch?->company_id;
                                if (! $companyId) {
                                    return false;
                                }

                                return ! ContractTemplate::where('type', $type)->where('company_id', $companyId)->exists();
                            })
                            ->columnSpan(3),
                    ])
                    ->columns(3),

                Section::make('Período del Contrato')
                    ->description('Fechas y duración del contrato')
                    ->icon('heroicon-o-calendar')
                    ->schema([
                        DatePicker::make('start_date')
                            ->label('Fecha de Inicio')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->required()
                            ->default(now())
                            ->closeOnDateSelection(),

                        DatePicker::make('end_date')
                            ->label('Fecha de Finalización')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->closeOnDateSelection()
                            ->visible(fn (Get $get) => Contract::requiresEndDate($get('type') ?? ''))
                            ->required(fn (Get $get) => Contract::requiresEndDate($get('type') ?? ''))
                            ->after('start_date')
                            ->maxDate(function (Get $get) {
                                $startDate = $get('start_date');
                                $type = $get('type');
                                // Art. 53 CLT: Plazo fijo máximo 1 año
                                if ($startDate && in_array($type, ['plazo_fijo', 'aprendizaje'])) {
                                    return Carbon::parse($startDate)->addYear();
                                }

                                return null;
                            })
                            ->helperText(function (Get $get) {
                                $type = $get('type');
                                if (in_array($type, ['plazo_fijo', 'aprendizaje'])) {
                                    return 'Art. 53 CLT: Máximo 1 año para contratos a plazo determinado';
                                }

                                return null;
                            }),

                        TextInput::make('trial_days')
                            ->label('Días de Prueba')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(180)
                            ->default(30)
                            ->suffix('días'),
                    ])
                    ->columns(3),

                Section::make('Condiciones Laborales')
                    ->description('Salario, cargo y modalidad de trabajo')
                    ->icon('heroicon-o-briefcase')
                    ->schema([
                        Select::make('salary_type')
                            ->label('Tipo de Remuneración')
                            ->options(Contract::getSalaryTypeOptions())
                            ->native(false)
                            ->default('mensual')
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, ?string $state) {
                                $settings = app(PayrollSettings::class);
                                if ($state === 'mensual') {
                                    $set('payroll_type', 'monthly');
                                    $set('salary', $settings->min_salary_monthly);
                                } elseif ($state === 'jornal') {
                                    $set('salary', $settings->min_salary_daily_jornal);
                                }
                            })
                            ->helperText('Art. 231 CLT: Forma de remuneración del trabajador'),

                        Select::make('payroll_type')
                            ->label('Tipo de Nómina')
                            ->options(Employee::getPayrollTypeOptions())
                            ->native(false)
                            ->default('monthly')
                            ->required()
                            ->hidden(fn (Get $get) => $get('salary_type') === 'mensual')
                            ->dehydrated()
                            ->helperText('Define la frecuencia de pago del empleado'),

                        TextInput::make('salary')
                            ->label(fn (Get $get) => $get('salary_type') === 'jornal' ? 'Jornal Diario' : 'Salario Mensual')
                            ->numeric()
                            ->required()
                            ->minValue(fn (Get $get) => Contract::getMinSalaryFor($get('salary_type')))
                            ->prefix('Gs.')
                            ->suffix(fn (Get $get) => $get('salary_type') === 'jornal' ? '/día' : '/mes')
                            ->default(fn () => app(PayrollSettings::class)->min_salary_monthly)
                            ->validationMessages(['minValue' => 'El salario no puede ser menor al mínimo legal vigente.'])
                            ->placeholder('0'),

                        Select::make('department_id')
                            ->label('Departamento')
                            ->options(function (Get $get) {
                                $employeeId = $get('employee_id');
                                if (! $employeeId) {
                                    return Department::orderBy('name')->pluck('name', 'id');
                                }
                                $companyId = Employee::find($employeeId)?->branch?->company_id;

                                return $companyId
                                    ? Department::where('company_id', $companyId)->orderBy('name')->pluck('name', 'id')
                                    : Department::orderBy('name')->pluck('name', 'id');
                            })
                            ->searchable()
                            ->native(false)
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('position_id', null)),

                        Select::make('position_id')
                            ->label('Cargo')
                            ->options(function (Get $get) {
                                $deptId = $get('department_id');

                                return $deptId
                                    ? Position::where('department_id', $deptId)->orderBy('name')->pluck('name', 'id')->toArray()
                                    : Position::getOptionsWithDepartment();
                            })
                            ->searchable()
                            ->native(false)
                            ->required(),

                        Select::make('work_modality')
                            ->label('Modalidad de Trabajo')
                            ->options(Contract::getWorkModalityOptions())
                            ->native(false)
                            ->default('presencial')
                            ->required(),

                        Select::make('payment_method')
                            ->label('Método de Pago')
                            ->options(Employee::getPaymentMethodOptions())
                            ->native(false)
                            ->default('debit')
                            ->required()
                            ->live(),
                    ])
                    ->columns(2),

                Section::make('Cuenta Bancaria')
                    ->description('Información de cuenta para acreditación del salario')
                    ->icon('heroicon-o-credit-card')
                    ->visible(fn (Get $get) => $get('payment_method') === 'debit')
                    ->schema(function (Get $get) {
                        $employeeId = $get('employee_id');
                        $existingAccount = $employeeId
                            ? EmployeeBankAccount::where('employee_id', $employeeId)
                                ->where('status', 'active')
                                ->first()
                            : null;

                        if ($existingAccount) {
                            return [
                                Placeholder::make('bank_account_info')
                                    ->label('Cuenta registrada')
                                    ->content(
                                        'Banco: '.$existingAccount->bank_label.
                                        ' | Cuenta: '.$existingAccount->account_number.
                                        ' ('.$existingAccount->account_type_label.')'
                                    )
                                    ->columnSpanFull(),
                            ];
                        }

                        return [
                            Select::make('ba_bank')
                                ->label('Banco')
                                ->options(EmployeeBankAccount::getBankOptions())
                                ->native(false)
                                ->searchable()
                                ->dehydrated(false)
                                ->required(),
                            Select::make('ba_account_type')
                                ->label('Tipo de Cuenta')
                                ->options(EmployeeBankAccount::getAccountTypeOptions())
                                ->native(false)
                                ->dehydrated(false)
                                ->required(),
                            TextInput::make('ba_account_number')
                                ->label('Número de Cuenta')
                                ->maxLength(30)
                                ->dehydrated(false)
                                ->required(),
                            TextInput::make('ba_holder_name')
                                ->label('Titular')
                                ->dehydrated(false)
                                ->required(),
                            TextInput::make('ba_holder_ci')
                                ->label('CI del Titular')
                                ->dehydrated(false)
                                ->required(),
                        ];
                    })
                    ->columns(2),

                Section::make('Documento Firmado')
                    ->description('Documento escaneado del contrato firmado')
                    ->icon('heroicon-o-paper-clip')
                    ->schema([
                        FileUpload::make('document_path')
                            ->label('Contrato Firmado (PDF)')
                            ->disk('public')
                            ->directory('contracts')
                            ->acceptedFileTypes(['application/pdf'])
                            ->maxSize(10240) // 10 MB
                            ->downloadable()
                            ->previewable()
                            ->openable()
                            ->helperText('Suba el documento escaneado del contrato firmado. Solo PDF, máximo 10 MB.')
                            ->columnSpanFull(),
                    ])
                    ->visible(fn (string $operation) => $operation === 'edit'),

                Section::make('Cláusulas Adicionales')
                    ->description('Cláusulas específicas para este contrato, que se agregan al final del cuerpo de la plantilla')
                    ->icon('heroicon-o-document-plus')
                    ->collapsible()
                    ->schema([
                        RichEditor::make('additional_clauses')
                            ->label('Cláusulas adicionales')
                            ->helperText('Se mostrarán después de las cláusulas estándar de la plantilla en el PDF.')
                            ->toolbarButtons(['bold', 'italic', 'underline', 'orderedList', 'bulletList', 'redo', 'undo'])
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Section::make('Estado')
                    ->schema([
                        Select::make('status')
                            ->label('Estado')
                            ->options(Contract::getStatusOptions())
                            ->native(false)
                            ->default('active')
                            ->disabled(),

                        Placeholder::make('created_by')
                            ->label('Creado por')
                            ->content(fn (?Contract $record) => $record?->createdBy?->name ?? '-')
                            ->visible(fn (string $operation) => $operation === 'edit'),

                        Placeholder::make('duration')
                            ->label('Duración')
                            ->content(fn (?Contract $record) => $record?->duration_description ?? '-')
                            ->visible(fn (string $operation) => $operation === 'edit'),

                        Placeholder::make('expiration')
                            ->label('Vencimiento')
                            ->content(fn (?Contract $record) => $record?->expiration_description ?? '-')
                            ->visible(fn (string $operation, ?Contract $record = null) => $operation === 'edit' && $record?->end_date),

                        Placeholder::make('trial_status')
                            ->label('Período de Prueba')
                            ->content(function (?Contract $record) {
                                if (! $record || ! $record->trial_days) {
                                    return '-';
                                }
                                if ($record->isInTrialPeriod()) {
                                    // Mostrar días restantes, formatear en entero para evitar decimales
                                    $daysLeft = (int) $record->trial_days_left;

                                    return "{$daysLeft} días restantes";
                                }

                                return 'Período de prueba finalizado';
                            })
                            ->visible(fn (string $operation) => $operation === 'edit'),
                    ])
                    ->columns(2)
                    ->visible(fn (string $operation) => $operation === 'edit'),
            ]);
    }

    /**
     * Define el infolist para mostrar el detalle de un contrato.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfoSection::make('Contrato')
                ->description('Datos generales del contrato laboral')
                ->icon('heroicon-o-document-text')
                ->collapsible()
                ->schema([
                    TextEntry::make('employee.full_name')
                        ->label('Empleado')
                        ->icon('heroicon-o-user')
                        ->columnSpan(2),

                    TextEntry::make('employee.ci')
                        ->label('CI')
                        ->icon('heroicon-o-identification')
                        ->copyable()
                        ->tooltip('Haz clic para copiar')
                        ->copyMessage('Cédula copiada'),

                    TextEntry::make('type')
                        ->label('Tipo')
                        ->badge()
                        ->formatStateUsing(fn ($state) => Contract::getTypeLabel($state))
                        ->color(fn ($state) => Contract::getTypeColor($state))
                        ->icon(fn ($state) => Contract::getTypeIcon($state)),

                    TextEntry::make('status')
                        ->label('Estado')
                        ->badge()
                        ->formatStateUsing(fn ($state) => Contract::getStatusLabel($state))
                        ->color(fn ($state) => Contract::getStatusColor($state))
                        ->icon(fn ($state) => Contract::getStatusIcon($state)),

                    TextEntry::make('document_signed_status')
                        ->label('Documento Firmado')
                        ->badge()
                        ->getStateUsing(fn (Contract $record) => $record->document_path ? 'Cargado' : 'Pendiente')
                        ->color(fn (?string $state) => $state === 'Cargado' ? 'success' : 'gray')
                        ->icon(fn (?string $state) => $state === 'Cargado' ? 'heroicon-o-check-circle' : 'heroicon-o-clock'),
                ])
                ->columns(3),

            InfoSection::make('Período')
                ->description('Fechas y duración del contrato')
                ->icon('heroicon-o-calendar')
                ->collapsible()
                ->schema([
                    TextEntry::make('start_date')
                        ->label('Fecha de Inicio')
                        ->date('d/m/Y'),

                    TextEntry::make('end_date')
                        ->label('Fecha de Finalización')
                        ->date('d/m/Y')
                        ->placeholder('Indefinido'),

                    TextEntry::make('trial_period_status')
                        ->label('Período de Prueba')
                        ->badge()
                        ->getStateUsing(function (Contract $record) {
                            if ($record->isInTrialPeriod()) {
                                return "{$record->trial_days_left} días restantes";
                            }

                            return 'Finalizado';
                        })
                        ->color(fn (?string $state) => str_contains((string) $state, 'restantes') ? 'success' : 'gray')
                        ->hidden(fn (Contract $record) => ! $record->trial_days),

                    TextEntry::make('duration_description')
                        ->label('Duración'),

                    TextEntry::make('expiration_description')
                        ->label('Vencimiento')
                        ->placeholder('—'),
                ])
                ->columns(3),

            InfoSection::make('Condiciones Laborales')
                ->description('Salario, cargo y modalidad de trabajo')
                ->icon('heroicon-o-briefcase')
                ->collapsible()
                ->schema([
                    TextEntry::make('formatted_salary')
                        ->label('Salario'),

                    TextEntry::make('salary_type')
                        ->label('Tipo de Remuneración')
                        ->badge()
                        ->formatStateUsing(fn ($state) => Contract::getSalaryTypeLabel($state))
                        ->color(fn ($state) => Contract::getSalaryTypeColor($state))
                        ->icon(fn ($state) => Contract::getSalaryTypeIcon($state)),

                    TextEntry::make('work_modality')
                        ->label('Modalidad')
                        ->badge()
                        ->formatStateUsing(fn ($state) => Contract::getWorkModalityLabel($state))
                        ->color(fn ($state) => Contract::getWorkModalityColor($state))
                        ->icon(fn ($state) => Contract::getWorkModalityIcon($state)),

                    TextEntry::make('position.name')
                        ->label('Cargo'),

                    TextEntry::make('department.name')
                        ->label('Departamento'),

                    TextEntry::make('department.company.name')
                        ->label('Empresa'),

                    TextEntry::make('payroll_type')
                        ->label('Tipo de Nómina')
                        ->formatStateUsing(fn ($state) => Employee::getPayrollTypeOptions()[$state] ?? $state),

                    TextEntry::make('payment_method')
                        ->label('Método de Pago')
                        ->formatStateUsing(fn ($state) => Employee::getPaymentMethodOptions()[$state] ?? $state),
                ])
                ->columns(4),

            InfoSection::make('Cuenta Bancaria')
                ->description('Datos de la cuenta para acreditación del salario')
                ->icon('heroicon-o-credit-card')
                ->collapsible()
                ->visible(fn (Contract $record) => $record->payment_method === 'debit')
                ->schema(function (Contract $record) {
                    $account = $record->employee->bankAccounts()->where('status', 'active')->first();

                    if (! $account) {
                        return [
                            TextEntry::make('no_bank_account')
                                ->label('')
                                ->getStateUsing(fn () => 'Sin cuenta bancaria registrada.')
                                ->columnSpanFull(),
                            InfoActions::make([
                                InfoAction::make('ir_al_empleado')
                                    ->label('Ir al perfil del empleado')
                                    ->icon('heroicon-o-arrow-top-right-on-square')
                                    ->url(EmployeeResource::getUrl('view', ['record' => $record->employee_id]))
                                    ->openUrlInNewTab(),
                            ])->columnSpanFull(),
                        ];
                    }

                    return [
                        TextEntry::make('bank_label')
                            ->label('Banco')
                            ->getStateUsing(fn () => $account->bank_label),

                        TextEntry::make('account_number')
                            ->label('Número de Cuenta')
                            ->getStateUsing(fn () => $account->account_number)
                            ->copyable(),

                        TextEntry::make('account_type_label')
                            ->label('Tipo de Cuenta')
                            ->getStateUsing(fn () => $account->account_type_label),

                        TextEntry::make('holder_name')
                            ->label('Titular')
                            ->getStateUsing(fn () => $account->holder_name),

                        TextEntry::make('holder_ci')
                            ->label('CI del Titular')
                            ->getStateUsing(fn () => $account->holder_ci ?? '—'),
                    ];
                })
                ->columns(2),

            InfoSection::make('Cláusulas Adicionales')
                ->icon('heroicon-o-document-plus')
                ->collapsible()
                ->collapsed()
                ->schema([
                    TextEntry::make('additional_clauses')
                        ->label('')
                        ->html()
                        ->columnSpanFull()
                        ->placeholder('Sin cláusulas adicionales'),
                ])
                ->visible(fn ($record) => filled($record->additional_clauses)),

            InfoSection::make('Registro')
                ->icon('heroicon-o-information-circle')
                ->collapsible()
                ->schema([
                    TextEntry::make('createdBy.name')
                        ->label('Creado por'),

                    TextEntry::make('created_at')
                        ->label('Creado')
                        ->dateTime('d/m/Y H:i'),

                    TextEntry::make('updated_at')
                        ->label('Actualizado')
                        ->dateTime('d/m/Y H:i'),
                ])
                ->columns(3),
        ]);
    }

    /**
     * Definición de la tabla para listar contratos
     */
    public static function table(Table $table): Table
    {
        $settings = app(GeneralSettings::class);
        $alertDays = $settings->contract_alert_days;

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['employee', 'position', 'department', 'createdBy']))
            ->columns([
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
                    ->badge()
                    ->color('gray')
                    ->copyable()
                    ->tooltip('Haz clic para copiar')
                    ->copyMessage('Cédula copiada'),

                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Contract::getTypeLabel($state))
                    ->color(fn (string $state): string => Contract::getTypeColor($state))
                    ->icon(fn (string $state): string => Contract::getTypeIcon($state))
                    ->sortable(),

                TextColumn::make('employee.branch.company.name')
                    ->label('Empresa')
                    ->badge()
                    ->color('primary')
                    ->sortable()
                    ->visible(fn () => Company::active()->count() > 1),

                TextColumn::make('position.name')
                    ->label('Cargo')
                    ->sortable()
                    ->searchable()
                    ->toggleable()
                    ->wrap(),

                TextColumn::make('department.name')
                    ->label('Departamento')
                    ->sortable()
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('work_modality')
                    ->label('Modalidad')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Contract::getWorkModalityLabel($state))
                    ->color(fn (string $state): string => Contract::getWorkModalityColor($state))
                    ->icon(fn (string $state): string => Contract::getWorkModalityIcon($state))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Tipo de Contrato')
                    ->options(Contract::getTypeOptions())
                    ->native(false),

                SelectFilter::make('salary_type')
                    ->label('Tipo de Remuneración')
                    ->options(Contract::getSalaryTypeOptions())
                    ->native(false),

                SelectFilter::make('work_modality')
                    ->label('Modalidad')
                    ->options(Contract::getWorkModalityOptions())
                    ->native(false),

                SelectFilter::make('employee_id')
                    ->label('Empleado')
                    ->relationship('employee', 'first_name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->full_name} (CI: {$record->ci})")
                    ->searchable(['first_name', 'last_name', 'ci'])
                    ->preload(false)
                    ->native(false),

                Filter::make('expiring_soon')
                    ->label('Por vencer')
                    ->query(fn (Builder $query) => $query->expiringSoon($alertDays))
                    ->toggle(),

                Filter::make('expired')
                    ->label('Vencidos')
                    ->query(fn (Builder $query) => $query->expired())
                    ->toggle(),

                Filter::make('dates')
                    ->label('Fecha de Inicio')
                    ->form([
                        DatePicker::make('start_from')
                            ->label('Desde')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->closeOnDateSelection(),
                        DatePicker::make('start_until')
                            ->label('Hasta')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->closeOnDateSelection(),
                    ])
                    ->columns(2)
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['start_from'], fn (Builder $q, $date) => $q->whereDate('start_date', '>=', $date))
                            ->when($data['start_until'], fn (Builder $q, $date) => $q->whereDate('start_date', '<=', $date));
                    }),
            ])
            ->actions([
                // --- Menú dropdown (acciones de gestión) ---
                ActionGroup::make([
                    Action::make('renew')
                        ->label('Renovar')
                        ->icon('heroicon-o-arrow-path')
                        ->color('success')
                        ->visible(fn (Contract $record) => $record->status === 'active' && $record->type !== 'indefinido' && auth()->user()->can('renew_contract'))
                        ->requiresConfirmation()
                        ->modalHeading('Renovar Contrato')
                        ->modalDescription(function (Contract $record) {
                            $msg = "Se creará un nuevo contrato para {$record->employee->full_name} y el contrato actual pasará a estado 'Renovado'.";
                            if ($record->wouldBecomeIndefiniteOnRenewal()) {
                                $msg .= "\n\n⚠ Art. 53 CLT: Este contrato a plazo fijo ya fue renovado anteriormente. Al renovar nuevamente, el nuevo contrato será automáticamente de tipo INDEFINIDO.";
                            }

                            return $msg;
                        })
                        ->modalSubmitActionLabel('Sí, renovar')
                        ->form([
                            DatePicker::make('start_date')
                                ->label('Fecha de Inicio')
                                ->native(false)
                                ->displayFormat('d/m/Y')
                                ->required()
                                ->default(fn (Contract $record) => $record->end_date ?? now())
                                ->closeOnDateSelection(),

                            DatePicker::make('end_date')
                                ->label('Fecha de Finalización')
                                ->native(false)
                                ->displayFormat('d/m/Y')
                                ->closeOnDateSelection()
                                ->visible(fn (Contract $record) => ! $record->wouldBecomeIndefiniteOnRenewal())
                                ->required(fn (Contract $record) => ! $record->wouldBecomeIndefiniteOnRenewal())
                                ->helperText(fn (Contract $record) => $record->type === 'plazo_fijo' ? 'Art. 53 CLT: Máximo 1 año' : null),

                            TextInput::make('salary')
                                ->label(fn (Contract $record) => $record->salary_type === 'jornal' ? 'Jornal Diario' : 'Salario Mensual')
                                ->numeric()
                                ->required()
                                ->minValue(fn (Contract $record) => Contract::getMinSalaryFor($record->salary_type))
                                ->prefix('Gs.')
                                ->suffix(fn (Contract $record) => $record->salary_type === 'jornal' ? '/día' : '/mes')
                                ->default(fn (Contract $record) => $record->salary)
                                ->validationMessages(['minValue' => 'El salario no puede ser menor al mínimo legal vigente.']),

                            Textarea::make('notes')
                                ->label('Notas')
                                ->placeholder('Notas sobre la renovación...')
                                ->rows(2),
                        ])
                        ->action(function (Contract $record, array $data) {
                            $oldType = $record->type;
                            $newContract = ContractService::renew($record, $data);

                            $typeMsg = $newContract->type === 'indefinido' && $oldType !== 'indefinido'
                                ? ' (convertido a INDEFINIDO por Art. 53 CLT)'
                                : '';

                            Notification::make()
                                ->title('Contrato Renovado')
                                ->body("Se creó un nuevo contrato{$typeMsg} para {$record->employee->full_name}.")
                                ->success()
                                ->send();
                        }),

                    Action::make('generate_pdf')
                        ->label('Generar PDF')
                        ->icon('heroicon-o-printer')
                        ->color('info')
                        ->url(fn (Contract $record) => route('contracts.pdf', $record))
                        ->openUrlInNewTab(),

                    Action::make('upload_signed')
                        ->label(fn (Contract $record) => $record->document_path ? 'Reemplazar Firmado' : 'Subir Firmado')
                        ->icon(fn (Contract $record) => $record->document_path ? 'heroicon-o-arrow-path' : 'heroicon-o-arrow-up-tray')
                        ->color(fn (Contract $record) => $record->document_path ? 'warning' : 'success')
                        ->visible(fn (Contract $record) => $record->status === 'active')
                        ->form([
                            FileUpload::make('document_path')
                                ->label('Contrato Firmado (PDF)')
                                ->disk('public')
                                ->directory('contracts')
                                ->acceptedFileTypes(['application/pdf'])
                                ->maxSize(10240)
                                ->required()
                                ->helperText('Suba el documento escaneado del contrato firmado. Solo PDF, máximo 10 MB.'),
                        ])
                        ->modalHeading(fn (Contract $record) => $record->document_path ? 'Reemplazar Documento Firmado' : 'Subir Contrato Firmado')
                        ->modalDescription(fn (Contract $record) => $record->document_path
                            ? 'El documento actual será reemplazado por el nuevo archivo.'
                            : 'Suba el contrato escaneado con las firmas correspondientes.')
                        ->modalSubmitActionLabel('Subir documento')
                        ->action(function (Contract $record, array $data) {
                            if ($record->document_path && Storage::disk('public')->exists($record->document_path)) {
                                Storage::disk('public')->delete($record->document_path);
                            }

                            $record->update(['document_path' => $data['document_path']]);

                            Notification::make()
                                ->title('Documento subido')
                                ->body('El contrato firmado se ha guardado correctamente.')
                                ->success()
                                ->send();
                        }),

                    Action::make('download_signed')
                        ->label('Descargar Firmado')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->color('gray')
                        ->visible(fn (Contract $record) => (bool) $record->document_path)
                        ->action(fn (Contract $record) => response()->download(
                            Storage::disk('public')->path($record->document_path),
                            'contrato_firmado_'.($record->employee?->ci ?? 'sin_ci').'_'.$record->start_date->format('Y_m_d').'.pdf'
                        )),

                    Action::make('suspend')
                        ->label('Suspender Contrato')
                        ->icon('heroicon-o-pause-circle')
                        ->color('warning')
                        ->visible(fn (Contract $record) => $record->status === 'active' && auth()->user()->can('suspend_contract'))
                        ->requiresConfirmation()
                        ->modalHeading('Suspender contrato')
                        ->modalDescription(fn (Contract $record) => 'Se suspenderá el contrato de '.($record->employee?->full_name ?? 'empleado').'. Sus percepciones y deducciones serán desactivadas temporalmente.')
                        ->modalSubmitActionLabel('Sí, suspender')
                        ->action(function (Contract $record) {
                            $record->update(['status' => 'suspended']);
                            Notification::make()->success()->title('Contrato suspendido')->send();
                        }),

                    Action::make('reactivate')
                        ->label('Reactivar Contrato')
                        ->icon('heroicon-o-play-circle')
                        ->color('success')
                        ->visible(fn (Contract $record) => $record->status === 'suspended' && auth()->user()->can('reactivate_contract'))
                        ->requiresConfirmation()
                        ->modalHeading('Reactivar contrato')
                        ->modalDescription(fn (Contract $record) => 'Se reactivará el contrato de '.($record->employee?->full_name ?? 'empleado').'. Sus percepciones y deducciones serán restauradas.')
                        ->modalSubmitActionLabel('Sí, reactivar')
                        ->action(function (Contract $record) {
                            $record->update(['status' => 'active']);
                            Notification::make()->success()->title('Contrato reactivado')->send();
                        }),

                    Action::make('terminate')
                        ->label('Terminar Contrato')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->visible(fn (Contract $record) => $record->status === 'active' && auth()->user()->can('terminate_contract'))
                        ->requiresConfirmation()
                        ->modalHeading('Terminar Contrato')
                        ->modalDescription(fn (Contract $record) => '¿Está seguro de que desea terminar el contrato de '.($record->employee?->full_name ?? 'empleado eliminado').'?')
                        ->modalSubmitActionLabel('Sí, terminar')
                        ->form([
                            Textarea::make('termination_notes')
                                ->label('Motivo de terminación')
                                ->placeholder('Ingrese el motivo...')
                                ->rows(3),
                        ])
                        ->action(function (Contract $record, array $data) {
                            ContractService::terminate($record, $data['termination_notes'] ?? null);

                            Notification::make()
                                ->title('Contrato Terminado')
                                ->body('El contrato de '.($record->employee?->full_name ?? 'empleado eliminado').' ha sido terminado. Puede crear una liquidación desde el módulo correspondiente.')
                                ->warning()
                                ->persistent()
                                ->actions([
                                    NotificationAction::make('create_liquidacion')
                                        ->label('Crear Liquidación')
                                        ->url(route('filament.admin.resources.liquidaciones.create', [
                                            'employee_id' => $record->employee_id,
                                        ]))
                                        ->button(),
                                ])
                                ->send();
                        }),
                ])
                    ->icon('heroicon-o-ellipsis-vertical')
                    ->tooltip('Más acciones'),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('bulk_activate')
                        ->label('Activar seleccionados')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->visible(fn () => auth()->user()->can('activate_contract'))
                        ->requiresConfirmation()
                        ->modalHeading('Activar contratos')
                        ->modalDescription('Se activarán todos los contratos en estado Borrador del grupo seleccionado. Los demás serán ignorados.')
                        ->modalSubmitActionLabel('Sí, activar')
                        ->action(function (Collection $records) {
                            $processed = 0;
                            foreach ($records as $record) {
                                if ($record->status === 'draft') {
                                    $record->update(['status' => 'active']);
                                    $processed++;
                                }
                            }
                            $ignored = $records->count() - $processed;
                            $msg = "{$processed} contrato(s) activados.";
                            if ($ignored > 0) {
                                $msg .= " {$ignored} ignorado(s) por no estar en Borrador.";
                            }
                            Notification::make()->success()->title('Contratos activados')->body($msg)->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('bulk_suspend')
                        ->label('Suspender seleccionados')
                        ->icon('heroicon-o-pause-circle')
                        ->color('warning')
                        ->visible(fn () => auth()->user()->can('suspend_contract'))
                        ->requiresConfirmation()
                        ->modalHeading('Suspender contratos')
                        ->modalDescription('Se suspenderán todos los contratos Vigentes del grupo seleccionado. Los demás serán ignorados.')
                        ->modalSubmitActionLabel('Sí, suspender')
                        ->action(function (Collection $records) {
                            $processed = 0;
                            foreach ($records as $record) {
                                if ($record->status === 'active') {
                                    $record->update(['status' => 'suspended']);
                                    $processed++;
                                }
                            }
                            $ignored = $records->count() - $processed;
                            $msg = "{$processed} contrato(s) suspendidos.";
                            if ($ignored > 0) {
                                $msg .= " {$ignored} ignorado(s) por no estar Vigentes.";
                            }
                            Notification::make()->success()->title('Contratos suspendidos')->body($msg)->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('bulk_terminate')
                        ->label('Terminar seleccionados')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->visible(fn () => auth()->user()->can('terminate_contract'))
                        ->requiresConfirmation()
                        ->modalHeading('Terminar contratos')
                        ->modalDescription('Se terminarán todos los contratos Vigentes del grupo seleccionado. Esta acción no se puede deshacer. Los demás serán ignorados.')
                        ->modalSubmitActionLabel('Sí, terminar')
                        ->action(function (Collection $records) {
                            $processed = 0;
                            foreach ($records as $record) {
                                if ($record->status === 'active') {
                                    ContractService::terminate($record);
                                    $processed++;
                                }
                            }
                            $ignored = $records->count() - $processed;
                            $msg = "{$processed} contrato(s) terminados.";
                            if ($ignored > 0) {
                                $msg .= " {$ignored} ignorado(s) por no estar Vigentes.";
                            }
                            Notification::make()->warning()->title('Contratos terminados')->body($msg)->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No hay contratos registrados')
            ->emptyStateDescription('Comienza agregando el primer contrato al sistema')
            ->emptyStateIcon('heroicon-o-document-text');
    }

    /**
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            RelationManagers\ContractAuditsRelationManager::class,
        ];
    }

    /**
     * Definición de las páginas del recurso.
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListContracts::route('/'),
            'create' => Pages\CreateContract::route('/create'),
            'view' => Pages\ViewContract::route('/{record}'),
            'edit' => Pages\EditContract::route('/{record}/edit'),
        ];
    }

    /**
     * Muestra un badge en el menú de navegación con la cantidad de contratos que están por vencer según el período configurado en ajustes generales.
     */
    public static function getNavigationBadge(): ?string
    {
        $settings = app(GeneralSettings::class);
        $count = static::getModel()::expiringSoon($settings->contract_alert_days)->count();

        return $count > 0 ? (string) $count : null;
    }

    /**
     * Define el color del badge en el menú de navegación para contratos por vencer.
     */
    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * Define el tooltip del badge en el menú de navegación para contratos por vencer, indicando que el número representa los "Contratos por vencer".
     */
    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Contratos por vencer';
    }
}
