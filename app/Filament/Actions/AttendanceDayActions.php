<?php

namespace App\Filament\Actions;

use App\Filament\Resources\AttendanceDayResource;
use App\Models\AttendanceDay;
use App\Services\AttendanceCalculator;
use App\Services\OpenShiftCloser;
use App\Settings\PayrollSettings;
use Filament\Actions\Action;
use Filament\Actions\MountableAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Acciones de {@see AttendanceDay} compartidas por el listado (acciones de fila), las páginas de detalle y
 * edición (acciones de encabezado) y las acciones masivas. Se construye con la clase de acción de tabla o de
 * página, igual que `TerminalLinkActions` y `AbsenceActions`, para no mantener dos copias de cada una.
 */
class AttendanceDayActions
{
    /** Indica si las acciones se arman para una tabla (fila) y no para una página (encabezado). */
    private readonly bool $inTable;

    /**
     * @param  class-string<MountableAction>  $actionClass  `Filament\Tables\Actions\Action` o `Filament\Actions\Action`.
     */
    public function __construct(private readonly string $actionClass)
    {
        $this->inTable = is_a($actionClass, TableAction::class, true);
    }

    /** Acciones de fila para el listado. */
    public static function forTable(): self
    {
        return new self(TableAction::class);
    }

    /** Acciones de encabezado para las páginas de detalle y edición. */
    public static function forPage(): self
    {
        return new self(Action::class);
    }

    /** Crea la acción con la clase de tabla o de página. */
    private function make(string $name): MountableAction
    {
        return ($this->actionClass)::make($name);
    }

