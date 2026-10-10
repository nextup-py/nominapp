<?php

namespace App\Filament\Actions;

use App\Models\Employee;
use App\Models\Schedule;
use App\Services\ScheduleAssignmentService;
use Carbon\Carbon;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Support\Collection;

/**
 * Asignación de un horario fijo a empleados desde el listado de Empleados (fila y masiva), con vigencia
 * elegible. La lógica de vigencias vive en `ScheduleAssignmentService`; los demás puntos de entrada
 * (desde el horario) reutilizan `formFields()` y `apply()`.
 */
class ScheduleAssignmentActions
{
    /** Indica si el usuario puede asignar horarios (administrar horarios). */
    public static function canAssign(): bool
    {
        return auth()->user()?->can('update_schedule') ?? false;
    }

    /**
     * Campos de vigencia compartidos: desde cuándo rige, hasta cuándo (opcional) y notas.
     *
     * @return array<int, Component>
     */
    public static function periodFields(): array
    {
        return [
            DatePicker::make('valid_from')
                ->label('Rige desde')
                ->default(Carbon::today())
                ->required()
                ->native(false)
                ->displayFormat('d/m/Y')
                ->helperText('Puede ser una fecha futura para programar el cambio: el horario actual sigue hasta el día anterior. Los días ya calculados no se recalculan solos.'),

            DatePicker::make('valid_until')
                ->label('Hasta (opcional)')
                ->native(false)
                ->displayFormat('d/m/Y')
                ->afterOrEqual('valid_from')
                ->helperText('Vacío = sin fecha de fin.'),

            Textarea::make('notes')
                ->label('Notas (opcional)')
                ->rows(2)
                ->maxLength(255)
                ->columnSpanFull(),
        ];
    }

    /**
     * Formulario de las acciones del listado de Empleados: horario a asignar más la vigencia.
     *
     * @return array<int, Component>
     */
    public static function formFields(): array
    {
        return [
            Select::make('schedule_id')
                ->label('Horario')
                ->options(fn () => Schedule::query()->orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->native(false)
                ->required(),

            ...static::periodFields(),
        ];
    }

    /**
     * Acción de fila "Asignar horario" para un empleado activo.
     */
    public static function row(): Action
    {
        return Action::make('assign_schedule')
            ->label('Asignar horario')
            ->icon('heroicon-o-clock')
            ->color('primary')
            ->visible(fn (Employee $record): bool => $record->status === 'active' && static::canAssign())
            ->modalHeading(fn (Employee $record): string => "Asignar horario a {$record->full_name}")
            ->modalDescription(function (Employee $record): string {
                $current = $record->getScheduleForDate(Carbon::today());

                return $current
                    ? "Horario actual: {$current->name}. Al asignar uno nuevo, el actual se cierra el día anterior a la fecha de inicio."
                    : 'Este empleado no tiene horario vigente: hasta que tenga uno no se calculan horas, tardanzas ni ausencias.';
            })
            ->form(static::formFields())
            ->modalSubmitActionLabel('Asignar')
            ->action(fn (Employee $record, array $data) => static::apply(collect([$record]), $data));
    }

    /**
     * Acción masiva "Asignar horario" para los empleados seleccionados.
     */
    public static function bulk(): BulkAction
    {
        return BulkAction::make('assign_schedule_bulk')
            ->label('Asignar horario')
            ->icon('heroicon-o-clock')
            ->color('primary')
            ->visible(fn (): bool => static::canAssign())
            ->modalHeading('Asignar horario a los seleccionados')
            ->modalDescription(function (Collection $records): string {
                $active = $records->where('status', 'active');
                $withSchedule = $active->filter(fn (Employee $e) => $e->getScheduleForDate(Carbon::today()) !== null)->count();

                $text = "Se asignará el horario a {$active->count()} empleado(s) activo(s) de {$records->count()} seleccionado(s).";

                return $withSchedule > 0
                    ? "{$text} Atención: {$withSchedule} ya tiene(n) un horario vigente, que se cerrará el día anterior a la fecha de inicio."
                    : $text;
            })
            ->form(static::formFields())
            ->modalSubmitActionLabel('Asignar')
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records, array $data) => static::apply($records, $data));
    }

    /**
     * Asigna el horario a los empleados activos indicados y avisa cuántos se asignaron, omitieron o fallaron.
     * Omite a quien ya tiene ese mismo horario vigente en la fecha de inicio.
     *
     * @param  Collection<int, Employee>  $employees
     * @param  array{schedule_id: int|string, valid_from: string, valid_until?: string|null, notes?: string|null}  $data
     */
    public static function apply(Collection $employees, array $data): void
    {
        if (! static::canAssign()) {
            return;
        }

        $schedule = Schedule::findOrFail($data['schedule_id']);
        $from = Carbon::parse($data['valid_from'])->startOfDay();
        $until = filled($data['valid_until'] ?? null) ? Carbon::parse($data['valid_until'])->startOfDay() : null;

        $assigned = 0;
        $skipped = 0;
        $failed = [];

        foreach ($employees as $employee) {
            if ($employee->status !== 'active' || $employee->getScheduleForDate($from)?->id === $schedule->id) {
                $skipped++;

                continue;
            }

            try {
                ScheduleAssignmentService::assign($employee, $schedule, $from, $until, $data['notes'] ?? null);
                $assigned++;
            } catch (\Exception) {
                $failed[] = $employee->full_name;
            }
        }

        Notification::make()
            ->title("Horario asignado a {$assigned} empleado(s)")
            ->body("\"{$schedule->name}\" rige desde {$from->format('d/m/Y')}. Omitidos (inactivos o ya con ese horario): {$skipped}."
                .($failed ? ' Revisar solapamientos en: '.implode(', ', $failed).'.' : ''))
            ->color($failed ? 'warning' : 'success')
            ->persistent((bool) $failed)
            ->send();
    }
}
