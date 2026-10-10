<?php

namespace App\Filament\Actions;

use App\Models\Absence;
use App\Models\AttendanceEvent;
use App\Models\EmployeeLeave;
use App\Services\AttendanceCalculator;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

/**
 * Acciones de resolución de una ausencia (registrar asistencia, justificar, marcar injustificada).
 * Se construyen con la misma lógica para la tabla (`Filament\Tables\Actions\Action`) y para el
 * encabezado de la página (`Filament\Actions\Action`): el llamador pasa la clase de acción.
 * El registro llega por inyección de `$record` en ambos casos.
 */
class AbsenceActions
{
    /**
     * Registra la jornada manual (crea las marcaciones y justifica la ausencia).
     *
     * @param  class-string  $class  `Filament\Tables\Actions\Action` o `Filament\Actions\Action`.
     */
    public static function registerAttendance(string $class): mixed
    {
        return $class::make('register_attendance')
            ->label('Registrar asistencia')
            ->icon('heroicon-o-clock')
            ->color('primary')
            ->tooltip('El empleado SÍ estuvo presente pero no marcó — crea las marcaciones y justifica el día automáticamente')
            ->visible(fn (Absence $record) => ! $record->isJustified() && auth()->user()->can('justify_absence'))
            ->modalHeading('Registrar jornada manual')
            ->modalDescription(fn (Absence $record) => $record->isUnjustified()
                ? '⚠️ Esta ausencia está marcada como injustificada con deducción generada. Al registrar la asistencia se justificará automáticamente y se eliminará la deducción.'
                : 'Complete los horarios para registrar la asistencia manualmente. La ausencia quedará justificada de forma automática.')
            ->modalSubmitActionLabel('Registrar y justificar')
            ->mountUsing(function (Form $form, Absence $record) {
                $day = $record->attendanceDay;
                $existingTypes = $day->events()->pluck('event_type')->toArray();

                $form->fill([
                    'check_in_time' => ! in_array('check_in', $existingTypes) ? $day->expected_check_in : null,
                    'check_out_time' => ! in_array('check_out', $existingTypes) ? $day->expected_check_out : null,
                    'reason_select' => 'no_mark',
                    'notes' => null,
                ]);
            })
            ->form([
                Placeholder::make('employee_info')
                    ->label('Empleado')
                    ->content(fn (Absence $record) => $record->employee->full_name
                        .' — '
                        .$record->attendanceDay->date->translatedFormat('l d/m/Y')),

                Grid::make(2)->schema([
                    TimePicker::make('check_in_time')
                        ->label('Hora de entrada')
                        ->seconds(false)
                        ->native(false)
                        ->helperText('Dejar vacío si ya existe marcación de entrada'),

                    TimePicker::make('check_out_time')
                        ->label('Hora de salida')
                        ->seconds(false)
                        ->native(false)
                        ->helperText('Dejar vacío si ya existe marcación de salida'),
                ]),

                Select::make('reason_select')
                    ->label('Motivo')
                    ->options([
                        'no_mark' => 'Presente sin marcación — olvido del empleado',
                        'tech_failure' => 'Falla técnica en el terminal',
                        'supervisor' => 'Marcación manual autorizada por supervisor',
                        'data_error' => 'Error de carga previo',
                        'other' => 'Otro',
                    ])
                    ->native(false)
                    ->required()
                    ->live(),

                Textarea::make('notes')
                    ->label('Notas adicionales')
                    ->rows(2)
                    ->placeholder('Especifique el motivo...')
                    ->required(fn (Get $get) => $get('reason_select') === 'other'),
            ])
            ->action(function (array $data, Absence $record) {
                $day = $record->attendanceDay;
                $date = $day->date;
                $existingTypes = $day->events()->pluck('event_type')->toArray();
                $created = 0;

                if (! in_array('check_in', $existingTypes) && filled($data['check_in_time'])) {
                    AttendanceEvent::create([
                        'attendance_day_id' => $day->id,
                        'event_type' => 'check_in',
                        'recorded_at' => Carbon::parse($date->format('Y-m-d').' '.$data['check_in_time']),
                        'source' => 'manual',
                    ]);
                    $created++;
                }

                if (! in_array('check_out', $existingTypes) && filled($data['check_out_time'])) {
                    AttendanceEvent::create([
                        'attendance_day_id' => $day->id,
                        'event_type' => 'check_out',
                        'recorded_at' => Carbon::parse($date->format('Y-m-d').' '.$data['check_out_time']),
                        'source' => 'manual',
                    ]);
                    $created++;
                }

                $day->refresh();
                AttendanceCalculator::apply($day);
                $day->save();

                $reasonLabels = [
                    'no_mark' => 'Presente sin marcación — olvido del empleado',
                    'tech_failure' => 'Falla técnica en el terminal',
                    'supervisor' => 'Marcación manual autorizada por supervisor',
                    'data_error' => 'Error de carga previo',
                    'other' => $data['notes'] ?? 'Otro',
                ];

                $reviewNotes = $reasonLabels[$data['reason_select']];
                if ($data['reason_select'] !== 'other' && filled($data['notes'] ?? null)) {
                    $reviewNotes .= ' — '.$data['notes'];
                }

                $record->justify(Auth::id(), $reviewNotes);

                $body = $created > 0
                    ? "Se registraron {$created} marcación(es) manual(es). La ausencia fue justificada."
                    : 'La ausencia fue justificada. No se crearon marcaciones (ya existían para este día).';

                Notification::make()
                    ->success()
                    ->title('Asistencia registrada')
                    ->body($body)
                    ->send();

                $record->refresh();
            });
    }

