<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>En Mantenimiento</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #0f172a;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            color: #e2e8f0;
            overflow: hidden;
        }

        .bg-pattern {
            position: fixed;
            inset: 0;
            background-image:
                radial-gradient(ellipse at 20% 50%, rgba(20, 184, 166, 0.08) 0%, transparent 50%),
                radial-gradient(ellipse at 80% 20%, rgba(20, 184, 166, 0.05) 0%, transparent 50%),
                radial-gradient(ellipse at 50% 80%, rgba(20, 184, 166, 0.04) 0%, transparent 50%);
        }

        .container {
            position: relative;
            text-align: center;
            padding: 2rem;
            max-width: 520px;
        }

        .icon-wrapper {
            margin-bottom: 2rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: rgba(20, 184, 166, 0.1);
            border: 1px solid rgba(20, 184, 166, 0.2);
        }

        .icon-wrapper svg {
            width: 40px;
            height: 40px;
            color: #14b8a6;
            animation: spin 4s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .code {
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            color: #14b8a6;
            margin-bottom: 1rem;
        }

        h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #f1f5f9;
            margin-bottom: 0.75rem;
            line-height: 1.3;
        }

        p {
            font-size: 1rem;
            color: #94a3b8;
            line-height: 1.6;
            margin-bottom: 2rem;
        }

        .divider {
            width: 48px;
            height: 2px;
            background: linear-gradient(90deg, transparent, #14b8a6, transparent);
            margin: 0 auto 2rem;
            border-radius: 1px;
        }

        .status {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 9999px;
            background: rgba(20, 184, 166, 0.08);
            border: 1px solid rgba(20, 184, 166, 0.15);
            font-size: 0.8rem;
            color: #5eead4;
        }

        .status-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #14b8a6;
            animation: pulse 2s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }

        /* Mismo footer de atribución que el resto de las vistas públicas —
           colores calcados a mano de tokens.css (--c-subtle/--c-muted en oscuro),
           ya que esta página no puede depender de @vite durante un deploy. */
        .branding-footer {
            position: relative;
            margin-top: 2.5rem;
            text-align: center;
        }
        .branding-footer-link {
            font-size: 0.75rem;
            color: #64748b;
            text-decoration: none;
            transition: color 150ms ease-in-out;
        }
        .branding-footer-link:hover { color: #94a3b8; }
    </style>
</head>
<body>
    <div class="bg-pattern"></div>
    <div class="container">
        <div class="icon-wrapper">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
            </svg>
        </div>

        <div class="code">Mantenimiento</div>
        <h1>Estamos realizando mejoras</h1>
        <p>El sistema se encuentra en mantenimiento programado. Volvemos en breve con una mejor experiencia.</p>

        <div class="divider"></div>

        <div class="status">
            <span class="status-dot"></span>
            Trabajando en ello
        </div>

        <footer class="branding-footer">
            <a href="https://nextup.com.py" target="_blank" rel="noopener" class="branding-footer-link">Desarrollado por NextUp</a>
        </footer>
    </div>
</body>
</html>
