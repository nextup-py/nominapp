<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contrato Individual de Trabajo - {{ $contract->employee?->full_name }}</title>
    <style>
        @page {
            size: A4;
            margin: 0;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            font-size: 11px;
            line-height: 1.5;
            padding: 15mm 20mm;
        }

        /* ── Encabezado empresa ── */
        .company-header {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #0d9488;
        }

        .company-logo {
            max-height: 40px;
            max-width: 120px;
            margin-bottom: 8px;
        }

        .company-name {
            font-size: 14px;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 3px;
        }

        .company-info {
            font-size: 9px;
        }

        /* ── Titulo del documento ── */
        .doc-title {
            text-align: center;
            font-size: 13px;
            font-weight: bold;
            text-transform: uppercase;
            margin: 20px 0 5px 0;
        }

        .doc-subtitle {
            text-align: center;
            font-size: 10px;
            margin-bottom: 3px;
        }

        .doc-art {
            text-align: center;
            font-size: 10px;
            margin-bottom: 18px;
        }

        /* ── Parrafo introductorio ── */
        .intro {
            text-align: justify;
            margin-bottom: 15px;
            font-size: 11px;
            line-height: 1.6;
        }

        /* ── Encabezado MODALIDADES ── */
        .section-header {
            font-weight: bold;
            font-size: 10px;
            text-transform: uppercase;
            padding: 5px 0;
            margin-bottom: 8px;
            border-bottom: 1px solid #000;
        }

        /* ── Clausulas ── */
        .clause {
            margin-bottom: 10px;
            font-size: 11px;
            text-align: justify;
            page-break-inside: avoid;
        }

        .clause-num {
            font-weight: bold;
        }

        .clause-label {
            font-weight: bold;
            text-transform: uppercase;
        }

        .sub-item {
            padding-left: 20px;
            margin-top: 3px;
        }

        .sub-sub-item {
            padding-left: 20px;
            margin-top: 2px;
        }

        /* ── Firmas ── */
        .signature-section {
            margin-top: 50px;
            display: table;
            width: 100%;
        }

        .signature-item {
            display: table-cell;
            width: 50%;
            text-align: center;
            padding: 0 25px;
        }

        .signature-line {
            border-top: 1px solid #000;
            margin-bottom: 5px;
            padding-top: 5px;
        }

        .signature-label {
            font-size: 10px;
            font-weight: bold;
        }

        .signature-sublabel {
            font-size: 9px;
        }

        .signature-notes {
            text-align: center;
            font-size: 9px;
            margin-top: 10px;
            color: #333;
        }

        /* ── Cuerpo editable ── */
        .contract-body {
            margin: 20px 0;
            font-size: 11px;
            line-height: 1.6;
        }

        .contract-body p {
            margin-bottom: 8px;
            text-align: justify;
        }

        .contract-body ol,
        .contract-body ul {
            margin: 8px 0 8px 20px;
        }

        .contract-body li {
            margin-bottom: 4px;
        }

        .contract-body strong {
            font-weight: bold;
        }

        .contract-body em {
            font-style: italic;
        }

        /* ── Footer ── */
        .footer {
            margin-top: 40px;
            text-align: center;
            font-size: 8px;
            border-top: 1px solid #ccc;
            padding-top: 10px;
        }
    </style>
</head>

