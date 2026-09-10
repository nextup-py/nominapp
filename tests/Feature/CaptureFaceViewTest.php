<?php

it('la vista de captura facial usa face-api.js local, no CDN externo, e incluye el toggle de tema', function () {
    $html = view('shared.capture-face', [
        'mode' => 'enrollment',
        'css' => 'resources/css/shared/capture-face.css',
        'js' => 'resources/js/shared/capture-face.js',
        'formAction' => '/registro-facial/token-de-prueba',
        'enrollment' => (object) ['expires_at' => now()->addDay(), 'token' => 'token-de-prueba'],
        'employee' => (object) ['id' => 1, 'name' => null, 'first_name' => 'Juan', 'last_name' => 'Pérez', 'document_number' => null],
    ])->render();

    expect($html)->toContain('js/face-api.min.js')
        ->not->toContain('unpkg.com')
        ->not->toContain('onclick="handleCancel()"')
        ->not->toContain('style="display: none;"')
        ->toContain('id="btnThemeToggle"');
});
