{{-- Cartel para quien gestiona terminales: hay equipos sin vincular o sin conexión dentro de su horario. Se puede cerrar (por navegador) hasta que cambie el problema o pasen 24 horas. --}}
@php($terminals = \App\Services\TerminalAttentionBanner::visibleToCurrentUser() ? \App\Services\TerminalAttentionBanner::items() : [])

@if (count($terminals) > 0)
    <style>
        .terminal-alert-banner { margin: 1rem 0 1.25rem; padding: .875rem 1rem; border-radius: .75rem; font-size: .875rem; background: rgba(var(--danger-500), .08); border: 1px solid rgba(var(--danger-500), .3); color: rgb(var(--danger-700)); }
        .dark .terminal-alert-banner { color: rgb(var(--danger-300)); }
        .terminal-alert-banner a.terminal-alert-link { display: inline-block; margin-left: .5rem; padding: .125rem .625rem; border-radius: .5rem; border: 1px solid currentColor; font-weight: 600; text-decoration: none; color: inherit; }
        .terminal-alert-banner a { color: inherit; font-weight: 600; text-decoration: underline; }
        .terminal-alert-banner button.terminal-alert-close { margin-left: auto; padding: .125rem; line-height: 0; border-radius: .375rem; opacity: .7; }
        .terminal-alert-banner button.terminal-alert-close:hover { opacity: 1; }
    </style>

    @php($labels = ['stale' => 'sin conexión', 'unlinked' => 'sin vincular', 'outside' => 'fuera de la sucursal'])
    @php($signature = \App\Services\TerminalAttentionBanner::signature($terminals))
    <div
        class="terminal-alert-banner"
        role="alert"
        x-data="{
            signature: @js($signature),
            hidden: false,
            init() {
                try {
                    const saved = JSON.parse(localStorage.getItem('terminal-banner-dismissed') || 'null');
                    this.hidden = !!saved && saved.signature === this.signature && saved.until > Date.now();
                } catch (e) { this.hidden = false; }
            },
            dismiss() {
                this.hidden = true;
                try { localStorage.setItem('terminal-banner-dismissed', JSON.stringify({ signature: this.signature, until: Date.now() + 24 * 60 * 60 * 1000 })); } catch (e) {}
            },
        }"
        x-show="! hidden"
        x-cloak
    >
        <div style="display: flex; align-items: flex-start; gap: .625rem;">
            <x-filament::icon icon="heroicon-o-exclamation-triangle" style="width: 1.25rem; height: 1.25rem; flex-shrink: 0; margin-top: .0625rem;" />
            <div style="flex: 1; min-width: 0;">
                <strong>
                    {{ count($terminals) === 1 ? '1 terminal necesita atención' : count($terminals).' terminales necesitan atención' }}:
                </strong>
                {{ collect(\App\Services\TerminalAttentionBanner::countsByStatus($terminals))->map(fn ($n, $status) => $n.' '.$labels[$status])->implode(' · ') }}.
                <a class="terminal-alert-link" href="{{ \App\Filament\Resources\TerminalResource::getUrl('index') }}">Ver terminales</a>
                <details style="margin-top: .375rem;">
                    <summary style="cursor: pointer;">Ver el detalle</summary>
                    <div style="margin-top: .375rem;">
                        {{ collect($terminals)->map(fn ($t) => $t['name'].($t['branch'] ? " ({$t['branch']})" : '').' — '.$labels[$t['status']])->implode('; ') }}.
                    </div>
                </details>
                @php($waiting = collect($terminals)->where('status', 'unlinked')->where('pending', '>', 0))
                @if ($waiting->isNotEmpty())
                    <div style="margin-top: .5rem;">
                        <strong>Esperan aprobación:</strong>
                        {{ $waiting->map(fn ($t) => $t['name'].' ('.$t['pending'].($t['pending'] === 1 ? ' código' : ' códigos').')')->implode('; ') }}.
                        Sin aprobar, nadie puede marcar ahí.
                        <a href="{{ \App\Filament\Pages\TerminalPairingInbox::getUrl() }}">Aprobar ahora</a>
                    </div>
                @endif
            </div>
            <button type="button" class="terminal-alert-close" x-on:click="dismiss()" title="Ocultar por 24 horas" aria-label="Ocultar el aviso">
                <x-filament::icon icon="heroicon-o-x-mark" style="width: 1.25rem; height: 1.25rem;" />
            </button>
        </div>
    </div>
@endif
