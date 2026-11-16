<?php

/**
 * Los scripts de deploy para servidores cPanel (scripts/cpanel/) se ejecutan a mano en servidores ajenos: deben ir en
 * ASCII (las terminales de algunos hosting y Windows PowerShell 5.1 rompen las tildes), tener sintaxis válida y no
 * llevar datos de ninguna instalación.
 */
dataset('cpanel scripts', [
    'deploy.sh' => ['scripts/cpanel/deploy.sh'],
    'Preparar-Build.ps1' => ['scripts/cpanel/Preparar-Build.ps1'],
    'deploy.conf.example' => ['scripts/cpanel/deploy.conf.example'],
]);

it('va escrito en ASCII', function (string $path) {
    $content = file_get_contents(dirname(__DIR__, 2).'/'.$path);

    expect(preg_match('/[^\x00-\x7F]/', $content))->toBe(0);
})->with('cpanel scripts');

it('no lleva datos de clientes ni de servidores reales', function (string $path) {
    $content = strtolower(file_get_contents(dirname(__DIR__, 2).'/'.$path));

    foreach (['macro', 'sedacosmetica', 'bar777', 'bh7104', 'sedvouco'] as $forbidden) {
        expect($content)->not->toContain($forbidden);
    }
})->with('cpanel scripts');

it('deploy.sh tiene sintaxis de bash válida', function () {
    $bash = trim((string) shell_exec('command -v bash'));

    if ($bash === '') {
        $this->markTestSkipped('bash no está disponible');
    }

    exec(escapeshellarg($bash).' -n '.escapeshellarg(dirname(__DIR__, 2).'/scripts/cpanel/deploy.sh').' 2>&1', $output, $code);

    expect($code)->toBe(0, implode("\n", $output));
});

it('deploy.sh ofrece los tres modos y la copia a temporal', function () {
    $content = file_get_contents(dirname(__DIR__, 2).'/'.'scripts/cpanel/deploy.sh');

    expect($content)->toContain('verificar|actualizar|restaurar')
        ->and($content)->toContain('NOMINAPP_DEPLOY_COPY')
        ->and($content)->toContain('RESTAURAR');
});

it('deploy.sh rota cron.log y avisa de los logs y del modo debug', function () {
    $content = file_get_contents(dirname(__DIR__, 2).'/scripts/cpanel/deploy.sh');

    expect($content)->toContain('verificar|actualizar|restaurar|logs')
        ->and($content)->toContain('rotate_logs()')
        ->and($content)->toContain('check_logs()')
        ->and($content)->toContain('LOG_LEVEL=debug')
        ->and($content)->toContain('APP_DEBUG=true');
});

it('el archivo de configuración de ejemplo define lo obligatorio', function () {
    $content = file_get_contents(dirname(__DIR__, 2).'/'.'scripts/cpanel/deploy.conf.example');

    expect($content)->toContain('APP_DIR=')->toContain('PHP_BIN=')->toContain('APP_URL=');
});

it('deploy.sh no corta la lista de commits con head (pipefail aborta con 141 si hay mas de 15)', function () {
    $content = file_get_contents(dirname(__DIR__, 2).'/scripts/cpanel/deploy.sh');

    expect($content)->toContain('git log --oneline -15')
        ->and($content)->not->toContain('| head -15');
});

it('deploy.sh comprueba que PHP_BIN cumpla lo que exigen vendor/ y el composer.json a desplegar', function () {
    $content = file_get_contents(dirname(__DIR__, 2).'/scripts/cpanel/deploy.sh');

    expect($content)->toContain('check_php_requirements()')
        ->and($content)->toContain('vendor/composer/platform_check.php')
        ->and($content)->toContain('composer.json')
        ->and($content)->toContain('check_php_requirements || problems=');
});

it('composer.lock esta al dia con composer.json (si no, composer install avisa en cada deploy)', function () {
    $root = dirname(__DIR__, 2);
    $lock = json_decode((string) file_get_contents($root.'/composer.lock'), true);
    $composer = json_decode((string) file_get_contents($root.'/composer.json'), true);

    $relevant = [];
    foreach (['name', 'version', 'require', 'require-dev', 'conflict', 'replace', 'provide', 'minimum-stability', 'prefer-stable', 'repositories', 'extra'] as $key) {
        if (isset($composer[$key])) {
            $relevant[$key] = $composer[$key];
        }
    }
    if (isset($composer['config']['platform'])) {
        $relevant['config']['platform'] = $composer['config']['platform'];
    }

    // Replica Composer\Package\Locker::getContentHash(): md5 del JSON de las claves relevantes ordenadas por clave.
    ksort($relevant);
    $hash = md5(json_encode($relevant));

    expect($lock['content-hash'])->toBe($hash);
});
