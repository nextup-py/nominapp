<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Retiro de Mercadería #{{ $withdrawal->id }}</title>
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
            font-size: 10px;
            line-height: 1.4;
            padding: 12mm 18mm;
        }

        .company-header {
            text-align: center;
            margin-bottom: 8px;
            padding-bottom: 6px;
            border-bottom: 2px solid #0d9488;
        }

        .company-logo {
            max-height: 35px;
            max-width: 110px;
            margin-bottom: 4px;
        }

        .company-name {
            font-size: 13px;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 2px;
        }

        .company-info {
            font-size: 8px;
        }

        .title {
            text-align: center;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
            margin: 6px 0 2px 0;
        }

        .subtitle {
            text-align: center;
            font-size: 9px;
            margin-bottom: 8px;
        }

        .section {
            margin-bottom: 8px;
        }

        .section-title {
            font-weight: bold;
            font-size: 9px;
            text-transform: uppercase;
            padding: 3px 0;
            margin-bottom: 5px;
            border-bottom: 1px solid #000;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }

        th,
        td {
            border: 1px solid #000;
            padding: 3px 5px;
            font-size: 8px;
        }

        th {
            font-weight: bold;
            background-color: #f5f5f5;
            text-align: center;
        }

        .text-left {
            text-align: left;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .amount {
            font-family: 'Courier New', monospace;
            text-align: right;
        }

        .total-row td {
            font-weight: bold;
            background-color: #f5f5f5;
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
            margin: 6px 0;
            padding: 6px 8px;
            border: 1px solid #000;
        }

        .note-title {
            font-weight: bold;
            margin-bottom: 3px;
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
            padding: 0 25px;
        }

        .signature-line {
            border-top: 1px solid #000;
            margin-bottom: 4px;
            padding-top: 4px;
        }

        .signature-label {
            font-size: 9px;
            font-weight: bold;
        }

        .signature-sublabel {
            font-size: 8px;
        }

        .footer {
            margin-top: 20px;
            text-align: center;
            font-size: 7px;
            border-top: 1px solid #ccc;
            padding-top: 6px;
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

    {{-- Título --}}
    <div class="title">Retiro de Mercadería</div>
    <div class="subtitle">Documento #{{ $withdrawal->id }}</div>

    {{-- Empleado e Info de Pago (lado a lado) --}}
    <div style="display: table; width: 100%; margin-bottom: 8px;">
        {{-- Columna izquierda: Empleado --}}
        <div style="display: table-cell; width: 50%; vertical-align: top; padding-right: 6px;">
            <div class="section-title">Información del Empleado</div>
            <table>
                <tbody>
                    <tr>
                        <td style="width: 42%; font-weight: bold;">Nombre:</td>
                        <td>{{ $withdrawal->employee->full_name }}</td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold;">C.I.:</td>
                        <td>{{ $withdrawal->employee->ci }}</td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold;">Cargo:</td>
                        <td>{{ $withdrawal->employee->activeContract?->position?->name ?? 'N/A' }}</td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold;">Departamento:</td>
                        <td>{{ $withdrawal->employee->activeContract?->position?->department?->name ?? 'N/A' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        {{-- Columna derecha: Resumen --}}
        <div style="display: table-cell; width: 50%; vertical-align: top; padding-left: 6px;">
            <div class="section-title">Resumen del Retiro</div>
            <table>
                <tbody>
                    <tr>
                        <td style="width: 50%; font-weight: bold;">Monto Total:</td>
                        <td class="amount"><strong>Gs.
                                {{ number_format((float) $withdrawal->total_amount, 0, ',', '.') }}</strong></td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold;">Monto por Cuota:</td>
                        <td class="amount">Gs.
                            {{ number_format((float) $withdrawal->installment_amount, 0, ',', '.') }}</td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold;">Cantidad de Cuotas:</td>
                        <td>{{ $withdrawal->installments_count }}</td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold;">Saldo Pendiente:</td>
                        <td class="amount">Gs.
                            {{ number_format((float) $withdrawal->outstanding_balance, 0, ',', '.') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- Detalle de Productos --}}
    @if ($withdrawal->items->count() > 0)
        <div class="section">
            <div class="section-title">Productos Retirados</div>
            <table>
                <thead>
                    <tr>
                        <th style="width: 11%;">Código</th>
                        <th style="width: 35%;" class="text-left">Producto</th>
                        <th style="width: 20%;">Precio Unitario</th>
                        <th style="width: 9%;">Cant.</th>
                        <th style="width: 25%;">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($withdrawal->items as $item)
                        <tr>
                            <td class="text-center">{{ $item->code ?: '-' }}</td>
                            <td class="text-left">
                                <strong>{{ $item->name }}</strong>
                                @if ($item->description)
                                    <br><span style="color:#555;">{{ $item->description }}</span>
                                @endif
                            </td>
                            <td class="amount">Gs. {{ number_format((float) $item->price, 0, ',', '.') }}</td>
                            <td class="text-center">{{ $item->quantity }}</td>
                            <td class="amount">Gs. {{ number_format((float) $item->subtotal, 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="total-row">
                        <td colspan="4" class="text-right">Total:</td>
                        <td class="amount">Gs. {{ number_format((float) $withdrawal->total_amount, 0, ',', '.') }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif

    {{-- Plan de Cuotas --}}
    @if ($withdrawal->installments->count() > 0)
        <div class="section">
            <div class="section-title">Plan de Cuotas</div>
            <table>
                <thead>
                    <tr>
                        <th style="width: 8%;">#</th>
                        <th style="width: 24%;">Vencimiento</th>
                        <th style="width: 28%;">Monto</th>
                        <th style="width: 20%;">Estado</th>
                        <th style="width: 20%;">Fecha de Pago</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($withdrawal->installments->sortBy('installment_number') as $installment)
                        <tr>
                            <td class="text-center">{{ $installment->installment_number }}</td>
                            <td class="text-center {{ $installment->isOverdue() ? 'overdue' : '' }}">
                                {{ $installment->due_date->format('d/m/Y') }}
                            </td>
                            <td class="amount">Gs. {{ number_format((float) $installment->amount, 0, ',', '.') }}</td>
                            <td class="text-center">
                                <span class="status-{{ $installment->status }}">
                                    {{ \App\Models\MerchandiseWithdrawalInstallment::getStatusLabel($installment->status) }}
                                </span>
                            </td>
                            <td class="text-center">
                                {{ $installment->paid_at ? $installment->paid_at->format('d/m/Y') : '-' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Notas --}}
    @if ($withdrawal->notes)
        <div class="note-section">
            <div class="note-title">Observaciones:</div>
            <p>{{ $withdrawal->notes }}</p>
        </div>
    @endif

    {{-- Firmas --}}
    <div class="signature-section">
        <div class="signature-item">
            <div class="signature-line"></div>
            <div class="signature-label">Empleado</div>
            <div class="signature-sublabel">{{ $withdrawal->employee->full_name }}</div>
            <div class="signature-sublabel">CI: {{ $withdrawal->employee->ci }}</div>
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
