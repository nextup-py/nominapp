{{-- Compacta el menú superior para que entre en 1280 px: sin íconos, texto algo menor y espacios más cortos. --}}
<style>
    .fi-topbar ul.lg\:flex { gap: 0.125rem; }
    .fi-topbar .fi-topbar-item-button { padding: 0.5rem 0.3125rem; gap: 0.25rem; white-space: nowrap; }
    .fi-topbar .fi-topbar-item-button > svg:first-child { display: none; }
    .fi-topbar .fi-topbar-item-label { white-space: nowrap; font-size: 0.8125rem; }
    .fi-topbar .fi-dropdown-panel { max-width: 18rem; }
</style>
