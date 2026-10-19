<?php

/**
 * Guardas del script de instalación de terminales en Windows (`scripts/windows/Configurar-Terminal.ps1`).
 * No ejecutan PowerShell: fijan lo que ya rompió o puede romper en un equipo real.
 */
$scriptPath = dirname(__DIR__, 2).'/scripts/windows/Configurar-Terminal.ps1';

it('existe el script y está escrito solo en ASCII (Windows PowerShell 5.1 lee mal los acentos sin BOM)', function () use ($scriptPath) {
    expect(file_exists($scriptPath))->toBeTrue();

    $nonAscii = preg_match('/[^\x00-\x7F]/', file_get_contents($scriptPath));

    expect($nonAscii)->toBe(0);
});

it('valida que la dirección sea la de un terminal y pide la URL como obligatoria', function () use ($scriptPath) {
    $script = file_get_contents($scriptPath);

    expect($script)
        ->toContain('[Parameter(Mandatory = $true)]')
        ->toContain("[ValidatePattern('^https://[^/\\s]+/terminal/[A-Za-z0-9]+/?\$')]");
});

it('usa un perfil de navegador exclusivo y arranque automático', function () use ($scriptPath) {
    $script = file_get_contents($scriptPath);

    expect($script)
        ->toContain('--user-data-dir=')
        ->toContain("GetFolderPath('Startup')")
        ->toContain("'--kiosk'");
});

it('deja la energía en nunca suspender ni hibernar, y solo lo intenta como administrador', function () use ($scriptPath) {
    $script = file_get_contents($scriptPath);

    expect($script)
        ->toContain('standby-timeout-$mode')
        ->toContain('hibernate-timeout-$mode')
        ->toContain("'/hibernate', 'off'")
        ->toContain('Test-IsAdmin');
});

it('baja el ErrorActionPreference al llamar a powercfg (en PS 5.1 el stderr redirigido corta el script)', function () use ($scriptPath) {
    $script = file_get_contents($scriptPath);

    expect($script)->toMatch("/function Invoke-PowerCfg.*\\\$ErrorActionPreference = 'Continue'.*finally/s");
});

it('la guía del panel documenta el script y sus opciones', function () use ($scriptPath) {
    $guide = file_get_contents(dirname(__DIR__, 2).'/resources/docs/23-terminales.md');

    expect($guide)
        ->toContain('Configurar-Terminal.ps1')
        ->toContain('-NoKiosk')
        ->toContain('-NoPowerSettings');

    // Todo parámetro del script debe figurar en la guía.
    preg_match('/param\((.*?)\n\)/s', file_get_contents($scriptPath), $match);
    preg_match_all('/\[(?:switch|string)\]\$(\w+)/', $match[1] ?? '', $params);

    foreach ($params[1] as $param) {
        expect($guide)->toContain('-'.$param);
    }
});
