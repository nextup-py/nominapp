<?php

namespace App\Filament\Resources;

use App\Filament\Actions\CompanyStatusAction;
use App\Filament\Resources\CompanyResource\Pages;
use App\Filament\Resources\CompanyResource\RelationManagers;
use App\Models\Company;
use App\Models\PyDepartment;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = 'Empresas';

    protected static ?string $navigationGroup = 'Organización';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Empresa';

    protected static ?string $pluralModelLabel = 'Empresas';

    protected static ?string $slug = 'empresas';

    protected static ?string $recordTitleAttribute = 'name';

    /** Título del registro en breadcrumbs, página y búsqueda global: nombre comercial o, si no tiene, la razón social. */
    public static function getRecordTitle(?Model $record): ?string
    {
        return $record?->display_name;
    }

    /**
     * Campos de la búsqueda global del panel.
     *
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'trade_name', 'ruc', 'employer_number'];
    }

    /**
     * Define el formulario para crear y editar empresas, organizado en secciones para mejorar la usabilidad.
     */
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Información Legal')
                    ->description('Datos legales básicos de la empresa, necesarios para su identificación y registro fiscal.')
                    ->schema([
                        TextInput::make('name')
                            ->label('Razón Social')
                            ->placeholder('Ej: Industrias XYZ S.A.')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Nombre legal registrado de la empresa.'),

                        TextInput::make('trade_name')
                            ->label('Nombre Comercial')
                            ->placeholder('Ej: El Taller de Juan')
                            ->maxLength(255)
                            ->helperText('Nombre comercial o de fantasía, si es diferente a la razón social.'),

                        TextInput::make('ruc')
                            ->label('RUC')
                            ->placeholder('Ej: 80012345-6')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(20)
                            ->regex('/^\d{1,8}-\d$/')
                            ->validationMessages([
                                'regex' => 'El formato del RUC debe ser número base + guion + dígito verificador. Ej: 80012345-6 o 1234567-1',
                            ])
                            ->helperText('Número de Registro Único de Contribuyentes (RUC).'),

                        TextInput::make('employer_number')
                            ->label('Número Patronal IPS')
                            ->placeholder('Ej: 12345678')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->integer()
                            ->minValue(1)
                            ->maxValue(99999999)
                            ->helperText('Número de registro patronal en el Instituto de Previsión Social (IPS).'),
                    ])
                    ->columns(2)
                    ->collapsible(),

                Section::make('Constitución Legal')
                    ->description('Detalles sobre la constitución legal de la empresa y su representante legal.')
                    ->schema([
                        Select::make('legal_type')
                            ->label('Tipo Societario')
                            ->required()
                            ->options(Company::$legalTypes)
                            ->searchable()
                            ->native(false)
                            ->helperText('Tipo de sociedad según la legislación vigente.'),

                        DatePicker::make('founded_at')
                            ->label('Fecha de Constitución')
                            ->placeholder('Selecciona una fecha')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->maxDate(now())
                            ->closeOnDateSelection()
                            ->helperText('Fecha en que la empresa fue legalmente constituida.'),

                        TextInput::make('legal_rep_name')
                            ->label('Representante Legal')
                            ->required()
                            ->placeholder('Ej: Juan Pérez')
                            ->maxLength(255)
                            ->helperText('Nombre completo del representante legal de la empresa.'),

                        TextInput::make('legal_rep_ci')
                            ->label('CI del Representante')
                            ->required()
                            ->placeholder('Ej: 1234567')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(99999999)
                            ->helperText('Número de Cédula de Identidad sin puntos ni guiones.'),
                    ])
                    ->columns(2)
                    ->collapsible(),

                Section::make('Contacto')
                    ->description('Información de contacto de la empresa para comunicaciones oficiales y comerciales.')
                    ->schema([
                        TextInput::make('address')
                            ->label('Dirección')
                            ->required()
                            ->placeholder('Ej: Av. Siempre Viva 123')
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->helperText('Dirección física, incluyendo calle, número y referencia si es necesario.'),

                        Select::make('department')
                            ->label('Departamento')
                            ->options(fn () => PyDepartment::getOptions())
                            ->searchable()
                            ->native(false)
                            ->live()
                            ->dehydrated(false)
                            ->afterStateHydrated(function (Select $component, ?Company $record): void {
                                $component->state(Company::departmentIdForCity($record?->city));
                            })
                            ->afterStateUpdated(fn (Set $set) => $set('city', null))
                            ->visible(fn () => PyDepartment::query()->exists())
                            ->helperText('Filtra las ciudades; no se guarda.'),

                        Select::make('city')
                            ->label('Ciudad')
                            ->required()
                            ->options(fn (Get $get, ?Company $record) => Company::citiesOptions(
                                filled($get('department')) ? (int) $get('department') : null,
                                $record?->city,
                            ))
                            ->searchable()
                            ->native(false)
                            ->helperText('Ciudad donde se encuentra ubicada la empresa.'),

                        TextInput::make('phone')
                            ->label('Teléfono')
                            ->required()
                            ->tel()
                            ->placeholder('Ej: 0981123456 o 0211234567')
                            ->maxLength(10)
                            ->regex('/^0\d{8,9}$/')
                            ->validationMessages([
                                'regex' => 'Ingrese un número válido de Paraguay: móvil (09XXXXXXXX) o fijo (021XXXXXX / 0XXXXXXXX).',
                            ])
                            ->helperText('Número de teléfono movil o fijo de la empresa.'),

                        TextInput::make('email')
                            ->label('Correo Electrónico')
                            ->required()
                            ->placeholder('Ej: contacto@empresa.com')
                            ->email()
                            ->maxLength(255)
                            ->helperText('Correo electrónico de contacto para la empresa.'),
                    ])
                    ->columns(2)
                    ->collapsible(),

                Section::make('Configuración')
                    ->description('Opciones de configuración y personalización para la empresa.')
                    ->schema([
                        FileUpload::make('logo')
                            ->label('Logo')
                            ->image()
                            ->imageEditor()
                            ->directory('companies/logos')
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'])
                            ->maxSize(5120)
                            ->getUploadedFileNameForStorageUsing(fn (TemporaryUploadedFile $file): string => 'logo_empresa_'.now()->format('Y-m-d_H-i-s').'.'.$file->getClientOriginalExtension())
                            ->helperText('Formatos: JPG, PNG, WEBP o SVG. Máximo 5 MB. El SVG se ve en los PDFs y el panel, pero no en el terminal ni en el celular (use PNG para que aparezca allí).'),

                        Toggle::make('is_active')
                            ->label('Activa')
                            ->default(true)
                            ->disabled(fn (?Company $record) => $record?->is_active && $record->activeEmployeesCount() > 0)
                            ->helperText(fn (?Company $record) => $record?->is_active && ($active = $record->activeEmployeesCount()) > 0
                                ? "No se puede desactivar: la empresa tiene {$active} empleados activos. Desvincúlelos o transfiéralos primero."
                                : 'Las empresas inactivas no aparecen en los selectores.')
                            ->hiddenOn('create'),
                    ])
                    ->collapsible(),
            ]);
    }

    /**
     * Define la infolist para mostrar detalles de la empresa en la vista de registro, organizada en secciones para mejorar la legibilidad.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfoSection::make('Datos pendientes')
                ->icon('heroicon-o-exclamation-triangle')
                ->iconColor('warning')
                ->visible(fn (Company $record) => $record->missingDataWarnings() !== [])
                ->schema([
                    TextEntry::make('_missing_data')
                        ->hiddenLabel()
                        ->getStateUsing(fn (Company $record) => $record->missingDataWarnings())
                        ->listWithLineBreaks()
                        ->bulleted()
                        ->color('warning'),
                ])
                ->collapsible(),

            InfoSection::make('Estadísticas')
                ->columns(4)
                ->schema([
                    TextEntry::make('branches_count')
                        ->label('Sucursales')
                        ->getStateUsing(fn (Company $record) => $record->branches()->count())
                        ->badge()
                        ->color('info')
                        ->icon('heroicon-o-building-office-2'),

                    TextEntry::make('departments_count')
                        ->label('Departamentos')
                        ->getStateUsing(fn (Company $record) => $record->departments()->count())
                        ->badge()
                        ->color('info')
                        ->icon('heroicon-o-building-library'),

                    TextEntry::make('active_employees')
                        ->label('Empleados Activos / Total')
                        ->getStateUsing(fn (Company $record) => $record->getEmployeesSummary())
                        ->badge()
                        ->color('success')
                        ->icon('heroicon-o-users'),

                    TextEntry::make('active_contracts')
                        ->label('Contratos Activos')
                        ->getStateUsing(fn (Company $record) => $record->activeContractsCount())
                        ->badge()
                        ->color('primary')
                        ->icon('heroicon-o-document-text'),

                    TextEntry::make('expiring_contracts')
                        ->label('Por Vencer (30 días)')
                        ->getStateUsing(fn (Company $record) => $record->expiringSoonContractsCount(30))
                        ->badge()
                        ->color(fn (string $state) => (int) $state > 0 ? 'warning' : 'gray')
                        ->icon('heroicon-o-clock'),

                    TextEntry::make('employees_without_contract')
                        ->label('Activos sin contrato')
                        ->getStateUsing(fn (Company $record) => $record->activeEmployeesWithoutContractCount())
                        ->badge()
                        ->color(fn (string $state) => (int) $state > 0 ? 'danger' : 'gray')
                        ->icon('heroicon-o-exclamation-circle'),

                    TextEntry::make('active_terminals')
                        ->label('Terminales Activos')
                        ->getStateUsing(fn (Company $record) => $record->activeTerminalsCount())
                        ->badge()
                        ->color('info')
                        ->icon('heroicon-o-computer-desktop'),

                    TextEntry::make('payroll_periods_year')
                        ->label('Períodos de Nómina del Año')
                        ->getStateUsing(fn (Company $record) => $record->payrollPeriodsCount())
                        ->badge()
                        ->color('primary')
                        ->icon('heroicon-o-banknotes'),
                ])
                ->collapsible(),

            InfoSection::make('Información Legal')
                ->columns(2)
                ->schema([
                    TextEntry::make('name')
                        ->label('Razón Social')
                        ->weight('bold')
                        ->copyable()
                        ->copyMessage('Razón social copiada')
                        ->placeholder('Sin razón social'),

                    TextEntry::make('trade_name')
                        ->label('Nombre Comercial')
                        ->copyable()
                        ->copyMessage('Nombre comercial copiado')
                        ->placeholder('Sin nombre comercial'),

                    TextEntry::make('ruc')
                        ->label('RUC')
                        ->copyable()
                        ->copyMessage('RUC copiado')
                        ->placeholder('Sin RUC'),

                    TextEntry::make('employer_number')
                        ->label('Nro. Patronal IPS')
                        ->copyable()
                        ->copyMessage('Número patronal copiado')
                        ->placeholder('Sin número patronal'),
                ])
                ->collapsible(),

            InfoSection::make('Constitución Legal')
                ->columns(2)
                ->schema([
                    TextEntry::make('legal_type')
                        ->label('Tipo Societario')
                        ->getStateUsing(fn (Company $record) => $record->legal_type_label)
                        ->badge()
                        ->color('info')
                        ->placeholder('No especificado'),

                    TextEntry::make('founded_at')
                        ->label('Fecha de Constitución')
                        ->date('d/m/Y')
                        ->placeholder('No especificada'),

                    TextEntry::make('legal_rep_name')
                        ->label('Representante Legal')
                        ->copyable()
                        ->copyMessage('Nombre copiado')
                        ->placeholder('No especificado'),

                    TextEntry::make('legal_rep_ci')
                        ->label('CI del Representante')
                        ->copyable()
                        ->copyMessage('CI copiado')
                        ->placeholder('No especificado'),
                ])
                ->collapsible(),

            InfoSection::make('Contacto')
                ->columns(2)
                ->schema([
                    TextEntry::make('address')
                        ->label('Dirección')
                        ->copyable()
                        ->copyMessage('Dirección copiada')
                        ->placeholder('Sin dirección'),

                    TextEntry::make('city')
                        ->label('Ciudad')
                        ->badge()
                        ->color('gray')
                        ->placeholder('Sin ciudad'),

                    TextEntry::make('phone')
                        ->label('Teléfono')
                        ->icon('heroicon-o-phone')
                        ->copyable()
                        ->copyMessage('Teléfono copiado')
                        ->placeholder('Sin teléfono'),

                    TextEntry::make('email')
                        ->label('Correo Electrónico')
                        ->icon('heroicon-o-envelope')
                        ->copyable()
                        ->copyMessage('Correo copiado')
                        ->placeholder('Sin correo'),
                ])
                ->collapsible(),

            InfoSection::make('Configuración')
                ->columns(2)
                ->schema([
                    ImageEntry::make('logo')
                        ->label('Logo')
                        ->height(64)
                        ->width('auto')
                        ->extraImgAttributes(['class' => 'object-contain'])
                        ->placeholder('Sin logo'),

                    TextEntry::make('is_active')
                        ->label('Estado')
                        ->formatStateUsing(fn (string $state) => $state ? 'Activa' : 'Inactiva')
                        ->badge()
                        ->color(fn (string $state) => $state ? 'success' : 'danger'),
                ])
                ->collapsible(),

            InfoSection::make('Cuenta Bancaria Principal')
                ->description('Cuenta desde la que se generan los lotes de pago bancario.')
                ->columns(2)
                ->schema([
                    TextEntry::make('_primary_account')
                        ->label('Cuenta')
                        ->icon('heroicon-o-credit-card')
                        ->getStateUsing(function (Company $record): ?string {
                            $account = $record->primaryBankAccount();

                            return $account
                                ? "{$account->bank_label} · {$account->account_type_label} · {$account->account_number}"
                                : null;
                        })
                        ->placeholder('Sin cuenta bancaria principal'),

                    TextEntry::make('_primary_account_holder')
                        ->label('Titular')
                        ->getStateUsing(fn (Company $record) => $record->primaryBankAccount()?->holder_name)
                        ->placeholder('Sin titular'),
                ])
                ->collapsible(),
        ]);
    }

    /**
     * Define la tabla para listar las empresas, con columnas clave, filtros y acciones para una gestión eficiente.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('logo')
                    ->label('Logo')
                    ->height(40)
                    ->width(80)
                    ->extraImgAttributes(['class' => 'object-contain']),

                TextColumn::make('name')
                    ->label('Razón Social')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('trade_name')
                    ->label('Nombre Comercial')
                    ->searchable()
                    ->sortable()
                    ->placeholder('Sin nombre comercial'),

                TextColumn::make('ruc')
                    ->label('RUC')
                    ->badge()
                    ->color('primary')
                    ->copyable()
                    ->copyMessage('RUC copiado')
                    ->alignCenter()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('employer_number')
                    ->label('Nro. Patronal')
                    ->badge()
                    ->color('gray')
                    ->copyable()
                    ->copyMessage('Número patronal copiado')
                    ->alignCenter()
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('city')
                    ->label('Ciudad')
                    ->searchable()
                    ->sortable()
                    ->placeholder('Sin ciudad')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('branches_count')
                    ->label('Sucursales')
                    ->counts('branches')
                    ->badge()
                    ->color('info')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('active_employees_count')
                    ->label('Empleados Activos')
                    ->counts('activeEmployees')
                    ->badge()
                    ->color('success')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('pending_data_count')
                    ->label('Datos')
                    ->getStateUsing(fn (Company $record) => (int) $record->pending_data_count)
                    ->formatStateUsing(fn (int $state) => $state === 0 ? 'Completos' : "{$state} pendiente".($state > 1 ? 's' : ''))
                    ->badge()
                    ->color(fn (int $state) => $state === 0 ? 'success' : 'warning')
                    ->icon(fn (int $state) => $state === 0 ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle')
                    ->tooltip(fn (Company $record) => $record->pendingDataList() === [] ? null : implode(' · ', $record->pendingDataList()))
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('is_active')
                    ->label('Estado')
                    ->formatStateUsing(fn ($state) => $state ? 'Activa' : 'Inactiva')
                    ->badge()
                    ->color(fn ($state) => $state ? 'success' : 'danger')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('Última actualización')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Estado')
                    ->placeholder('Todas')
                    ->trueLabel('Activas')
                    ->falseLabel('Inactivas')
                    ->native(false),
            ])
            ->actions([
                ActionGroup::make([
                    Action::make('orgChart')
                        ->label('Organigrama')
                        ->tooltip('Abre el organigrama de la empresa en una pestaña nueva')
                        ->icon('heroicon-o-rectangle-group')
                        ->color('info')
                        ->url(fn (Company $record) => route('org-chart.show', $record))
                        ->openUrlInNewTab(),

                    Action::make('orgChartPdf')
                        ->label('Organigrama en PDF')
                        ->tooltip('Descarga el organigrama como PDF en una pestaña nueva')
                        ->icon('heroicon-o-document-arrow-down')
                        ->color('gray')
                        ->url(fn (Company $record) => route('org-chart.pdf', $record))
                        ->openUrlInNewTab(),

                    CompanyStatusAction::make(Action::class),
                ]),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->withPendingData())
            ->paginationPageOptions([10, 25, 50, 100])
            ->defaultSort('name')
            ->emptyStateHeading('No hay empresas registradas')
            ->emptyStateDescription('Comienza agregando la primera empresa.')
            ->emptyStateIcon('heroicon-o-building-office-2');
    }

    /**
     * Define las relaciones para la empresa.
     */
    public static function getRelations(): array
    {
        return [
            RelationManagers\BranchesRelationManager::class,
            RelationManagers\DepartmentsRelationManager::class,
            RelationManagers\EmployeesRelationManager::class,
            RelationManagers\TerminalsRelationManager::class,
            RelationManagers\PayrollPeriodsRelationManager::class,
            RelationManagers\AguinaldoPeriodsRelationManager::class,
            RelationManagers\BankAccountsRelationManager::class,
            RelationManagers\AuditsRelationManager::class,
        ];
    }

    /**
     * Define las páginas para la gestión de empresas.
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCompanies::route('/'),
            'create' => Pages\CreateCompany::route('/create'),
            'view' => Pages\ViewCompany::route('/{record}'),
            'edit' => Pages\EditCompany::route('/{record}/edit'),
        ];
    }
}
