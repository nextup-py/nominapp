{{-- resources/views/enrollments/already-submitted.blade.php --}}
<x-status-page
    variant="success"
    label="Registro Enviado"
    title="Su rostro ya fue registrado"
    :description="['Su registro facial se encuentra pendiente de aprobación por el administrador. No es necesario realizar ninguna acción adicional.']"
    icon="<path stroke-linecap='round' stroke-linejoin='round' d='M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z' />"
/>
