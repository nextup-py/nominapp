<?php

namespace App\Filament\Pages;

use App\Models\Company;
use App\Settings\GeneralSettings;
use App\Settings\ModuleSettings;
use App\Settings\PayrollSettings;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Wizard;
use Filament\Forms\Components\Wizard\Step;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/**
 * Asistente de configuración inicial. Obligatorio en instalaciones nuevas
 * (bloqueado por EnsureSetupIsComplete hasta completarse), reabrible después
 * de forma manual y no bloqueante desde ManageGeneralSettings.
 */
class SetupWizardPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'setup-inicial';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.setup-wizard-page';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public bool $isReopen = false;

    public static function canAccess(): bool
    {
        return auth()->user()?->roles()->exists() ?? false;
    }

    public function mount(): void
    {
        $this->isReopen = app(GeneralSettings::class)->setup_completed;

        $this->form->fill($this->isReopen ? $this->stateFromExistingData() : $this->defaultState());
    }

    /** @return array<string, mixed> */
    protected function defaultState(): array
    {
        return [
            'branch_mode' => 'single',
            'company' => [],
            'branches' => [['name' => 'Casa Matriz']],
            'departments' => [],
            'modules' => app(ModuleSettings::class)->toArray(),
            'payroll' => app(PayrollSettings::class)->toArray(),
            'general' => ['timezone' => app(GeneralSettings::class)->timezone],
        ];
    }

    /** @return array<string, mixed> */
    protected function stateFromExistingData(): array
    {
        $company = Company::with('branches', 'departments.positions')->first();

        return [
            'branch_mode' => ($company?->branches->count() ?? 0) > 1 ? 'multiple' : 'single',
            'company' => $company?->only(['name', 'trade_name', 'ruc', 'employer_number']) ?? [],
            'branches' => $company?->branches->map->only(['name', 'address', 'city'])->toArray()
                ?: [['name' => 'Casa Matriz']],
            'departments' => $company?->departments->map(fn ($department) => [
                'name' => $department->name,
                'positions' => $department->positions->map->only(['name'])->toArray(),
            ])->toArray() ?? [],
            'modules' => app(ModuleSettings::class)->toArray(),
            'payroll' => app(PayrollSettings::class)->toArray(),
            'general' => ['timezone' => app(GeneralSettings::class)->timezone],
        ];
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Wizard::make([
                    Step::make('Empresa y sucursales')
                        ->icon('heroicon-o-building-office-2')
                        ->schema([
                            Section::make('Datos de la empresa')
                                ->columns(2)
                                ->schema([
                                    TextInput::make('company.name')
                                        ->label('Razón social')
                                        ->required(),
                                    TextInput::make('company.trade_name')
                                        ->label('Nombre comercial'),
                                    TextInput::make('company.ruc')
                                        ->label('RUC')
                                        ->required(),
                                    TextInput::make('company.employer_number')
                                        ->label('Número de empleador IPS')
                                        ->integer()
                                        ->minValue(1)
                                        ->maxValue(99999999)
                                        ->required(),
                                ]),

                            Select::make('branch_mode')
                                ->label('¿Cuántas sucursales tiene la empresa?')
                                ->options([
                                    'single' => 'Una sola',
                                    'multiple' => 'Varias',
                                ])
                                ->live()
                                ->required()
                                ->disabled(fn () => $this->isReopen)
                                ->helperText(fn () => $this->isReopen
                                    ? 'La cantidad de sucursales ya no se puede cambiar desde acá — usá el módulo de Sucursales.'
                                    : null),

                            Repeater::make('branches')
                                ->label('Sucursales')
                                ->schema([
                                    TextInput::make('name')->label('Nombre')->required(),
                                    TextInput::make('address')->label('Dirección'),
                                    TextInput::make('city')->label('Ciudad'),
                                ])
                                ->columns(3)
                                ->minItems(1)
                                ->addActionLabel('Agregar sucursal')
                                ->disabled(fn () => $this->isReopen)
                                ->helperText(fn () => $this->isReopen
                                    ? 'Sucursales ya registradas — para editarlas o agregar nuevas, usá el módulo de Sucursales.'
                                    : null)
                                ->hidden(fn ($get) => $get('branch_mode') === 'single'),
                        ]),

                    Step::make('Estructura organizacional')
                        ->icon('heroicon-o-building-library')
                        ->schema([
                            Repeater::make('departments')
                                ->label('Departamentos (opcional)')
                                ->schema([
                                    TextInput::make('name')->label('Departamento')->required(),
                                    Repeater::make('positions')
                                        ->label('Cargos')
                                        ->schema([
                                            TextInput::make('name')->label('Cargo')->required(),
                                        ])
                                        ->addActionLabel('Agregar cargo'),
                                ])
                                ->addActionLabel('Agregar departamento')
                                ->disabled(fn () => $this->isReopen)
                                ->helperText(fn () => $this->isReopen
                                    ? 'La estructura organizacional ya registrada se administra desde Organización → Departamentos y Cargos.'
                                    : 'Podés saltar este paso y crear departamentos y cargos más adelante desde el panel.'),
                        ]),

                    Step::make('Módulos opcionales')
                        ->icon('heroicon-o-squares-2x2')
                        ->schema([
                            Toggle::make('modules.loans_enabled')->label('Préstamos'),
                            Toggle::make('modules.advances_enabled')->label('Adelantos de salario'),
                            Toggle::make('modules.merchandise_withdrawals_enabled')->label('Retiros de mercadería'),
                            Toggle::make('modules.vacations_enabled')->label('Vacaciones'),
                            Toggle::make('modules.aguinaldo_enabled')->label('Aguinaldo'),
                            Toggle::make('modules.warnings_enabled')->label('Llamados de atención'),
                            Toggle::make('modules.employee_leaves_enabled')->label('Permisos y licencias'),
                            Toggle::make('modules.biometric_attendance_enabled')->label('Marcación biométrica (terminales / dispositivo facial)'),
                            Toggle::make('modules.disbursement_batches_enabled')->label('Pagos bancarios por lote'),
                        ]),

                    Step::make('Parámetros de nómina')
                        ->icon('heroicon-o-banknotes')
                        ->schema([
                            Section::make('Parámetros clave')
                                ->columns(2)
                                ->schema([
                                    TextInput::make('payroll.monthly_hours')
                                        ->label('Horas mensuales (jornada diurna)')
                                        ->numeric()
                                        ->required(),
                                    TextInput::make('payroll.overtime_multiplier_diurno')
                                        ->label('Multiplicador de horas extra diurnas')
                                        ->numeric()
                                        ->step(0.01)
                                        ->required(),
                                    TextInput::make('payroll.ips_employee_rate')
                                        ->label('Tasa IPS del empleado (%)')
                                        ->numeric()
                                        ->step(0.01)
                                        ->required(),
                                    TextInput::make('payroll.min_salary_monthly')
                                        ->label('Salario mínimo mensual (Gs.)')
                                        ->numeric()
                                        ->required(),
                                    Select::make('general.timezone')
                                        ->label('Zona horaria')
                                        ->options([
                                            'America/Asuncion' => 'América/Asunción (UTC -3)',
                                        ])
                                        ->default('America/Asuncion')
                                        ->native(false)
                                        ->required(),
                                ]),
                        ]),
                ])
                    ->columnSpanFull()
                    ->submitAction($this->getSubmitAction()),
            ])
            ->statePath('data');
    }

    protected function getSubmitAction(): HtmlString
    {
        $label = $this->isReopen ? 'Guardar cambios' : 'Finalizar configuración';

        return new HtmlString(Blade::render(<<<BLADE
            <x-filament::button type="submit" wire:loading.attr="disabled">
                {$label}
            </x-filament::button>
        BLADE));
    }

    public function submit(): void
    {
        $data = $this->form->getState();

        DB::transaction(function () use ($data): void {
            $company = Company::query()->first();

            $company = $company
                ? tap($company)->update([
                    'name' => $data['company']['name'],
                    'trade_name' => $data['company']['trade_name'] ?? null,
                    'ruc' => $data['company']['ruc'],
                    'employer_number' => $data['company']['employer_number'] ?? null,
                ])
                : Company::create([
                    'name' => $data['company']['name'],
                    'trade_name' => $data['company']['trade_name'] ?? null,
                    'ruc' => $data['company']['ruc'],
                    'employer_number' => $data['company']['employer_number'] ?? null,
                    'is_active' => true,
                ]);

            if (! $this->isReopen) {
                $branches = $data['branch_mode'] === 'single'
                    ? [['name' => 'Casa Matriz']]
                    : $data['branches'];

                foreach ($branches as $branchData) {
                    $company->branches()->create($branchData);
                }

                foreach ($data['departments'] ?? [] as $departmentData) {
                    $department = $company->departments()->create(['name' => $departmentData['name']]);

                    foreach ($departmentData['positions'] ?? [] as $positionData) {
                        $department->positions()->create(['name' => $positionData['name']]);
                    }
                }
            }

            app(ModuleSettings::class)->fill($data['modules'])->save();
            app(PayrollSettings::class)->fill($data['payroll'])->save();

            $general = app(GeneralSettings::class);
            $general->timezone = $data['general']['timezone'];
            $general->setup_completed = true;
            $general->save();
        });

        Notification::make()
            ->title($this->isReopen ? 'Configuración actualizada' : 'Configuración inicial completada')
            ->success()
            ->send();

        $this->redirect(Dashboard::getUrl());
    }
}
