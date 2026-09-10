<?php

it('renderiza título, descripción, variante y contenido extra', function () {
    $html = (string) $this->blade(<<<'BLADE'
        <x-status-page
            variant="danger"
            icon="<path d='M1 1' />"
            label="Prueba"
            title="Título de prueba"
            :description="['Primer párrafo.', 'Segundo párrafo.']"
        >
            <x-slot:extra>Contenido extra</x-slot:extra>
        </x-status-page>
    BLADE);

    expect($html)->toContain('Título de prueba')
        ->toContain('Primer párrafo.')
        ->toContain('Segundo párrafo.')
        ->toContain('Contenido extra')
        ->toContain('status-page-icon--danger')
        ->toContain('status-page-label--danger')
        ->toContain('Prueba');
});

it('omite el label y el extra cuando no se pasan', function () {
    $html = (string) $this->blade(<<<'BLADE'
        <x-status-page
            variant="success"
            icon="<path d='M1 1' />"
            title="Sin extras"
            :description="['Solo un párrafo.']"
        />
    BLADE);

    expect($html)->toContain('Sin extras')
        ->not->toContain('status-page-label');
});
