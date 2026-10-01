{{--
    Layout compartido por las páginas de error HTTP (403/404/503). Mismo
    patrón visual que <x-status-page> (tarjeta centrada, ícono circular,
    label, título, descripción, footer de marca, claro/oscuro automático),
    pero con los tokens y el CSS embebidos en <style> en vez de @vite — estas
    vistas las renderiza Laravel directamente ante un error HTTP (incluida
    una instalación en mantenimiento o con el manifest de Vite ausente/roto a
    mitad de deploy), así que no pueden depender del pipeline de assets.

    $icon es el contenido crudo de los <path>/<circle>/etc. del SVG central.
--}}
@props([
    'icon',
    'variant' => 'danger',
    'label' => null,
    'title',
    'description' => [],
])
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <meta name="color-scheme" content="light dark">
    <x-favicon-links />
    <style>
        :root {
            --font: 'Segoe UI', system-ui, -apple-system, sans-serif;

            --c-primary-l:    #14b8a6;
            --c-primary-bg:   #f0fdfa;

            --c-bg:       #f8fafc;
            --c-surface:  #ffffff;
            --c-border:   #e2e8f0;
            --c-text:     #0f172a;
            --c-muted:    #64748b;
            --c-subtle:   #94a3b8;

            --c-danger-l:   #ef4444;
            --c-danger-bg:  #fee2e2;

            --c-warning-l:  #eab308;
            --c-warning-bg: #fef9c3;

            --c-info-l:   #3b82f6;
            --c-info-bg:  #dbeafe;

            --sp-2: 8px; --sp-3: 12px; --sp-4: 16px; --sp-6: 24px; --sp-8: 32px;
            --r-xl: 16px;
            --sh-sm: 0 1px 2px rgba(0,0,0,.05);
            --tr-f: 150ms ease-in-out;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --c-bg:         #0f172a;
                --c-surface:    #1e293b;
                --c-border:     #334155;
                --c-text:       #f1f5f9;
                --c-muted:      #94a3b8;
                --c-subtle:     #64748b;
                --c-primary-bg: rgba(20, 184, 166, .12);
                --c-danger-bg:  rgba(220, 38, 38, .15);
                --c-warning-bg: rgba(202, 138, 4, .15);
                --c-info-bg:    rgba(37, 99, 235, .15);
                --sh-sm: 0 1px 2px rgba(0,0,0,.3);
            }
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: var(--font);
            background: var(--c-bg);
            color: var(--c-text);
            min-height: 100dvh;
            text-align: center;
        }

        .page-wrapper {
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: var(--sp-6) var(--sp-4);
        }

        .status-page-card {
            max-width: 480px;
            width: 100%;
            background: var(--c-surface);
            border: 1px solid var(--c-border);
            border-radius: var(--r-xl);
            box-shadow: var(--sh-sm);
            overflow: hidden;
        }

        .card-body { padding: var(--sp-8) var(--sp-6) var(--sp-6); }

        .status-page-icon {
            width: 80px; height: 80px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto var(--sp-6);
            border: 2px solid;
        }
        .status-page-icon svg { width: 40px; height: 40px; }

        .status-page-icon--primary { background: var(--c-primary-bg); border-color: var(--c-primary-l); }
        .status-page-icon--primary svg { color: var(--c-primary-l); }
        .status-page-icon--danger  { background: var(--c-danger-bg);  border-color: var(--c-danger-l);  }
        .status-page-icon--danger svg  { color: var(--c-danger-l); }
        .status-page-icon--warning { background: var(--c-warning-bg); border-color: var(--c-warning-l); }
        .status-page-icon--warning svg { color: var(--c-warning-l); }
        .status-page-icon--info    { background: var(--c-info-bg);    border-color: var(--c-info-l);    }
        .status-page-icon--info svg    { color: var(--c-info-l); }

        .status-page-label {
            font-size: .75rem; font-weight: 600; letter-spacing: .15em;
            text-transform: uppercase; margin-bottom: var(--sp-3);
        }
        .status-page-label--primary { color: var(--c-primary-l); }
        .status-page-label--danger  { color: var(--c-danger-l); }
        .status-page-label--warning { color: var(--c-warning-l); }
        .status-page-label--info    { color: var(--c-info-l); }

        .status-page-title { font-size: 1.5rem; font-weight: 700; margin-bottom: var(--sp-3); color: var(--c-text); }
        .status-page-description { font-size: 1rem; color: var(--c-muted); line-height: 1.6; margin-bottom: var(--sp-2); }

        .status-page-divider {
            width: 48px; height: 2px;
            margin: var(--sp-6) auto var(--sp-2);
            border-radius: 1px;
        }
        .status-page-divider--primary { background: linear-gradient(90deg, transparent, var(--c-primary-l), transparent); }
        .status-page-divider--danger  { background: linear-gradient(90deg, transparent, var(--c-danger-l),  transparent); }
        .status-page-divider--warning { background: linear-gradient(90deg, transparent, var(--c-warning-l), transparent); }
        .status-page-divider--info    { background: linear-gradient(90deg, transparent, var(--c-info-l),    transparent); }

        .status-page-extra { margin-top: var(--sp-4); }

        .btn-home {
            display: inline-flex;
            align-items: center;
            gap: var(--sp-2);
            padding: 10px 20px;
            border-radius: 9999px;
            background: var(--c-primary-bg);
            border: 1px solid rgba(20, 184, 166, .35);
            font-size: .875rem;
            font-weight: 600;
            color: var(--c-primary-l);
            text-decoration: none;
            transition: background var(--tr-f), border-color var(--tr-f);
        }
        .btn-home:hover { background: rgba(20, 184, 166, .18); border-color: rgba(20, 184, 166, .55); }
        .btn-home svg { width: 16px; height: 16px; }

        .branding-footer {
            display: flex;
            justify-content: center;
            padding: var(--sp-4) var(--sp-4) calc(var(--sp-4) + env(safe-area-inset-bottom, 0px));
        }
        .branding-footer-link {
            font-size: .75rem;
            color: var(--c-subtle);
            text-decoration: none;
            transition: color var(--tr-f);
        }
        .branding-footer-link:hover { color: var(--c-muted); }
    </style>
</head>

<body>
    <main class="page-wrapper">
        <div class="status-page-card">
            <div class="card-body">
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

                <div class="status-page-divider status-page-divider--{{ $variant }}" aria-hidden="true"></div>

                @isset($extra)
                    <div class="status-page-extra">{{ $extra }}</div>
                @endisset
            </div>
        </div>
    </main>

    <x-branding-footer />
</body>

</html>
