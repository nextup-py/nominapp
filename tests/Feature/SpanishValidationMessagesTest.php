<?php

use Illuminate\Support\Facades\Validator;

/**
 * El repo no traía traducciones de validación: con la app en español, los formularios mostraban
 * «The fecha de nacimiento field is required». `lang/es/validation.php` las cubre.
 */
it('muestra en español el mensaje de campo obligatorio', function () {
    app()->setLocale('es');

    $errors = Validator::make(['fecha' => ''], ['fecha' => 'required'])->errors();

    expect($errors->first('fecha'))->toBe('El campo fecha es obligatorio.');
});

it('muestra en español los mensajes con variantes por tipo (tamaño)', function () {
    app()->setLocale('es');

    $errors = Validator::make(
        ['nombre' => 'ab', 'cantidad' => 1],
        ['nombre' => 'min:5', 'cantidad' => 'numeric|min:10'],
    )->errors();

    expect($errors->first('nombre'))->toBe('El campo nombre debe tener al menos 5 caracteres.')
        ->and($errors->first('cantidad'))->toBe('El campo cantidad debe ser al menos 10.');
});

it('cubre todas las reglas del framework: si Laravel agrega una nueva, este test avisa', function () {
    $framework = base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');

    if (! is_file($framework)) {
        $this->markTestSkipped('El framework no trae sus mensajes en esta versión.');
    }

    $missing = array_diff(array_keys(require $framework), array_keys(require lang_path('es/validation.php')));

    expect($missing)->toBe([]);
});

it('mantiene la misma estructura de variantes que el framework', function () {
    $framework = base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');

    if (! is_file($framework)) {
        $this->markTestSkipped('El framework no trae sus mensajes en esta versión.');
    }

    $en = require $framework;
    $es = require lang_path('es/validation.php');

    foreach (['between', 'gt', 'gte', 'lt', 'lte', 'max', 'min', 'size', 'password'] as $rule) {
        expect(array_keys($es[$rule]))->toEqualCanonicalizing(array_keys($en[$rule]), "Variantes de «{$rule}»");
    }
});
