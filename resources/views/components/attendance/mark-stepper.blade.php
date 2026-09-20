@props(['active' => 1])

<div class="mark-stepper" role="list" aria-label="Progreso de marcación">
    <div class="mark-stepper-step {{ $active >= 1 ? 'is-active' : '' }} {{ $active > 1 ? 'is-done' : '' }}" role="listitem">
        <span class="mark-stepper-dot" aria-hidden="true">1</span>
        <span class="mark-stepper-label">Identificación</span>
    </div>
    <span class="mark-stepper-line {{ $active > 1 ? 'is-done' : '' }}" aria-hidden="true"></span>
    <div class="mark-stepper-step {{ $active >= 2 ? 'is-active' : '' }}" role="listitem">
        <span class="mark-stepper-dot" aria-hidden="true">2</span>
        <span class="mark-stepper-label">Confirmación</span>
    </div>
</div>
