<?php

namespace App\Filament\Pages;

use App\Settings\PayrollSettings;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Pages\SettingsPage;

class ManagePayrollSettings extends SettingsPage
{
    /** Acceso restringido por permiso (las páginas de Filament no consultan la policy por sí solas). */
    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('Super Admin') ?? false;
    }

    // Configuraciones de la página
    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationLabel = 'Configuración de Nómina';

    protected static ?string $title = 'Configuración de Nómina';

    protected static ?string $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 4;

    protected static string $settings = PayrollSettings::class;

    /**
     * Define el formulario de configuración de nómina.
     */
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                // Sección para configuración de horas de trabajo y cálculos relacionados
                Section::make('Horas de Trabajo - Jornada Diurna')
                    ->description('Parámetros para jornada diurna (06:00 - 20:00)')
                    ->icon('heroicon-o-sun')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                // Horas de trabajo mensuales con validación numérica
                                TextInput::make('monthly_hours')
                                    ->label('Horas mensuales')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(720)
                                    ->required()
                                    ->suffix('hrs')
                                    ->helperText('Default: 240 (30 días × 8 hrs)'),

                                // Horas de trabajo por jornada con validación numérica
                                TextInput::make('daily_hours')
                                    ->label('Horas por jornada')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(24)
                                    ->required()
                                    ->suffix('hrs')
                                    ->helperText('Default: 8 hrs (Art. 194)'),

                                // Días laborales por mes con validación numérica
                                TextInput::make('days_per_month')
                                    ->label('Días laborales/mes')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(31)
                                    ->required()
                                    ->suffix('días')
                                    ->helperText('Default: 30 días'),
                            ]),
                    ]),

                // Sección para configuración de horas de trabajo nocturno y mixto
                Section::make('Horas de Trabajo - Jornada Nocturna y Mixta')
                    ->description('Parámetros para jornadas nocturna (20:00 - 06:00) y mixta')
                    ->icon('heroicon-o-moon')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                // Horas de trabajo mensuales nocturno con validación numérica
                                TextInput::make('monthly_hours_nocturno')
                                    ->label('Horas mensuales nocturno')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(720)
                                    ->required()
                                    ->suffix('hrs')
                                    ->helperText('Default: 210 (30 días × 7 hrs)'),

                                // Horas de trabajo por jornada nocturna con validación numérica
                                TextInput::make('daily_hours_nocturno')
                                    ->label('Horas por jornada nocturna')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(24)
                                    ->required()
                                    ->suffix('hrs')
                                    ->helperText('Default: 7 hrs (Art. 196)'),
                            ]),

                        Grid::make(2)
                            ->schema([
                                // Horas de trabajo mensuales mixto con validación numérica
                                TextInput::make('monthly_hours_mixto')
                                    ->label('Horas mensuales mixto')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(720)
                                    ->required()
                                    ->suffix('hrs')
                                    ->helperText('Default: 225 (30 días × 7.5 hrs)'),

                                // Horas de trabajo por jornada mixta con validación numérica
                                TextInput::make('daily_hours_mixto')
                                    ->label('Horas por jornada mixta')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(24)
                                    ->required()
                                    ->suffix('hrs')
                                    ->helperText('Default: 7.5 hrs (Art. 197)'),
                            ]),
                    ]),

                // Sección para configuración de horas de trabajo en días feriados
                Section::make('Multiplicadores de Horas Extra')
                    ->description('Factores de cálculo según Código del Trabajo (Art. 234)')
                    ->icon('heroicon-o-clock')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('overtime_multiplier_diurno')
                                    ->label('HE Diurnas')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(10)
                                    ->step(0.1)
                                    ->required()
                                    ->suffix('x')
                                    ->helperText('50% recargo sobre hora diurna → 1.5x'),

                                TextInput::make('overtime_multiplier_nocturno')
                                    ->label('HE Nocturnas (día regular)')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(10)
                                    ->step(0.1)
                                    ->required()
                                    ->suffix('x')
                                    ->helperText('1.30 × 2.0 sobre base diurna → 2.6x'),

                                TextInput::make('overtime_multiplier_holiday')
                                    ->label('HE Diurnas Feriado/Domingo')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(10)
                                    ->step(0.1)
                                    ->required()
                                    ->suffix('x')
                                    ->helperText('100% recargo sobre hora diurna → 2.0x'),

                                TextInput::make('overtime_multiplier_nocturno_holiday')
                                    ->label('HE Nocturnas Feriado/Domingo')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(10)
                                    ->step(0.1)
                                    ->required()
                                    ->suffix('x')
                                    ->helperText('1.30 × 2.0 sobre base diurna → 2.6x'),
                            ]),
                    ]),

                // Sección para configuración de límites de horas extra
                Section::make('Límites de Horas Extra')
                    ->description('Límites legales de horas extraordinarias (Art. 202 CLT)')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('overtime_max_daily_hours')
                                    ->label('Máximo horas extra por día')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(24)
                                    ->required()
                                    ->suffix('hrs')
                                    ->helperText('Límite legal: 3 hrs/día (Art. 202)'),

                                TextInput::make('overtime_max_weekly_hours')
                                    ->label('Máximo horas extra por semana')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(168)
                                    ->required()
                                    ->suffix('hrs')
                                    ->helperText('Límite legal: 9 hrs/semana (Art. 202)'),
                            ]),
                    ]),

                // Sección para configuración de liquidación y finiquito
                Section::make('Liquidación / Finiquito')
                    ->description('Parámetros para cálculo de liquidaciones (Art. 78-100)')
                    ->icon('heroicon-o-document-text')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                // Aporte IPS obrero con validación numérica y formato porcentual
                                TextInput::make('ips_employee_rate')
                                    ->label('Aporte IPS Obrero')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->step(0.5)
                                    ->required()
                                    ->suffix('%')
                                    ->helperText('Default: 9%'),

                                // Código de la deducción IPS en el catálogo de deducciones
                                TextInput::make('ips_deduction_code')
                                    ->label('Código deducción IPS')
                                    ->required()
                                    ->maxLength(20)
                                    ->helperText('Código del catálogo de deducciones (Default: IPS001)'),

                                // Días de indemnización por año con validación numérica
                                TextInput::make('indemnizacion_days_per_year')
                                    ->label('Días indemnización por año')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(30)
                                    ->required()
                                    ->suffix('días')
                                    ->helperText('Default: 15 días/año'),
                            ]),
                    ]),

                Section::make('Salarios Mínimos Legales')
                    ->description('Montos fijados por el Ministerio de Trabajo. Actualizar ante cada resolución ministerial.')
                    ->icon('heroicon-o-scale')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('min_salary_monthly')
                                    ->label('Salario mínimo mensual')
                                    ->numeric()
                                    ->minValue(1)
                                    ->required()
                                    ->prefix('Gs.')
                                    ->helperText('Para trabajadores mensualizados'),

                                TextInput::make('min_salary_daily_jornal')
                                    ->label('Salario mínimo diario (jornaleros)')
                                    ->numeric()
                                    ->minValue(1)
                                    ->required()
                                    ->prefix('Gs.')
                                    ->helperText('Monto diario independiente para trabajadores a jornal'),
                            ]),
                    ]),

                Section::make('Bonificación Familiar')
                    ->description('Arts. 253-262 Código del Trabajo. Aplica a empleados con hijos que ganen hasta 2 salarios mínimos.')
                    ->icon('heroicon-o-heart')
                    ->schema([
                        TextInput::make('family_bonus_percentage')
                            ->label('Porcentaje por hijo')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->step(0.5)
                            ->required()
                            ->suffix('%')
                            ->helperText('% del salario mínimo mensual por hijo. Default: 5%'),
                    ]),

                Section::make('IRP — Impuesto a la Renta Personal')
                    ->description('Ley 2421/04. Aplica a empleados cuya remuneración anual supera el umbral. La empresa actúa como agente de retención.')
                    ->icon('heroicon-o-receipt-percent')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('irp_annual_threshold')
                                    ->label('Umbral anual gravado')
                                    ->numeric()
                                    ->minValue(1)
                                    ->required()
                                    ->prefix('Gs.')
                                    ->helperText('Renta anual a partir de la cual aplica IRP. Default: 80.000.000 Gs.'),

                                TextInput::make('irp_rate')
                                    ->label('Tasa IRP')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->step(0.5)
                                    ->required()
                                    ->suffix('%')
                                    ->helperText('Tasa sobre la renta gravada. Default: 10%'),
                            ]),
                    ]),

                Section::make('Préstamos')
                    ->description('Límites para la concesión de préstamos a empleados.')
                    ->icon('heroicon-o-banknotes')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextInput::make('max_loan_amount')
                                    ->label('Monto máximo de préstamo')
                                    ->numeric()
                                    ->minValue(0)
                                    ->required()
                                    ->prefix('Gs.')
                                    ->helperText('Monto máximo que se puede prestar a un empleado'),

                                TextInput::make('loan_installment_cap_percent')
                                    ->label('Tope de cuota (% salario)')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(100)
                                    ->step(0.5)
                                    ->required()
                                    ->suffix('%')
                                    ->helperText('% máximo del salario que puede representar la cuota mensual. Default: 25% (Art. 245 CLT)'),

                                TextInput::make('loan_max_installments')
                                    ->label('Máximo de cuotas')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(360)
                                    ->required()
                                    ->suffix('cuotas')
                                    ->helperText('Cantidad máxima de cuotas al crear un préstamo. Default: 60'),

                                TextInput::make('loan_max_interest_rate')
                                    ->label('Tasa de interés máxima')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->step(0.5)
                                    ->required()
                                    ->suffix('% anual')
                                    ->helperText('Tasa de interés anual máxima permitida. Default: 100%'),

                                TextInput::make('loan_first_installment_days')
                                    ->label('Días hasta primera cuota (default)')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(365)
                                    ->required()
                                    ->suffix('días')
                                    ->helperText('Días desde la aprobación hasta el vencimiento de la primera cuota. Se usa como valor por defecto al crear un préstamo.'),
                            ]),
                    ]),

                Section::make('Adelantos de Salario')
                    ->description('Límites para las solicitudes de adelanto por parte de los empleados.')
                    ->icon('heroicon-o-currency-dollar')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('advance_max_percent')
                                    ->label('Porcentaje máximo por adelanto')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(100)
                                    ->step(1)
                                    ->required()
                                    ->live()
                                    ->suffix('%')
                                    ->helperText('% del salario del empleado que se puede adelantar por solicitud. Default: 50%'),

                                TextInput::make('advance_max_per_period')
                                    ->label('Máximo de adelantos por período')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(99)
                                    ->required()
                                    ->live()
                                    ->suffix('adelantos')
                                    ->helperText('Cantidad máxima por período de nómina. 0 = sin límite.')
                                    ->rules([
                                        fn (Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                                            $perPeriod = (int) $value;
                                            $percent = (int) $get('advance_max_percent');

                                            if ($perPeriod > 0 && ($perPeriod * $percent) > 100) {
                                                $total = $perPeriod * $percent;
                                                $fail("{$perPeriod} adelantos × {$percent}% = {$total}% del salario, lo que supera el 100%. Reducí la cantidad o el porcentaje.");
                                            }
                                        },
                                    ]),
                            ]),
                    ]),

                Section::make('Retiros de Mercadería')
                    ->description('Límites para los retiros de mercadería a crédito.')
                    ->icon('heroicon-o-shopping-bag')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextInput::make('merchandise_max_amount')
                                    ->label('Monto máximo por retiro')
                                    ->numeric()
                                    ->minValue(1)
                                    ->required()
                                    ->prefix('Gs.')
                                    ->helperText('Monto total máximo permitido por retiro. Default: 10.000.000 Gs.'),

                                TextInput::make('merchandise_max_installments')
                                    ->label('Máximo de cuotas')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(120)
                                    ->required()
                                    ->suffix('cuotas')
                                    ->helperText('Cantidad máxima de cuotas al crear un retiro. Default: 24.'),

                                TextInput::make('merchandise_first_installment_days')
                                    ->label('Días hasta primera cuota')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(365)
                                    ->required()
                                    ->suffix('días')
                                    ->helperText('Días desde la aprobación hasta el vencimiento de la primera cuota. Default: 30.'),
                            ]),
                    ]),

                // Nueva sección para configuración de vacaciones
                Section::make('Vacaciones')
                    ->description('Parámetros generales de vacaciones')
                    ->icon('heroicon-o-calendar-days')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('vacation_min_consecutive_days')
                                    ->label('Mínimo días consecutivos')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(30)
                                    ->required()
                                    ->suffix('días')
                                    ->helperText('Fraccionamiento mínimo (Default: 6)'),

                                TextInput::make('vacation_min_years_service')
                                    ->label('Años mínimos de servicio')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(10)
                                    ->required()
                                    ->suffix('años')
                                    ->helperText('Para tener derecho a vacaciones (Default: 1)'),
                            ]),

                        CheckboxList::make('vacation_business_days')
                            ->label('Días hábiles para vacaciones')
                            ->helperText('Días que se cuentan como hábiles al calcular vacaciones. Default: Lunes a Sábado.')
                            ->options([
                                1 => 'Lunes',
                                2 => 'Martes',
                                3 => 'Miércoles',
                                4 => 'Jueves',
                                5 => 'Viernes',
                                6 => 'Sábado',
                                7 => 'Domingo',
                            ])
                            ->columns(7)
                            ->required(),
                    ]),
            ]);
    }
}
