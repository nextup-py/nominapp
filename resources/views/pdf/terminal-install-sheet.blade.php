<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Hoja de instalación — {{ $terminal->name }}</title>
    <style>
        @page { size: A4; margin: 0; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; font-size: 11px; line-height: 1.5; padding: 15mm 20mm; }

        .company-header { text-align: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #000; }
        .company-logo { max-height: 40px; max-width: 120px; margin-bottom: 8px; }
        .company-name { font-size: 14px; font-weight: bold; text-transform: uppercase; margin-bottom: 3px; }

        .title { text-align: center; font-size: 13px; font-weight: bold; text-transform: uppercase; margin: 20px 0 5px 0; }
        .subtitle { text-align: center; font-size: 10px; margin-bottom: 20px; }

        .section-title { font-weight: bold; font-size: 10px; text-transform: uppercase; padding: 5px 0; margin: 18px 0 8px 0; border-bottom: 1px solid #000; }

        .qr-box { text-align: center; margin: 10px 0; }
        .qr-box img { width: 180px; height: 180px; }
        .url { text-align: center; font-family: monospace; font-size: 10px; word-break: break-all; }

        ol { margin-left: 18px; }
        li { margin-bottom: 5px; }
        .note { border: 1px solid #000; padding: 8px 10px; margin-top: 12px; background: #f5f5f5; font-size: 10px; }

        .footer { margin-top: 40px; text-align: center; font-size: 8px; border-top: 1px solid #ccc; padding-top: 10px; }
    </style>
</head>

<body>
    {{-- Encabezado de empresa --}}
    <div class="company-header">
        @if ($companyLogo)
            <img src="{{ $companyLogo }}" class="company-logo" alt="">
        @endif
        <div class="company-name">{{ $companyName }}</div>
    </div>

    <div class="title">Hoja de instalación del terminal de marcación</div>
    <div class="subtitle">{{ $terminal->name }}{{ $terminal->branch ? ' — '.$terminal->branch->name : '' }}</div>

    {{-- QR y URL del terminal --}}
    <div class="section-title">1. Abrir el terminal en el dispositivo</div>
    <p>Con el dispositivo conectado a internet, escanee este código QR o escriba la dirección en el navegador (Chrome en Android, Safari en iPhone/iPad).</p>
    <div class="qr-box"><img src="{{ $qrDataUri }}" alt="QR del terminal"></div>
    <div class="url">{{ $terminal->url }}</div>

    {{-- Vinculación --}}
    <div class="section-title">2. Vincular el dispositivo</div>
    <p>Al abrir la dirección por primera vez aparece la pantalla <strong>"Terminal sin vincular"</strong> con un <strong>código de 6 caracteres</strong>. Para vincularlo hay dos caminos:</p>
    <ol>
        <li><strong>Con aprobación del administrador:</strong> comunicarle el código (por teléfono o mensaje). El administrador lo aprueba en el panel (Asistencias → Solicitudes de vinculación) y, en pocos segundos, el terminal arranca solo. Si el código vence (10 minutos), tocar <strong>"Pedir código nuevo"</strong>.</li>
        <li><strong>Con ventana de vinculación:</strong> el administrador abre una ventana de {{ $windowMinutes }} minutos desde el panel y avisa. Mientras esté abierta, el dispositivo se vincula solo al abrir la dirección, sin código.</li>
    </ol>
    <div class="note">
        No comparta el código ni la dirección del terminal con personas ajenas a la instalación. Si el dispositivo se cambia, se pierde o se roba, avise al administrador para desvincularlo.
    </div>

    {{-- Después de vincular --}}
    <div class="section-title">3. Dejar el terminal listo</div>
    <ol>
        <li>Permitir el uso de la cámara cuando el navegador lo solicite.</li>
        <li>Instalar la app en la pantalla de inicio (menú del navegador → "Agregar a pantalla de inicio") para que funcione como terminal fijo y sin conexión.</li>
        <li>Verificar con una marcación de prueba y que el administrador vea el terminal "En línea" en el panel.</li>
    </ol>

    <div class="footer">Hoja generada el {{ now()->format('d/m/Y H:i') }} — {{ $terminal->code }}</div>
</body>

</html>