    /**
     * Justifica la ausencia vinculándola a un permiso aprobado o creando uno rápido.
     *
     * @param  class-string  $class  `Filament\Tables\Actions\Action` o `Filament\Actions\Action`.
     */
    public static function justify(string $class): mixed
    {
        return $class::make('justify')
            ->label('Justificar')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->tooltip('Marcar esta ausencia como justificada')
            ->visible(fn (Absence $record) => ! $record->isJustified() && auth()->user()->can('justify_absence'))
            ->modalHeading(fn (Absence $record) => $record->isUnjustified()
                ? 'Cambiar a Justificada'
                : 'Justificar Ausencia')
            ->modalDescription(fn (Absence $record) => $record->isUnjustified()
                ? '⚠️ Esta ausencia tiene una deducción generada. Al justificarla se eliminará la deducción.'
                : null)
            ->modalSubmitActionLabel('Justificar')
            ->mountUsing(function (Form $form, Absence $record) {
                $hasLeaves = EmployeeLeave::where('employee_id', $record->employee_id)
                    ->where('status', 'approved')
                    ->whereDate('start_date', '<=', $record->attendanceDay->date)
                    ->whereDate('end_date', '>=', $record->attendanceDay->date)
                    ->exists();

                $form->fill([
                    'has_approved_leaves' => $hasLeaves,
                    'justify_mode' => $hasLeaves ? 'existing' : 'quick',
                    'quick_leave_end_date' => $record->attendanceDay->date->format('Y-m-d'),
                ]);
            })
            ->form([
                Placeholder::make('employee_info')
                    ->label('Empleado')
                    ->content(fn (Absence $record) => $record->employee->full_name
                        .' — '
                        .$record->attendanceDay->date->translatedFormat('l d/m/Y')),

                Hidden::make('has_approved_leaves'),

                Radio::make('justify_mode')
                    ->label('¿Cómo justificar?')
                    ->options([
                        'existing' => 'Vincular a permiso ya existente',
                        'quick' => 'Crear permiso nuevo ahora',
                    ])
                    ->live()
                    ->required(),

                // ── Modo: permiso existente ──────────────────────────────

                Placeholder::make('no_leaves_notice')
                    ->label('Sin permisos disponibles')
                    ->content('No hay permisos aprobados que cubran esta fecha. Seleccioná "Crear permiso nuevo ahora" para generarlo en el acto.')
                    ->visible(fn (Get $get) => $get('justify_mode') === 'existing' && ! $get('has_approved_leaves')),

                Select::make('employee_leave_id')
                    ->label('Permiso / Licencia')
                    ->options(fn (Absence $record) => EmployeeLeave::where('employee_id', $record->employee_id)
                        ->where('status', 'approved')
                        ->whereDate('start_date', '<=', $record->attendanceDay->date)
                        ->whereDate('end_date', '>=', $record->attendanceDay->date)
                        ->get()
                        ->mapWithKeys(fn ($leave) => [
                            $leave->id => (EmployeeLeave::getTypeOptions()[$leave->type] ?? $leave->type)
                                .' ('.$leave->start_date->format('d/m/Y').' al '.$leave->end_date->format('d/m/Y').')',
                        ]))
                    ->native(false)
                    ->required(fn (Get $get) => $get('justify_mode') === 'existing' && (bool) $get('has_approved_leaves'))
                    ->helperText('Solo se muestran permisos aprobados que cubren este día')
                    ->visible(fn (Get $get) => $get('justify_mode') === 'existing' && (bool) $get('has_approved_leaves')),

                Textarea::make('review_notes')
                    ->label('Notas adicionales')
                    ->placeholder('Observaciones opcionales...')
                    ->rows(2)
                    ->visible(fn (Get $get) => $get('justify_mode') === 'existing' && (bool) $get('has_approved_leaves')),

                // ── Modo: permiso rápido ─────────────────────────────────

                Select::make('quick_leave_type')
                    ->label('Tipo de permiso')
                    ->options(EmployeeLeave::getTypeOptions())
                    ->native(false)
                    ->required(fn (Get $get) => $get('justify_mode') === 'quick')
                    ->visible(fn (Get $get) => $get('justify_mode') === 'quick'),

                DatePicker::make('quick_leave_end_date')
                    ->label('Fecha de fin del permiso')
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->closeOnDateSelection()
                    ->minDate(fn (Absence $record) => $record->attendanceDay->date)
                    ->helperText(fn (Absence $record) => 'Inicio: '.$record->attendanceDay->date->format('d/m/Y').'. Ajustá si el permiso cubre más de un día.')
                    ->required(fn (Get $get) => $get('justify_mode') === 'quick')
                    ->visible(fn (Get $get) => $get('justify_mode') === 'quick'),

                Textarea::make('quick_leave_reason')
                    ->label('Motivo del permiso')
                    ->placeholder('Descripción opcional...')
                    ->rows(2)
                    ->visible(fn (Get $get) => $get('justify_mode') === 'quick'),
            ])
            ->action(function (array $data, Absence $record, $action) {
                if ($data['justify_mode'] === 'quick') {
                    $leave = EmployeeLeave::create([
                        'employee_id' => $record->employee_id,
                        'type' => $data['quick_leave_type'],
                        'start_date' => $record->attendanceDay->date,
                        'end_date' => $data['quick_leave_end_date'] ?? $record->attendanceDay->date,
                        'reason' => $data['quick_leave_reason'] ?? null,
                        'status' => 'pending',
                    ]);

                    $result = $leave->approve(Auth::id());
                    $count = $result['justified_count'];
                    $dates = $result['justified_dates'] ?? [];
                    $typeLabel = EmployeeLeave::getTypeOptions()[$data['quick_leave_type']] ?? $data['quick_leave_type'];
                    $prefix = "Se creó y aprobó el permiso «{$typeLabel}». ";

                    if ($count <= 1) {
                        $body = $prefix.'La ausencia fue justificada.';
                    } elseif ($count <= 5) {
                        $dateList = collect($dates)->sortBy(fn ($d) => $d->timestamp)->map(fn ($d) => $d->translatedFormat('d/m'))->join(', ');
                        $body = $prefix."Se justificaron {$count} ausencias: {$dateList}.";
                    } else {
                        $body = $prefix."Se justificaron {$count} ausencias del período automáticamente.";
                    }

                    Notification::make()->success()->title('Ausencia justificada')->body($body)->send();
                    $record->refresh();

                    return;
                }

                if (empty($data['employee_leave_id'])) {
                    Notification::make()
                        ->warning()
                        ->title('Sin permiso seleccionado')
                        ->body('No hay permisos aprobados para vincular. Seleccioná "Crear permiso nuevo ahora".')
                        ->send();
                    $action->halt();

                    return;
                }

                $result = $record->justify(
                    Auth::id(),
                    $data['review_notes'] ?? null,
                    (int) $data['employee_leave_id']
                );

                Notification::make()->success()->title('Ausencia justificada')->body($result['message'])->send();
                $record->refresh();
            });
    }

