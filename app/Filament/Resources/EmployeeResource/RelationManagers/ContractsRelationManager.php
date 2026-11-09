<?php

namespace App\Filament\Resources\EmployeeResource\RelationManagers;

use App\Filament\Resources\ContractResource;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\Position;
use App\Settings\GeneralSettings;
use App\Settings\PayrollSettings;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/** Gestiona los contratos del empleado desde su vista de detalle. */
class ContractsRelationManager extends RelationManager
{
    protected static string $relationship = 'contracts';

    protected static ?string $title = 'Contratos';

    protected static ?string $recordTitleAttribute = 'type';

    protected static ?string $modelLabel = 'Contrato';

    protected static ?string $pluralModelLabel = 'Contratos';

    public function isReadOnly(): bool
    {
        return false;
    }

    /**
     * Define el formulario para editar contratos.
     */
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Contrato')
                    ->icon('heroicon-o-document-text')
                    ->compact()
                    ->schema([
                        Select::make('type')
                            ->label('Tipo de Contrato')
                            ->options(Contract::getTypeOptions())
                            ->required()
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(function (?string $state, Set $set, Get $get) {
                                if ($state === 'indefinido') {
                                    $set('end_date', null);
                                }
                                $set('trial_days', 30);
                                if ($state && ! $get('body')) {
                                    $set('body', ContractTemplate::getForType($state)?->body);
                                }
                            })
                            ->columnSpan(2),

                        Select::make('work_modality')
                            ->label('Modalidad')
                            ->options(Contract::getWorkModalityOptions())
                            ->native(false)
                            ->default('presencial')
                            ->required(),

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
                            ->after('start_date'),

                        TextInput::make('trial_days')
                            ->label('Días de Prueba')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(180)
                            ->default(30)
                            ->suffix('días'),

                        TextInput::make('renewals_count')
                            ->label('Renovaciones previas')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(5)
                            ->default(0)
                            ->visible(fn (Get $get) => $get('type') === 'plazo_fijo')
                            ->helperText('Cuántas veces ya se renovó este plazo fijo (incluyendo contratos cargados a mano). Con 1 o más, la próxima renovación pasa a indefinido (Art. 53 CLT).'),
                    ])
                    ->columns(3),

