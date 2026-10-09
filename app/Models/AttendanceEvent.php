<?php

namespace App\Models;

use Database\Factories\AttendanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceEvent extends Model
{
    /** @use HasFactory<AttendanceFactory> */
    use HasFactory;

    protected $fillable = [
        'attendance_day_id',
        'event_type',
        'recorded_at',
        'synced_at',
        'source',
        'location',
        'employee_id',
        'employee_name',
        'employee_ci',
        'branch_id',
        'branch_name',
        'terminal_id',
        'branch_mismatch',
        'client_event_id',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'synced_at' => 'datetime',
        'location' => 'array',
        'branch_mismatch' => 'boolean',
    ];

    /**
     * Relación con AttendanceDay
     */
    public function day(): BelongsTo
    {
        return $this->belongsTo(AttendanceDay::class, 'attendance_day_id');
    }

    /**
     * Relación directa con Employee (datos desnormalizados)
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Relación directa con Branch (datos desnormalizados)
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Terminal desde la que se realizó la marcación (null si fue móvil o manual). */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    /**
     * Accessor optimizado para mostrar ubicación formateada
     * Cache del resultado para evitar procesamiento repetido
     */
    public function getLocationDisplayAttribute(): string
    {
        if (! $this->location) {
            return 'Sin ubicación';
        }

        try {
            if (isset($this->location['lat']) && isset($this->location['lng'])) {
                return sprintf(
                    'Lat: %s, Lng: %s',
                    number_format($this->location['lat'], 6, '.', ''),
                    number_format($this->location['lng'], 6, '.', '')
                );
            }

            if (is_array($this->location)) {
                return collect($this->location)
                    ->map(fn ($value, $key) => "{$key}: {$value}")
                    ->join(', ');
            }

            return 'Ubicación inválida';
        } catch (\Exception $e) {
            return 'Error al procesar ubicación';
        }
    }

    /**
     * Accessor optimizado para URL de Google Maps
     */
    public function getGoogleMapsUrlAttribute(): ?string
    {
        if (! $this->location || ! is_array($this->location)) {
            return null;
        }

        if (isset($this->location['lat']) && isset($this->location['lng'])) {
            $lat = (float) $this->location['lat'];
            $lng = (float) $this->location['lng'];

            if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
                return sprintf(
                    'https://www.google.com/maps?q=%s,%s',
                    $lat,
                    $lng
                );
            }
        }

        return null;
    }

    /**
     * Obtiene el label traducido del tipo de evento
     */
    public static function getEventTypeLabel(string $eventType): string
    {
        return match ($eventType) {
            'check_in' => 'Entrada jornada',
            'break_start' => 'Inicio descanso',
            'break_end' => 'Fin descanso',
            'check_out' => 'Salida jornada',
            default => 'Desconocido',
        };
    }

    /**
     * Obtiene el color del badge según el tipo de evento
     */
    public static function getEventTypeColor(string $eventType): string
    {
        return match ($eventType) {
            'check_in' => 'success',
            'break_start' => 'warning',
            'break_end' => 'info',
            'check_out' => 'danger',
            default => 'gray',
        };
    }

    /**
     * Obtiene el icono según el tipo de evento
     */
    public static function getEventTypeIcon(string $eventType): string
    {
        return match ($eventType) {
            'check_in' => 'heroicon-o-arrow-right-on-rectangle',
            'break_start' => 'heroicon-o-pause-circle',
            'break_end' => 'heroicon-o-play-circle',
            'check_out' => 'heroicon-o-arrow-left-on-rectangle',
            default => 'heroicon-o-question-mark-circle',
        };
    }

    /**
     * Obtiene todas las opciones de tipo de evento para selects
     */
    public static function getEventTypeOptions(): array
    {
        return [
            'check_in' => 'Entrada jornada',
            'break_start' => 'Inicio descanso',
            'break_end' => 'Fin descanso',
            'check_out' => 'Salida jornada',
        ];
    }

    /**
     * Obtiene las opciones de origen para selects.
     *
     * @return array<string, string>
     */
    public static function getSourceOptions(): array
    {
        return [
            'terminal' => 'Terminal (kiosco)',
            'mobile' => 'Móvil',
            'manual' => 'Manual (admin)',
        ];
    }

    /**
     * Obtiene el label del origen de marcación.
     */
    public static function getSourceLabel(string $source): string
    {
        return match ($source) {
            'terminal' => 'Terminal',
            'mobile' => 'Móvil',
            'manual' => 'Manual',
            default => 'Desconocido',
        };
    }

    /**
     * Obtiene el color del badge según el origen de marcación.
     */
    public static function getSourceColor(string $source): string
    {
        return match ($source) {
            'terminal' => 'info',
            'mobile' => 'success',
            'manual' => 'warning',
            default => 'gray',
        };
    }

    /**
     * Obtiene el icono según el origen de marcación.
     */
    public static function getSourceIcon(string $source): string
    {
        return match ($source) {
            'terminal' => 'heroicon-o-computer-desktop',
            'mobile' => 'heroicon-o-device-phone-mobile',
            'manual' => 'heroicon-o-pencil-square',
            default => 'heroicon-o-question-mark-circle',
        };
    }

    /**
     * Máquina de estados de marcación diaria: dado el último evento del día,
     * retorna qué tipos de evento son válidos a continuación. Compartida por
     * el flujo de marcación en línea (AttendanceFaceMarkController::store())
     * y el flujo de sincronización de terminales offline (AttendanceEventSyncService)
     * para no duplicar la regla de negocio en dos lugares.
     *
     * @return array<int, string>
     */
    public static function allowedNextEventTypes(?string $last): array
    {
        return match ($last) {
            null => ['check_in'],
            'check_in' => ['break_start', 'check_out'],
            'break_start' => ['break_end'],
            'break_end' => ['break_start', 'check_out'],
            'check_out' => ['check_in'],
            default => ['check_in'],
        };
    }

    /**
     * Verifica si tiene una ubicación válida
     */
    public function hasValidLocation(): bool
    {
        return is_array($this->location)
            && isset($this->location['lat'])
            && isset($this->location['lng']);
    }

    /**
     * Obtiene la ubicación formateada para mostrar
     */
    public function getFormattedLocation(): string
    {
        if (! $this->hasValidLocation()) {
            return 'Sin ubicación';
        }

        return sprintf(
            '%s, %s',
            $this->location['lat'],
            $this->location['lng']
        );
    }

    /**
     * Obtiene la URL de Google Maps
     */
    public function getMapUrl(): ?string
    {
        return $this->google_maps_url;
    }

    /**
     * Obtiene el tooltip con información de ubicación
     */
    public function getLocationTooltip(): string
    {
        if (! $this->hasValidLocation()) {
            return 'No hay datos de ubicación';
        }

        return sprintf(
            "Latitud: %s\nLongitud: %s\nClick en 'Mapa' para ver en Google Maps",
            $this->location['lat'],
            $this->location['lng']
        );
    }
}
