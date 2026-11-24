<?php

use App\Support\BlockerSummary;

it('concuerda el número de cada motivo', function () {
    expect(BlockerSummary::format(['sucursal|sucursales' => 1]))->toBe('1 sucursal')
        ->and(BlockerSummary::format(['sucursal|sucursales' => 2]))->toBe('2 sucursales')
        ->and(BlockerSummary::format([
            'cargo|cargos' => 1,
            'contrato|contratos' => 12,
            'terminal|terminales' => 1,
        ]))->toBe('1 cargo, 12 contratos y 1 terminal');
});

it('usa la etiqueta tal cual cuando no tiene forma singular', function () {
    expect(BlockerSummary::format(['marcaciones' => 1]))->toBe('1 marcaciones')
        ->and(BlockerSummary::format([]))->toBe('');
});
