<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso Denegado</title>
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

        .btn-home {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1.25rem;
            border-radius: 9999px;
            background: rgba(20, 184, 166, 0.1);
            border: 1px solid rgba(20, 184, 166, 0.25);
            font-size: 0.875rem;
            font-weight: 600;
            color: #5eead4;
            text-decoration: none;
            transition: background 150ms ease-in-out, border-color 150ms ease-in-out;
        }

        .btn-home:hover {
            background: rgba(20, 184, 166, 0.18);
            border-color: rgba(20, 184, 166, 0.4);
        }

        .btn-home svg {
            width: 16px;
            height: 16px;
        }

        /* Mismo footer de atribución que el resto de las vistas públicas —
           colores calcados a mano de tokens.css (--c-subtle/--c-muted en oscuro),
           ya que esta página no puede depender de @@vite durante un deploy. */
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
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
            </svg>
        </div>

        <div class="code">Error 403</div>
        <h1>Acceso denegado</h1>
        <p>No tenés permiso para acceder a esta sección. Si creés que esto es un error, consultá con un administrador del sistema.</p>

        <div class="divider"></div>

        <a href="{{ url('/') }}" class="btn-home">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
            </svg>
            Volver al inicio
        </a>

        <footer class="branding-footer">
            <a href="https://nextup.com.py" target="_blank" rel="noopener" class="branding-footer-link">Desarrollado por NextUp</a>
        </footer>
    </div>
</body>
</html>
