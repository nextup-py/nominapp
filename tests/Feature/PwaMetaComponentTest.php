<?php

it('el componente pwa-meta renderiza el link de manifest y los meta tags de iOS', function () {
    $html = (string) $this->blade(
        '<x-pwa-meta manifest-url="/marcar/manifest.json" app-title="Nominapp Marcación" />'
    );

    expect($html)
        ->toContain('<link rel="manifest" href="/marcar/manifest.json">')
        ->toContain('name="apple-mobile-web-app-capable" content="yes"')
        ->toContain('name="apple-mobile-web-app-title" content="Nominapp Marcación"');
});

it('theme-vars incluye el meta theme-color con el hex del color primario', function () {
    $html = (string) $this->blade('<x-theme-vars />');

    expect($html)->toContain('<meta name="theme-color" content="#0d9488">');
});
