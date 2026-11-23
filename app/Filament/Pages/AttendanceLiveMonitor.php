<?php

namespace App\Filament\Pages;

use App\Filament\Resources\AttendanceDayResource;
use App\Filament\Resources\TerminalResource;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Services\LiveAttendanceMonitor;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * Monitor de asistencia en vivo: estado de cada empleado hoy (en turno, en descanso, sin marcar, ausente...),
 * contadores, alertas del momento y últimas marcaciones. Se refresca solo (polling de Livewire) y tiene un modo
 * pantalla completa pensado para dejarlo fijo en un monitor de RR.HH. Solo consulta: las acciones de resolución
 * viven en las listas de Asistencias, adonde enlazan las alertas.
 */
class AttendanceLiveMonitor extends Page
{
    protected static ?string $navigationLabel = 'Monitor en vivo';

    protected static ?string $navigationIcon = 'heroicon-o-signal';

    protected static ?string $navigationGroup = 'Asistencias';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Monitor de asistencia en vivo';

    protected static ?string $slug = 'monitor-en-vivo';

    protected static string $view = 'filament.pages.attendance-live-monitor';

    /** Segundos entre refrescos. */
    public const POLL_SECONDS = 20;

    #[Url(as: 'empresa')]
    public ?string $companyId = null;

    #[Url(as: 'sucursal')]
    public ?string $branchId = null;

    #[Url(as: 'departamento')]
    public ?string $departmentId = null;

    /** Solo cuenta a quienes tienen turno hoy (oculta «Sin turno hoy»). */
    #[Url(as: 'con_turno')]
    public bool $onlyScheduled = true;

    #[Url(as: 'buscar')]
    public string $search = '';

    /** Estado elegido tocando una tarjeta (vacío = todos). */
    #[Url(as: 'estado')]
    public ?string $state = null;

    /** Modo pantalla grande: oculta el menú y los filtros, y agranda la letra. */
    #[Url(as: 'pantalla')]
    public bool $kiosk = false;

    /** Agrupa la lista de empleados por sucursal (falso = lista plana ordenada por estado). */
    #[Url(as: 'agrupar')]
    public bool $grouped = false;

    /** Mismo permiso que las listas de Asistencias. */
    public static function canAccess(): bool
    {
        return AttendanceDayResource::canViewAny();
    }

    /** Al cambiar la empresa se limpian los filtros que dependen de ella. */
    public function updatedCompanyId(): void
    {
        $this->branchId = null;
        $this->departmentId = null;
    }

    /** Alterna el filtro de estado (tocar la misma tarjeta otra vez lo quita). */
    public function toggleState(string $state): void
    {
        $this->state = $this->state === $state ? null : $state;
    }

    /**
     * Datos de la vista: foto de hoy, opciones de los filtros y accesos permitidos.
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $companies = Company::active()->orderBy('name')->pluck('name', 'id');

        $snapshot = LiveAttendanceMonitor::snapshot([
            'company_id' => $this->companyId,
            'branch_id' => $this->branchId,
            'department_id' => $this->departmentId,
            'only_scheduled' => $this->onlyScheduled,
            'search' => $this->search,
            'state' => $this->state,
        ]);

        return [
            'snapshot' => $snapshot,
            'states' => LiveAttendanceMonitor::states(),
            'companies' => $companies,
            'showCompanyFilter' => $companies->count() > 1,
            'branches' => Branch::active()
                ->when(filled($this->companyId), fn ($q) => $q->where('company_id', (int) $this->companyId))
                ->orderBy('name')->pluck('name', 'id'),
            'departments' => Department::query()
                ->when(filled($this->companyId), fn ($q) => $q->where('company_id', (int) $this->companyId))
                ->orderBy('name')->pluck('name', 'id'),
            'pollSeconds' => self::POLL_SECONDS,
            'daysUrl' => AttendanceDayResource::getUrl('index', ['activeTab' => 'attention']),
            'inboxUrl' => AttendanceInbox::canAccess() ? AttendanceInbox::getUrl() : null,
            'terminalsUrl' => TerminalResource::canViewAny() ? TerminalResource::getUrl('index') : null,
        ];
    }
}
