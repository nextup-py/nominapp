{{-- Historial de cambios de un turno puntual del Planificador: quién, cuándo y qué cambió. --}}
@php
    $events = ['created' => 'Asignado', 'updated' => 'Modificado', 'deleted' => 'Restaurado al ciclo'];
@endphp

<div style="font-size: .875rem;">
    @forelse ($audits as $audit)
        <div style="padding: .5rem 0; border-bottom: 1px solid rgba(var(--gray-500), .2);">
            <div style="font-weight: 600;">
                {{ $events[$audit->event] ?? ucfirst($audit->event) }}
                <span style="font-weight: 400; opacity: .7;">· {{ $audit->user?->name ?? 'Sistema' }} · {{ $audit->created_at->format('d/m/Y H:i') }}</span>
            </div>
            @if ($audit->event !== 'created' && ! empty($audit->old_values))
                <div style="opacity: .8;">Antes: {{ \App\Models\ShiftOverride::describeAuditValues($audit->old_values) }}</div>
            @endif
            @if ($audit->event !== 'deleted' && ! empty($audit->new_values))
                <div>Ahora: {{ \App\Models\ShiftOverride::describeAuditValues($audit->new_values) }}</div>
            @endif
        </div>
    @empty
        <div style="opacity: .7;">Sin cambios registrados.</div>
    @endforelse
</div>
