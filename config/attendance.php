<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Umbral de Ausencia (en minutos)
    |--------------------------------------------------------------------------
    |
    | Tiempo en minutos después de la hora de entrada esperada que debe pasar
    | antes de marcar automáticamente al empleado como ausente.
    |
    | Por defecto: 30 minutos
    |
    */
    'absence_threshold_minutes' => env('ABSENCE_THRESHOLD_MINUTES', 30),
];
