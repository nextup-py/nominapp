<?php

namespace App\Filament\Resources\EmployeeResource\Schemas;

use App\Filament\Actions\MobileMarkingFields;
use App\Filament\Actions\ReportingLineActions;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Schedule;
use App\Services\LocationRadiusService;
use App\Services\ReportingLineService;
use App\Support\Nationalities;
use App\Support\PhoneNormalizer;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Wizard\Step;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;

/**
 * Formulario de empleados: edición completa y asistente por pasos del alta.
 *
 * Los campos se definen una sola vez en los métodos privados y los comparten
 * `configure()` (edición) y `wizardSteps()` (alta).
 */
final class EmployeeForm
{
    /** Define el formulario completo para editar empleados. */
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
                                self::photo()->columnSpan(1),

                                Grid::make(3)
                                    ->schema([
                                        self::ci(),
                                        self::firstName(),
                                        self::lastName(),

                                        Grid::make(4)
                                            ->schema([
                                                self::birthDate(),
                                                self::gender(),
                                                self::maritalStatus(),
                                                self::nationality(),
                                                self::phone()->columnSpan(2),
                                                self::email()->columnSpan(2),
                                            ]),
                                    ])
                                    ->columnSpan(3),
                            ]),
                    ]),

                Section::make('Asignación Laboral')
                    ->description('Sucursal, superior directo, estado y marcación desde el celular.')
                    ->icon('heroicon-o-building-office-2')
                    ->collapsible()
                    ->columns(3)
                    ->schema([
                        self::company(),
                        self::branch(),
                        self::reportsTo(),
                        self::status(),

                        MobileMarkingFields::select('«Heredar» usa el valor de la sucursal, la empresa o Configuración General.'),
                    ]),

                Section::make('Lugar de trabajo propio (opcional)')
                    ->description('Solo para quien trabaja en un lugar fijo propio (casa, oficina de un cliente).')
                    ->icon('heroicon-o-map-pin')
                    ->collapsible()
                    ->collapsed(fn (?Employee $record): bool => blank($record?->work_location) && blank($record?->location_radius_m))
                    ->columns(3)
                    ->schema([
                        TextInput::make('work_location.lat')
                            ->label('Lugar de trabajo: latitud')
                            ->numeric()
                            ->minValue(-90)
                            ->maxValue(90)
                            ->placeholder('-25.286700')
                            ->required(fn (Get $get): bool => filled($get('work_location.lng')))
                            ->helperText('Vacío = usa el de su sucursal si controla la ubicación; si no, es «de campo» y solo se registra dónde marca.'),

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
                    ]),

                Section::make('Protección de Maternidad')
                    ->description('Ley N° 5508/15 — Protección durante el embarazo y hasta el primer año de vida del hijo')
                    ->icon('heroicon-o-heart')
                    ->collapsible()
                    ->collapsed(fn (?Employee $record): bool => blank($record?->maternity_protection_until))
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
            ])
            ->columns(1);
    }

    /**
     * Pasos del asistente de alta: datos básicos, ubicación y horario, y contrato (opcional).
     *
     * La foto, el contacto, el domicilio, la protección de maternidad y las deducciones
     * quedan fuera: se completan desde la ficha (las deducciones obligatorias se asignan solas).
     *
     * @return array<int, Step>
     */
    public static function wizardSteps(): array
    {
        return [
            Step::make('Datos básicos')
                ->description('Identidad del empleado')
                ->icon('heroicon-o-user')
                ->columns(3)
                ->schema([
                    self::ci(),
                    self::firstName(),
                    self::lastName(),
                    self::birthDate(),
                    self::gender(),
                    self::maritalStatus(),
                    self::nationality(),
                ]),

            Step::make('Ubicación y horario')
                ->description('Dónde y cuándo trabaja')
                ->icon('heroicon-o-building-office-2')
                ->columns(3)
                ->schema([
                    self::company(),
                    self::branch(),
                    self::initialSchedule(),
                ]),

            Step::make('Contrato')
                ->description('Opcional')
                ->icon('heroicon-o-document-text')
                ->visible(fn (): bool => auth()->user()?->can('create_contract') ?? false)
                ->schema([
                    Placeholder::make('contract_notice')
                        ->hiddenLabel()
                        ->content('Opcional. Si completás el salario y el cargo, el contrato se crea junto con el empleado; si no, el empleado queda sin contrato y se lo podés crear después desde la pestaña «Contratos» de su ficha.'),

                    ...self::contractFields(),
                ]),
        ];
    }

    /** Foto del empleado (solo edición). */
    private static function photo(): FileUpload
    {
        return FileUpload::make('photo')
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
            ->nullable();
    }

    /** Cédula de identidad, con aviso en vivo si ya existe. */
    private static function ci(): TextInput
    {
        return TextInput::make('ci')
            ->label('Cédula de Identidad')
            ->placeholder('Ej: 1234567')
            ->integer()
            ->minValue(1)
            ->maxValue(99999999)
            ->step(1)
            ->required()
            ->unique(Employee::class, 'ci', ignoreRecord: true)
            ->validationMessages(['unique' => 'Ya existe un empleado con esta cédula.'])
            ->helperText('Número sin puntos ni guiones.')
            ->live(onBlur: true)
            ->hint(fn (Get $get, ?Employee $record) => self::ciDuplicateWarning($get, $record) ? 'Ya existe un empleado con esta CI' : null)
            ->hintColor('danger')
            ->hintIcon(fn (Get $get, ?Employee $record) => self::ciDuplicateWarning($get, $record) ? 'heroicon-o-exclamation-triangle' : null);
    }

    /** Nombre(s) del empleado. */
    private static function firstName(): TextInput
    {
        return TextInput::make('first_name')
            ->label('Nombre(s)')
            ->placeholder('Ej: Juan Carlos')
            ->required()
            ->maxLength(60)
            ->helperText('Primer nombre y segundo nombre (si aplica).');
    }

    /** Apellido(s) del empleado. */
    private static function lastName(): TextInput
    {
        return TextInput::make('last_name')
            ->label('Apellido(s)')
            ->placeholder('Ej: González López')
            ->required()
            ->maxLength(60)
            ->helperText('Primer apellido y segundo apellido (si aplica).');
    }

    /** Fecha de nacimiento (mayor de 18 años). */
    private static function birthDate(): DatePicker
    {
        return DatePicker::make('birth_date')
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
            ->helperText('El empleado debe ser mayor de 18 años.');
    }

    /** Género (obligatorio; habilita la protección de maternidad en la edición). */
    private static function gender(): Select
    {
        return Select::make('gender')
            ->label('Género')
            ->options(Employee::getGenderOptions())
            ->native(false)
            ->live()
            ->required()
            ->placeholder('Seleccionar');
    }

    /** Estado civil (opcional). */
    private static function maritalStatus(): Select
    {
        return Select::make('marital_status')
            ->label('Estado civil')
            ->options(Employee::getMaritalStatusOptions())
            ->native(false)
            ->nullable()
            ->placeholder('Seleccionar');
    }

    /** Nacionalidad (opcional). */
    private static function nationality(): Select
    {
        return Select::make('nationality')
            ->label('Nacionalidad')
            ->options(fn (?Employee $record): array => Nationalities::optionsWith($record?->nationality))
            ->default(Nationalities::DEFAULT)
            ->searchable()
            ->native(false)
            ->nullable();
    }

    /** Teléfono normalizado al formato único del sistema (solo edición). */
    private static function phone(): TextInput
    {
        return TextInput::make('phone')
            ->label('Teléfono')
            ->tel()
            ->placeholder('Ej: 0981123456')
            ->maxLength(10)
            ->regex(PhoneNormalizer::PATTERN)
            ->live(onBlur: true)
            ->afterStateUpdated(PhoneNormalizer::onBlur())
            ->validationMessages(['regex' => 'Ingrese un número válido de Paraguay: móvil (09XXXXXXXX) o fijo (021XXXXXX / 0XXXXXXXX).'])
            ->helperText('Número sin espacios ni guiones.')
            ->nullable();
    }

    /** Correo electrónico (solo edición). */
    private static function email(): TextInput
    {
        return TextInput::make('email')
            ->label('Correo electrónico')
            ->email()
            ->placeholder('Ej: info@empresa.com')
            ->maxLength(100)
            ->unique(Employee::class, 'email', ignoreRecord: true)
            ->validationMessages(['unique' => 'Este correo ya está registrado en otro empleado.'])
            ->nullable()
            ->helperText('Si se ingresa, debe ser único en el sistema.');
    }

    /** Empresa (virtual): solo filtra las sucursales. */
    private static function company(): Select
    {
        return Select::make('company_id')
            ->label('Empresa')
            ->options(fn () => Company::orderBy('name')->pluck('name', 'id'))
            ->searchable()
            ->native(false)
            ->live()
            ->dehydrated(false)
            ->default(fn () => Company::active()->count() === 1 ? Company::active()->value('id') : null)
            ->afterStateUpdated(fn (callable $set) => $set('branch_id', null))
            ->helperText('Filtra las sucursales disponibles.')
            ->visible(fn (): bool => Company::active()->count() > 1)
            ->afterStateHydrated(function (callable $set, callable $get) {
                $branchId = $get('branch_id');
                if ($branchId) {
                    $branch = Branch::find($branchId);
                    $set('company_id', $branch?->company_id);
                }
            })
            ->required();
    }

    /** Sucursal del empleado (siempre incluye la ya asignada para no vaciar el select al editar). */
    private static function branch(): Select
    {
        return Select::make('branch_id')
            ->label('Sucursal')
            ->options(function (callable $get) {
                $companyId = $get('company_id');
                $currentId = $get('branch_id');

                return Branch::when($companyId, fn ($q) => $q->where('company_id', $companyId), fn ($q) => $q->whereHas('company', fn ($c) => $c->active()))
                    ->where(fn ($q) => $q->where('is_active', true)->when($currentId, fn ($q) => $q->orWhere('id', $currentId)))
                    ->orderBy('name')
                    ->pluck('name', 'id');
            })
            ->searchable()
            ->native(false)
            ->required()
            ->live()
            ->hint(fn (Get $get, ?Employee $record): ?string => $record?->activeContract !== null && filled($get('branch_id')) && (int) $get('branch_id') !== $record->branch_id
                ? 'Al cambiar de sucursal, el empleado marcará en los terminales de la nueva.'
                : null)
            ->hintColor('warning')
            ->hintIcon(fn (Get $get, ?Employee $record): ?string => $record?->activeContract !== null && filled($get('branch_id')) && (int) $get('branch_id') !== $record->branch_id
                ? 'heroicon-o-exclamation-triangle'
                : null)
            ->default(function (callable $get) {
                $companyId = $get('company_id');
                $query = Branch::active()->when($companyId, fn ($q) => $q->where('company_id', $companyId));

                return $query->count() === 1 ? $query->value('id') : null;
            });
    }

    /** Superior directo (solo edición): busca por nombre o CI entre quienes pueden serlo. */
    private static function reportsTo(): Select
    {
        return Select::make('reports_to_id')
            ->label('Reporta a')
            ->searchable()
            ->native(false)
            ->nullable()
            ->placeholder('Sin superior directo')
            ->searchPrompt('Escribir nombre o CI')
            ->getSearchResultsUsing(function (string $search, Get $get, ?Employee $record): array {
                $companyId = $get('company_id') ?? $record?->branch?->company_id;
                $excluded = $record ? [$record->id, ...app(ReportingLineService::class)->descendantIds($record)] : [];

                return Employee::query()
                    ->with('activeContract.position')
                    ->where('status', 'active')
                    ->when($companyId, fn ($q) => $q->whereHas('branch', fn ($b) => $b->where('company_id', $companyId)))
                    ->whereNotIn('id', $excluded)
                    ->where(fn ($q) => $q
                        ->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('ci', 'like', "%{$search}%"))
                    ->orderBy('last_name')->orderBy('first_name')
                    ->limit(50)
                    ->get()
                    ->mapWithKeys(fn (Employee $e): array => [$e->id => self::managerLabel($e)])
                    ->all();
            })
            ->getOptionLabelUsing(fn ($value): ?string => ($manager = Employee::query()->with('activeContract.position')->find($value)) ? self::managerLabel($manager) : null)
            ->rules(fn (?Employee $record) => [ReportingLineActions::managerRule($record)])
            ->helperText('Solo pueden ser superior empleados activos de la empresa que no le reporten a él.');
    }

    /** Etiqueta de un posible superior: «Apellido, Nombre (Cargo)». */
    private static function managerLabel(Employee $employee): string
    {
        $position = $employee->activeContract?->position?->name;

        return $employee->last_name.', '.$employee->first_name.($position ? " ({$position})" : '');
    }

    /** Estado del empleado (solo edición; con contrato vigente la baja va por «Dar de baja»). */
    private static function status(): Select
    {
        return Select::make('status')
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
                : ($record?->status === 'inactive'
                    ? 'Para reactivarlo con un contrato nuevo, usar «Reingresar» desde la ficha.'
                    : 'Al inactivar se cierran su horario, percepciones y deducciones.'));
    }

    /** Horario inicial (virtual, solo alta). */
    private static function initialSchedule(): Select
    {
        return Select::make('initial_schedule_id')
            ->label('Horario Inicial')
            ->options(fn () => Schedule::orderBy('name')->pluck('name', 'id'))
            ->searchable()
            ->native(false)
            ->nullable()
            ->dehydrated(false)
            ->helperText('Opcional. Se asigna desde hoy y puede ajustarse después desde la pestaña «Horarios».');
    }

    /**
     * Campos del contrato inicial (todos virtuales, prefijo `ic_`).
     *
     * @return array<int, Grid>
     */
    private static function contractFields(): array
    {
        return [
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
                        $companyId = self::companyIdFromBranch($get('branch_id'));

                        return Department::active()
                            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                            ->orderBy('name')
                            ->pluck('name', 'id');
                    })
                    ->searchable()
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('ic_position_id', null))
                    ->helperText('Solo se muestran los departamentos de la empresa elegida en el paso anterior.')
                    ->dehydrated(false),

                Select::make('ic_position_id')
                    ->label('Cargo')
                    ->options(function (Get $get) {
                        $deptId = $get('ic_department_id');

                        if ($deptId) {
                            return Position::active()->where('department_id', $deptId)->orderBy('name')->pluck('name', 'id')->toArray();
                        }

                        $companyId = self::companyIdFromBranch($get('branch_id'));

                        return Position::query()
                            ->assignable()
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
        ];
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
