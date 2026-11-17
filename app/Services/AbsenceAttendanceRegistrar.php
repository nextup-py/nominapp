<?php

namespace App\Services;

use App\Models\Absence;
use App\Models\AttendanceEvent;
use Carbon\Carbon;

/**
 * Registra la asistencia de quien SÍ estuvo presente pero no marcó (olvido, falla del terminal): crea las marcaciones
 * manuales que falten, recalcula la jornada y justifica la ausencia. Es la única definición de lo que hace
 * **Resolver → Registrar asistencia** y la usa también el comando `attendance:register-entries`.
 */
class AbsenceAttendanceRegistrar
{
    /** Motivos de la regularización con el texto que queda en la revisión de la ausencia. */
    public const REASONS = [
        'no_mark' => 'Presente sin marcación — olvido del empleado',
        'tech_failure' => 'Falla técnica en el terminal',
        'supervisor' => 'Marcación manual autorizada por supervisor',
        'data_error' => 'Error de carga previo',
        'other' => 'Otro',
    ];

    /**
     * Crea las marcaciones manuales indicadas que todavía no existan, recalcula la jornada y justifica la ausencia.
     *
     * @param  string|null  $checkIn  Hora de entrada (HH:MM) o null para no crear entrada.
     * @param  string|null  $checkOut  Hora de salida (HH:MM) o null para no crear salida.
     * @param  string  $reason  Una clave de `REASONS`.
     * @param  int  $reviewerId  Usuario que figura como revisor de la ausencia.
     * @return int Cantidad de marcaciones creadas.
     */
    public function register(Absence $absence, ?string $checkIn, ?string $checkOut, string $reason, ?string $notes, int $reviewerId): int
    {
        $day = $absence->attendanceDay;
        $date = $day->date->format('Y-m-d');
        $existingTypes = $day->events()->pluck('event_type')->toArray();
        $created = 0;

        foreach (['check_in' => $checkIn, 'check_out' => $checkOut] as $type => $time) {
            if (! in_array($type, $existingTypes, true) && filled($time)) {
                AttendanceEvent::create([
                    'attendance_day_id' => $day->id,
                    'event_type' => $type,
                    'recorded_at' => Carbon::parse("{$date} {$time}"),
                    'source' => 'manual',
                ]);
                $created++;
            }
        }

        $day->refresh();
        AttendanceCalculator::apply($day);
        $day->save();

        $reviewNotes = self::REASONS[$reason] ?? self::REASONS['other'];

        if ($reason === 'other') {
            $reviewNotes = $notes ?: $reviewNotes;
        } elseif (filled($notes)) {
            $reviewNotes .= ' — '.$notes;
        }

        $absence->justify($reviewerId, $reviewNotes);

        return $created;
    }
}