                Section::make('Remuneración')
                    ->icon('heroicon-o-banknotes')
                    ->compact()
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
                            }),

                        TextInput::make('salary')
                            ->label(fn (Get $get) => $get('salary_type') === 'jornal' ? 'Jornal Diario' : 'Salario Mensual')
                            ->numeric()
                            ->required()
                            ->minValue(fn (Get $get) => Contract::getMinSalaryFor($get('salary_type')))
                            ->prefix('Gs.')
                            ->suffix(fn (Get $get) => $get('salary_type') === 'jornal' ? '/día' : '/mes')
                            ->default(fn () => app(PayrollSettings::class)->min_salary_monthly)
                            ->validationMessages(['minValue' => 'El salario no puede ser menor al mínimo legal vigente.'])
                            ->helperText('Para jornada nocturna, incluir el recargo del 30% en este monto (Art. 196 CLT).'),

                        Select::make('payment_method')
                            ->label('Método de Pago')
                            ->options(Employee::getPaymentMethodOptions())
                            ->native(false)
                            ->default('debit')
                            ->required()
                            ->live(),

                        Select::make('payroll_type')
                            ->label('Tipo de Nómina')
                            ->options(Employee::getPayrollTypeOptions())
                            ->native(false)
                            ->default('monthly')
                            ->required()
                            ->hidden(fn (Get $get) => $get('salary_type') === 'mensual')
                            ->dehydrated(),

                        TextInput::make('advance_percent')
                            ->label('Adelanto automático')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(25)
                            ->suffix('%')
                            ->nullable()
                            ->placeholder('Sin adelanto')
                            ->helperText('Si se define, el sistema generará un adelanto mensual por este % del salario. Máx. 25% (Art. 245 CLT).')
                            ->visible(fn (Get $get) => $get('salary_type') === 'mensual'),
                    ])
                    ->columns(2),

                Section::make('Cuenta Bancaria')
                    ->description('Información de cuenta para acreditación del salario')
                    ->icon('heroicon-o-credit-card')
                    ->compact()
                    ->visible(fn (Get $get) => $get('payment_method') === 'debit')
                    ->schema(function () {
                        $existingAccount = EmployeeBankAccount::where('employee_id', $this->getOwnerRecord()->id)
                            ->where('status', 'active')
                            ->first();

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
                            Placeholder::make('no_bank_account')
                                ->label('Sin cuenta bancaria')
                                ->content('No hay una cuenta bancaria registrada. Puede agregar una desde la pestaña "Cuentas Bancarias".')
                                ->columnSpanFull(),
                        ];
                    })
                    ->columns(2),

                Section::make('Cuerpo del Contrato')
                    ->icon('heroicon-o-document-text')
                    ->compact()
                    ->schema([
                        RichEditor::make('body')
                            ->label('Cuerpo del Contrato')
                            ->helperText('Se pre-rellena con la plantilla del tipo elegido. Podés editarlo libremente.')
                            ->columnSpanFull()
                            ->toolbarButtons([
                                'bold',
                                'italic',
                                'underline',
                                'orderedList',
                                'bulletList',
                                'redo',
                                'undo',
                            ]),
                    ]),

                Section::make('Cargo')
                    ->icon('heroicon-o-briefcase')
                    ->compact()
                    ->schema([
                        Select::make('department_id')
                            ->label('Departamento')
                            ->relationship(
                                'department',
                                'name',
                                fn (Builder $query) => $query
                                    ->where('company_id', $this->getOwnerRecord()->branch?->company_id)
                                    ->orderBy('name')
                            )
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('position_id', null))
                            ->createOptionForm([
                                Grid::make(2)->schema([
                                    TextInput::make('name')
                                        ->label('Nombre')
                                        ->placeholder('Ej: Recursos Humanos, Finanzas, IT...')
                                        ->required()
                                        ->maxLength(60)
                                        ->unique(
                                            table: Department::class,
                                            column: 'name',
                                            ignoreRecord: true,
                                            modifyRuleUsing: fn ($rule) => $rule->where('company_id', $this->getOwnerRecord()->branch?->company_id)
                                        )
                                        ->validationMessages(['unique' => 'Ya existe un departamento con ese nombre en esta empresa.'])
                                        ->helperText('El nombre debe ser único dentro de la misma empresa.'),

                                    TextInput::make('cost_center')
                                        ->label('Centro de Costo')
                                        ->placeholder('Ej: RH-001, FIN-002, IT-003...')
                                        ->unique(
                                            table: Department::class,
                                            column: 'cost_center',
                                            ignoreRecord: true,
                                            modifyRuleUsing: fn ($rule) => $rule->where('company_id', $this->getOwnerRecord()->branch?->company_id)
                                        )
                                        ->validationMessages(['unique' => 'Ya existe un departamento con ese centro de costo en esta empresa.'])
                                        ->maxLength(30)
                                        ->nullable()
                                        ->helperText('Identificador opcional para organizar y clasificar el departamento.'),

                                    Textarea::make('description')
                                        ->label('Descripción')
                                        ->placeholder('Ej: Este departamento se encarga de gestionar el talento humano...')
                                        ->nullable()
                                        ->maxLength(500)
                                        ->rows(3)
                                        ->columnSpanFull()
                                        ->helperText('La descripción es opcional.'),
                                ]),
                            ])
                            ->createOptionUsing(function (array $data) {
                                return Department::create([
                                    'name' => $data['name'],
                                    'company_id' => $this->getOwnerRecord()->branch?->company_id,
                                    'cost_center' => $data['cost_center'] ?? null,
                                    'description' => $data['description'] ?? null,
                                ])->id;
                            }),

                        Select::make('position_id')
                            ->label('Cargo')
                            ->options(function (Get $get) {
                                $deptId = $get('department_id');
                                if ($deptId) {
                                    return Position::where('department_id', $deptId)
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->toArray();
                                }

                                return Position::getOptionsWithDepartment();
                            })
                            ->searchable()
                            ->native(false)
                            ->required()
                            ->createOptionForm(function (Get $get) {
                                $companyId = $this->getOwnerRecord()->branch?->company_id;

                                return [
                                    Select::make('department_id')
                                        ->label('Departamento')
                                        ->options(
                                            Department::where('company_id', $companyId)
                                                ->orderBy('name')
                                                ->pluck('name', 'id')
                                                ->toArray()
                                        )
                                        ->default($get('department_id'))
                                        ->required()
                                        ->native(false)
                                        ->disabled()
                                        ->dehydrated(),

                                    TextInput::make('name')
                                        ->label('Nombre del Cargo')
                                        ->required()
                                        ->maxLength(255),
                                ];
                            })
                            ->createOptionUsing(function (array $data) {
                                return Position::create([
                                    'name' => $data['name'],
                                    'department_id' => $data['department_id'],
                                ])->id;
                            }),
                    ])
                    ->columns(2),
            ])
            ->columns(1);
    }

    /**
     * Define la tabla de contratos con columnas, filtros y acciones.
     */
    public function table(Table $table): Table
    {
        $settings = app(GeneralSettings::class);
        $alertDays = $settings->contract_alert_days;

        return $table
            ->recordTitleAttribute('type')
            ->modifyQueryUsing(fn ($query) => $query->with(['position', 'department'])->latest('start_date'))
            ->columns([
                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Contract::getTypeLabel($state))
                    ->color(fn (string $state): string => Contract::getTypeColor($state))
                    ->icon(fn (string $state): string => Contract::getTypeIcon($state)),

                TextColumn::make('start_date')
                    ->label('Inicio')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('end_date')
                    ->label('Fin')
                    ->date('d/m/Y')
                    ->placeholder('Indefinido')
                    ->description(fn (Contract $record) => $record->expiration_description)
                    ->color(function (Contract $record) use ($alertDays) {
                        if (! $record->end_date || $record->status !== 'active') {
                            return null;
                        }
                        if ($record->isExpired()) {
                            return 'danger';
                        }
                        if ($record->isExpiringSoon($alertDays)) {
                            return 'warning';
                        }

                        return null;
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('position.department.company.name')
                    ->label('Empresa')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('position.department.name')
                    ->label('Departamento')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('position.name')
                    ->label('Cargo')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('work_modality')
                    ->label('Modalidad')
                    ->formatStateUsing(fn (string $state) => Contract::getWorkModalityLabel($state))
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Contract::getStatusLabel($state))
                    ->color(fn (string $state): string => Contract::getStatusColor($state))
                    ->icon(fn (string $state): string => Contract::getStatusIcon($state)),
            ])
            ->headerActions([
                Action::make('new_contract')
                    ->label('Nuevo Contrato')
                    ->icon('heroicon-o-plus')
                    ->url(fn () => ContractResource::getUrl('create').'?employee_id='.$this->getOwnerRecord()->id)
                    ->openUrlInNewTab(),
            ])
            ->actions([
                Action::make('generate_pdf')
                    ->label('Generar PDF')
                    ->icon('heroicon-o-printer')
                    ->color('info')
                    ->url(fn (Contract $record) => route('contracts.pdf', $record))
                    ->openUrlInNewTab(),

                Action::make('upload_signed')
                    ->label(fn (Contract $record) => $record->document_path ? 'Reemplazar' : 'Subir Firmado')
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
                    ->action(function (Contract $record) {
                        $path = Storage::disk('public')->path($record->document_path);

                        return response()->download(
                            $path,
                            "Contrato_{$record->employee->first_name}_{$record->employee->last_name}_{$record->type}.pdf"
                        );
                    }),

                ActionGroup::make([
                    Action::make('suspend_contract')
                        ->label('Suspender contrato')
                        ->icon('heroicon-o-pause-circle')
                        ->color('warning')
                        ->tooltip('Suspender temporalmente el contrato — desactiva percepciones y deducciones')
                        ->visible(fn (Contract $record) => $record->status === 'active')
                        ->requiresConfirmation()
                        ->modalHeading('Suspender contrato')
                        ->modalDescription(fn (Contract $record) => "Se suspenderá el contrato de {$record->employee->full_name}. Sus percepciones y deducciones serán desactivadas temporalmente. Podrá reactivarse en cualquier momento.")
                        ->modalSubmitActionLabel('Sí, suspender')
                        ->action(function (Contract $record) {
                            $record->update(['status' => 'suspended']);
                            Notification::make()->success()->title('Contrato suspendido')->send();
                        }),

                    Action::make('reactivate_contract')
                        ->label('Reactivar contrato')
                        ->icon('heroicon-o-play-circle')
                        ->color('success')
                        ->tooltip('Reactivar el contrato suspendido — reactiva percepciones y deducciones')
                        ->visible(fn (Contract $record) => $record->status === 'suspended')
                        ->requiresConfirmation()
                        ->modalHeading('Reactivar contrato')
                        ->modalDescription(fn (Contract $record) => "Se reactivará el contrato de {$record->employee->full_name}. Sus percepciones y deducciones desactivadas por el sistema serán restauradas.")
                        ->modalSubmitActionLabel('Sí, reactivar')
                        ->action(function (Contract $record) {
                            $record->update(['status' => 'active']);
                            Notification::make()->success()->title('Contrato reactivado')->send();
                        }),

                    EditAction::make()
                        ->label('Editar')
                        ->icon('heroicon-o-pencil-square')
                        ->color('primary')
                        ->visible(fn (Contract $record) => $record->status === 'active'),

                    DeleteAction::make()
                        ->label('Eliminar')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->modalHeading('¿Eliminar contrato?')
                        ->modalSubmitActionLabel('Sí, eliminar')
                        ->before(function (Contract $record) {
                            if ($record->document_path && Storage::disk('public')->exists($record->document_path)) {
                                Storage::disk('public')->delete($record->document_path);
                            }
                        }),
                ])
                    ->icon('heroicon-o-ellipsis-vertical')
                    ->tooltip('Más acciones'),
            ])
            ->defaultSort('start_date', 'desc')
            ->emptyStateHeading('No hay contratos')
            ->emptyStateDescription('Comienza registrando el primer contrato del empleado')
            ->emptyStateIcon('heroicon-o-document-text');
    }
}
