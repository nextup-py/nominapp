{{-- resources/views/components/status-page.blade.php --}}
@props([
    'icon',
    'variant' => 'danger',
    'label' => null,
    'title',
    'description' => [],
])
{{--
    Layout compartido por las 4 pantallas de estado/error de los flujos
    de marcación y enrolamiento (terminal desactivado, enlace inválido,
    enlace expirado, registro ya enviado). $icon es el contenido crudo
    de los <path>/<circle>/etc. del SVG central — cada vista pasa el suyo.
--}}
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <meta name="color-scheme" content="light dark">
    <x-favicon-links />
    @vite('resources/css/shared/status-page.css')
    <x-theme-vars />
</head>

<body>
    <div class="status-page-container">
        <div class="status-page-icon status-page-icon--{{ $variant }}" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                {!! $icon !!}
            </svg>
        </div>

        @if ($label)
            <div class="status-page-label status-page-label--{{ $variant }}">{{ $label }}</div>
        @endif

        <h1 class="status-page-title">{{ $title }}</h1>

        @foreach ($description as $paragraph)
            <p class="status-page-description">{{ $paragraph }}</p>
        @endforeach

        {{ $extra ?? '' }}
    </div>
</body>

</html>
