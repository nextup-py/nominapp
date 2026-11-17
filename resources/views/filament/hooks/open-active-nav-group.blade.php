{{-- Abre el grupo del menú que contiene la página actual aunque esté colapsado (Filament solo recuerda lo que el usuario cerró). --}}
<script>
    document.addEventListener('alpine:initialized', () => {
        const active = document.querySelector('.fi-sidebar-item-active');
        const label = active?.closest('.fi-sidebar-group')?.dataset.groupLabel;
        const sidebar = window.Alpine?.store('sidebar');

        if (label && sidebar && sidebar.groupIsCollapsed(label)) {
            sidebar.toggleCollapsedGroup(label);
        }
    });
</script>
