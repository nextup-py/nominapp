<x-filament-panels::page>
    {{-- Formulario del período a simular --}}
    <x-filament::section>
        <form wire:submit="simulate" class="space-y-4">
            {{ $this->form }}

            <div style="display:flex; gap:.75rem; align-items:center; flex-wrap:wrap;">
                <x-filament::button type="submit" icon="heroicon-o-beaker" wire:loading.attr="disabled" wire:target="simulate">
                    Simular
                </x-filament::button>
                <span wire:loading wire:target="simulate" style="font-size:.875rem;">Calculando la nómina, puede tardar unos segundos…</span>
            </div>
        </form>

        <p style="margin-top:.75rem; font-size:.8125rem; opacity:.8;">
            La simulación usa el mismo cálculo que la generación real, con la asistencia tal como está hoy, y <strong>no guarda nada</strong>:
            no crea recibos ni marca cuotas, adelantos o vacaciones como pagados.
        </p>
    </x-filament::section>

    @if ($error)
        <x-filament::section>
            <p style="color: rgb(var(--danger-600)); font-weight:600;">{{ $error }}</p>
        </x-filament::section>
    @endif

    @if ($result)
        @php
            $t = $result['totals'];
            $money = fn ($v) => 'Gs. '.number_format((float) $v, 0, ',', '.');
        @endphp

        {{-- Avisos previos del período --}}
        @if (! empty($result['warnings']))
            <x-filament::section heading="Revisar antes de generar" icon="heroicon-o-exclamation-triangle">
                <ul style="margin:0; padding-left:1.25rem;">
                    @foreach ($result['warnings'] as $warning)
                        <li><strong>{{ $warning['count'] }}</strong> {{ $warning['label'] }}</li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif

        {{-- Totales --}}
        <x-filament::section heading="Totales de la simulación">
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:1rem;">
                @foreach ([
                    'Empleados' => $t['employees'],
                    'Salario base' => $money($t['base_salary']),
                    'Percepciones' => $money($t['perceptions']),
                    'IPS' => $money($t['ips']),
                    'Otros descuentos' => $money($t['other_deductions']),
                    'Neto total' => $money($t['net']),
                    'Neto por transferencia' => $money($t['net_transfer']),
                    'Neto en efectivo' => $money($t['net_cash']),
                ] as $label => $value)
                    <div>
                        <div style="font-size:.75rem; opacity:.7;">{{ $label }}</div>
                        <div style="font-size:1.125rem; font-weight:600;">{{ $value }}</div>
                    </div>
                @endforeach
            </div>

            @if ($this->excelUrl())
                <div style="margin-top:1rem;">
                    <x-filament::button tag="a" :href="$this->excelUrl()" icon="heroicon-o-arrow-down-tray" color="gray">
                        Descargar Excel (resumen y detalle)
                    </x-filament::button>
                </div>
            @endif
        </x-filament::section>

        {{-- Empleados omitidos --}}
        @if (! empty($result['skipped']))
            <x-filament::section heading="Empleados omitidos ({{ count($result['skipped']) }})" collapsible collapsed>
                <ul style="margin:0; padding-left:1.25rem;">
                    @foreach ($result['skipped'] as $skipped)
                        <li>{{ $skipped['name'] }} (CI {{ $skipped['ci'] }}): {{ $skipped['reason'] }}</li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif

        {{-- Resumen por empleado --}}
        <x-filament::section heading="Resumen por empleado">
            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:.8125rem;">
                    <thead>
                        <tr style="text-align:left; border-bottom:1px solid rgba(128,128,128,.35);">
                            <th style="padding:.4rem;">CI</th>
                            <th style="padding:.4rem;">Empleado</th>
                            <th style="padding:.4rem;">Sucursal</th>
                            <th style="padding:.4rem;">Pago</th>
                            <th style="padding:.4rem; text-align:right;">Base</th>
                            <th style="padding:.4rem; text-align:right;">Percepciones</th>
                            <th style="padding:.4rem; text-align:right;">IPS</th>
                            <th style="padding:.4rem; text-align:right;">Otros desc.</th>
                            <th style="padding:.4rem; text-align:right;">Neto</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($result['rows'] as $row)
                            <tr style="border-bottom:1px solid rgba(128,128,128,.15);">
                                <td style="padding:.4rem;">{{ $row['ci'] }}</td>
                                <td style="padding:.4rem;">{{ $row['name'] }}</td>
                                <td style="padding:.4rem;">{{ $row['branch'] }}</td>
                                <td style="padding:.4rem;">{{ $row['payment_method'] === 'cash' ? 'Efectivo' : 'Transferencia' }}</td>
                                <td style="padding:.4rem; text-align:right;">{{ number_format($row['base_salary'], 0, ',', '.') }}</td>
                                <td style="padding:.4rem; text-align:right;">{{ number_format($row['perceptions'], 0, ',', '.') }}</td>
                                <td style="padding:.4rem; text-align:right;">{{ number_format($row['ips'], 0, ',', '.') }}</td>
                                <td style="padding:.4rem; text-align:right;">{{ number_format($row['other_deductions'], 0, ',', '.') }}</td>
                                <td style="padding:.4rem; text-align:right; font-weight:600;">{{ number_format($row['net'], 0, ',', '.') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="9" style="padding:.75rem; text-align:center; opacity:.7;">Ningún empleado entra en esta nómina.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
