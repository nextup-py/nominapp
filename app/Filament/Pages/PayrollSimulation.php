<?php

namespace App\Filament\Pages;

use App\Filament\Resources\PayrollPeriodResource;
use App\Models\Company;
use App\Models\Employee;
use App\Services\PayrollSimulationService;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

/**
 * Simulación de nómina: calcula la nómina de una empresa, frecuencia y rango de fechas con el mismo cálculo
 * que la generación real, pero sin guardar nada (ver `PayrollSimulationService`). Sirve para ensayar un mes o
 * compararlo contra el cálculo manual del cliente; el Excel descargable trae un resumen por empleado y el detalle.
 */
class PayrollSimulation extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationLabel = 'Simulación de Nómina';

    protected static ?string $navigationGroup = 'Nóminas';

    protected static ?int $navigationSort = 9;

    protected static ?string $slug = 'simulacion-nomina';

    protected static ?string $title = 'Simulación de Nómina';

    protected static string $view = 'filament.pages.payroll-simulation';

    /** @var array<string, mixed>|null Estado del formulario (empresa, frecuencia y fechas). */
    public ?array $data = [];

    /** @var array<string, mixed>|null Resultado de la última simulación, sin el detalle de ítems. */
    public ?array $result = null;

    /** Mensaje cuando el período no se puede simular. */
    public ?string $error = null;

    /** Quien puede ver períodos de nómina puede simular: la simulación no deja nada guardado. */
    public static function canAccess(): bool
    {
        return PayrollPeriodResource::canViewAny();
    }

    /** Carga los valores por defecto: la única empresa activa (si hay una), mensual y el mes en curso. */
    public function mount(): void
    {
        $companies = Company::active()->pluck('id');

        $this->form->fill([
            'company_id' => $companies->count() === 1 ? $companies->first() : null,
            'frequency' => 'monthly',
            'from' => Carbon::today()->startOfMonth()->toDateString(),
            'to' => Carbon::today()->endOfMonth()->toDateString(),
        ]);
    }

    /** Formulario con los cuatro datos del período a simular. */
    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Grid::make(4)->schema([
                    Select::make('company_id')
                        ->label('Empresa')
                        ->options(fn () => Company::active()->orderBy('name')->pluck('name', 'id')->toArray())
                        ->required()
                        ->native(false),
                    Select::make('frequency')
                        ->label('Frecuencia de pago')
                        ->options(Employee::getPayrollTypeOptions())
                        ->required()
                        ->native(false),
                    DatePicker::make('from')->label('Desde')->native(false)->displayFormat('d/m/Y')->required(),
                    DatePicker::make('to')->label('Hasta')->native(false)->displayFormat('d/m/Y')->required()->afterOrEqual('from'),
                ]),
            ]);
    }

    /** Corre la simulación y deja el resultado en pantalla (sin los ítems de cada recibo). */
    public function simulate(PayrollSimulationService $service): void
    {
        $state = $this->form->getState();
        $this->result = null;
        $this->error = null;

        try {
            $simulation = $service->simulate(
                (int) $state['company_id'],
                $state['frequency'],
                Carbon::parse($state['from']),
                Carbon::parse($state['to']),
            );
        } catch (\DomainException|\InvalidArgumentException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $simulation['rows'] = array_map(function (array $row) {
            unset($row['items']);

            return $row;
        }, $simulation['rows']);

        $this->result = $simulation;
    }

    /** URL de la descarga en Excel con los mismos parámetros de la simulación mostrada. */
    public function excelUrl(): ?string
    {
        if (! $this->result) {
            return null;
        }

        return route('payroll-simulation.excel', [
            'company' => $this->result['period']['company_id'],
            'frequency' => $this->result['period']['frequency'],
            'from' => $this->result['period']['start'],
            'to' => $this->result['period']['end'],
        ]);
    }
}
