<?php

namespace App\Filament\Pages;

use App\Filament\Resources\AttendanceDayResource;
use App\Models\Employee;
use App\Services\EmployeeMonthSheetService;
use Carbon\Carbon;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * Ficha mensual de asistencia de un empleado: cuadrícula con una celda por día (estado, entrada y salida,
 * horas y alertas) y los totales del mes. Es solo de consulta: cada día enlaza a su jornada, donde están
 * **Corregir marcaciones** y las aprobaciones. Se abre desde el botón de cada fila de Asistencias y no
 * figura en el menú.
 */
class EmployeeMonthSheet extends Page
{
    protected static ?string $slug = 'ficha-mensual';

    protected static string $view = 'filament.pages.employee-month-sheet';

    protected static bool $shouldRegisterNavigation = false;

    /** @var int|null ID del empleado (query string `employee`). */
    #[Url(as: 'employee')]
    public ?int $employeeId = null;

    /** @var string|null Mes con formato `Y-m` (query string `month`); por defecto, el mes en curso. */
    #[Url(as: 'month')]
    public ?string $month = null;

    /** Quien puede ver asistencias puede ver la ficha. */
    public static function canAccess(): bool
    {
        return AttendanceDayResource::canViewAny();
    }

    /** Valida el empleado y normaliza el mes. */
    public function mount(): void
    {
        abort_unless($this->employeeId && Employee::query()->whereKey($this->employeeId)->exists(), 404);

        $this->month = $this->resolveMonth()->format('Y-m');
    }

    /** Título con el nombre del empleado. */
    public function getTitle(): string
    {
        return 'Ficha mensual — '.($this->employee()?->full_name ?? 'Empleado');
    }

    /** Migas de pan: Asistencias → Ficha mensual. */
    public function getBreadcrumbs(): array
    {
        return [AttendanceDayResource::getUrl() => 'Asistencias', 'Ficha mensual'];
    }

    /** URL de la ficha de otro mes del mismo empleado. */
    public function urlForMonth(Carbon $month): string
    {
        return static::getUrl(['employee' => $this->employeeId, 'month' => $month->format('Y-m')]);
    }

    /**
     * Datos de la vista: empleado, ficha del mes y enlaces de navegación.
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $employee = $this->employee();
        $month = $this->resolveMonth();

        return [
            'employee' => $employee,
            'sheet' => EmployeeMonthSheetService::build($employee, $month),
            'previousUrl' => $this->urlForMonth($month->copy()->subMonth()),
            'nextUrl' => $this->urlForMonth($month->copy()->addMonth()),
            'currentUrl' => $this->urlForMonth(Carbon::today()),
            'isCurrentMonth' => $month->isSameMonth(Carbon::today()),
            'backUrl' => AttendanceDayResource::getUrl(),
        ];
    }

    /** Empleado de la ficha, con sucursal y cargo para el encabezado. */
    private function employee(): ?Employee
    {
        return $this->employeeId
            ? Employee::query()->with(['branch', 'activeContract.position'])->find($this->employeeId)
            : null;
    }

    /** Mes pedido; si falta o es inválido, el mes en curso. */
    private function resolveMonth(): Carbon
    {
        if ($this->month && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month)) {
            return Carbon::createFromFormat('Y-m-d', $this->month.'-01')->startOfMonth();
        }

        return Carbon::today()->startOfMonth();
    }
}
