<?php

/**
 * PHP 8.4 marca como obsoleto declarar `Tipo $x = null` sin el `?`: avisa con «Implicitly marking parameter
 * $x as nullable is deprecated» en cada ejecución (ensuciaba la consola y el log al sembrar la demo en 8.4).
 * Este test recorre las firmas de funciones del código propio con el tokenizador de PHP.
 */

/**
 * Parámetros con valor por defecto `null` cuyo tipo no admite null de forma explícita.
 *
 * @return array<int, string> `archivo:línea — parámetro`
 */
function implicitNullableParameters(string $source, string $file): array
{
    $tokens = token_get_all($source);
    $found = [];
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        if (! is_array($tokens[$i]) || ! in_array($tokens[$i][0], [T_FUNCTION, T_FN], true)) {
            continue;
        }

        // Avanza hasta el paréntesis que abre la lista de parámetros.
        while ($i < $count && $tokens[$i] !== '(') {
            if ($tokens[$i] === ';' || $tokens[$i] === '{') {
                continue 2;
            }
            $i++;
        }

        $depth = 0;
        $parts = [[]];
        $line = 0;

        for (; $i < $count; $i++) {
            $token = $tokens[$i];
            $text = is_array($token) ? $token[1] : $token;
            $line = is_array($token) ? $token[2] : $line;

            if ($token === '(' || $token === '[') {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            }

            if ($token === ')' || $token === ']') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }

            if ($token === ',' && $depth === 1) {
                $parts[] = [];

                continue;
            }

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $parts[count($parts) - 1][] = [$text, $line];
        }

        foreach ($parts as $param) {
            $texts = array_column($param, 0);
            $variable = array_search(true, array_map(fn ($t) => str_starts_with($t, '$'), $texts), true);

            if ($variable === false || ($texts[$variable + 1] ?? null) !== '=' || strtolower($texts[$variable + 2] ?? '') !== 'null') {
                continue;
            }

            $type = array_slice($texts, 0, $variable);
            // Quita modificadores de promoción de propiedades (`public readonly Foo $x = null`) y el `&`/`...`.
            $type = array_values(array_filter($type, fn ($t) => ! in_array(strtolower($t), ['public', 'protected', 'private', 'readonly', '&', '...'], true)));

            if ($type === []) {
                continue;
            }

            $typeText = strtolower(implode('', $type));

            if (str_starts_with($typeText, '?') || str_contains($typeText, 'null') || $typeText === 'mixed') {
                continue;
            }

            $found[] = $file.':'.$param[$variable][1].' — '.implode('', $type).' '.$texts[$variable];
        }
    }

    return $found;
}

it('detecta el patrón obsoleto en una firma de ejemplo', function () {
    $source = '<?php class A { public function f(Foo $a = null, ?Foo $b = null, int|null $c = null, $d = null, mixed $e = null) {} }';

    expect(implicitNullableParameters($source, 'ejemplo.php'))->toBe(['ejemplo.php:1 — Foo $a']);
});

it('no hay parámetros con tipo implícitamente nullable en app, database ni routes', function () {
    $root = dirname(__DIR__, 2);
    $violations = [];

    foreach (['app', 'database', 'routes'] as $dir) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $violations = array_merge(
                $violations,
                implicitNullableParameters(file_get_contents($file->getPathname()), str_replace($root.'/', '', $file->getPathname())),
            );
        }
    }

    expect($violations)->toBe([]);
});
