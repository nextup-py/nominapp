<?php

it('el service worker sirve el shell del terminal con red primero (una recarga debe traer la versión nueva)', function () {
    $sw = file_get_contents(public_path('sw.js'));

    expect($sw)->toContain('networkFirstWithTimeout(request, SHELL_NETWORK_TIMEOUT_MS)')
        ->and($sw)->not->toContain('respondWith(staleWhileRevalidate(request))');
});
