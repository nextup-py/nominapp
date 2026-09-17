<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contrato de Prestamo #{{ $loan->id }}</title>
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

        .company-header {
            text-align: center;
            margin-bottom: 10px;
            padding-bottom: 8px;
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

        .title {
            text-align: center;
            font-size: 13px;
            font-weight: bold;
            text-transform: uppercase;
            margin: 10px 0 3px 0;
        }

        .subtitle {
            text-align: center;
            font-size: 10px;
            margin-bottom: 10px;
        }

        .section {
            margin-bottom: 15px;
        }

        .section-title {
            font-weight: bold;
            font-size: 10px;
            text-transform: uppercase;
            padding: 5px 0;
            margin-bottom: 8px;
            border-bottom: 1px solid #000;
        }

        .info-grid {
            display: table;
            width: 100%;
            border-collapse: collapse;
        }

        .info-row {
            display: table-row;
        }

        .info-label {
            display: table-cell;
            font-weight: bold;
            width: 180px;
            padding: 5px 8px;
            border: 1px solid #000;
        }

        .info-value {
            display: table-cell;
            padding: 5px 8px;
            border: 1px solid #000;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }

        th,
        td {
            border: 1px solid #000;
            padding: 6px;
            text-align: center;
            font-size: 9px;
        }

        th {
            font-weight: bold;
            background-color: #f5f5f5;
        }

        .text-left {
            text-align: left;
        }

        .text-right {
            text-align: right;
        }

        .amount {
            font-family: 'Courier New', monospace;
            text-align: right;
        }

        .total-row td {
            font-weight: bold;
        }

        .status-paid {
            color: #065f46;
        }

        .status-pending {
            color: #92400e;
        }

        .status-cancelled {
            color: #6b7280;
        }

        .overdue {
            color: #991b1b;
            font-weight: bold;
        }

        .note-section {
            margin: 15px 0;
            padding: 12px;
            border: 1px solid #000;
        }

        .note-title {
            font-weight: bold;
            margin-bottom: 5px;
        }

        .legal-note {
            margin-top: 15px;
            font-size: 9px;
            text-align: justify;
            padding: 10px;
            border: 1px solid #ccc;
        }

        .signature-section {
            margin-top: 50px;
            display: table;
            width: 100%;
        }

        .signature-item {
            display: table-cell;
            width: 50%;
            text-align: center;
            padding: 0 30px;
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
            <div class="company-info">{{ $companyAddress }}</div>
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

    {{-- Titulo --}}
    <div class="title">Préstamo</div>
    <div class="subtitle">Documento #{{ $loan->id }}</div>

    {{-- Informacion del Empleado y Detalles del Prestamo (lado a lado) --}}
    @php
        $reasonLabels = [
            'personal' => 'Personal',
            'medical' => 'Médico',
            'education' => 'Educación',
            'other' => 'Otro',
        ];
    @endphp
    <div style="display: table; width: 100%; margin-bottom: 12px; border-collapse: collapse;">
        {{-- Columna izquierda: Empleado --}}
        <div style="display: table-cell; width: 48%; vertical-align: top; padding-right: 8px;">
            <div class="section-title">Información del Empleado</div>
            <table style="margin: 0; width: 100%;">
                <tbody>
                    <tr>
                        <td class="text-left" style="width: 45%; font-weight: bold;">Nombre:</td>
                        <td class="text-left">{{ $loan->employee->full_name }}</td>
                    </tr>
                    <tr>
                        <td class="text-left" style="font-weight: bold;">C.I.:</td>
                        <td class="text-left">{{ $loan->employee->ci }}</td>
                    </tr>
                    <tr>
                        <td class="text-left" style="font-weight: bold;">Cargo:</td>
                        <td class="text-left">{{ $loan->employee->activeContract?->position?->name ?? 'N/A' }}</td>
                    </tr>
                    <tr>
                        <td class="text-left" style="font-weight: bold;">Departamento:</td>
                        <td class="text-left">
                            {{ $loan->employee->activeContract?->position?->department?->name ?? 'N/A' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        {{-- Columna derecha: Préstamo --}}
        <div style="display: table-cell; width: 52%; vertical-align: top; padding-left: 8px;">
            <div class="section-title">Detalles del Préstamo</div>
            <table style="margin: 0; width: 100%;">
                <tbody>
                    <tr>
                        <td class="text-left" style="width: 45%; font-weight: bold;">Monto Total:</td>
                        <td class="text-left"><strong>Gs. {{ number_format($loan->amount, 0, ',', '.') }}</strong></td>
                    </tr>
                    <tr>
                        <td class="text-left" style="font-weight: bold;">Monto por Cuota:</td>
                        <td class="text-left">Gs. {{ number_format($loan->installment_amount, 0, ',', '.') }}</td>
                    </tr>
                    <tr>
                        <td class="text-left" style="font-weight: bold;">Cantidad de Cuotas:</td>
                        <td class="text-left">{{ $loan->installments_count }}</td>
                    </tr>
                    <tr>
                        <td class="text-left" style="font-weight: bold;">Tasa de Interés Anual:</td>
                        <td class="text-left">
                            {{ $loan->hasInterest() ? number_format((float) $loan->interest_rate, 2, ',', '.') . '%' : 'Sin interés' }}
                        </td>
                    </tr>
                    <tr>
                        <td class="text-left" style="font-weight: bold;">Motivo:</td>
                        <td class="text-left">{{ $reasonLabels[$loan->reason] ?? ($loan->reason ?? '-') }}</td>
                    </tr>
                    @if ($loan->granted_at)
                        <tr>
                            <td class="text-left" style="font-weight: bold;">Fecha de Otorgamiento:</td>
                            <td class="text-left">{{ $loan->granted_at->format('d/m/Y') }}</td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>

    {{-- Detalle de Cuotas --}}
    @if ($loan->installments->count() > 0)
        @php $hasInterest = $loan->hasInterest(); @endphp
        <div class="section">
            <div class="section-title">Detalle de Cuotas</div>
            <table>
                <thead>
                    <tr>
                        <th style="width: 8%;">#</th>
                        <th style="width: {{ $hasInterest ? '18%' : '24%' }};">Vencimiento</th>
                        @if ($hasInterest)
                            <th style="width: 16%;">Capital</th>
                            <th style="width: 14%;">Interés</th>
                        @endif
                        <th style="width: {{ $hasInterest ? '16%' : '24%' }};">Total Cuota</th>
                        <th style="width: {{ $hasInterest ? '14%' : '22%' }};">Estado</th>
                        <th style="width: {{ $hasInterest ? '14%' : '22%' }};">Fecha de Pago</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($loan->installments->sortBy('installment_number') as $installment)
                        <tr>
                            <td>{{ $installment->installment_number }}</td>
                            <td class="{{ $installment->isOverdue() ? 'overdue' : '' }}">
                                {{ $installment->due_date->format('d/m/Y') }}
                            </td>
                            @if ($hasInterest)
                                <td class="amount">Gs.
                                    {{ number_format((float) $installment->capital_amount, 0, ',', '.') }}</td>
                                <td class="amount">Gs.
                                    {{ number_format((float) $installment->interest_amount, 0, ',', '.') }}</td>
                            @endif
                            <td class="amount">Gs. {{ number_format((float) $installment->amount, 0, ',', '.') }}</td>
                            <td>
                                <span class="status-{{ $installment->status }}">
                                    {{ \App\Models\LoanInstallment::getStatusLabel($installment->status) }}
                                </span>
                            </td>
                            <td>
                                {{ $installment->paid_at ? $installment->paid_at->format('d/m/Y') : '-' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                @if ($hasInterest)
                    @php
                        $totalCapital = $loan->installments->sum('capital_amount');
                        $totalInterest = $loan->installments->sum('interest_amount');
                        $totalAmount = $loan->installments->sum('amount');
                    @endphp
                    <tfoot>
                        <tr class="total-row">
                            <td colspan="2" class="text-right">Total:</td>
                            <td class="amount">Gs. {{ number_format($totalCapital, 0, ',', '.') }}</td>
                            <td class="amount">Gs. {{ number_format($totalInterest, 0, ',', '.') }}</td>
                            <td class="amount">Gs. {{ number_format($totalAmount, 0, ',', '.') }}</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    @endif

    {{-- Notas --}}
    @if ($loan->notes)
        <div class="note-section">
            <div class="note-title">Observaciones:</div>
            <p>{{ $loan->notes }}</p>
        </div>
    @endif

    {{-- Firmas --}}
    <div class="signature-section">
        <div class="signature-item">
            <div class="signature-line"></div>
            <div class="signature-label">Empleado</div>
            <div class="signature-sublabel">{{ $loan->employee->full_name }}</div>
            <div class="signature-sublabel">CI: {{ $loan->employee->ci }}</div>
        </div>
        <div class="signature-item">
            <div class="signature-line"></div>
            <div class="signature-label">Recursos Humanos</div>
            <div class="signature-sublabel">Firma y Sello</div>
        </div>
    </div>

    {{-- Footer --}}
    <div class="footer">
        Documento generado el {{ now()->format('d/m/Y H:i') }}
        @if ($city)
            | {{ $city }}, Paraguay
        @endif
    </div>
</body>

</html>