    /**
     * Retorna la acción de aprobar/revocar horas extras para tabla
     */
    public function approveOvertime(): MountableAction
    {
        return $this->make('approve_overtime')
            ->authorize(fn (): bool => AttendanceDayResource::canManageDays())
            ->label(fn (AttendanceDay $record) => match (true) {
                $this->inTable => $record->overtime_approved ? 'Revocar' : 'Aprobar',
                default => $record->overtime_approved ? 'Revocar Horas Extra' : 'Aprobar Horas Extra',
            })
            ->icon(fn (AttendanceDay $record) => $record->overtime_approved ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
            ->color(fn (AttendanceDay $record) => $record->overtime_approved ? 'danger' : 'success')
            ->visible(fn (AttendanceDay $record) => $record->extra_hours > 0)
            ->tooltip(
                fn (AttendanceDay $record) => $record->overtime_approved
                    ? 'Revocar aprobación de '.$record->extra_hours.' hrs extra'
                    : 'Aprobar '.$record->extra_hours.' hrs extra'
            )
            ->requiresConfirmation()
            ->modalHeading(
                fn (AttendanceDay $record) => $record->overtime_approved
                    ? 'Revocar aprobación de horas extra'
                    : 'Aprobar horas extra'
            )
            ->modalDescription(
                function (AttendanceDay $record) {
                    return self::buildOvertimeModalDescription($record);
                }
            )
            ->modalSubmitActionLabel(fn (AttendanceDay $record) => $record->overtime_approved ? 'Sí, revocar' : 'Sí, aprobar')
            ->action(function (AttendanceDay $record) {
                if (AttendanceDayResource::blockedByPayroll($record)) {
                    return;
                }

                $wasApproved = $record->overtime_approved;
                $record->overtime_approved = ! $wasApproved;
                $record->save();

                $action = $wasApproved ? 'revocada' : 'aprobada';

                Notification::make()
                    ->title("Aprobación {$action}")
                    ->body("Las {$record->extra_hours} hrs extra han sido {$action}s exitosamente.")
                    ->success()
                    ->send();
            });
    }

    /**
     * Retorna la acción de aceptar/revertir un doble turno como jornada normal para tabla.
     */
    public function regularShift(): MountableAction
    {
        return $this->make('regular_shift')
            ->authorize(fn (): bool => AttendanceDayResource::canManageDays())
            ->label(fn (AttendanceDay $record) => $record->second_shift_regular ? 'Volver a revisar doble turno' : 'Aceptar doble turno como jornada normal')
            ->icon('heroicon-o-clock')
            ->color(fn (AttendanceDay $record) => $record->second_shift_regular ? 'gray' : 'info')
            ->visible(fn (AttendanceDay $record) => self::canDecideRegularShift($record))
            ->tooltip($this->inTable ? 'El empleado hizo más de un turno el mismo día: las horas sobre el horario no se pagan como extra' : null)
            ->requiresConfirmation()
            ->modalHeading(fn (AttendanceDay $record) => $record->second_shift_regular ? 'Volver a revisar el doble turno' : 'Aceptar el doble turno como jornada normal')
            ->modalDescription(fn (AttendanceDay $record) => $record->second_shift_regular
                ? 'Las horas sobre el horario volverán a contarse como horas extra pendientes de aprobación.'
                : "Las {$record->extra_hours} hrs sobre el horario no se pagarán como horas extra y el día saldrá de los pendientes. Se puede deshacer.")
            ->modalSubmitActionLabel(fn (AttendanceDay $record) => $record->second_shift_regular ? 'Sí, volver a revisar' : 'Sí, aceptar como jornada normal')
            ->action(function (AttendanceDay $record, $livewire) {
                self::applyRegularShiftDecision($record, ! $record->second_shift_regular);

                if (! $this->inTable && method_exists($livewire, 'refreshFormData')) {
                    $livewire->refreshFormData(['second_shift_regular', 'extra_hours', 'overtime_approved']);
                }
            });
    }

    /**
     * Retorna la acción de aprobar/revocar descuento de tardanza para tabla.
     */
    public function approveTardiness(): MountableAction
    {
        return $this->make('approve_tardiness')
            ->authorize(fn (): bool => AttendanceDayResource::canManageDays())
            ->label(fn (AttendanceDay $record) => match (true) {
                $this->inTable => $record->tardiness_deduction_approved ? 'Revocar Tardanza' : 'Aprobar Tardanza',
                default => $record->tardiness_deduction_approved ? 'Revocar Descuento Tardanza' : 'Aprobar Descuento Tardanza',
            })
            ->icon(fn (AttendanceDay $record) => $record->tardiness_deduction_approved ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
            ->color(fn (AttendanceDay $record) => $record->tardiness_deduction_approved ? 'danger' : 'warning')
            ->visible(fn (AttendanceDay $record) => $record->late_minutes > 0)
            ->tooltip(fn (AttendanceDay $record) => match (true) {
                ! $this->inTable => null,
                $record->tardiness_deduction_approved => "Revocar descuento por {$record->late_minutes} min de tardanza",
                default => "Aprobar descuento por {$record->late_minutes} min de tardanza",
            })
            ->requiresConfirmation()
            ->modalHeading(fn (AttendanceDay $record) => $record->tardiness_deduction_approved
                ? 'Revocar descuento por tardanza'
                : 'Aprobar descuento por tardanza'
            )
            ->modalDescription(fn (AttendanceDay $record) => self::buildTardinessModalDescription($record))
            ->modalSubmitActionLabel(fn (AttendanceDay $record) => $record->tardiness_deduction_approved ? 'Sí, revocar' : 'Sí, aprobar')
            ->action(function (AttendanceDay $record) {
                if (AttendanceDayResource::blockedByPayroll($record)) {
                    return;
                }

                $wasApproved = $record->tardiness_deduction_approved;
                $record->tardiness_deduction_approved = ! $wasApproved;
                $record->save();

                $action = $wasApproved ? 'revocado' : 'aprobado';
                Notification::make()
                    ->title("Descuento {$action}")
                    ->body("El descuento por {$record->late_minutes} min de tardanza ha sido {$action}.")
                    ->success()
                    ->send();
            });
    }

    /**
     * Retorna la acción de exportar PDF para tabla
     */
    public function exportPdf(): MountableAction
    {
        return $this->make('export')
            ->label('Exportar PDF')
            ->icon('heroicon-o-arrow-down-tray')
            ->color($this->inTable ? 'info' : 'gray')
            ->tooltip('Exportar registro como PDF')
            ->url(fn (AttendanceDay $record) => route('attendance-days.export', ['attendance_day' => $record->id]))
            ->openUrlInNewTab();
    }

    /**
     * Retorna la acción de calcular/recalcular asistencia para tabla
     */
    public function calculate(): MountableAction
    {
        return $this->make('calculate')
            ->authorize(fn (): bool => AttendanceDayResource::canManageDays())
            ->label(fn (AttendanceDay $record) => $record->is_calculated ? 'Recalcular' : 'Calcular')
            ->icon('heroicon-o-calculator')
            ->color(fn (AttendanceDay $record) => $record->is_calculated ? 'warning' : 'success')
            ->tooltip(
                fn (AttendanceDay $record) => $record->is_calculated
                    ? 'Última vez calculado: '.$record->calculated_at?->diffForHumans()
                    : 'Este registro aún no ha sido calculado'
            )
            ->requiresConfirmation()
            ->modalHeading(
                fn (AttendanceDay $record) => $record->is_calculated
                    ? 'Recalcular asistencia'
                    : 'Calcular asistencia'
            )
            ->modalDescription(
                fn (AttendanceDay $record) => $record->is_calculated
                    ? 'Este registro ya fue calculado el '.$record->calculated_at?->format('d/m/Y H:i').'. ¿Deseas recalcularlo?'
                    : 'Se calcularán todos los campos de asistencia para este registro.'
            )
            ->modalSubmitActionLabel(fn (AttendanceDay $record) => $record->is_calculated ? 'Sí, recalcular' : 'Sí, calcular')
            ->form([
                Toggle::make('refresh_schedule')
                    ->label('Volver a leer el horario vigente')
                    ->helperText('Una jornada ya calculada conserva las horas esperadas guardadas. Activalo si el horario cambió; no aplica a jornadas con aprobaciones, ajustes o exclusión.')
                    ->visible(fn (AttendanceDay $record) => $record->is_calculated),
            ])
            ->action(function (AttendanceDay $record, array $data) {
                self::calculateAttendanceRecord($record, (bool) ($data['refresh_schedule'] ?? false));
            });
    }

    /**
     * Retorna la acción de ajuste manual de horas extras para tabla
     */
    public function adjustExtraHours(): MountableAction
    {
        $settings = app(PayrollSettings::class);
        $pctDiurno = (int) (($settings->overtime_multiplier_diurno - 1) * 100);
        $pctNocturno = (int) (($settings->overtime_multiplier_nocturno - 1) * 100);

        return $this->make('adjust_extra_hours')
            ->authorize(fn (): bool => AttendanceDayResource::canManageDays())
            ->label('Ajustar Horas Extra')
            ->icon('heroicon-o-clock')
            ->color('warning')
            ->tooltip('Cargar horas extras manualmente para este día')
            ->mountUsing(function (Form $form, AttendanceDay $record) {
                $form->fill([
                    'extra_hours_diurnas' => $record->extra_hours_diurnas ?? 0,
                    'extra_hours_nocturnas' => $record->extra_hours_nocturnas ?? 0,
                    'notes' => $record->notes,
                ]);
            })
            ->form([
                TextInput::make('extra_hours_diurnas')
                    ->label("Horas extras diurnas ({$pctDiurno}%)")
                    ->numeric()
                    ->step(0.5)
                    ->minValue(0)
                    ->maxValue(24)
                    ->default(0)
                    ->suffix('hrs'),

                TextInput::make('extra_hours_nocturnas')
                    ->label("Horas extras nocturnas ({$pctNocturno}%)")
                    ->numeric()
                    ->step(0.5)
                    ->minValue(0)
                    ->maxValue(24)
                    ->default(0)
                    ->suffix('hrs'),

                Textarea::make('notes')
                    ->label('Notas')
                    ->maxLength(500)
                    ->rows(2)
                    ->columnSpanFull(),
            ])
            ->modalHeading(fn (AttendanceDay $record) => 'Ajustar horas extras — '.$record->employee?->full_name.' — '.$record->date->format('d/m/Y'))
            ->modalSubmitActionLabel('Guardar')
            ->action(function (AttendanceDay $record, array $data) {
                if (AttendanceDayResource::blockedByPayroll($record)) {
                    return;
                }

                $diurnas = (float) ($data['extra_hours_diurnas'] ?? 0);
                $nocturnas = (float) ($data['extra_hours_nocturnas'] ?? 0);
                $total = round($diurnas + $nocturnas, 2);

                $record->extra_hours_diurnas = $diurnas;
                $record->extra_hours_nocturnas = $nocturnas;
                $record->extra_hours = $total;

                if (filled($data['notes'] ?? null)) {
                    $record->notes = $data['notes'];
                }

                if ($total > 0) {
                    $record->manual_adjustment = true;
                    $record->overtime_approved = true;
                    $record->status = 'present';
                    $record->is_calculated = true;
                } else {
                    $record->manual_adjustment = false;
                }

                $record->save();

                Notification::make()
                    ->title('Horas extras actualizadas')
                    ->body($total > 0
                        ? "Se registraron {$total} hrs extras ({$diurnas}h diurnas + {$nocturnas}h nocturnas)."
                        : 'Las horas extras fueron removidas del registro.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Indica si corresponde ofrecer la decisión de doble turno: el día tiene más de una entrada y
     * o bien horas extra por revisar, o bien ya se aceptó como jornada normal (para poder deshacerlo).
     */
    private static function canDecideRegularShift(AttendanceDay $record): bool
    {
        return ($record->second_shift_regular || (float) $record->extra_hours > 0) && $record->hasMultipleShifts();
    }

    /**
     * Acepta o revierte un doble turno como jornada normal y recalcula el día.
     * Aceptarlo deja las horas sobre el horario sin pago extra y quita el día de los pendientes.
     */
    private static function applyRegularShiftDecision(AttendanceDay $record, bool $accept): void
    {
        if (AttendanceDayResource::blockedByPayroll($record)) {
            return;
        }

        $extraBefore = (float) $record->extra_hours;

        $record->second_shift_regular = $accept;
        $record->overtime_approved = false;
        AttendanceCalculator::apply($record);
        $record->save();

        Notification::make()
            ->title($accept ? 'Doble turno aceptado como jornada normal' : 'Doble turno vuelto a revisión')
            ->body($accept
                ? "Las {$extraBefore} hrs del segundo turno ya no se cuentan como horas extra."
                : "Las horas sobre el horario ({$record->extra_hours} hrs extra) vuelven a quedar pendientes de aprobación.")
            ->success()
            ->send();
    }

    /**
     * Construye la descripción del modal de aprobación de tardanza.
     */
    private static function buildTardinessModalDescription(AttendanceDay $record): string
    {
        $action = $record->tardiness_deduction_approved ? 'Se revocará el descuento de' : 'Se aprobará el descuento de';
        $settings = app(PayrollSettings::class);
        $hourlyRate = $record->employee->base_salary / max(1, $settings->monthly_hours);
        $amount = round(($record->late_minutes / 60) * $hourlyRate, 2);

        return "{$action} {$record->late_minutes} min de tardanza para {$record->employee->full_name}"
            ." del {$record->date->format('d/m/Y')} (Gs. ".number_format($amount, 0, ',', '.').').';
    }

    private static function buildOvertimeModalDescription(AttendanceDay $record): string
    {
        $settings = app(PayrollSettings::class);
        $pctDiurno = (int) (($settings->overtime_multiplier_diurno - 1) * 100);
        $pctNocturno = (int) (($settings->overtime_multiplier_nocturno - 1) * 100);

        $action = $record->overtime_approved ? 'Se revocara la aprobacion de' : 'Se aprobaran';
        $desc = "{$action} {$record->extra_hours} hrs extra para {$record->employee->full_name} del {$record->date->format('d/m/Y')}.";

        if ($record->extra_hours_diurnas > 0 || $record->extra_hours_nocturnas > 0) {
            $parts = [];
            if ($record->extra_hours_diurnas > 0) {
                $parts[] = "{$record->extra_hours_diurnas}h diurnas ({$pctDiurno}%)";
            }
            if ($record->extra_hours_nocturnas > 0) {
                $parts[] = "{$record->extra_hours_nocturnas}h nocturnas ({$pctNocturno}%)";
            }
            $desc .= ' Desglose: '.implode(' + ', $parts).'.';
        }

        // Resumen semanal — usar copy() para no mutar $record->date
        $weekStart = $record->date->copy()->startOfWeek()->toDateString();
        $weekEnd = $record->date->copy()->endOfWeek()->toDateString();
        $weeklyOtherHours = AttendanceDay::where('employee_id', $record->employee_id)
            ->whereBetween('date', [$weekStart, $weekEnd])
            ->where('id', '!=', $record->id)
            ->sum('extra_hours');
        $weeklyTotal = (float) $weeklyOtherHours + (float) $record->extra_hours;
        $weeklyMax = $settings->overtime_max_weekly_hours;

        $desc .= " Total semana: {$weeklyTotal}h / {$weeklyMax}h permitidas.";

        if ($record->extra_hours > $settings->overtime_max_daily_hours) {
            $desc .= " ATENCION: Excede el limite diario de {$settings->overtime_max_daily_hours}h/dia (Art. 202 CLT).";
        }
        if ($weeklyTotal > $weeklyMax) {
            $desc .= " ATENCION: Excede el limite semanal de {$weeklyMax}h/semana (Art. 202 CLT).";
        }

        return $desc;
    }

    /**
     * Calcula un registro de asistencia y muestra notificación
     */
    private static function calculateAttendanceRecord(AttendanceDay $record, bool $refreshSchedule = false): void
    {
        if (AttendanceDayResource::blockedByPayroll($record)) {
            return;
        }

        try {
            $wasCalculated = $record->is_calculated;

            if ($refreshSchedule && $wasCalculated) {
                if ($record->hasProtectedDecisions()) {
                    Notification::make()
                        ->warning()
                        ->title('Horario sin actualizar')
                        ->body('La jornada tiene aprobaciones, ajustes o exclusión: se recalculó con las horas esperadas guardadas.')
                        ->send();
                } else {
                    $record->is_calculated = false;
                }
            }

            AttendanceCalculator::apply($record);
            $record->save();

            $action = $wasCalculated ? 'recalculado' : 'calculado';
            $statusMessages = AttendanceDay::getCalculationStatusMessages($wasCalculated);
            $message = $statusMessages[$record->status] ?? "Cálculo {$action}";

            Notification::make()
                ->title("Registro {$action}")
                ->body($message)
                ->success()
                ->send();
        } catch (\Exception $e) {
            Log::error("Error calculando AttendanceDay ID {$record->id}: {$e->getMessage()}", [
                'attendance_day_id' => $record->id,
                'date' => $record->date,
                'trace' => $e->getTraceAsString(),
            ]);

            Notification::make()
                ->title('Error al calcular')
                ->body('Ocurrió un error al procesar el registro. Revisa los logs para más detalles.')
                ->danger()
                ->persistent()
                ->send();
        }
    }

    /** Texto para las notificaciones masivas con las jornadas omitidas por tener nómina generada. */
    private static function lockedSuffix(int $locked): string
    {
        return $locked > 0 ? " | Omitidas por nómina generada: {$locked}" : '';
    }

    /** Acción masiva `approve_overtime`. */
    public static function approveOvertimeBulk(): BulkAction
    {
        return BulkAction::make('approve_overtime')
            ->authorize(fn (): bool => AttendanceDayResource::canManageDays())
            ->label('Aprobar Horas Extra')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Aprobar horas extra seleccionadas')
            ->modalDescription(function (Collection $records) {
                $withExtraHours = $records->where('extra_hours', '>', 0);
                $totalHours = $withExtraHours->sum('extra_hours');
                $alreadyApproved = $withExtraHours->where('overtime_approved', true)->count();
                $pending = $withExtraHours->where('overtime_approved', false)->count();

                return "Total: {$withExtraHours->count()} registro(s) con {$totalHours} hrs extra. Aprobadas: {$alreadyApproved}, Pendientes: {$pending}.";
            })
            ->modalSubmitActionLabel('Sí, aprobar')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records) {
                $approved = 0;
                $skipped = 0;
                $locked = 0;
                $closer = app(OpenShiftCloser::class);

                foreach ($records as $day) {
                    if ($closer->isPayrollLocked($day)) {
                        $locked++;
                    } elseif ($day->extra_hours > 0 && ! $day->overtime_approved) {
                        $day->overtime_approved = true;
                        $day->save();
                        $approved++;
                    } else {
                        $skipped++;
                    }
                }

                Notification::make()
                    ->title('Aprobación completada')
                    ->body("Aprobadas: {$approved} | Omitidas (sin hrs extra o ya aprobadas): {$skipped}".self::lockedSuffix($locked))
                    ->success()
                    ->send();
            });
    }

    /** Acción masiva `revoke_overtime`. */
    public static function revokeOvertimeBulk(): BulkAction
    {
        return BulkAction::make('revoke_overtime')
            ->authorize(fn (): bool => AttendanceDayResource::canManageDays())
            ->label('Revocar Horas Extra')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Revocar aprobación de horas extra')
            ->modalDescription(function (Collection $records) {
                $approved = $records->where('overtime_approved', true)->count();
                $totalHours = $records->where('overtime_approved', true)->sum('extra_hours');

                return "Se revocará la aprobación de {$approved} registro(s) con {$totalHours} hrs extra aprobadas.";
            })
            ->modalSubmitActionLabel('Sí, revocar')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records) {
                $revoked = 0;
                $skipped = 0;
                $locked = 0;
                $closer = app(OpenShiftCloser::class);

                foreach ($records as $day) {
                    if ($closer->isPayrollLocked($day)) {
                        $locked++;
                    } elseif ($day->overtime_approved) {
                        $day->overtime_approved = false;
                        $day->save();
                        $revoked++;
                    } else {
                        $skipped++;
                    }
                }

                Notification::make()
                    ->title('Revocación completada')
                    ->body("Revocadas: {$revoked} | Omitidas (ya sin aprobar): {$skipped}".self::lockedSuffix($locked))
                    ->success()
                    ->send();
            });
    }

    /** Acción masiva `approve_tardiness`. */
    public static function approveTardinessBulk(): BulkAction
    {
        return BulkAction::make('approve_tardiness')
            ->authorize(fn (): bool => AttendanceDayResource::canManageDays())
            ->label('Aprobar Descuento Tardanza')
            ->icon('heroicon-o-check-circle')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Aprobar descuento de tardanza seleccionados')
            ->modalDescription(function (Collection $records) {
                $withTardiness = $records->where('late_minutes', '>', 0);
                $pending = $withTardiness->where('tardiness_deduction_approved', false)->count();

                return "Total: {$withTardiness->count()} registro(s) con tardanza. Pendientes de aprobación: {$pending}.";
            })
            ->modalSubmitActionLabel('Sí, aprobar')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records) {
                $approved = 0;
                $skipped = 0;
                $locked = 0;
                $closer = app(OpenShiftCloser::class);
                foreach ($records as $day) {
                    if ($closer->isPayrollLocked($day)) {
                        $locked++;
                    } elseif ($day->late_minutes > 0 && ! $day->tardiness_deduction_approved) {
                        $day->tardiness_deduction_approved = true;
                        $day->save();
                        $approved++;
                    } else {
                        $skipped++;
                    }
                }
                Notification::make()
                    ->title('Aprobación completada')
                    ->body("Aprobadas: {$approved} | Omitidas: {$skipped}".self::lockedSuffix($locked))
                    ->success()
                    ->send();
            });
    }

