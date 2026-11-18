<?php

namespace App\Filament\Resources\EmployeeResource\Schemas;

use App\Filament\Actions\MobileMarkingFields;
use App\Filament\Actions\ReportingLineActions;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Deduction;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Schedule;
use App\Services\LocationRadiusService;
use App\Support\PhoneNormalizer;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;

/** Formulario de alta y edición de empleados. */
final class EmployeeForm
{
    /** Define el formulario para crear y editar empleados. */
    public static function configure(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Datos Personales')
                    ->description('Información básica del empleado.')
                    ->icon('heroicon-o-user')
                    ->collapsible()
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                FileUpload::make('photo')
                                    ->label('Fotografía')
                                    ->disk('public')
                                    ->directory('employees/photos')
                                    ->image()
                                    ->avatar()
                                    ->imageEditor()
                                    ->circleCropper()
                                    ->maxSize(5120)
                                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/jpg'])
                                    ->downloadable()
                                    ->previewable()
                                    ->helperText('Formatos aceptados: jpg, jpeg, png. Tamaño máximo: 5MB.')
                                    ->nullable()
                                    ->columnSpan(1),

                                Grid::make(3)
                                    ->schema([
                                        TextInput::make('ci')
                                            ->label('Cédula de Identidad')
                                            ->placeholder('Ej: 1234567')
                                            ->integer()
                                            ->minValue(1)
                                            ->maxValue(99999999)
                                            ->step(1)
                                            ->required()
                                            ->unique(Employee::class, 'ci', ignoreRecord: true)
                                            ->helperText('Número sin puntos ni guiones.')
                                            ->live(onBlur: true)
                                            ->hint(fn (Get $get, ?Employee $record) => self::ciDuplicateWarning($get, $record) ? 'Ya existe un empleado con esta CI' : null)
                                            ->hintColor('danger')
                                            ->hintIcon(fn (Get $get, ?Employee $record) => self::ciDuplicateWarning($get, $record) ? 'heroicon-o-exclamation-triangle' : null),

                                        TextInput::make('first_name')
                                            ->label('Nombre(s)')
                                            ->placeholder('Ej: Juan Carlos')
                                            ->required()
                                            ->maxLength(60)
                                            ->helperText('Primer nombre y segundo nombre (si aplica).'),

                                        TextInput::make('last_name')
                                            ->label('Apellido(s)')
                                            ->placeholder('Ej: González López')
                                            ->required()
                                            ->maxLength(60)
                                            ->helperText('Primer apellido y segundo apellido (si aplica).'),

                                        Grid::make(4)
                                            ->schema([
                                                DatePicker::make('birth_date')
                                                    ->label('Fecha de nacimiento')
                                                    ->native(false)
                                                    ->displayFormat('d/m/Y')
                                                    ->maxDate(now()->subYears(18))
                                                    // Sin esto el calendario abre en el mes actual, donde todos los días están
                                                    // deshabilitados por la regla de mayoría de edad, y parece un campo bloqueado.
                                                    ->defaultFocusedDate(now()->subYears(30))
                                                    ->closeOnDateSelection()
                                                    ->required()
                                                    ->placeholder('Seleccionar fecha')
                                                    ->helperText('El empleado debe ser mayor de 18 años.'),

                                                Select::make('gender')
                                                    ->label('Género')
                                                    ->options(Employee::getGenderOptions())
                                                    ->native(false)
                                                    ->live()
                                                    ->required()
                                                    ->placeholder('Seleccionar')
                                                    ->helperText('Seleccioná el género del empleado.'),

                                                Select::make('marital_status')
                                                    ->label('Estado civil')
                                                    ->options(Employee::getMaritalStatusOptions())
                                                    ->native(false)
                                                    ->nullable()
                                                    ->placeholder('Seleccionar'),

                                                TextInput::make('nationality')
                                                    ->label('Nacionalidad')
                                                    ->maxLength(60)
                                                    ->default('Paraguaya')
                                                    ->nullable(),

                                                TextInput::make('phone')
                                                    ->label('Teléfono')
                                                    ->tel()
                                                    ->placeholder('Ej: 0981123456')
                                                    ->maxLength(10)
                                                    ->regex(PhoneNormalizer::PATTERN)
                                                    ->live(onBlur: true)
                                                    ->afterStateUpdated(PhoneNormalizer::onBlur())
                                                    ->validationMessages(['regex' => 'Ingrese un número válido de Paraguay: móvil (09XXXXXXXX) o fijo (021XXXXXX / 0XXXXXXXX).'])
                                                    ->helperText('Número sin espacios ni guiones.')
                                                    ->nullable(),

                                                TextInput::make('email')
                                                    ->label('Correo electrónico')
                                                    ->email()
                                                    ->placeholder('Ej: info@empresa.com')
                                                    ->maxLength(100)
                                                    ->unique(Employee::class, 'email', ignoreRecord: true)
                                                    ->nullable()
                                                    ->helperText('Si se ingresa, debe ser único en el sistema.'),

                                            ]),
                                    ])
                                    ->columnSpan(3),
                            ]),

                    ]),

                Section::make('Asignación Laboral')
                    ->description('Empresa, sucursal y horario de trabajo del empleado.')
                    ->icon('heroicon-o-building-office-2')
                    ->collapsible()
                    ->columns(3)
                    ->schema([
                        Select::make('company_id')
                            ->label('Empresa')
                            ->options(fn () => Company::orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->native(false)
                            ->live()
                            ->dehydrated(false)
                            ->default(fn () => Company::active()->count() === 1 ? Company::active()->value('id') : null)
                            ->afterStateUpdated(fn (callable $set) => $set('branch_id', null))
                            ->helperText('Seleccioná la empresa para filtrar las sucursales.')
                            ->afterStateHydrated(function (callable $set, callable $get) {
                                $branchId = $get('branch_id');
                                if ($branchId) {
                                    $branch = Branch::find($branchId);
                                    $set('company_id', $branch?->company_id);
                                }
                            })
                            ->required(),

                        Select::make('branch_id')
                            ->label('Sucursal')
                            ->options(function (callable $get) {
                                $companyId = $get('company_id');
                                $currentId = $get('branch_id');

                                return Branch::when($companyId, fn ($q) => $q->where('company_id', $companyId))
                                    ->where(fn ($q) => $q->where('is_active', true)->when($currentId, fn ($q) => $q->orWhere('id', $currentId)))
                                    ->orderBy('name')
                                    ->pluck('name', 'id');
                            })
                            ->searchable()
                            ->native(false)
                            ->required()
                            ->default(function (callable $get) {
                                $companyId = $get('company_id');
                                $query = Branch::active()->when($companyId, fn ($q) => $q->where('company_id', $companyId));

                                return $query->count() === 1 ? $query->value('id') : null;
                            })
                            ->helperText('Sucursal donde trabaja el empleado.'),

                        Select::make('reports_to_id')
                            ->label('Reporta a')
                            ->options(function (Get $get, ?Employee $record) {
                                $companyId = $get('company_id');

                                return Employee::where('status', 'active')
                                    ->when($companyId, fn ($q) => $q->whereHas('branch', fn ($b) => $b->where('company_id', $companyId)))
                                    ->when($record?->id, fn ($q) => $q->where('id', '!=', $record->id))
                                    ->orderBy('last_name')->orderBy('first_name')
                                    ->get()
                                    ->mapWithKeys(fn ($e) => [
                                        $e->id => $e->last_name.', '.$e->first_name.($e->activeContract?->position ? ' ('.$e->activeContract->position->name.')' : ''),
                                    ])
                                    ->toArray();
                            })
                            ->searchable()
                            ->native(false)
                            ->nullable()
                            ->placeholder('Sin superior directo')
                            ->rules(fn (?Employee $record) => [ReportingLineActions::managerRule($record)])
                            ->helperText('Superior jerárquico directo del empleado. No puede ser él mismo ni alguien que ya le reporte, directa o indirectamente.'),

                        Select::make('status')
                            ->label('Estado')
                            ->options(Employee::getStatusOptions())
                            ->native(false)
                            ->required()
                            ->hiddenOn('create')
                            ->rules([
                                fn (?Employee $record): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                                    if ($value === 'active' && $record?->status === 'inactive' && ($blocker = $record->activationBlocker())) {
                                        $fail($blocker);
                                    }
                                },
                            ])
                            ->disabled(fn (?Employee $record): bool => $record?->status === 'active' && $record->activeContract !== null)
                            ->helperText(fn (?Employee $record): string => $record?->status === 'active' && $record->activeContract !== null
                                ? 'Con contrato vigente, la baja se registra con «Dar de baja» desde la ficha.'
                                : 'Seleccioná el estado del empleado.'),

                        MobileMarkingFields::select('Por defecto se marca solo desde el terminal. «Sí» permite vincular su celular; «No» lo bloquea y revoca el vinculado. «Heredar» usa lo definido en la sucursal, la empresa o Configuración General.'),

                        TextInput::make('work_location.lat')
                            ->label('Lugar de trabajo: latitud')
                            ->numeric()
                            ->minValue(-90)
                            ->maxValue(90)
                            ->placeholder('-25.286700')
                            ->required(fn (Get $get): bool => filled($get('work_location.lng')))
                            ->helperText('Solo para quien trabaja en un lugar fijo propio (casa, oficina de un cliente). Vacío = usa el de su sucursal si controla la ubicación; si no, es «de campo» y solo se registra dónde marca.'),

                        TextInput::make('work_location.lng')
                            ->label('Lugar de trabajo: longitud')
                            ->numeric()
                            ->minValue(-180)
                            ->maxValue(180)
                            ->placeholder('-57.647800')
                            ->required(fn (Get $get): bool => filled($get('work_location.lat'))),

                        TextInput::make('location_radius_m')
                            ->label('Radio de marcación')
                            ->numeric()
                            ->minValue(LocationRadiusService::MIN_RADIUS)
                            ->maxValue(LocationRadiusService::MAX_RADIUS)
                            ->suffix('metros')
                            ->placeholder('Usar el general')
                            ->helperText('Vacío = el radio de la sucursal o el de Configuración General.'),

                        Select::make('initial_schedule_id')
                            ->label('Horario Inicial')
                            ->options(fn () => Schedule::orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->native(false)
                            ->nullable()
                            ->dehydrated(false)
                            ->visibleOn('create')
                            ->helperText('Opcional. Se asignará desde hoy. Podés ajustarlo después desde la pestaña "Horarios".'),
                    ]),

                Section::make('Protección de Maternidad')
                    ->description('Ley N° 5508/15 — Protección durante el embarazo y hasta el primer año de vida del hijo')
                    ->icon('heroicon-o-heart')
                    ->collapsible()
                    ->collapsed()
                    ->visible(fn (Get $get) => $get('gender') === 'femenino')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                DatePicker::make('maternity_protection_until')
                                    ->label('Protegida hasta')
                                    ->native(false)
                                    ->displayFormat('d/m/Y')
                                    ->nullable()
                                    ->helperText('Fecha hasta la que aplica la protección (normalmente 1 año desde el nacimiento del hijo). Dejar vacío si no aplica.')
                                    ->columnSpan(1),

                                Placeholder::make('maternity_info')
                                    ->label('¿Qué implica este campo?')
                                    ->content('Si esta fecha es hoy o posterior, el sistema mostrará una advertencia al intentar crear una liquidación para esta empleada. La protección no impide el proceso — es solo un aviso legal.')
                                    ->columnSpan(1),
                            ]),
                    ]),

                Section::make('Contrato Inicial')
                    ->description('Opcional — si lo completás, el contrato se creará junto con el empleado.')
                    ->icon('heroicon-o-document-text')
                    ->collapsible()
                    ->collapsed()
                    ->visible(fn (string $operation): bool => $operation === 'create' && (auth()->user()?->can('create_contract') ?? false))
                    ->schema([
                        Grid::make(3)->schema([
                            Select::make('ic_type')
                                ->label('Tipo de Contrato')
                                ->options(Contract::getTypeOptions())
                                ->default('indefinido')
                                ->native(false)
                                ->live()
                                ->afterStateUpdated(function (?string $state, Set $set) {
                                    if ($state === 'indefinido') {
                                        $set('ic_end_date', null);
                                    }
                                    $set('ic_trial_days', 30);
                                })
                                ->dehydrated(false),

                            Select::make('ic_work_modality')
                                ->label('Modalidad')
                                ->options(Contract::getWorkModalityOptions())
                                ->default('presencial')
                                ->native(false)
                                ->dehydrated(false),

                            Select::make('ic_payment_method')
                                ->label('Método de Pago')
                                ->options(Employee::getPaymentMethodOptions())
                                ->default('debit')
                                ->native(false)
                                ->dehydrated(false),

                            DatePicker::make('ic_start_date')
                                ->label('Fecha de Inicio')
                                ->default(now())
                                ->native(false)
                                ->displayFormat('d/m/Y')
                                ->closeOnDateSelection()
                                ->dehydrated(false),

                            DatePicker::make('ic_end_date')
                                ->label('Fecha de Finalización')
                                ->native(false)
                                ->displayFormat('d/m/Y')
                                ->closeOnDateSelection()
                                ->after('ic_start_date')
                                ->visible(fn (Get $get) => Contract::requiresEndDate($get('ic_type') ?? ''))
                                ->dehydrated(false),

                            TextInput::make('ic_trial_days')
                                ->label('Días de Prueba')
                                ->numeric()
                                ->default(30)
                                ->minValue(0)
                                ->maxValue(30)
                                ->suffix('días')
                                ->dehydrated(false),
                        ]),

                        Grid::make(3)->schema([
                            Select::make('ic_salary_type')
                                ->label('Tipo de Remuneración')
                                ->options(Contract::getSalaryTypeOptions())
                                ->default('mensual')
                                ->native(false)
                                ->live()
                                ->dehydrated(false),

                            TextInput::make('ic_salary')
                                ->label(fn (Get $get) => $get('ic_salary_type') === 'jornal' ? 'Jornal Diario' : 'Salario Mensual')
                                ->numeric()
                                ->minValue(1)
                                ->prefix('Gs.')
                                ->suffix(fn (Get $get) => $get('ic_salary_type') === 'jornal' ? '/día' : '/mes')
                                ->dehydrated(false),

                            Select::make('ic_payroll_type')
                                ->label('Tipo de Nómina')
                                ->options(Employee::getPayrollTypeOptions())
                                ->default('monthly')
                                ->native(false)
                                ->dehydrated(false),
                        ]),

                        Grid::make(2)->schema([
                            Select::make('ic_department_id')
                                ->label('Departamento')
                                ->options(function (Get $get) {
                                    $companyId = static::companyIdFromBranch($get('branch_id'));

                                    return Department::when($companyId, fn ($q) => $q->where('company_id', $companyId))
                                        ->orderBy('name')
                                        ->pluck('name', 'id');
                                })
                                ->searchable()
                                ->native(false)
                                ->live()
                                ->afterStateUpdated(fn (Set $set) => $set('ic_position_id', null))
                                ->helperText('Solo se muestran los departamentos de la empresa seleccionada arriba.')
                                ->dehydrated(false),

                            Select::make('ic_position_id')
                                ->label('Cargo')
                                ->options(function (Get $get) {
                                    $deptId = $get('ic_department_id');

                                    if ($deptId) {
                                        return Position::where('department_id', $deptId)->orderBy('name')->pluck('name', 'id')->toArray();
                                    }

                                    $companyId = static::companyIdFromBranch($get('branch_id'));

                                    return Position::query()
                                        ->when($companyId, fn ($q) => $q->whereHas('department', fn ($d) => $d->where('company_id', $companyId)))
                                        ->with('department')
                                        ->get()
                                        ->mapWithKeys(fn ($position) => [
                                            $position->id => $position->department
                                                ? "{$position->name} - {$position->department->name}"
                                                : $position->name,
                                        ])
                                        ->toArray();
                                })
                                ->searchable()
                                ->native(false)
                                ->dehydrated(false),
                        ]),
                    ]),

                Section::make('Deducciones Iniciales')
                    ->description('Las deducciones marcadas se asignarán al empleado desde hoy. Podés gestionarlas después desde la pestaña "Deducciones".')
                    ->icon('heroicon-o-minus-circle')
                    ->visibleOn('create')
                    ->schema([
                        CheckboxList::make('mandatory_deduction_ids')
                            ->label('')
                            ->options(fn () => Deduction::mandatoryAssignable()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->toArray())
                            ->default(fn () => Deduction::mandatoryAssignable()
                                ->pluck('id')
                                ->toArray())
                            ->columns(2)
                            ->dehydrated(false),
                    ]),

            ])
            ->columns(1);
    }

    /**
     * Resuelve el `company_id` a partir de un `branch_id` — usado para
     * filtrar departamento/cargo del contrato inicial por la empresa
     * elegida, mismo criterio que ya aplica `ContractsRelationManager`
     * al editar (ahí vía `$this->getOwnerRecord()->branch?->company_id`).
     */
    private static function companyIdFromBranch(mixed $branchId): ?int
    {
        return $branchId ? Branch::find($branchId)?->company_id : null;
    }

    /**
     * Indica si la CI actualmente cargada en el form ya pertenece a otro
     * empleado — usado para el aviso en vivo del campo `ci` (antes de que
     * la validación `unique()` recién se dispare al hacer submit).
     */
    private static function ciDuplicateWarning(Get $get, ?Employee $record): bool
    {
        $ci = $get('ci');

        if (blank($ci)) {
            return false;
        }

        return Employee::where('ci', $ci)
            ->when($record, fn ($q) => $q->where('id', '!=', $record->id))
            ->exists();
    }
}
