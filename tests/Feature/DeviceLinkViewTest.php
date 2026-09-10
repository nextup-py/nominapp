<?php

it('la vista de vinculación incluye el toggle de tema y los elementos de branding post-éxito', function () {
    $response = $this->get(route('device-link.show'));

    $response->assertSuccessful();
    $response->assertSee('id="btnThemeToggle"', false);
    $response->assertSee('id="linkSuccessBranding"', false);
    $response->assertSee('id="linkSuccessLogo"', false);
    $response->assertSee('id="linkSuccessCompanyName"', false);
    $response->assertSee('id="linkForm"', false);
});