<body>

    {{-- Encabezado de la Empresa --}}
    @if ($showHeader ?? true)
        <div class="company-header">
            @if ($companyLogo)
                <img src="{{ $companyLogo }}" alt="Logo" class="company-logo">
            @endif
            <div class="company-name">{{ $companyName }}</div>
            <div class="company-info">
                @if ($companyRuc)
                    RUC: {{ $companyRuc }}
                @endif
                @if ($employerNumber)
                    | Nro. Patronal: {{ $employerNumber }}
                @endif
            </div>
            @if ($companyAddress)
                <div class="company-info">{{ $companyAddress }}{{ $city ? ', ' . $city : '' }}</div>
            @endif
            @if ($companyPhone || $companyEmail)
                <div class="company-info">
                    @if ($companyPhone)
                        Tel: {{ $companyPhone }}
                    @endif
                    @if ($companyPhone && $companyEmail)
                        |
                    @endif
                    @if ($companyEmail)
                        {{ $companyEmail }}
                    @endif
                </div>
            @endif
        </div>
    @endif

    {{-- Titulo --}}
    <div class="doc-title">{{ $documentTitle ?? 'CONTRATO INDIVIDUAL DE TRABAJO' }}</div>

    <div class="doc-subtitle">
        @if ($documentSubtitle ?? false)
            {{ $documentSubtitle }}
        @elseif ($contract->type === 'indefinido')
            Por Tiempo Indefinido
        @elseif ($contract->type === 'plazo_fijo')
            Por tiempo determinado o Fijo
        @elseif ($contract->type === 'obra_determinada')
            Por Obra Determinada
        @elseif ($contract->type === 'aprendizaje')
            De Aprendizaje
        @elseif ($contract->type === 'pasantia')
            De Pasantia
        @elseif ($contract->type)
            {{ \App\Models\Contract::getTypeLabel($contract->type) }}
        @endif
    </div>

    @php
        // null = mostrar default; string vacío = ocultar; cualquier otro string = mostrar ese texto
        $artRef = $documentArtReference ?? null;
        $showArtRef = $artRef === null || $artRef !== '';
        $artRefText = ($artRef !== null && $artRef !== '') ? $artRef : '(En cumplimiento del Art. 48 del C. De T.)';
    @endphp
    @if ($showArtRef)
        <div class="doc-art">{{ $artRefText }}</div>
    @endif

    {{-- Párrafo introductorio: solo se renderiza si fue definido en la plantilla --}}
    @if (!empty($introText))
        <div class="intro">{!! $introText !!}</div>
    @endif

    {{-- Cuerpo del contrato (desde plantilla con variables resueltas) --}}
    @if ($contractBody)
        <div class="contract-body">
            {!! $contractBody !!}
        </div>
    @endif

    {{-- Cláusulas adicionales del contrato (específicas de este contrato) --}}
    @if ($additionalClauses)
        <div class="contract-body" style="margin-top: 15px;">
            <div class="section-header">Cláusulas Adicionales</div>
            {!! $additionalClauses !!}
        </div>
    @endif

    {{-- Texto de cierre (desde plantilla, opcional) --}}
    @if (!empty($closingText))
        <div class="contract-body" style="margin-top: 10px;">
            {!! $closingText !!}
        </div>
    @endif

    {{-- Firmas --}}
    <div class="signature-section">
        <div class="signature-item">
            <div class="signature-line"></div>
            <div class="signature-label">{{ $signatureEmployeeLabel ?? 'Trabajador' }}</div>
            <div class="signature-sublabel">{{ $contract->employee?->full_name }}</div>
            <div class="signature-sublabel">C.I. N.: {{ $contract->employee?->ci }}</div>
        </div>
        <div class="signature-item">
            <div class="signature-line"></div>
            <div class="signature-label">{{ $signatureEmployerLabel ?? 'Empleador o responsable legal' }}</div>
            <div class="signature-sublabel">{{ $companyName }}</div>
            <div class="signature-sublabel">{{ $signatureEmployerSublabel ?? 'Firma y Sello' }}</div>
        </div>
    </div>

    {{-- Notas en firmas (desde plantilla, opcional) --}}
    @if (!empty($signatureNotes))
        <div class="signature-notes">{{ $signatureNotes }}</div>
    @endif

    {{-- Footer --}}
    @if ($showFooter ?? true)
        <div class="footer">
            Documento generado el {{ now()->format('d/m/Y H:i') }}
            @if ($city)
                | {{ $city }}, Paraguay
            @endif
            | Contrato #{{ $contract->id }}
        </div>
    @endif

</body>

</html>