    /**
     * Marca la ausencia como injustificada y genera la deducción salarial.
     *
     * @param  class-string  $class  `Filament\Tables\Actions\Action` o `Filament\Actions\Action`.
     */
    public static function markUnjustified(string $class): mixed
    {
        return $class::make('mark_unjustified')
            ->label('Marcar Injustificada')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->tooltip('Marcar como injustificada y generar deducción salarial')
            ->visible(fn (Absence $record) => ! $record->isUnjustified() && auth()->user()->can('mark_unjustified_absence'))
            ->modalHeading(fn (Absence $record) => $record->isJustified()
                ? 'Cambiar a Injustificada'
                : 'Marcar como Injustificada')
            ->modalSubmitActionLabel('Sí, marcar injustificada')
            ->mountUsing(function (Form $form, Absence $record) {
                $form->fill([
                    'deduction_preview' => $record->employee->getAbsenceDeductionAmount(),
                    'has_deduction' => $record->hasDeduction(),
                ]);
            })
            ->form([
                Hidden::make('deduction_preview'),
                Hidden::make('has_deduction'),

                Placeholder::make('deduction_amount_info')
                    ->label('Deducción a generar')
                    ->content(fn (Get $get): string => $get('has_deduction')
                        ? 'Ya existe una deducción para esta ausencia — no se creará una nueva.'
                        : 'Gs. '.number_format((float) $get('deduction_preview'), 0, ',', '.')),

                Textarea::make('review_notes')
                    ->label('Notas de revisión')
                    ->placeholder('Motivo por el cual se marca como injustificada...')
                    ->rows(3)
                    ->required(),
            ])
            ->action(function (array $data, Absence $record) {
                $result = $record->markAsUnjustified(Auth::id(), $data['review_notes']);

                if (! $result['success']) {
                    Notification::make()->warning()->title('No se pudo marcar como injustificada')->body($result['message'])->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Ausencia Marcada como Injustificada')
                    ->body($result['message'])
                    ->send();

                $record->refresh();
            });
    }
}
