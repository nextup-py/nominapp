{{--
    Meta tags de PWA que varían por modo (manifest y título) — no van en
    favicon-links.blade.php porque ese componente es idéntico en las 5 vistas
    públicas, mientras esto solo aplica a /marcar y /terminal (las únicas
    instalables). apple-touch-icon ya lo provee <x-favicon-links />, no se
    duplica acá.
--}}
@props(['manifestUrl', 'appTitle'])
<link rel="manifest" href="{{ $manifestUrl }}">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="{{ $appTitle }}">
