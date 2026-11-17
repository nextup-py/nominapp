{{-- Cartel fijo para quien gestiona terminales: hay equipos sin vincular o sin conexión dentro de su horario. --}}
@php($terminals = \App\Services\TerminalAttentionBanner::visibleToCurrentUser() ? \App\Services\TerminalAttentionBanner::items() : [])

@if (count($terminals) > 0)
    <style>
        .terminal-alert-banner { background: rgba(var(--danger-500), .1); border: 1px solid rgba(var(--danger-500), .35); color: rgb(var(--danger-700)); }
        .dark .terminal-alert-banner { color: rgb(var(--danger-300)); }
        .terminal-alert-banner a { color: inherit; font-weight: 600; text-decoration: underline; }
    </style>

    <div class="terminal-alert-banner" style="margin-bottom: 1rem; padding: .75rem 1rem; border-radius: .75rem; font-size: .875rem;" role="alert">
        <strong>
            {{ count($terminals) === 1 ? '1 terminal necesita atención' : count($terminals).' terminales necesitan atención' }}:
        </strong>
        {{ collect($terminals)->map(fn ($t) => $t['name'].($t['branch'] ? " ({$t['branch']})" : '').' — '.['unlinked' => 'sin vincular', 'stale' => 'sin conexión', 'outside' => 'fuera de la sucursal'][$t['status']])->implode('; ') }}.
        <a href="{{ \App\Filament\Resources\TerminalResource::getUrl('index') }}">Ver terminales</a>
    </div>
@endif
