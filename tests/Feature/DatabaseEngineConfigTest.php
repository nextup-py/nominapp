<?php

/**
 * El motor de las tablas nuevas es configurable: en hostings compartidos el servidor crea MyISAM por
 * defecto, y ahí las migraciones fallan por el límite de 1000 bytes de los índices y las claves foráneas
 * se ignoran en silencio. `DB_ENGINE=InnoDB` lo fuerza sin tocar el código.
 */
it('deja el motor sin definir cuando DB_ENGINE no está configurado', function () {
    $config = require config_path('database.php');

    expect($config['connections']['mysql']['engine'])->toBeNull()
        ->and($config['connections']['mariadb']['engine'])->toBeNull();
});

it('toma el motor de DB_ENGINE', function () {
    $_ENV['DB_ENGINE'] = 'InnoDB';

    try {
        $config = require config_path('database.php');

        expect($config['connections']['mysql']['engine'])->toBe('InnoDB')
            ->and($config['connections']['mariadb']['engine'])->toBe('InnoDB');
    } finally {
        unset($_ENV['DB_ENGINE']);
    }
});

it('trata DB_ENGINE vacío como sin definir, para no generar «engine = » en el SQL', function () {
    $_ENV['DB_ENGINE'] = '';

    try {
        $config = require config_path('database.php');

        expect($config['connections']['mysql']['engine'])->toBeNull();
    } finally {
        unset($_ENV['DB_ENGINE']);
    }
});
