<?php

it('la vista de marcación incluye el botón de toggle de tema compartido', function () {
    $response = $this->get(route('mark.show'));

    $response->assertSuccessful();
    $response->assertSee('id="btnThemeToggle"', false);
});
