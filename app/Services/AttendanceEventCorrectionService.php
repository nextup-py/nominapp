<?php

namespace App\Services;

use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Corrige en bloque las marcaciones de una jornada (agregar, editar la hora, eliminar) y recalcula el día. */
class AttendanceEventCorrectionService
{
    /** Días posteriores a la fecha de la jornada en que se admiten marcaciones (turnos que cruzan medianoche). */
    public const MAX_DAYS_AFTER = 1;

    /**
     * Normaliza las filas del formulario a eventos con instante y tipo, ordenados cronológicamente.
     *
     * @param  array<int, array<string, mixed>>  $rows  Cada fila: `id` (opcional), `event_type`, `recorded_at`.
     * @return array<int, array{id: int|null, event_type: string, recorded_at: Carbon}>
     */
    public function normalize(array $rows): array
    {
        $events = collect($rows)
            ->filter(fn ($row) => filled($row['event_type'] ?? null) && filled($row['recorded_at'] ?? null))
            ->map(fn ($row) => [
                'id' => filled($row['id'] ?? null) ? (int) $row['id'] : null,
                'event_type' => (string) $row['event_type'],
                'recorded_at' => Carbon::parse($row['recorded_at'])->seconds(0),
            ])
            ->sortBy(fn ($event) => $event['recorded_at']->getTimestamp())
            ->values();

        return $events->all();
    }

    /**
     * Valida la secuencia y las fechas de las marcaciones resultantes.
     *
     * @param  array<int, array{id: int|null, event_type: string, recorded_at: Carbon}>  $events
     * @return array<int, string> Mensajes de error (vacío si todo es válido).
     */
    public function validate(AttendanceDay $day, array $events): array
    {
        $errors = [];
        $from = $day->date->copy()->startOfDay();
        $until = $day->date->copy()->addDays(self::MAX_DAYS_AFTER)->endOfDay();
        $last = null;

        foreach ($events as $event) {
            $label = AttendanceEvent::getEventTypeLabel($event['event_type']);
            $when = $event['recorded_at']->format('d/m H:i');

            if ($event['recorded_at']->gt(now())) {
                $errors[] = "«{$label}» de las {$when} está en el futuro.";
            } elseif ($event['recorded_at']->lt($from) || $event['recorded_at']->gt($until)) {
                $errors[] = "«{$label}» de las {$when} cae fuera de la jornada del {$day->date->format('d/m/Y')}.";
            }

            if (! in_array($event['event_type'], AttendanceEvent::allowedNextEventTypes($last), true)) {
                $errors[] = "«{$label}» de las {$when} no puede ir después de "
                    .($last ? '«'.AttendanceEvent::getEventTypeLabel($last).'»' : 'el inicio del día').'.';
            }

            $last = $event['event_type'];
        }

        return array_values(array_unique($errors));
    }

    /**
     * Aplica la corrección: elimina lo que ya no está, actualiza lo modificado, crea lo nuevo y recalcula la jornada.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{created: int, updated: int, deleted: int}
     *
     * @throws InvalidArgumentException Si la secuencia resultante no es válida.
     */
    public function apply(AttendanceDay $day, array $rows): array
    {
        $events = $this->normalize($rows);
        $errors = $this->validate($day, $events);

        if ($errors !== []) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }

        return DB::transaction(function () use ($day, $events) {
            $existing = $day->events()->get()->keyBy('id');
            $keptIds = collect($events)->pluck('id')->filter()->all();
            $summary = ['created' => 0, 'updated' => 0, 'deleted' => 0];

            foreach ($existing as $id => $record) {
                if (! in_array($id, $keptIds, true)) {
                    $record->delete();
                    $summary['deleted']++;
                }
            }

            foreach ($events as $event) {
                $record = $event['id'] !== null ? $existing->get($event['id']) : null;

                if ($record === null) {
                    AttendanceEvent::create([
                        'attendance_day_id' => $day->id,
                        'employee_id' => $day->employee_id,
                        'event_type' => $event['event_type'],
                        'recorded_at' => $event['recorded_at'],
                        'source' => 'manual',
                    ]);
                    $summary['created']++;

                    continue;
                }

                $changed = $record->event_type !== $event['event_type']
                    || ! $record->recorded_at->copy()->seconds(0)->equalTo($event['recorded_at']);

                if ($changed) {
                    $record->update([
                        'event_type' => $event['event_type'],
                        'recorded_at' => $event['recorded_at'],
                        'source' => 'manual',
                    ]);
                    $summary['updated']++;
                }
            }

            $fresh = $day->fresh();
            AttendanceCalculator::apply($fresh);
            $fresh->save();

            return $summary;
        });
    }
}
