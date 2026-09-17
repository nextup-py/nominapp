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

    .num    { text-align: right; }
    .center { text-align: center; }
    .badge-ok     { background: #d4edda; color: #155724; padding: 1px 4px; border-radius: 3px; }
    .badge-warn   { background: #fff3cd; color: #856404; padding: 1px 4px; border-radius: 3px; }
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

<div class="report-title">Reporte de Horas Extras y Tardanzas</div>
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
            @if(in_array('total_extra_hours', $columns)) <th class="num">HE Total (h)</th> @endif
            @if(in_array('total_extra_diurnas', $columns)) <th class="num">HE Diurnas (h)</th> @endif
            @if(in_array('total_extra_nocturnas', $columns)) <th class="num">HE Nocturnas (h)</th> @endif
            @if(in_array('days_with_extras', $columns)) <th class="center">Días con HE</th> @endif
            @if(in_array('days_approved', $columns)) <th class="center">HE Aprobados</th> @endif
            @if(in_array('total_late_minutes', $columns)) <th class="num">Tardanza Total</th> @endif
            @if(in_array('days_late', $columns)) <th class="center">Días Tarde</th> @endif
            @if(in_array('avg_late_minutes', $columns)) <th class="num">Prom. Tardanza</th> @endif
        </tr>
    </thead>
    <tbody>
        @forelse($summary as $row)
        @php
            $lateMin = (int) $row->total_late_minutes;
            $lateH   = intdiv($lateMin, 60);
            $lateM   = $lateMin % 60;
            $lateStr = $lateH > 0 ? "{$lateH}h {$lateM}min" : "{$lateM}min";
        @endphp
        <tr>
            <td>{{ $row->last_name }}, {{ $row->first_name }}</td>
            @if(in_array('ci', $columns)) <td>{{ $row->ci }}</td> @endif
            @if(in_array('branch_name', $columns)) <td>{{ $row->branch_name ?? '—' }}</td> @endif
            @if(in_array('department_name', $columns)) <td>{{ $row->department_name ?? '—' }}</td> @endif
            @if(in_array('total_extra_hours', $columns))
            <td class="num">
                @if((float)$row->total_extra_hours > 0)
                    <span class="badge-warn">{{ number_format((float)$row->total_extra_hours, 2) }}</span>
                @else 0.00 @endif
            </td>
            @endif
            @if(in_array('total_extra_diurnas', $columns)) <td class="num">{{ number_format((float)$row->total_extra_diurnas, 2) }}</td> @endif
            @if(in_array('total_extra_nocturnas', $columns)) <td class="num">{{ number_format((float)$row->total_extra_nocturnas, 2) }}</td> @endif
            @if(in_array('days_with_extras', $columns)) <td class="center">{{ (int) $row->days_with_extras }}</td> @endif
            @if(in_array('days_approved', $columns))
            <td class="center">
                @if((int)$row->days_approved > 0)
                    <span class="badge-ok">{{ (int) $row->days_approved }}</span>
                @else 0 @endif
            </td>
            @endif
            @if(in_array('total_late_minutes', $columns))
            <td class="num">
                @if($lateMin > 0)
                    <span class="badge-danger">{{ $lateStr }}</span>
                @else — @endif
            </td>
            @endif
            @if(in_array('days_late', $columns)) <td class="center">{{ (int) $row->days_late }}</td> @endif
            @if(in_array('avg_late_minutes', $columns)) <td class="num">{{ (int)$row->avg_late_minutes > 0 ? (int)$row->avg_late_minutes.' min' : '—' }}</td> @endif
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
                <th>Entrada esp.</th>
                <th>Entrada real</th>
                <th class="center">Tardanza</th>
                <th>Salida esp.</th>
                <th>Salida real</th>
                <th class="num">HE Total (h)</th>
                <th class="num">HE Diurnas (h)</th>
                <th class="num">HE Nocturnas (h)</th>
                <th class="center">HE Aprobada</th>
                <th class="center">Límite exc.</th>
                <th class="center">Extraordinario</th>
            </tr>
        </thead>
        <tbody>
            @foreach($days as $day)
            @php
                $dias   = ['Monday'=>'Lunes','Tuesday'=>'Martes','Wednesday'=>'Miércoles','Thursday'=>'Jueves','Friday'=>'Viernes','Saturday'=>'Sábado','Sunday'=>'Domingo'];
                $lateMin = (int)($day->late_minutes ?? 0);
                $lateH   = intdiv($lateMin, 60);
                $lateM   = $lateMin % 60;
                $lateStr = $lateH > 0 ? "{$lateH}h {$lateM}min" : "{$lateM}min";
            @endphp
            <tr>
                <td>{{ $day->date?->format('d/m/Y') }}</td>
                <td>{{ $dias[$day->date?->format('l')] ?? '' }}</td>
                <td>{{ $day->expected_check_in  ? \Carbon\Carbon::parse($day->expected_check_in)->format('H:i')  : '—' }}</td>
                <td>{{ $day->check_in_time      ? \Carbon\Carbon::parse($day->check_in_time)->format('H:i')      : '—' }}</td>
                <td class="center">
                    @if($lateMin > 0)
                        <span class="badge-danger">{{ $lateStr }}</span>
                    @else — @endif
                </td>
                <td>{{ $day->expected_check_out ? \Carbon\Carbon::parse($day->expected_check_out)->format('H:i') : '—' }}</td>
                <td>{{ $day->check_out_time     ? \Carbon\Carbon::parse($day->check_out_time)->format('H:i')     : '—' }}</td>
                <td class="num">{{ number_format((float)($day->extra_hours ?? 0), 2) }}</td>
                <td class="num">{{ number_format((float)($day->extra_hours_diurnas ?? 0), 2) }}</td>
                <td class="num">{{ number_format((float)($day->extra_hours_nocturnas ?? 0), 2) }}</td>
                <td class="center">
                    @if($day->overtime_approved)
                        <span class="badge-ok">Sí</span>
                    @else No @endif
                </td>
                <td class="center">
                    @if($day->overtime_limit_exceeded)
                        <span class="badge-danger">Sí</span>
                    @else No @endif
                </td>
                <td class="center">
                    @if($day->is_extraordinary_work)
                        <span class="badge-warn">Sí</span>
                    @else No @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
@empty
    <p style="text-align:center;color:#666;">Sin registros de horas extras ni tardanzas para el período seleccionado.</p>
@endforelse

<div class="footer">
    Nominapp &nbsp;·&nbsp; Reporte de Horas Extras y Tardanzas &nbsp;·&nbsp; {{ now()->format('d/m/Y H:i:s') }}
</div>
</body>
</html>
