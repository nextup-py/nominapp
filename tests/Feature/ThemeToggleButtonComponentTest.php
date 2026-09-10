<?php

it('renderiza el botón de toggle de tema con su id y aria-label', function () {
    $html = (string) $this->blade('<x-theme-toggle-button />');

    expect($html)->toContain('id="btnThemeToggle"')
        ->toContain('aria-label="Cambiar tema claro/oscuro"')
        ->toContain('icon-moon')
        ->toContain('icon-sun');
});
