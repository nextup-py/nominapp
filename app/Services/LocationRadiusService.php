<?php

namespace App\Services;

use App\Models\Employee;
use App\Settings\GeneralSettings;

/**
 * Control de ubicación de la marcación desde el celular. El punto y el radio salen del empleado (lugar fijo propio)
 * o, si no tiene, de su sucursal cuando esta activó el control; sin ninguno el empleado es «de campo» y solo se
 * registra la ubicación. El control se hace en el servidor al sincronizar, así funciona también sin conexión.
 */
class LocationRadiusService
{
    /** Radio mínimo y máximo (metros) que se acepta configurar. */
    public const MIN_RADIUS = 25;

    public const MAX_RADIUS = 2000;

    public const FLAG_OUTSIDE = 'outside_radius';

    public const FLAG_NO_LOCATION = 'no_location';

    public const REVIEW_PENDING = 'pending';

    public const REVIEW_APPROVED = 'approved';

    public const REVIEW_DISCARDED = 'discarded';

    /**
     * Punto y radio vigentes del empleado, o null si es «de campo».
     *
     * @return array{lat: float, lng: float, radius: int, source: 'employee'|'branch'}|null
     */
    public static function resolve(Employee $employee): ?array
    {
        $default = (int) app(GeneralSettings::class)->attendance_location_default_radius;

        $own = $employee->work_location;
        if (is_array($own) && isset($own['lat'], $own['lng'])) {
            return ['lat' => (float) $own['lat'], 'lng' => (float) $own['lng'], 'radius' => $employee->location_radius_m ?: $default, 'source' => 'employee'];
        }

        $branch = $employee->branch;
        $coordinates = $branch?->coordinates;
        if ($branch?->location_control && is_array($coordinates) && isset($coordinates['lat'], $coordinates['lng'])) {
            return ['lat' => (float) $coordinates['lat'], 'lng' => (float) $coordinates['lng'], 'radius' => $branch->location_radius_m ?: $default, 'source' => 'branch'];
        }

        return null;
    }

    /**
     * Resultado del control para una marcación: columnas a guardar en el evento. Vacío si el empleado no tiene control.
     *
     * @param  array{lat?: mixed, lng?: mixed}|null  $location
     * @return array<string, mixed>
     */
    public static function evaluate(Employee $employee, ?array $location): array
    {
        $rule = self::resolve($employee);

        if ($rule === null) {
            return [];
        }

        if (! is_array($location) || ! isset($location['lat'], $location['lng'])) {
            return ['location_flag' => self::FLAG_NO_LOCATION, 'location_review' => self::REVIEW_PENDING];
        }

        $distance = (int) round(self::distanceMeters($rule['lat'], $rule['lng'], (float) $location['lat'], (float) $location['lng']));

        if ($distance <= $rule['radius']) {
            return ['location_distance_m' => $distance];
        }

        return ['location_distance_m' => $distance, 'location_flag' => self::FLAG_OUTSIDE, 'location_review' => self::REVIEW_PENDING];
    }

    /** Distancia en metros entre dos puntos (fórmula de Haversine). */
    public static function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** @return array<string, string> */
    public static function getFlagLabels(): array
    {
        return [self::FLAG_OUTSIDE => 'Fuera de radio', self::FLAG_NO_LOCATION => 'Sin ubicación'];
    }

    /** @return array<string, string> */
    public static function getReviewLabels(): array
    {
        return [self::REVIEW_PENDING => 'Por revisar', self::REVIEW_APPROVED => 'Aprobada', self::REVIEW_DISCARDED => 'Descartada'];
    }

    /** @return array<string, string> */
    public static function getReviewColors(): array
    {
        return [self::REVIEW_PENDING => 'warning', self::REVIEW_APPROVED => 'success', self::REVIEW_DISCARDED => 'danger'];
    }
}
