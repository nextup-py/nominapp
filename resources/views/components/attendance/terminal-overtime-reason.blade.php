{{-- Pantalla que pide el motivo de las horas extra al marcar una salida pasada la hora del horario.
     Los botones se dibujan desde terminal/overtime-reason-prompt.js con la lista que trae el latido. Si nadie
     elige, la salida se registra igual sin motivo (no bloquea la marcación). --}}
<section id="overtimeReasonScreen" class="terminal-screen hidden" role="region" aria-label="Motivo de las horas extra">
    <div class="screen-body">
        <p class="screen-eyebrow">Horas extra</p>
        <h1 class="screen-title">Motivo de las horas extra</h1>
        <p class="screen-subtitle">Seleccione por qué se quedó después de su horario</p>

        <div class="overtime-reason-list" id="overtimeReasonList"></div>

        <p class="type-selection-countdown" id="overtimeReasonCountdown" aria-live="polite"></p>
    </div>
</section>
