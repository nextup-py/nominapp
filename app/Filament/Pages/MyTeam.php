<?php

namespace App\Filament\Pages;

use App\Filament\Actions\OvertimeRequestActions;
use App\Models\AttendanceEvent;
use App\Models\OvertimeRequest;
use App\Services\LiveAttendanceMonitor;
use App\Services\TeamScopeService;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Attributes\Url;

/**
 * «Mi equipo»: estado de hoy de las personas a cargo del usuario (quienes le reportan y, con `view_branch_team`,
 * toda su sucursal). Reutiliza la foto del Monitor en vivo acotada a ese alcance. Además lista los pedidos previos de
 * horas extra del equipo y permite pedirlos y, cuando esperan al jefe, aprobarlos o rechazarlos. Lo demás sigue siendo
 * de consulta (la aprobación de asistencia sigue en RR.HH.).
 */
class MyTeam extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationLabel = 'Mi equipo';

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 0;

    protected static ?string $title = 'Mi equipo';

    protected static ?string $slug = 'mi-equipo';

    protected static string $view = 'filament.pages.my-team';

    /** Segundos entre refrescos. */
    public const POLL_SECONDS = 30;

    #[Url(as: 'buscar')]
    public string $search = '';

    /** Estado elegido tocando una tarjeta (vacío = todos). */
    #[Url(as: 'estado')]
    public ?string $state = null;

    /** Quien tiene alguno de los permisos de equipo. */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && app(TeamScopeService::class)->canView($user);
    }

    /** Alterna el filtro de estado (tocar la misma tarjeta otra vez lo quita). */
    public function toggleState(string $state): void
    {
        $this->state = $this->state === $state ? null : $state;
    }

    /**
     * Datos de la vista: foto de hoy del equipo y avisos si no hay ficha vinculada o equipo.
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $scope = app(TeamScopeService::class);
        $user = auth()->user();
        $linked = $scope->hasLinkedEmployee($user);
        $ids = $scope->employeeIds($user);

        return [
            'linked' => $linked,
            'hasTeam' => $ids !== [],
            'snapshot' => $ids === [] ? null : LiveAttendanceMonitor::snapshot([
                'employee_ids' => $ids,
                'search' => $this->search,
                'state' => $this->state,
            ]),
            'states' => LiveAttendanceMonitor::states(),
            'pollSeconds' => self::POLL_SECONDS,
        ];
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [OvertimeRequestActions::request(Action::class)];
    }

    /** Tabla de pedidos de horas extra del equipo del usuario. */
    public function table(Table $table): Table
    {
        return $table
            ->query(OvertimeRequest::query()
                ->with('employee')
                ->whereIn('employee_id', app(TeamScopeService::class)->employeeIds(auth()->user())))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('employee.first_name')
                    ->label('Empleado')
                    ->formatStateUsing(fn ($state, OvertimeRequest $record): string => trim(($record->employee?->first_name ?? '').' '.($record->employee?->last_name ?? ''))),
                TextColumn::make('date_from')
                    ->label('Fechas')
                    ->formatStateUsing(fn ($state, OvertimeRequest $record): string => $record->rangeLabel())
                    ->description(fn (OvertimeRequest $record): ?string => $record->windowLabel()),
                TextColumn::make('max_hours')->label('Máx. por día')->suffix(' h'),
                TextColumn::make('reason')->label('Motivo')->formatStateUsing(fn ($state): string => AttendanceEvent::overtimeReasonLabel($state)),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => OvertimeRequest::getStatusLabels()[$state] ?? $state)
                    ->color(fn (string $state): string => OvertimeRequest::getStatusColors()[$state] ?? 'gray'),
            ])
            ->actions([
                ActionGroup::make([
                    OvertimeRequestActions::approve(TableAction::class),
                    OvertimeRequestActions::reject(TableAction::class),
                    OvertimeRequestActions::cancel(TableAction::class),
                ]),
            ])
            ->bulkActions([])
            ->emptyStateHeading('Sin pedidos de horas extra')
            ->emptyStateDescription('Usar «Pedir horas extra» para autorizar horas extra anticipadas de tu equipo.')
            ->emptyStateIcon('heroicon-o-clock')
            ->paginationPageOptions([10, 25, 50]);
    }
}
