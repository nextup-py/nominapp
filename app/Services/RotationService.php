<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\RotationAssignment;
use App\Models\RotationPattern;
use App\Models\ShiftOverride;
use App\Models\ShiftTemplate;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gestiona la lógica de rotación de turnos: asignación de patrones,
 * overrides puntuales y resolución del turno efectivo para cualquier fecha.
 *
 * Jerarquía de prioridad (de mayor a menor):
 *   1. ShiftOverride (override puntual por fecha)
 *   2. RotationAssignment (patrón rotativo vigente)
 *   3. EmployeeScheduleAssignment (horario fijo, sistema anterior)
 *   4. employees.schedule_id (legado)
 */
class RotationService
{
    /**
     * Retorna el turno efectivo de un empleado para una fecha dada.
     * Respeta la jerarquía de prioridad completa.
     *
     * @param  Carbon|null  $date  Fecha de consulta (por defecto hoy).
     * @return ShiftTemplate|null Turno efectivo, o null si no hay asignación de rotación.
     */
    public static function getShiftForDate(Employee $employee, ?Carbon $date = null): ?ShiftTemplate
    {
        $date ??= Carbon::today();

        // 1. Override puntual: máxima prioridad
        $override = ShiftOverride::where('employee_id', $employee->id)
            ->where('override_date', $date->toDateString())
            ->with('shift')
            ->first();

        if ($override) {
            return $override->shift;
        }

        // 2. Patrón rotativo vigente en la fecha dada
        $assignment = RotationAssignment::where('employee_id', $employee->id)
            ->forDate($date)
            ->with('pattern')
            ->latest('valid_from')
            ->first();

        if (! $assignment) {
            return null; // sin rotación activa → el caller cae al sistema de horarios fijos
        }

        $shiftId = $assignment->shiftIdForDate($date);

        if (! $shiftId) {
            return null;
        }

        return ShiftTemplate::find($shiftId);
    }

    /**
     * Asigna un patrón de rotación a un empleado a partir de una fecha dada.
     *
     * Cierra automáticamente el assignment abierto anterior (si existe) y
     * previene solapamientos con asignaciones ya cerradas.
     *
     * @param  Carbon  $validFrom  Fecha de inicio de vigencia.
     * @param  int  $startIndex  Posición inicial en la secuencia (0-based).
     * @param  Carbon|null  $validUntil  Fecha de fin (null = indefinido).
     *
     * @throws ValidationException
     */
    public static function assign(
        Employee $employee,
        RotationPattern $pattern,
        Carbon $validFrom,
        int $startIndex = 0,
        ?Carbon $validUntil = null,
        ?string $notes = null
    ): RotationAssignment {
        if ($validUntil && $validUntil->lt($validFrom)) {
            throw ValidationException::withMessages([
                'valid_until' => 'La fecha de fin no puede ser anterior a la fecha de inicio.',
            ]);
        }

        if ($startIndex < 0 || $startIndex >= $pattern->cycle_length) {
            throw ValidationException::withMessages([
                'start_index' => 'El índice inicial debe estar entre 0 y '.($pattern->cycle_length - 1).'.',
            ]);
        }

        return DB::transaction(function () use ($employee, $pattern, $validFrom, $startIndex, $validUntil, $notes) {
            // Cerrar la asignación abierta (sin valid_until) si su valid_from es anterior
            RotationAssignment::where('employee_id', $employee->id)
                ->whereNull('valid_until')
                ->where('valid_from', '<', $validFrom)
                ->update(['valid_until' => $validFrom->copy()->subDay()]);

            // Detectar solapamiento con asignaciones ya cerradas
            $overlap = RotationAssignment::where('employee_id', $employee->id)
                ->where('valid_from', '<=', $validUntil ?? '9999-12-31')
                ->where(fn ($q) => $q
                    ->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', $validFrom)
                )
                ->exists();

            if ($overlap) {
                throw ValidationException::withMessages([
                    'valid_from' => 'El rango de fechas se superpone con una asignación de rotación existente.',
                ]);
            }

            return RotationAssignment::create([
                'employee_id' => $employee->id,
                'pattern_id' => $pattern->id,
                'start_index' => $startIndex,
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
                'notes' => $notes,
                'created_by_id' => Auth::id(),
            ]);
        });
    }

    /**
     * Cierra la asignación de rotación activa del empleado en una fecha dada: la abierta (sin fin) o la que tenía
     * un fin posterior, que se adelanta. No hace nada si no tiene una asignación que siga rigiendo después de esa fecha.
     *
     * @param  Carbon  $closedOn  Último día de vigencia (inclusive).
     * @return int Número de registros actualizados (0 o 1).
     */
    public static function closeActive(Employee $employee, Carbon $closedOn): int
    {
        return RotationAssignment::where('employee_id', $employee->id)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', $closedOn->toDateString()))
            ->where('valid_from', '<=', $closedOn)
            ->update(['valid_until' => $closedOn]);
    }

    /**
     * Crea o reemplaza un override puntual para un empleado en una fecha específica.
     *
     * @param  string  $reasonType  Valor del enum reason_type.
     */
    public static function override(
        Employee $employee,
        Carbon $date,
        ShiftTemplate $shift,
        string $reasonType,
        ?string $notes = null
    ): ShiftOverride {
        return ShiftOverride::updateOrCreate(
            [
                'employee_id' => $employee->id,
                'override_date' => $date->toDateString(),
            ],
            [
                'shift_id' => $shift->id,
                'reason_type' => $reasonType,
                'notes' => $notes,
                'created_by_id' => Auth::id(),
            ]
        );
    }

    /**
     * Elimina el override de un empleado para una fecha específica,
     * restaurando el turno del patrón rotativo.
     */
    public static function removeOverride(Employee $employee, Carbon $date): bool
    {
        $overrides = ShiftOverride::where('employee_id', $employee->id)
            ->where('override_date', $date->toDateString())
            ->get();

        // Se elimina modelo por modelo para que quede registrado en el historial quién lo hizo.
        $overrides->each->delete();

        return $overrides->isNotEmpty();
    }
}
