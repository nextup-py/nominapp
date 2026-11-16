<?php

namespace App\Services;

/**
 * Adivina marca/modelo de un dispositivo a partir de datos que el propio
 * navegador entrega al vincular/provisionar (User-Agent + Client Hints de
 * alta entropía, cuando el navegador los soporta). Best-effort, no
 * autoritativo — el admin puede corregir el resultado a mano en cualquier
 * momento; los llamadores deciden si sobreescriben un valor ya cargado.
 *
 * Límite duro e inevitable: MAC address y número de serie NUNCA son
 * accesibles desde un navegador, en ningún dispositivo — no hay API web que
 * los exponga. Esta clase ni lo intenta.
 *
 * Precisión por plataforma:
 * - Android + navegador Chromium (Chrome, Edge...): alta — usa Client Hints
 *   (`navigator.userAgentData.getHighEntropyValues(['model'])` del lado del
 *   cliente), que da el modelo real (ej. "Pixel 7", "SM-A536B").
 * - iOS/Safari, Firefox: baja — esa API no existe ahí. El User-Agent de iOS
 *   deliberadamente nunca incluye el modelo (siempre "iPhone" genérico).
 * - Desktop: sin intento de adivinar marca — Windows/Linux son sistemas
 *   operativos, no marcas de hardware, y Client Hints tampoco identifica al
 *   fabricante del equipo.
 */
class DeviceHintsParser
{
    /**
     * Prefijos de modelo conocidos → marca. Heurística chica y ampliable
     * cubriendo los fabricantes Android más comunes — no pretende ser
     * exhaustiva, solo evitar que el admin tenga que tipear la marca cuando
     * ya es obvia a partir del modelo detectado.
     *
     * @var array<string, string>
     */
    private const BRAND_PREFIXES = [
        'SM-' => 'Samsung',
        'Pixel' => 'Google',
        'Redmi' => 'Xiaomi',
        'POCO' => 'Xiaomi',
        'Mi ' => 'Xiaomi',
        'moto' => 'Motorola',
        'Moto' => 'Motorola',
        'ONEPLUS' => 'OnePlus',
        'LG-' => 'LG',
        'HUAWEI' => 'Huawei',
        'Nokia' => 'Nokia',
    ];

    /**
     * @param  string|null  $userAgent  Header User-Agent crudo de la request.
     * @param  string|null  $clientHintModel  Modelo reportado por
     *                                        `navigator.userAgentData.getHighEntropyValues(['model'])` en el
     *                                        cliente, cuando el navegador lo soporta (Chromium + Android). Tiene
     *                                        prioridad sobre el parseo del User-Agent por ser más confiable.
     * @return array{brand: string|null, model: string|null}
     */
    public static function guess(?string $userAgent, ?string $clientHintModel = null): array
    {
        $clientHintModel = trim((string) $clientHintModel);

        if ($clientHintModel !== '') {
            return [
                'brand' => self::guessBrandFromModel($clientHintModel),
                'model' => $clientHintModel,
            ];
        }

        if (! $userAgent) {
            return ['brand' => null, 'model' => null];
        }

        if (str_contains($userAgent, 'iPad')) {
            return ['brand' => 'Apple', 'model' => 'iPad'];
        }

        if (str_contains($userAgent, 'iPhone')) {
            return ['brand' => 'Apple', 'model' => 'iPhone'];
        }

        if (str_contains($userAgent, 'Macintosh')) {
            return ['brand' => 'Apple', 'model' => null];
        }

        // Android clásico: "...Linux; Android 13; Pixel 7) AppleWebKit..." — el
        // modelo va entre la versión de Android y el paréntesis de cierre, a
        // veces con un sufijo " Build/XXXXX" que se recorta.
        if (preg_match('/Android\s[\d.]+;\s*([^)]+)\)/', $userAgent, $matches)) {
            $model = trim(preg_replace('/\s+Build\/.*/', '', $matches[1]));

            if ($model !== '' && ! str_starts_with($model, 'wv')) {
                return ['brand' => self::guessBrandFromModel($model), 'model' => $model];
            }
        }

        return ['brand' => null, 'model' => null];
    }

    /**
     * Sistema operativo y navegador (con versión mayor) a partir del User-Agent. Best-effort: los navegadores
     * reducen cada vez más el User-Agent (Windows siempre dice «10», Android una versión fija y el modelo «K»), así
     * que sirve para distinguir una PC de un celular o Chrome de Edge, no para fijar versiones exactas.
     *
     * @return array{os: string|null, browser: string|null}
     */
    public static function describe(?string $userAgent): array
    {
        if (! $userAgent) {
            return ['os' => null, 'browser' => null];
        }

        $os = match (true) {
            str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad') => preg_match('/OS (\d+)[_\d]*/', $userAgent, $m) ? 'iOS '.$m[1] : 'iOS',
            str_contains($userAgent, 'Android') => preg_match('/Android\s([\d.]+)/', $userAgent, $m) ? 'Android '.(int) $m[1] : 'Android',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'CrOS') => 'ChromeOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        $browser = null;
        foreach ([
            '/Edg(?:e|A|iOS)?\/(\d+)/' => 'Edge',
            '/OPR\/(\d+)/' => 'Opera',
            '/SamsungBrowser\/(\d+)/' => 'Samsung Internet',
            '/(?:Firefox|FxiOS)\/(\d+)/' => 'Firefox',
            '/(?:Chrome|CriOS)\/(\d+)/' => 'Chrome',
            '/Version\/(\d+)[\d.]*.*Safari/' => 'Safari',
        ] as $pattern => $name) {
            if (preg_match($pattern, $userAgent, $m)) {
                $browser = $name.' '.$m[1];
                break;
            }
        }

        return ['os' => $os, 'browser' => $browser];
    }

    private static function guessBrandFromModel(string $model): ?string
    {
        foreach (self::BRAND_PREFIXES as $prefix => $brand) {
            if (str_starts_with($model, $prefix)) {
                return $brand;
            }
        }

        return null;
    }
}
