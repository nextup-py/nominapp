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
