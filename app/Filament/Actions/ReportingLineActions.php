<?php

namespace App\Filament\Actions;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Position;
use App\Services\ReportingLineService;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * Acciones para cargar el superior directo («Reporta a») de muchos empleados: una acción masiva sobre la selección
 * y una regla con vista previa (por ejemplo «todos los cajeros reportan a la Encargada de su sucursal»). Las dos
 * pasan por `ReportingLineService`, que valida cada asignación (misma empresa, activo, sin ciclos).
 */
class ReportingLineActions
{
    /** Cantidad de filas que se listan en la vista previa de la regla. */
    private const PREVIEW_ROWS = 12;

    /**
     * Regla de validación del campo «Reporta a» del formulario del empleado: rechaza ciclos y superiores de otra empresa.
     */
    public static function managerRule(?Employee $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($record): void {
            if (! $record || blank($value) || ! ($manager = Employee::query()->with('branch')->find($value))) {
                return;
            }

            $error = app(ReportingLineService::class)->validate($record->loadMissing('branch'), $manager);

            if ($error !== null) {
                $fail(ReportingLineService::getReasonLabels()[$error]);
            }
        };
    }

    /**
     * Acción masiva: asigna el mismo superior a los empleados seleccionados.
     */
    public static function bulkAssign(): BulkAction
    {
        return BulkAction::make('assign_manager')
            ->label('Asignar superior directo')
            ->icon('heroicon-o-user-group')
            ->color('info')
            ->authorize(fn () => auth()->user()?->can('update_employee') ?? false)
            ->form([
                Select::make('manager_id')
                    ->label('Reportan a')
                    ->options(fn () => self::managerOptions())
                    ->searchable()
                    ->native(false)
                    ->required()
                    ->helperText('Elegí quién será el superior directo de los empleados seleccionados. Se omiten los que no se pueden asignar (el propio superior, los de otra empresa o los que crearían un ciclo).'),
            ])
            ->modalHeading('Asignar superior directo')
            ->modalSubmitActionLabel('Sí, asignar')
            ->action(function (Collection $records, array $data): void {
                $manager = Employee::query()->with('branch')->findOrFail($data['manager_id']);
                $result = app(ReportingLineService::class)->assignMany($records->load('branch'), $manager);

                self::notifyResult($result);
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Acción de encabezado: asigna superiores por regla, mostrando antes a quién se le asignaría cada empleado.
     */
    public static function rule(): Action
    {
        return Action::make('assign_managers_by_rule')
            ->label('Asignar superiores por regla')
            ->icon('heroicon-o-share')
            ->color('gray')
            ->visible(fn () => auth()->user()?->can('update_employee') ?? false)
            ->modalHeading('Asignar superiores por regla')
            ->modalDescription('Armá una regla y revisá la vista previa antes de aplicarla. Nunca pisa a quienes ya tienen superior, salvo que lo indiques.')
            ->modalWidth('3xl')
            ->form([
                Select::make('branch_id')
                    ->label('Solo la sucursal')
                    ->options(fn () => Branch::query()->orderBy('name')->pluck('name', 'id')->toArray())
                    ->searchable()
                    ->native(false)
                    ->placeholder('Todas las sucursales')
                    ->live(),

                Select::make('position_ids')
                    ->label('Solo quienes tienen el cargo')
                    ->options(fn () => self::positionOptions())
                    ->multiple()
                    ->searchable()
                    ->placeholder('Todos los cargos')
                    ->live(),

                Radio::make('mode')
                    ->label('Superior')
                    ->options([
                        'position' => 'El encargado de su sucursal (quien tiene cierto cargo)',
                        'fixed' => 'Una persona en particular',
                    ])
                    ->default('position')
                    ->live()
                    ->required(),

                Select::make('manager_position_id')
                    ->label('Cargo del superior')
                    ->options(fn () => self::positionOptions())
                    ->searchable()
                    ->native(false)
                    ->visible(fn (Get $get) => $get('mode') === 'position')
                    ->required(fn (Get $get) => $get('mode') === 'position')
                    ->live()
                    ->helperText('Cada empleado se asigna a quien tenga ese cargo en su misma sucursal, siempre que haya uno solo.'),

                Select::make('manager_id')
                    ->label('Persona')
                    ->options(fn () => self::managerOptions())
                    ->searchable()
                    ->native(false)
                    ->visible(fn (Get $get) => $get('mode') === 'fixed')
                    ->required(fn (Get $get) => $get('mode') === 'fixed')
                    ->live(),

                Toggle::make('only_without_manager')
                    ->label('Respetar a quienes ya tienen superior')
                    ->default(true)
                    ->live(),

                Placeholder::make('preview')
                    ->label('Vista previa')
                    ->content(fn (Get $get) => self::previewHtml(self::ruleFrom($get))),
            ])
            ->modalSubmitActionLabel('Sí, aplicar regla')
            ->action(function (array $data): void {
                self::notifyResult(app(ReportingLineService::class)->apply(self::ruleFrom($data)));
            });
    }

    /**
     * Arma la regla del servicio a partir de lo que el formulario tiene cargado (`Get` o el array enviado).
     *
     * @param  Get|array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private static function ruleFrom(Get|array $source): array
    {
        $value = fn (string $key) => $source instanceof Get ? $source($key) : ($source[$key] ?? null);
        $mode = $value('mode') ?? 'position';

        return [
            'branch_id' => $value('branch_id'),
            'position_ids' => (array) ($value('position_ids') ?? []),
            'manager_position_id' => $mode === 'position' ? $value('manager_position_id') : null,
            'manager_id' => $mode === 'fixed' ? $value('manager_id') : null,
            'only_without_manager' => (bool) ($value('only_without_manager') ?? true),
        ];
    }

    /**
     * Vista previa de la regla: cuántos se asignarían, cuántos se omiten y por qué, y los primeros ejemplos.
     *
     * @param  array<string, mixed>  $rule
     */
    private static function previewHtml(array $rule): HtmlString
    {
        if (blank($rule['manager_position_id']) && blank($rule['manager_id'])) {
            return new HtmlString('<span style="color: rgb(var(--gray-500));">Elegí el superior para ver a quién se le asignaría cada empleado.</span>');
        }

        $rows = app(ReportingLineService::class)->preview($rule);
        $assignable = $rows->whereNull('skip');
        $skipped = $rows->whereNotNull('skip')->countBy('skip');
        $labels = ReportingLineService::getReasonLabels();

        $html = '<div style="font-size: .875rem; line-height: 1.5;">';
        $html .= '<strong>'.$assignable->count().'</strong> empleado(s) se asignarían';

        foreach ($skipped as $reason => $count) {
            $html .= '<div style="color: rgb(var(--gray-500));">'.e($count.' omitido(s): '.($labels[$reason] ?? $reason)).'</div>';
        }

        foreach ($assignable->take(self::PREVIEW_ROWS) as $row) {
            $html .= '<div>'.e($row['employee']->full_name).' &rarr; '.e($row['manager']->full_name).'</div>';
        }

        if ($assignable->count() > self::PREVIEW_ROWS) {
            $html .= '<div style="color: rgb(var(--gray-500));">y '.($assignable->count() - self::PREVIEW_ROWS).' más</div>';
        }

        return new HtmlString($html.'</div>');
    }

    /**
     * Empleados activos que pueden ser superiores, con su cargo para distinguirlos.
     *
     * @return array<int, string>
     */
    private static function managerOptions(): array
    {
        return Employee::query()
            ->with('activeContract.position')
            ->where('status', 'active')
            ->orderBy('last_name')->orderBy('first_name')
            ->get()
            ->mapWithKeys(fn (Employee $e) => [
                $e->id => $e->last_name.', '.$e->first_name.($e->activeContract?->position ? ' ('.$e->activeContract->position->name.')' : ''),
            ])
            ->toArray();
    }

    /**
     * Cargos con su departamento (hay nombres repetidos entre departamentos y empresas).
     *
     * @return array<int, string>
     */
    private static function positionOptions(): array
    {
        return Position::query()
            ->with('department.company')
            ->get()
            ->sortBy('name')
            ->mapWithKeys(fn (Position $p) => [$p->id => $p->name.' — '.$p->department?->name.($p->department?->company ? ' · '.$p->department->company->name : '')])
            ->toArray();
    }

    /**
     * Notificación con el resultado de una asignación en bloque.
     *
     * @param  array{assigned: int, skipped: array<string, int>}  $result
     */
    private static function notifyResult(array $result): void
    {
        $labels = ReportingLineService::getReasonLabels();
        $skipped = collect($result['skipped'])->map(fn (int $count, string $reason) => "{$count} omitido(s): ".($labels[$reason] ?? $reason))->implode('. ');
        $assigned = $result['assigned'];

        $notification = Notification::make()
            ->title($assigned > 0 ? "Superior asignado a {$assigned} empleado(s)" : 'No se asignó ningún superior')
            ->body($skipped !== '' ? $skipped.'.' : null);

        ($assigned > 0 ? $notification->success() : $notification->warning())->send();
    }
}
