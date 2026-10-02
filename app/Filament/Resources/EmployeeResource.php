<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EmployeeResource\Pages;
use App\Filament\Resources\EmployeeResource\RelationManagers;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Deduction;
use App\Models\Department;
use App\Models\Employee;
use App\Models\FaceEnrollment;
use App\Models\Position;
use App\Models\Schedule;
use App\Settings\GeneralSettings;
use Carbon\Carbon;
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
use Filament\Infolists\Components\Actions\Action as InfolistAction;
use Filament\Infolists\Components\Grid as InfoGrid;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
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
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class EmployeeResource extends Resource
{
    protected static ?string $model = Employee::class;

    protected static ?string $navigationLabel = 'Empleados';

    protected static ?string $label = 'empleado';

    protected static ?string $pluralLabel = 'empleados';

    protected static ?string $navigationIcon = 'heroicon-o-user-circle';

    protected static ?string $navigationGroup = 'Empleados';

    protected static ?int $navigationSort = 1;

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

    /**
     * Define el formulario para crear y editar empleados.
     */
    public static function form(Form $form): Form
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
                                            ->hint(fn (Get $get, ?Employee $record) => static::ciDuplicateWarning($get, $record) ? 'Ya existe un empleado con esta CI' : null)
                                            ->hintColor('danger')
                                            ->hintIcon(fn (Get $get, ?Employee $record) => static::ciDuplicateWarning($get, $record) ? 'heroicon-o-exclamation-triangle' : null),

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
                                                    ->regex('/^0\d{8,9}$/')
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

                                return Branch::when($companyId, fn ($q) => $q->where('company_id', $companyId))
                                    ->orderBy('name')
                                    ->pluck('name', 'id');
                            })
                            ->searchable()
                            ->native(false)
                            ->required()
                            ->default(function (callable $get) {
                                $companyId = $get('company_id');
                                $query = Branch::when($companyId, fn ($q) => $q->where('company_id', $companyId));

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
                            ->helperText('Superior jerárquico directo del empleado.'),

                        Select::make('status')
                            ->label('Estado')
                            ->options(Employee::getStatusOptions())
                            ->native(false)
                            ->required()
                            ->hiddenOn('create')
                            ->helperText('Seleccioná el estado del empleado.'),

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
                    ->visibleOn('create')
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
     * Define la infolist para la vista de detalle del empleado.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfoSection::make('Datos Personales')
                ->icon('heroicon-o-user')
                ->schema([
                    InfoGrid::make(4)
                        ->schema([
                            ImageEntry::make('photo')
                                ->hiddenLabel()
                                ->circular()
                                ->defaultImageUrl(fn (Employee $record) => $record->avatar_url)
                                ->columnSpan(1),

                            InfoGrid::make(3)
                                ->columnSpan(3)
                                ->schema([
                                    TextEntry::make('ci')
                                        ->label('Cédula de Identidad')
                                        ->icon('heroicon-o-identification')
                                        ->copyable()
                                        ->copyMessage('CI copiada')
                                        ->badge()
                                        ->color('gray'),

                                    TextEntry::make('full_name')
                                        ->label('Nombre completo')
                                        ->getStateUsing(fn (Employee $record) => $record->full_name)
                                        ->icon('heroicon-o-user'),

                                    TextEntry::make('status')
                                        ->label('Estado')
                                        ->getStateUsing(fn (Employee $record) => $record->status_label)
                                        ->badge()
                                        ->color(fn (Employee $record) => $record->status_color)
                                        ->icon(fn (Employee $record) => $record->status_icon),

                                    TextEntry::make('birth_date')
                                        ->label('Fecha de nacimiento')
                                        ->getStateUsing(
                                            fn (Employee $record) => $record->birth_date
                                                ? $record->birth_date->format('d/m/Y').' · '.$record->age_description
                                                : null
                                        )
                                        ->icon('heroicon-o-cake')
                                        ->placeholder('No registrada'),

                                    TextEntry::make('gender')
                                        ->label('Género')
                                        ->getStateUsing(fn (Employee $record) => $record->gender_label)
                                        ->badge()
                                        ->color(fn (Employee $record) => $record->gender_color)
                                        ->placeholder('No registrado'),

                                    TextEntry::make('marital_status')
                                        ->label('Estado civil')
                                        ->getStateUsing(fn (Employee $record) => $record->marital_status_label)
                                        ->placeholder('No registrado'),
                                ]),
                        ]),

                    InfoGrid::make(4)
                        ->schema([
                            TextEntry::make('phone')
                                ->label('Teléfono')
                                ->icon('heroicon-o-phone')
                                ->url(fn (Employee $record) => $record->phone ? $record->contact_url : null)
                                ->openUrlInNewTab()
                                ->copyable()
                                ->copyMessage('Teléfono copiado')
                                ->placeholder('Sin teléfono'),

                            TextEntry::make('email')
                                ->label('Correo electrónico')
                                ->icon('heroicon-o-envelope')
                                ->copyable()
                                ->copyMessage('Email copiado')
                                ->placeholder('Sin email'),

                            TextEntry::make('nationality')
                                ->label('Nacionalidad')
                                ->placeholder('No registrada'),

                            TextEntry::make('maternity_protection_until')
                                ->label('Protección de maternidad')
                                ->date('d/m/Y')
                                ->badge()
                                ->color(fn (Employee $record) => $record->isUnderMaternityProtection() ? 'danger' : 'gray')
                                ->placeholder('No aplica'),

                        ]),
                ])
                ->collapsible(),

            InfoSection::make('Contrato Activo')
                ->icon('heroicon-o-document-text')
                ->columns(3)
                ->schema([
                    // Aviso cuando no hay contrato activo
                    TextEntry::make('_contract_notice')
                        ->hiddenLabel()
                        ->getStateUsing(fn () => 'Este empleado no tiene un contrato activo. Creá uno desde la pestaña "Contratos".')
                        ->icon('heroicon-o-exclamation-triangle')
                        ->color('warning')
                        ->columnSpanFull()
                        ->hidden(fn (Employee $record) => $record->activeContract !== null),

                    // Campos visibles solo cuando hay contrato activo
                    TextEntry::make('activeContract.position.name')
                        ->label('Cargo')
                        ->icon('heroicon-o-briefcase')
                        ->badge()
                        ->color('primary')
                        ->hidden(fn (Employee $record) => $record->activeContract === null),

                    TextEntry::make('activeContract.position.department.name')
                        ->label('Departamento')
                        ->icon('heroicon-o-building-office')
                        ->badge()
                        ->color('gray')
                        ->hidden(fn (Employee $record) => $record->activeContract === null),

                    TextEntry::make('hire_date')
                        ->label('Antigüedad')
                        ->getStateUsing(
                            fn (Employee $record) => $record->hire_date
                                ? $record->hire_date->format('d/m/Y').' · '.$record->antiquity_description
                                : null
                        )
                        ->hidden(fn (Employee $record) => $record->activeContract === null),

                    TextEntry::make('branch.company.name')
                        ->label('Empresa')
                        ->icon('heroicon-o-building-office-2')
                        ->badge()
                        ->color('info'),

                    TextEntry::make('branch.name')
                        ->label('Sucursal')
                        ->icon('heroicon-o-building-storefront')
                        ->badge()
                        ->color('info'),

                    TextEntry::make('reportsTo.full_name')
                        ->label('Reporta a')
                        ->icon('heroicon-o-arrow-up-circle')
                        ->getStateUsing(fn (Employee $record) => $record->reportsTo
                            ? $record->reportsTo->full_name.($record->reportsTo->activeContract?->position ? ' · '.$record->reportsTo->activeContract->position->name : '')
                            : null
                        )
                        ->placeholder('Sin superior asignado'),

                    TextEntry::make('subordinates_count')
                        ->label('Subordinados directos')
                        ->icon('heroicon-o-arrow-down-circle')
                        ->getStateUsing(fn (Employee $record) => $record->subordinates()->where('status', 'active')->count())
                        ->suffix(fn (Employee $record) => $record->subordinates()->where('status', 'active')->count() === 1 ? ' empleado' : ' empleados')
                        ->badge()
                        ->color(fn (Employee $record) => $record->subordinates()->where('status', 'active')->count() > 0 ? 'primary' : 'gray'),

                    TextEntry::make('employment_type')
                        ->label('Tipo de empleo')
                        ->getStateUsing(fn (Employee $record) => $record->employment_type_label)
                        ->badge()
                        ->color(fn (Employee $record) => $record->employment_type_color ?? 'gray')
                        ->icon(fn (Employee $record) => $record->employment_type_icon ?? 'heroicon-o-question-mark-circle')
                        ->hidden(fn (Employee $record) => $record->activeContract === null),

                    TextEntry::make('activeContract.salary')
                        ->label('Salario')
                        ->icon('heroicon-o-banknotes')
                        ->getStateUsing(
                            fn (Employee $record) => $record->activeContract
                                ? 'Gs. '.number_format((int) $record->activeContract->salary, 0, ',', '.').($record->activeContract->salary_type === 'jornal' ? '/día' : '/mes')
                                : null
                        )
                        ->badge()
                        ->color('success')
                        ->hidden(fn (Employee $record) => $record->activeContract === null),

                    TextEntry::make('activeContract.payroll_type')
                        ->label('Tipo de nómina')
                        ->getStateUsing(fn (Employee $record) => $record->payroll_type_label)
                        ->badge()
                        ->color(fn (Employee $record) => $record->payroll_type_color ?? 'gray')
                        ->icon(fn (Employee $record) => $record->payroll_type_icon ?? 'heroicon-o-calendar')
                        ->hidden(fn (Employee $record) => $record->activeContract === null),

                    TextEntry::make('payment_method')
                        ->label('Método de pago')
                        ->getStateUsing(fn (Employee $record) => $record->payment_method_label)
                        ->badge()
                        ->color(fn (Employee $record) => $record->payment_method_color ?? 'gray')
                        ->icon(fn (Employee $record) => $record->payment_method_icon ?? 'heroicon-o-question-mark-circle')
                        ->hidden(fn (Employee $record) => $record->activeContract === null),
                ])
                ->collapsible(),

            InfoSection::make('Reconocimiento Facial')
                ->icon('heroicon-o-camera')
                ->columns(4)
                ->headerActions([
                    InfolistAction::make('capture_face')
                        ->label(fn (Employee $record) => $record->has_face ? 'Actualizar rostro' : 'Enrolar rostro')
                        ->icon('heroicon-o-camera')
                        ->color(fn (Employee $record) => $record->has_face ? 'warning' : 'success')
                        ->url(fn (Employee $record) => route('face.capture', $record))
                        ->visible(fn (Employee $record) => $record->status === 'active'),

                    InfolistAction::make('generate_enrollment')
                        ->label('Enlace de enrolamiento')
                        ->icon('heroicon-o-link')
                        ->color('info')
                        ->visible(fn (Employee $record) => $record->status === 'active')
                        ->requiresConfirmation()
                        ->modalHeading('Generar Enlace de Registro Facial')
                        ->modalDescription(fn (Employee $record) => "Se generará un enlace temporal para que {$record->first_name} {$record->last_name} registre su rostro. El enlace expirará en ".app(GeneralSettings::class)->face_enrollment_expiry_hours.' horas.')
                        ->modalSubmitActionLabel('Generar Enlace')
                        ->action(function (Employee $record) {
                            $settings = app(GeneralSettings::class);
                            $enrollment = FaceEnrollment::createForEmployee(
                                $record,
                                Auth::id(),
                                $settings->face_enrollment_expiry_hours
                            );

                            $url = route('face-enrollment.show', $enrollment->token);

                            Notification::make()
                                ->success()
                                ->title('Enlace generado — expira en '.$settings->face_enrollment_expiry_hours.'h')
                                ->body($url)
                                ->persistent()
                                ->actions([
                                    NotificationAction::make('send_whatsapp')
                                        ->label('Enviar por WhatsApp')
                                        ->url('https://api.whatsapp.com/send?phone=595'.ltrim($record->phone ?? '', '0').'&text='.urlencode("Hola {$record->first_name}, usa este enlace para registrar tu rostro: {$url}"))
                                        ->openUrlInNewTab()
                                        ->visible(fn () => filled($record->phone)),
                                ])
                                ->send();
                        }),
                ])
                ->schema([
                    IconEntry::make('has_face')
                        ->label('Rostro registrado')
                        ->getStateUsing(fn (Employee $record) => $record->has_face)
                        ->boolean()
                        ->trueIcon('heroicon-o-check-circle')
                        ->falseIcon('heroicon-o-x-circle')
                        ->trueColor('success')
                        ->falseColor('warning'),

                    TextEntry::make('face_tooltip')
                        ->label('Estado facial')
                        ->getStateUsing(fn (Employee $record) => $record->face_tooltip)
                        ->badge()
                        ->color(fn (Employee $record) => $record->has_face ? 'success' : 'warning'),

                    TextEntry::make('created_at')
                        ->label('Registrado')
                        ->getStateUsing(fn (Employee $record) => $record->created_at->format('d/m/Y H:i').' · '.$record->created_at_since),

                    TextEntry::make('updated_at')
                        ->label('Última actualización')
                        ->getStateUsing(fn (Employee $record) => $record->updated_at->format('d/m/Y H:i').' · '.$record->updated_at_since),
                ])
                ->collapsible(),
        ]);
    }

    /**
     * Define la tabla para listar empleados.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('photo')
                    ->label('Foto')
                    ->circular()
                    ->defaultImageUrl(fn ($record) => $record->avatar_url)
                    ->toggleable(),

                TextColumn::make('full_name')
                    ->label('Nombre')
                    ->getStateUsing(fn (Employee $record): string => $record->full_name)
                    ->description(fn (Employee $record): string => 'CI: '.$record->ci)
                    ->searchable(query: fn (Builder $query, string $search) => $query
                        ->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('ci', 'like', "%{$search}%")
                    )
                    ->sortable(['first_name', 'last_name'])
                    ->wrap(),

                TextColumn::make('activeContract.position.name')
                    ->label('Cargo')
                    ->icon('heroicon-o-briefcase')
                    ->sortable()
                    ->searchable()
                    ->toggleable()
                    ->wrap()
                    ->badge()
                    ->color('primary')
                    ->default('-'),

                TextColumn::make('branch.name')
                    ->label('Sucursal')
                    ->icon('heroicon-o-building-storefront')
                    ->sortable()
                    ->searchable()
                    ->toggleable()
                    ->wrap()
                    ->badge()
                    ->color('info'),

                TextColumn::make('status')
                    ->label('Estado')
                    ->icon(fn (Employee $record): string => $record->status_icon)
                    ->color(fn (Employee $record): string => $record->status_color)
                    ->formatStateUsing(fn (Employee $record): string => $record->status_label)
                    ->badge()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('phone')
                    ->label('Contacto')
                    ->icon('heroicon-o-phone')
                    ->default('-')
                    ->url(fn (Employee $record): ?string => $record->phone ? $record->contact_url : null)
                    ->openUrlInNewTab()
                    ->tooltip(fn (Employee $record): string => $record->phone ? 'Haz clic para enviar WhatsApp' : 'Sin teléfono registrado')
                    ->sortable()
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('activeContract.payroll_type')
                    ->label('Nómina')
                    ->icon('heroicon-o-calendar')
                    ->formatStateUsing(fn ($state): string => Employee::getPayrollTypeOptions()[$state] ?? 'Sin contrato')
                    ->badge()
                    ->color('info')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('mobile_linked_at')
                    ->label('Dispositivo vinculado')
                    ->formatStateUsing(fn ($state) => $state ? 'Vinculado' : 'No vinculado')
                    ->badge()
                    ->color(fn ($state) => $state ? 'success' : 'gray')
                    ->tooltip(fn (Employee $record) => $record->mobile_linked_at?->format('d/m/Y H:i'))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('mobile_last_heartbeat_at')
                    ->label('Último sync dispositivo')
                    ->since()
                    ->placeholder('Sin sincronizar')
                    ->tooltip(fn (Employee $record) => $record->mobile_last_heartbeat_at?->format('d/m/Y H:i'))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Registrado')
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
                    ->options(
                        fn () => Company::orderBy('name')
                            ->get()
                            ->mapWithKeys(fn ($c) => [$c->id => $c->name.($c->trade_name ? ' — '.$c->trade_name : '')])
                    )
                    ->placeholder('Todas las empresas')
                    ->searchable()
                    ->native(false)
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'])) {
                            return $query;
                        }

                        return $query->whereHas('branch', fn ($q) => $q->where('company_id', $data['value']));
                    }),

                SelectFilter::make('branch_id')
                    ->label('Sucursal')
                    ->relationship('branch', 'name')
                    ->placeholder('Todas las sucursales')
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->multiple(),

                SelectFilter::make('department_id')
                    ->label('Departamento')
                    ->options(fn () => Department::orderBy('name')->pluck('name', 'id'))
                    ->placeholder('Todos los departamentos')
                    ->searchable()
                    ->native(false)
                    ->multiple()
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['values'])) {
                            return $query;
                        }

                        return $query->whereHas('activeContract.position', fn ($q) => $q->whereIn('department_id', $data['values']));
                    }),

                SelectFilter::make('payroll_type')
                    ->label('Tipo de nómina')
                    ->options(Employee::getPayrollTypeOptions())
                    ->placeholder('Todos los tipos')
                    ->native(false)
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'])) {
                            return $query;
                        }

                        return $query->whereHas('activeContract', fn ($q) => $q->where('payroll_type', $data['value']));
                    }),

                Filter::make('hire_date')
                    ->label('Inicio de contrato activo')
                    ->form([
                        DatePicker::make('hired_from')
                            ->label('Desde')
                            ->native(false)
                            ->closeOnDateSelection(),
                        DatePicker::make('hired_until')
                            ->label('Hasta')
                            ->native(false)
                            ->closeOnDateSelection(),
                    ])
                    ->columns(2)
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['hired_from'],
                                fn (Builder $query, $date): Builder => $query->whereHas('activeContract', fn ($q) => $q->where('start_date', '>=', Carbon::parse($date)->startOfDay())),
                            )
                            ->when(
                                $data['hired_until'],
                                fn (Builder $query, $date): Builder => $query->whereHas('activeContract', fn ($q) => $q->where('start_date', '<=', Carbon::parse($date)->endOfDay())),
                            );
                    }),

                TernaryFilter::make('has_face')
                    ->label('Reconocimiento facial')
                    ->placeholder('Todos')
                    ->trueLabel('Con rostro registrado')
                    ->falseLabel('Sin rostro registrado')
                    ->queries(
                        true: fn (Builder $query) => $query->withFace(),
                        false: fn (Builder $query) => $query->withoutFace(),
                        blank: fn (Builder $query) => $query,
                    ),

                SelectFilter::make('birthday_month')
                    ->label('Mes de cumpleaños')
                    ->options([
                        1 => 'Enero',
                        2 => 'Febrero',
                        3 => 'Marzo',
                        4 => 'Abril',
                        5 => 'Mayo',
                        6 => 'Junio',
                        7 => 'Julio',
                        8 => 'Agosto',
                        9 => 'Septiembre',
                        10 => 'Octubre',
                        11 => 'Noviembre',
                        12 => 'Diciembre',
                    ])
                    ->placeholder('Todos los meses')
                    ->native(false)
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'])) {
                            return $query;
                        }

                        return $query->whereMonth('birth_date', $data['value']);
                    }),

            ])
            ->actions([
                ActionGroup::make([
                    Action::make('capture_face')
                        ->label(fn (Employee $record): string => $record->has_face ? 'Re-enrolar rostro' : 'Enrolar rostro')
                        ->icon('heroicon-o-camera')
                        ->tooltip(fn (Employee $record): string => $record->has_face ? 'Capturar nuevo descriptor facial (reemplaza el actual)' : 'Registrar rostro del empleado por primera vez')
                        ->url(fn (Employee $record): string => route('face.capture', $record))
                        ->color(fn (Employee $record): string => $record->has_face ? 'warning' : 'success')
                        ->visible(fn (Employee $record): bool => $record->status === 'active'),

                    Action::make('generate_enrollment')
                        ->label('Generar enlace de enrolamiento')
                        ->icon('heroicon-o-link')
                        ->color('info')
                        ->tooltip('Generar enlace temporal para que el empleado registre su rostro desde su propio dispositivo')
                        ->visible(fn (Employee $record): bool => $record->status === 'active')
                        ->requiresConfirmation()
                        ->modalHeading('Generar Enlace de Registro Facial')
                        ->modalDescription(fn (Employee $record) => "Se generará un enlace temporal para que {$record->first_name} {$record->last_name} registre su rostro. El enlace expirará en ".app(GeneralSettings::class)->face_enrollment_expiry_hours.' horas.')
                        ->modalSubmitActionLabel('Generar Enlace')
                        ->action(function (Employee $record) {
                            $settings = app(GeneralSettings::class);
                            $enrollment = FaceEnrollment::createForEmployee(
                                $record,
                                Auth::id(),
                                $settings->face_enrollment_expiry_hours
                            );

                            $url = route('face-enrollment.show', $enrollment->token);

                            Notification::make()
                                ->success()
                                ->title('Enlace generado — expira en '.$settings->face_enrollment_expiry_hours.'h')
                                ->body($url)
                                ->persistent()
                                ->actions([
                                    NotificationAction::make('send_whatsapp')
                                        ->label('Enviar por WhatsApp')
                                        ->url('https://api.whatsapp.com/send?phone=595'.ltrim($record->phone ?? '', '0').'&text='.urlencode("Hola {$record->first_name}, usa este enlace para registrar tu rostro: {$url}"))
                                        ->openUrlInNewTab()
                                        ->visible(fn () => filled($record->phone)),
                                ])
                                ->send();
                        }),

                    Action::make('change_status')
                        ->label('Cambiar estado')
                        ->icon('heroicon-o-arrow-path')
                        ->color('gray')
                        ->tooltip('Cambiar el estado del empleado (activo, suspendido, inactivo)')
                        ->modalSubmitActionLabel('Cambiar estado')
                        ->form([
                            Select::make('status')
                                ->label('Nuevo estado')
                                ->options(
                                    fn (Employee $record) => collect(Employee::getStatusOptions())
                                        ->except($record->status)
                                        ->toArray()
                                )
                                ->required()
                                ->native(false),
                        ])
                        ->action(function (Employee $record, array $data, Action $action): void {
                            if ($data['status'] === 'inactive' && $record->activeContract !== null) {
                                Notification::make()
                                    ->danger()
                                    ->title('No se puede desactivar')
                                    ->body('El empleado tiene un contrato activo. Procesá una Liquidación primero para cerrar el contrato y liquidar los haberes correctamente.')
                                    ->send();
                                $action->halt();

                                return;
                            }

                            $record->update(['status' => $data['status']]);

                            Notification::make()
                                ->success()
                                ->title('Estado actualizado')
                                ->body("El estado de {$record->full_name} cambió a: ".Employee::getStatusOptions()[$data['status']])
                                ->send();
                        }),

                    Action::make('revoke_mobile_session')
                        ->label('Revocar sesión móvil')
                        ->icon('heroicon-o-device-phone-mobile')
                        ->color('danger')
                        ->tooltip('Desvincula el dispositivo vinculado a este empleado para marcación offline — requerirá vincularse de nuevo desde /vincular-dispositivo')
                        ->visible(fn (Employee $record): bool => $record->hasMobileLinked())
                        ->requiresConfirmation()
                        ->modalHeading('Revocar sesión móvil')
                        ->modalDescription(function (Employee $record): string {
                            $base = "El dispositivo vinculado de {$record->first_name} {$record->last_name} perderá acceso a la marcación offline de inmediato. Deberá vincularse de nuevo con CI + fecha de nacimiento.";

                            $linkedAt = $record->mobile_linked_at?->format('d/m/Y H:i');
                            $lastSync = $record->mobile_last_heartbeat_at?->diffForHumans() ?? 'nunca sincronizó';

                            return "{$base} Vinculado el {$linkedAt}. Último sync: {$lastSync}.";
                        })
                        ->modalSubmitActionLabel('Sí, revocar')
                        ->action(function (Employee $record) {
                            $record->revokeMobileToken();

                            Notification::make()
                                ->success()
                                ->title('Sesión móvil revocada')
                                ->body('El empleado deberá vincular su dispositivo de nuevo para volver a marcar offline.')
                                ->send();
                        }),
                ]),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('activate')
                        ->label('Activar seleccionados')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Activar empleados')
                        ->modalDescription(fn (Collection $records) => "Se activarán {$records->count()} empleado(s). Esta acción no se puede deshacer.")
                        ->modalSubmitActionLabel('Sí, activar')
                        ->action(function (Collection $records): void {
                            $count = $records->count();
                            Employee::whereIn('id', $records->pluck('id'))->update(['status' => 'active']);
                            Notification::make()
                                ->success()
                                ->title('Empleados activados')
                                ->body("{$count} empleado(s) activado(s) correctamente.")
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('deactivate')
                        ->label('Desactivar seleccionados')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Desactivar empleados')
                        ->modalDescription(function (Collection $records) {
                            $records->loadMissing('activeContract');
                            $withContract = $records->filter(fn ($e) => $e->activeContract !== null)->count();
                            $base = "Se desactivarán {$records->count()} empleado(s). Esta acción no se puede deshacer.";

                            return $withContract > 0
                                ? $base." Atención: {$withContract} empleado(s) con contrato activo serán ignorados (procesá una Liquidación para darlos de baja)."
                                : $base;
                        })
                        ->modalSubmitActionLabel('Sí, desactivar')
                        ->action(function (Collection $records): void {
                            $records->loadMissing('activeContract');
                            $withContract = $records->filter(fn ($e) => $e->activeContract !== null);
                            $toDeactivate = $records->reject(fn ($e) => $e->activeContract !== null);

                            if ($toDeactivate->isNotEmpty()) {
                                Employee::whereIn('id', $toDeactivate->pluck('id'))->update(['status' => 'inactive']);
                            }

                            $parts = [];
                            if ($toDeactivate->isNotEmpty()) {
                                $parts[] = "{$toDeactivate->count()} desactivado(s)";
                            }
                            if ($withContract->isNotEmpty()) {
                                $parts[] = "{$withContract->count()} ignorado(s) por tener contrato activo";
                            }

                            Notification::make()
                                ->{$toDeactivate->isEmpty() ? 'warning' : 'success'}()
                                ->title($toDeactivate->isEmpty() ? 'Sin cambios' : 'Empleados desactivados')
                                ->body(implode(', ', $parts).'.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('set_advance_percent')
                        ->label('Configurar adelanto automático')
                        ->icon('heroicon-o-banknotes')
                        ->color('info')
                        ->form([
                            Select::make('advance_percent')
                                ->label('Porcentaje de adelanto')
                                ->options([
                                    '' => 'Sin adelanto automático',
                                    '10' => '10%',
                                    '15' => '15%',
                                    '20' => '20%',
                                    '25' => '25% (máximo legal)',
                                ])
                                ->native(false)
                                ->required()
                                ->helperText('Se actualizará el contrato activo mensualizado de cada empleado seleccionado.'),
                        ])
                        ->requiresConfirmation()
                        ->modalHeading('Configurar adelanto automático')
                        ->modalDescription('El sistema generará automáticamente el adelanto el 1° de cada mes para los empleados configurados.')
                        ->modalSubmitActionLabel('Aplicar configuración')
                        ->action(function (Collection $records, array $data): void {
                            $percent = $data['advance_percent'] !== '' ? (int) $data['advance_percent'] : null;
                            $updated = 0;

                            foreach ($records as $employee) {
                                $contract = $employee->activeContract;
                                if ($contract && $contract->salary_type === 'mensual') {
                                    $contract->update(['advance_percent' => $percent]);
                                    $updated++;
                                }
                            }

                            $body = $percent
                                ? "Adelanto del {$percent}% configurado en {$updated} contrato(s)."
                                : "Adelanto automático desactivado en {$updated} contrato(s).";

                            Notification::make()
                                ->success()
                                ->title('Adelanto automático actualizado')
                                ->body($body)
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No hay empleados registrados')
            ->emptyStateDescription('Comienza agregando tu primer empleado al sistema')
            ->emptyStateIcon('heroicon-o-user-circle');
    }

    /**
     * Define las relaciones para el recurso de empleado.
     */
    public static function getRelations(): array
    {
        return [
            RelationManagers\ContractsRelationManager::class,
            RelationManagers\AddressesRelationManager::class,
            RelationManagers\ChildrenRelationManager::class,
            RelationManagers\DocumentsRelationManager::class,
            RelationManagers\VacationsRelationManager::class,
            RelationManagers\LeavesRelationManager::class,
            RelationManagers\EmployeeDeductionsRelationManager::class,
            RelationManagers\EmployeePerceptionsRelationManager::class,
            RelationManagers\BankAccountsRelationManager::class,
            RelationManagers\WarningsRelationManager::class,
            RelationManagers\DevicesRelationManager::class,
        ];
    }

    /**
     * Define las páginas para el recurso de empleado.
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmployees::route('/'),
            'create' => Pages\CreateEmployee::route('/create'),
            'view' => Pages\ViewEmployee::route('/{record}'),
            'edit' => Pages\EditEmployee::route('/{record}/edit'),
        ];
    }
}
