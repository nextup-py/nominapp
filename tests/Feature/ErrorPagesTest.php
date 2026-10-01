<?php

it('renderiza la vista de error 403 sin excepciones', function () {
    $html = view('errors.403')->render();

    expect($html)->toContain('Acceso denegado');
});

it('renderiza la vista de error 503 sin excepciones', function () {
    $html = view('errors.503')->render();

    expect($html)->toContain('mantenimiento');
});
