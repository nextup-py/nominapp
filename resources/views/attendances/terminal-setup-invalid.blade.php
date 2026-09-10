{{-- resources/views/attendances/terminal-setup-invalid.blade.php --}}
<x-status-page
    variant="danger"
    title="Enlace de configuración inválido"
    :description="['Este enlace ya fue usado, expiró, o no corresponde a ningún terminal.', 'Solicite un nuevo enlace de configuración desde el panel de administración (Terminales).']"
    icon="<path stroke-linecap='round' stroke-linejoin='round' d='M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z' />"
/>
