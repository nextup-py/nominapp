<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
    @page { size: A4 {{ $orientation }}; margin: 0; }
    body { font-family: Arial, sans-serif; font-size: 10px; line-height: 1.4; padding: 12mm 15mm; color: #000; }

    .company-header { text-align: center; margin-bottom: 14px; padding-bottom: 10px; border-bottom: 2px solid #0d9488; }
    .company-logo   { max-height: 36px; max-width: 100px; margin-bottom: 5px; }
    .company-name   { font-size: 13px; font-weight: bold; text-transform: uppercase; margin-bottom: 2px; }
    .company-info   { font-size: 8px; color: #444; }

    .report-title    { text-align: center; font-size: 12px; font-weight: bold; text-transform: uppercase; margin: 10px 0 2px; }
    .report-subtitle { text-align: center; font-size: 9px; color: #444; margin-bottom: 12px; }

    .section-title { font-weight: bold; font-size: 9px; text-transform: uppercase; padding: 4px 0; margin: 14px 0 6px; border-bottom: 1px solid #000; }

    table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    th { background: #222; color: #fff; font-size: 8px; padding: 4px 5px; text-align: left; }
    td { font-size: 8px; padding: 3px 5px; border-bottom: 1px solid #e0e0e0; vertical-align: top; }
    tr:nth-child(even) td { background: #f9f9f9; }

    .num   { text-align: right; }
    .center { text-align: center; }
    .badge-ok   { background: #d4edda; color: #155724; padding: 1px 4px; border-radius: 3px; }
    .badge-warn { background: #fff3cd; color: #856404; padding: 1px 4px; border-radius: 3px; }
    .badge-danger { background: #f8d7da; color: #721c24; padding: 1px 4px; border-radius: 3px; }

    .employee-header { font-weight: bold; font-size: 9px; background: #f0f0f0; padding: 3px 5px; margin-top: 8px; margin-bottom: 2px; border-left: 3px solid #333; }

    .footer { margin-top: 20px; text-align: center; font-size: 7px; color: #999; border-top: 1px solid #ccc; padding-top: 6px; }
</style>
</head>
<body>

{{-- Encabezado empresa --}}
<div class="company-header">
    @if($companyLogo)
        <div><img src="{{ $companyLogo }}" class="company-logo" alt="Logo"></div>
    @endif
    <div class="company-name">{{ $companyName }}</div>
    @if($companyRuc || $companyAddress)
        <div class="company-info">
            @if($companyRuc) RUC: {{ $companyRuc }} @endif
            @if($companyRuc && $companyAddress) &nbsp;|&nbsp; @endif
            @if($companyAddress) {{ $companyAddress }} @endif
        </div>
    @endif
</div>

<div class="report-title">Reporte de Asistencias</div>
<div class="report-subtitle">
    @if($from || $to)
        Período:
        @if($from) {{ \Carbon\Carbon::parse($from)->format('d/m/Y') }} @endif
        @if($from && $to) al @endif
        @if($to) {{ \Carbon\Carbon::parse($to)->format('d/m/Y') }} @endif
        &nbsp;·&nbsp;
    @endif
    Generado el {{ now()->format('d/m/Y H:i') }}
</div>

{{-- SECCIÓN RESUMEN --}}
@php $totalCols = 1 + count($columns); @endphp
<div class="section-title">Resumen por Empleado</div>
<table>
    <thead>
        <tr>
            <th>Empleado</th>
            @if(in_array('ci', $columns)) <th>CI</th> @endif
            @if(in_array('branch_name', $columns)) <th>Sucursal</th> @endif
            @if(in_array('department_name', $columns)) <th>Departamento</th> @endif
            @if(in_array('days_present', $columns)) <th class="center">Presentes</th> @endif
            @if(in_array('days_absent', $columns)) <th class="center">Ausencias</th> @endif
            @if(in_array('days_leave', $columns)) <th class="center">Licencias</th> @endif
            @if(in_array('total_net_hours', $columns)) <th class="num">Horas Netas</th> @endif
            @if(in_array('total_anomalies', $columns)) <th class="center">Anomalías</th> @endif
        </tr>
    </thead>
    <tbody>
        @forelse($summary as $row)
        <tr>
            <td>{{ $row->last_name }}, {{ $row->first_name }}</td>
            @if(in_array('ci', $columns)) <td>{{ $row->ci }}</td> @endif
            @if(in_array('branch_name', $columns)) <td>{{ $row->branch_name ?? '—' }}</td> @endif
            @if(in_array('department_name', $columns)) <td>{{ $row->department_name ?? '—' }}</td> @endif
            @if(in_array('days_present', $columns)) <td class="center">{{ (int) $row->days_present }}</td> @endif
            @if(in_array('days_absent', $columns))
            <td class="center">
                @if((int)$row->days_absent > 0)
                    <span class="badge-danger">{{ (int) $row->days_absent }}</span>
                @else 0 @endif
            </td>
            @endif
            @if(in_array('days_leave', $columns)) <td class="center">{{ (int) $row->days_leave }}</td> @endif
            @if(in_array('total_net_hours', $columns)) <td class="num">{{ number_format((float)$row->total_net_hours, 2) }} h</td> @endif
            @if(in_array('total_anomalies', $columns))
            <td class="center">
                @if((int)$row->total_anomalies > 0)
                    <span class="badge-warn">{{ (int) $row->total_anomalies }}</span>
                @else 0 @endif
            </td>
            @endif
        </tr>
        @empty
        <tr><td colspan="{{ $totalCols }}" class="center">Sin registros para el período seleccionado.</td></tr>
        @endforelse
    </tbody>
</table>

{{-- SECCIÓN DETALLE --}}
<div class="section-title">Detalle Día a Día</div>

@forelse($detail as $employeeId => $days)
    @php $emp = $days->first()->employee; @endphp
    <div class="employee-header">
        {{ $emp?->last_name }}, {{ $emp?->first_name }} — CI: {{ $emp?->ci }} — Sucursal: {{ $emp?->branch?->name ?? '—' }}
    </div>
    <table>
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Día</th>
                <th>Estado</th>
                <th>Entrada</th>
                <th>Salida</th>
                <th class="num">Hs. Totales</th>
                <th class="num">Hs. Netas</th>
                <th class="center">Sal. Anticipada</th>
                <th class="center">Anomalía</th>
            </tr>
        </thead>
        <tbody>
            @foreach($days as $day)
            @php
                $dias = ['Monday'=>'Lunes','Tuesday'=>'Martes','Wednesday'=>'Miércoles','Thursday'=>'Jueves','Friday'=>'Viernes','Saturday'=>'Sábado','Sunday'=>'Domingo'];
            @endphp
            <tr>
                <td>{{ $day->date?->format('d/m/Y') }}</td>
                <td>{{ $dias[$day->date?->format('l')] ?? '' }}</td>
                <td>{{ \App\Models\AttendanceDay::getStatusLabel($day->status ?? 'absent') }}</td>
                <td>{{ $day->check_in_time  ? \Carbon\Carbon::parse($day->check_in_time)->format('H:i')  : '—' }}</td>
                <td>{{ $day->check_out_time ? \Carbon\Carbon::parse($day->check_out_time)->format('H:i') : '—' }}</td>
                <td class="num">{{ number_format((float)($day->total_hours ?? 0), 2) }} h</td>
                <td class="num">{{ number_format((float)($day->net_hours ?? 0), 2) }} h</td>
                <td class="center">{{ (int)($day->early_leave_minutes ?? 0) > 0 ? (int)$day->early_leave_minutes.' min' : '—' }}</td>
                <td class="center">
                    @if($day->anomaly_flag)
                        <span class="badge-warn">Sí</span>
                    @else —
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
@empty
    <p style="text-align:center;color:#666;">Sin registros de detalle para el período seleccionado.</p>
@endforelse

<div class="footer">
    Nominapp &nbsp;·&nbsp; Reporte de Asistencias &nbsp;·&nbsp; {{ now()->format('d/m/Y H:i:s') }}
</div>
</body>
</html>
