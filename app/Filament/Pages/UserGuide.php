<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\UsesNavigationLayout;
use EightyNine\FilamentDocs\Pages\DocsPage;

/** Página de guía de usuario con documentación de todos los módulos del sistema. */
class UserGuide extends DocsPage
{
    use UsesNavigationLayout;

    protected static ?string $navigationLabel = 'Guía de Usuario';

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationGroup = 'Ayuda';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Guía de Usuario';

    /**
     * Retorna la ruta al directorio con los archivos Markdown de la guía.
     */
    protected function getDocsPath(): string
    {
        return resource_path('docs');
    }
}
