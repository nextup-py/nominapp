{{-- resources/views/enrollments/expired.blade.php --}}
<x-status-page
    variant="danger"
    label="Enlace Expirado"
    title="Este enlace ya no es válido"
    :description="['El enlace de registro facial ha expirado. Contacte al administrador para obtener uno nuevo.']"
    icon="<path stroke-linecap='round' stroke-linejoin='round' d='M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z' />"
/>