    /** Acción masiva `revoke_tardiness`. */
    public static function revokeTardinessBulk(): BulkAction
    {
        return BulkAction::make('revoke_tardiness')
            ->authorize(fn (): bool => AttendanceDayResource::canManageDays())
            ->label('Revocar Descuento Tardanza')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Revocar aprobación de tardanza')
            ->modalDescription(function (Collection $records) {
                $approved = $records->where('tardiness_deduction_approved', true)->count();
                $totalMinutes = $records->where('tardiness_deduction_approved', true)->sum('late_minutes');

                return "Se revocará el descuento de {$approved} registro(s) con {$totalMinutes} min de tardanza aprobados.";
            })
            ->modalSubmitActionLabel('Sí, revocar')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records) {
                $revoked = 0;
                $skipped = 0;
                $locked = 0;
                $closer = app(OpenShiftCloser::class);
                foreach ($records as $day) {
                    if ($closer->isPayrollLocked($day)) {
                        $locked++;
                    } elseif ($day->tardiness_deduction_approved) {
                        $day->tardiness_deduction_approved = false;
                        $day->save();
                        $revoked++;
                    } else {
                        $skipped++;
                    }
                }
                Notification::make()
                    ->title('Revocación completada')
                    ->body("Revocadas: {$revoked} | Omitidas: {$skipped}".self::lockedSuffix($locked))
                    ->success()
                    ->send();
            });
    }

    /** Acción masiva `calculate`. */
    public static function calculateBulk(): BulkAction
    {
        return BulkAction::make('calculate')
            ->authorize(fn (): bool => AttendanceDayResource::canManageDays())
            ->label('Calcular/Recalcular')
            ->icon('heroicon-o-calculator')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Calcular asistencias seleccionadas')
            ->modalDescription(function (Collection $records) {
                $calculated = $records->where('is_calculated', true)->count();
                $notCalculated = $records->where('is_calculated', false)->count();

                return "Se procesarán {$records->count()} registro(s): {$notCalculated} sin calcular y {$calculated} para recalcular.";
            })
            ->modalSubmitActionLabel('Sí, calcular')
            ->form([
                Toggle::make('refresh_schedule')
                    ->label('Volver a leer el horario vigente')
                    ->helperText('Las jornadas ya calculadas conservan las horas esperadas guardadas. Activalo si el horario cambió; se omite en las jornadas con aprobaciones, ajustes o exclusión.'),
            ])
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, array $data) {
                $refreshSchedule = (bool) ($data['refresh_schedule'] ?? false);
                $refreshed = 0;
                $protected = 0;
                $successful = 0;
                $failed = 0;
                $recalculated = 0;
                $calculated = 0;
                $locked = 0;
                $statusCounts = [];
                $closer = app(OpenShiftCloser::class);

                foreach ($records as $day) {
                    if ($closer->isPayrollLocked($day)) {
                        $locked++;

                        continue;
                    }

                    try {
                        $wasCalculated = $day->is_calculated;

                        if ($refreshSchedule && $wasCalculated) {
                            if ($day->hasProtectedDecisions()) {
                                $protected++;
                            } else {
                                $day->is_calculated = false;
                                $refreshed++;
                            }
                        }

                        AttendanceCalculator::apply($day);
                        $day->save();

                        $successful++;
                        $wasCalculated ? $recalculated++ : $calculated++;
                        $statusCounts[$day->status] = ($statusCounts[$day->status] ?? 0) + 1;
                    } catch (\Exception $e) {
                        $failed++;
                        Log::error("Error calculando AttendanceDay ID {$day->id}: {$e->getMessage()}", [
                            'attendance_day_id' => $day->id,
                            'date' => $day->date,
                            'trace' => $e->getTraceAsString(),
                        ]);
                    }
                }

                $statusLabels = AttendanceDay::getStatusOptions();

                $breakdown = collect($statusCounts)
                    ->map(fn ($count, $status) => ($statusLabels[$status] ?? $status).": {$count}")
                    ->implode(' · ');

                $summary = "Procesados: {$successful} ({$calculated} nuevos · {$recalculated} recalculados)";
                if ($breakdown) {
                    $summary .= "\nEstados: {$breakdown}";
                }
                if ($failed > 0) {
                    $summary .= "\nFallidos: {$failed}";
                }
                if ($refreshSchedule) {
                    $summary .= "\nHorario vigente aplicado a {$refreshed} jornada(s); {$protected} protegida(s) por aprobaciones o ajustes.";
                }
                $summary .= self::lockedSuffix($locked);

                Notification::make()
                    ->title($failed > 0 ? 'Cálculo completado con advertencias' : 'Cálculo completado')
                    ->body($summary)
                    ->color($failed > 0 ? 'warning' : 'success')
                    ->send();
            });
    }
}
